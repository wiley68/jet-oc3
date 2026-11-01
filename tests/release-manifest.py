#!/usr/bin/env python3
"""Prepare/check an exact source manifest; deliberately never creates an archive."""
import hashlib, json, re, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / 'docs/release-manifest.json'

def manifest():
    files = [ROOT / 'install.xml'] + sorted(path for path in (ROOT / 'upload').rglob('*')
                if path.is_file() and not path.name.endswith(':Zone.Identifier'))
    records = []
    for path in files:
        relative = path.relative_to(ROOT).as_posix()
        if any(part in {'.git','.codex','.agents','tests','cache','node_modules','vendor'} for part in path.relative_to(ROOT).parts):
            raise RuntimeError('Unexpected release path: ' + relative)
        if path.suffix not in {'.php','.twig','.js','.css','.png','.woff2','.xml'}:
            raise RuntimeError('Unexpected release asset type: ' + relative)
        records.append({'path': relative, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'bytes': path.stat().st_size})
    version = re.search(r"public const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';", (ROOT/'upload/system/library/jetcredit/version.php').read_text())[1]
    return {'format':'opencart3-ocmod-source-manifest-v1', 'module_version':version,
            'archive_roots':['install.xml','upload/'], 'archive_created':False,
            'documentation':['docs/CLEAN-INSTALL.md','docs/MYSQL-TEST.md','docs/BUILD-INSTALLER.md','docs/PURCHASE-LIFECYCLE.md'],
            'excluded':['*:Zone.Identifier','tests/**','docs/**','.git/**','credentials','caches'],
            'files':records}

current = manifest()
if '--write' in sys.argv:
    TARGET.parent.mkdir(parents=True,exist_ok=True)
    TARGET.write_text(json.dumps(current,indent=2)+'\n')
else:
    if json.loads(TARGET.read_text()) != current:
        raise SystemExit('Release source manifest is stale; review changes then regenerate with --write.')
print(f'RELEASE-MANIFEST PASS: {len(current["files"])} exact runtime/XML assets; ZIP roots install.xml + upload/; no archive created.')
