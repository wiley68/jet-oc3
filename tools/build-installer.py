#!/usr/bin/env python3
"""Read-only, exact-manifest OC3 packager. No checkout, commit, mail or DB access."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile
import zlib

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = 'docs/release-manifest.json'
VERSION_SOURCE = 'upload/system/library/jetcredit/version.php'
EXTENSIONS = {'.php', '.twig', '.js', '.css', '.png', '.woff2', '.xml'}
FORBIDDEN = {'.git', '.codex', '.agents', '.idea', '.vscode', 'tests', 'cache',
             'logs', 'node_modules', 'vendor', 'credentials', '__pycache__'}


class BuildError(Exception):
    pass


def sha(data):
    return hashlib.sha256(data).hexdigest()


def git(root, *args):
    result = subprocess.run(['git', '-C', str(root), *args], capture_output=True)
    if result.returncode:
        raise BuildError('Git source unavailable: ' + ' '.join(args[:2]))
    return result.stdout


def allowed(path):
    parts = PurePosixPath(path).parts
    return (path == PurePosixPath(path).as_posix() and '\\' not in path
            and path not in {'upload/config.php', 'upload/admin/config.php', 'upload/install.php'}
            and not any(p in {'', '.', '..'} or p.startswith('.') or ':' in p
                        or p.lower() in FORBIDDEN for p in parts)
            and (path == 'install.xml' or path.startswith('upload/'))
            and PurePosixPath(path).suffix in EXTENSIONS
            and not any(word in PurePosixPath(path).name.lower()
                        for word in ('credential', '.env', '.log', '.bak', '.secret')))


class Source:
    def __init__(self, root, mode, ref=None):
        self.root = Path(root).resolve()
        self.mode = mode
        if mode == 'release':
            if not ref or ref.startswith('-'):
                raise BuildError('Release mode requires an explicit committed SHA or tag.')
            # Fully qualified tag or full SHA only: no branch/HEAD/working-tree ambiguity.
            revision = ref if re.fullmatch(r'[a-fA-F0-9]{40}', ref) else 'refs/tags/' + ref
            if not re.fullmatch(r'[a-fA-F0-9]{40}', ref):
                git(self.root, 'check-ref-format', revision)
            self.commit = git(self.root, 'rev-parse', '--verify', revision + '^{commit}').decode().strip()
            self.ref = ref
            self.tree = {}
            for item in git(self.root, 'ls-tree', '-rz', '--full-tree', self.commit).split(b'\0'):
                if item:
                    info, path = item.split(b'\t', 1)
                    mode_bits, kind, oid = info.decode().split()
                    self.tree[path.decode()] = (mode_bits, kind, oid)
        else:
            self.commit = git(self.root, 'rev-parse', 'HEAD').decode().strip()
            self.ref = None
            self.tree = None

    def read(self, name):
        if self.tree is not None:
            entry = self.tree.get(name)
            if not entry or entry[0] not in {'100644', '100755'} or entry[1] != 'blob':
                raise BuildError('Missing/non-regular committed source: ' + name)
            return git(self.root, 'cat-file', 'blob', entry[2])
        path = self.root / name
        if not path.is_relative_to(self.root) or any(p.is_symlink() for p in [path, *path.parents] if p != self.root.parent):
            raise BuildError('Symlink/unsafe source: ' + name)
        if not path.is_file():
            raise BuildError('Missing source: ' + name)
        return path.read_bytes()

    def inventory(self):
        if self.tree is not None:
            names = [p for p in self.tree if p == 'install.xml' or p.startswith('upload/')]
        else:
            if (self.root / 'upload').is_symlink():
                raise BuildError('Symlink upload root.')
            paths = list((self.root / 'upload').rglob('*'))
            if any(p.is_symlink() for p in paths):
                raise BuildError('Symlinks are forbidden in runtime sources.')
            names = ['install.xml'] + [p.relative_to(self.root).as_posix() for p in paths if p.is_file()]
        # Known Windows download metadata remains in source, never in the ZIP.
        return {p for p in names if not p.endswith(':Zone.Identifier')}


def validate(source):
    raw_manifest = source.read(MANIFEST)
    try:
        manifest = json.loads(raw_manifest)
        records = manifest['files']
        paths = [r['path'] for r in records]
    except (ValueError, KeyError, TypeError) as exc:
        raise BuildError('Invalid source manifest.') from exc
    if (manifest.get('format') != 'opencart3-ocmod-source-manifest-v1'
            or manifest.get('archive_roots') != ['install.xml', 'upload/']
            or not paths or len(paths) != len(set(paths)) or not all(allowed(p) for p in paths)):
        raise BuildError('Duplicate, forbidden or invalid manifest paths/roots.')
    inventory = source.inventory()
    if inventory != set(paths):
        raise BuildError('Exact manifest mismatch; missing=' + repr(sorted(set(paths) - inventory))
                         + '; unexpected=' + repr(sorted(inventory - set(paths))))
    assets = {}
    for record in records:
        name = record['path']
        data = source.read(name)
        if sha(data) != record.get('sha256') or len(data) != record.get('bytes'):
            raise BuildError('Source hash/size mismatch: ' + name)
        assets[name] = data
    version_match = re.search(rb"public const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';", assets.get(VERSION_SOURCE, b''))
    if not version_match:
        raise BuildError('Authoritative version source missing/invalid.')
    version = version_match[1].decode()
    try:
        xml = ET.fromstring(assets['install.xml'])
    except ET.ParseError as exc:
        raise BuildError('Invalid install.xml.') from exc
    if xml.tag != 'modification' or xml.findtext('version') != version + ' for OpenCart 3.x' or manifest.get('module_version') != version:
        raise BuildError('Version mismatch between runtime, XML and manifest.')
    for gateway in ('jet', 'jet_card'):
        text = assets.get('upload/catalog/controller/extension/payment/' + gateway + '.php', b'').decode()
        if "require_once DIR_SYSTEM . 'library/jetcredit/version.php';" not in text or "$data['JET_VERSION'] = JetCreditVersion::VERSION;" not in text:
            raise BuildError('Gateway does not use authoritative version: ' + gateway)
    # Validate all statically declared owned includes and local image/font/script assets.
    for name, data in assets.items():
        if PurePosixPath(name).suffix not in {'.php', '.xml', '.twig', '.js', '.css'}:
            continue
        text = data.decode('utf-8')
        for base, tail in re.findall(r"require(?:_once)?\s+(DIR_SYSTEM|DIR_APPLICATION|__DIR__)\s*\.\s*'([^']+)'", text):
            root = ('upload/system' if base == 'DIR_SYSTEM' else
                    ('upload/admin' if name.startswith('upload/admin/') else 'upload/catalog') if base == 'DIR_APPLICATION' else str(PurePosixPath(name).parent))
            target = str(PurePosixPath(root) / tail.lstrip('/'))
            if target not in assets:
                raise BuildError('Missing runtime include: ' + target)
        for target in re.findall(r'catalog/[A-Za-z0-9_./-]+\.(?:js|css|png|woff2)', text):
            if 'upload/' + target not in assets:
                raise BuildError('Missing referenced asset: ' + target)
        if name.endswith('.css'):
            for target in re.findall(r'url\([\s\"\x27]*([^\)\s\"\x27]+)', text):
                if not target.startswith(('data:', 'http:', 'https:')):
                    target = os.path.normpath(str(PurePosixPath(name).parent / target.split('?')[0]))
                    if target not in assets:
                        raise BuildError('Missing CSS asset: ' + target)
    required = ['upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js',
                'upload/catalog/view/theme/default/template/extension/mt_jet_credit/success.twig',
                'upload/catalog/controller/extension/mt_jet_credit/success.php']
    for path in required:
        if path not in assets:
            raise BuildError('Required runtime asset missing: ' + path)
    with tempfile.TemporaryDirectory(prefix='jet-php-lint-') as directory:
        for name, data in assets.items():
            if name.endswith('.php'):
                path = Path(directory) / 'source.php'
                path.write_bytes(data)
                result = subprocess.run(['php', '-l', str(path)], capture_output=True)
                if result.returncode:
                    raise BuildError('PHP syntax failed: ' + name)
    return version, assets, raw_manifest


def archive_bytes(assets):
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, data in sorted(assets.items()):
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data, compresslevel=9)
    result = output.getvalue()
    verify_archive(result, assets)
    return result


def verify_archive(data, assets):
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        if archive.namelist() != sorted(assets) or archive.testzip() is not None:
            raise BuildError('ZIP entry inventory/CRC mismatch.')
        for info in archive.infolist():
            if not allowed(info.filename) or archive.read(info) != assets[info.filename]:
                raise BuildError('ZIP source hash/path mismatch: ' + info.filename)


def build(root, mode, output, ref=None):
    if mode not in {'candidate', 'release'} or (mode == 'candidate' and ref):
        raise BuildError('Invalid build mode/ref combination.')
    if Path(output).resolve().is_relative_to(Path(root).resolve() / 'upload'):
        raise BuildError('Build output must be outside runtime upload sources.')
    source = Source(root, mode, ref)
    version, assets, raw_manifest = validate(source)
    records = [{'path': p, 'bytes': len(d), 'sha256': sha(d)} for p, d in sorted(assets.items())]
    fingerprint = sha(json.dumps(records, sort_keys=True, separators=(',', ':')).encode())
    data = archive_bytes(assets)
    filename = 'CC_OpenCart.3.x_JET_v.' + version + '.ocmod.zip'
    # Candidate and release intentionally share the canonical flat filename.
    # Refuse even byte-identical collisions: their provenance/report can differ.
    destination = Path(output).resolve()
    report_filename = filename + '.build-report.json'
    report = {'status': 'TEST / UNRELEASED' if mode == 'candidate' else 'COMMITTED BUILD / NOT PUBLISHED',
              'mode': mode, 'version': version, 'version_source': VERSION_SOURCE,
              'git_commit': source.commit, 'git_tag': source.ref if mode == 'release' and not re.fullmatch(r'[a-fA-F0-9]{40}', source.ref) else None,
              'working_tree_used': mode == 'candidate',
              'git_status_porcelain': git(source.root, 'status', '--porcelain=v1', '--untracked-files=all').decode() if mode == 'candidate' else None,
              'source_fingerprint': fingerprint, 'source_manifest_sha256': sha(raw_manifest),
              'filename': filename, 'sha256': sha(data), 'bytes': len(data),
              'report_filename': report_filename, 'output_layout': 'flat',
              'archive_roots': ['install.xml', 'upload/'], 'files': records,
              'builder_sha256': sha(Path(__file__).read_bytes()),
              'toolchain': {'python': sys.version.split()[0], 'zlib': zlib.ZLIB_VERSION,
                            'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;']).decode()},
              'validation': 'exact inventory; SHA-256/size; XML/version; includes/assets; all runtime PHP lint; ZIP CRC and byte equality',
              'limitations': 'No real MySQL, native cache refresh, browser/store E2E or SMTP certification.'}
    # Recheck candidate sources before publishing the in-memory snapshot; never re-read them into ZIP.
    if mode == 'candidate':
        if source.inventory() != set(assets) or any(source.read(p) != d for p, d in assets.items()) or source.read(MANIFEST) != raw_manifest:
            raise BuildError('Sources changed during build; no artifact published.')
    bundle = {filename: data,
              filename + '.sha256': (sha(data) + '  ' + filename + '\n').encode(),
              report_filename: (json.dumps(report, indent=2) + '\n').encode()}
    destination.mkdir(parents=True, exist_ok=True)
    lock = destination / '.jet-installer.lock'
    try:
        lock.mkdir()
    except FileExistsError as exc:
        raise BuildError('Output is locked; inspect the other build or interrupted build before retrying: ' + str(lock)) from exc
    try:
        if any(os.path.lexists(destination / name) for name in bundle):
            raise BuildError('Artifact already exists; nothing overwritten. Explicitly archive/remove the prior ZIP, checksum and report before replacement: ' + str(destination / filename))
        with tempfile.TemporaryDirectory(prefix='.jet-build-', dir=destination) as temporary:
            staging = Path(temporary)
            for name, content in bundle.items():
                path = staging / name
                with path.open('xb') as stream:
                    stream.write(content)
                    stream.flush()
                    os.fsync(stream.fileno())
            # Same-filesystem exclusive publication: link never replaces an existing
            # path, even if a non-cooperating process races the output lock.
            # A crash may leave a partial bundle; subsequent builds refuse it.
            for name in bundle:
                os.link(staging / name, destination / name)
    finally:
        lock.rmdir()
    return destination, report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['candidate', 'release'])
    parser.add_argument('--ref', help='Release only: full committed SHA or tag name (never a branch).')
    parser.add_argument('--output', type=Path, default=ROOT / 'dist', help='Flat output directory; default: repository dist/. Override only for isolated verification builds.')
    args = parser.parse_args()
    if args.mode == 'candidate' and args.ref:
        parser.error('Candidate mode cannot claim a Git ref.')
    if args.mode == 'release' and not args.ref:
        parser.error('Release mode requires --ref SHA-or-tag.')
    try:
        destination, report = build(ROOT, args.mode, args.output, args.ref)
    except (BuildError, OSError, ValueError) as exc:
        print('BUILD REFUSED: ' + str(exc), file=sys.stderr)
        return 1
    print(report['status'] + '\n' + str(destination / report['filename']) + '\nSHA-256 ' + report['sha256'])
    return 0


if __name__ == '__main__':
    sys.exit(main())
