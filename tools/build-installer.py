#!/usr/bin/env python3
"""Build the current JET-OC3 working tree as one verified OCMOD installer."""
import hashlib
import os
from pathlib import Path
import re
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PREFIX = 'CC_OpenCart.3.x_JET_v.'
EXTENSIONS = {'.php', '.twig', '.js', '.css', '.png', '.woff2'}
EXCLUDED = {'tests', 'tools', 'dist', 'node_modules', 'vendor', 'cache', 'logs', '__pycache__'}


class BuildError(Exception):
    pass


def sources(root):
    upload = root / 'upload'
    if upload.is_symlink() or not upload.is_dir() or (root / 'install.xml').is_symlink():
        raise BuildError('Missing or symlinked installer sources.')
    files = {'install.xml': (root / 'install.xml').read_bytes()}
    for path in sorted(upload.rglob('*')):
        if path.is_symlink():
            raise BuildError('Symlink in runtime sources: ' + str(path))
        relative = path.relative_to(root)
        if (not path.is_file() or path.suffix not in EXTENSIONS
                or path.name in {'config.php', 'install.php'}
                or any(part.startswith('.') or part.lower() in EXCLUDED for part in relative.parts)):
            continue
        files[relative.as_posix()] = path.read_bytes()
    xml = ET.fromstring(files['install.xml'])
    match = re.fullmatch(r'(\d+\.\d+\.\d+) for OpenCart 3\.x', xml.findtext('version') or '')
    if xml.tag != 'modification' or not match or not xml.findall('file/operation'):
        raise BuildError('Invalid OpenCart 3.x OCMOD metadata.')
    version = match.group(1)
    for code in ('jet', 'jet_card'):
        name = 'upload/catalog/controller/extension/payment/' + code + '.php'
        declarations = re.findall(rb"\$data\['JET_VERSION'\]\s*=\s*'([^']+)';", files.get(name, b''))
        if declarations != [version.encode('ascii')]:
            raise BuildError('Version mismatch or missing Checkout metadata: ' + name)
    if not any(name.startswith('upload/system/') for name in files):
        raise BuildError('Runtime system libraries are missing.')
    for name, data in files.items():
        if Path(name).suffix not in {'.php', '.twig', '.js', '.css', '.xml'}:
            continue
        text = data.decode('utf-8')
        for base, tail in re.findall(r"require(?:_once)?\s+(DIR_SYSTEM|DIR_APPLICATION|__DIR__)\s*\.\s*'([^']+)'", text):
            directory = ('upload/system' if base == 'DIR_SYSTEM' else
                         ('upload/admin' if name.startswith('upload/admin/') else 'upload/catalog')
                         if base == 'DIR_APPLICATION' else str(Path(name).parent))
            target = (Path(directory) / tail.lstrip('/')).as_posix()
            if target not in files:
                raise BuildError('Missing runtime include: ' + target)
        for target in re.findall(r'catalog/view/theme/default/[A-Za-z0-9_./-]+\.(?:js|css|png|woff2)', text):
            if 'upload/' + target not in files:
                raise BuildError('Missing runtime asset: ' + target)
        if name.endswith('.css'):
            for target in re.findall(r'''url\([\s"']*([^\)\s"']+)''', text):
                if not target.startswith(('data:', 'http:', 'https:')):
                    target = os.path.normpath(str(Path(name).parent / target.split('?')[0]))
                    if target not in files:
                        raise BuildError('Missing CSS asset: ' + target)
    return version, files


def verify(archive_path, files):
    with zipfile.ZipFile(archive_path) as archive:
        if archive.namelist() != sorted(files) or archive.testzip() is not None:
            raise BuildError('ZIP inventory or CRC verification failed.')
        for name, data in files.items():
            if archive.read(name) != data:
                raise BuildError('Packaged source differs: ' + name)
        ET.fromstring(archive.read('install.xml'))


def build(root=ROOT):
    root = Path(root).resolve()
    version, files = sources(root)
    dist = root / 'dist'
    if dist.is_symlink():
        raise BuildError('dist/ must not be a symlink.')
    dist.mkdir(exist_ok=True)
    previous = []
    for path in dist.iterdir():
        if (path.is_symlink() or not path.is_file() or not path.name.startswith(PREFIX)
                or not path.name.endswith(('.ocmod.zip', '.ocmod.zip.sha256'))):
            raise BuildError('Unrelated or unsafe file in dist/; nothing removed: ' + path.name)
        previous.append(path)
    destination = dist / (PREFIX + version + '.ocmod.zip')
    with tempfile.TemporaryDirectory(prefix='.jet-build-', dir=dist) as temporary:
        candidate = Path(temporary) / 'installer.tmp'
        with zipfile.ZipFile(candidate, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name, data in sorted(files.items()):
                entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
                entry.create_system = 3
                entry.external_attr = 0o100644 << 16
                entry.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(entry, data, compresslevel=9)
        verify(candidate, files)
        if sources(root) != (version, files):
            raise BuildError('Sources changed during build; previous installer retained.')
        # Keep previous installers recoverable until the verified replacement is published.
        backups = {}
        try:
            for path in previous:
                backup = Path(temporary) / path.name
                os.replace(path, backup)
                backups[path] = backup
            os.replace(candidate, destination)
        except OSError:
            for path, backup in backups.items():
                os.replace(backup, path)
            raise
    return destination


def main():
    try:
        archive = build()
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    except (BuildError, OSError, ValueError, ET.ParseError, zipfile.BadZipFile) as error:
        print('BUILD FAILED: ' + str(error), file=sys.stderr)
        return 1
    print(str(archive))
    print('Bytes: ' + str(archive.stat().st_size))
    print('SHA-256: ' + digest)
    return 0


if __name__ == '__main__':
    sys.exit(main())
