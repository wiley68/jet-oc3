#!/usr/bin/env python3
"""Installer tests use temporary source copies; no Git commits or DB/mail calls."""
import hashlib
import contextlib
import os
import importlib.util
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

sys.dont_write_bytecode = True
SOURCE_ONLY = '--source-only' in sys.argv
if SOURCE_ONLY: sys.argv.remove('--source-only')
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('jet_builder', ROOT / 'tools/build-installer.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class InstallerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = builder.Source(ROOT, 'candidate')
        cls.version, cls.assets, cls.manifest = builder.validate(cls.source)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='jet-builder-test-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)

    def copy_source(self):
        root = self.directory / 'source'
        shutil.copytree(ROOT / 'upload', root / 'upload')
        (root / 'docs').mkdir()
        shutil.copyfile(ROOT / 'install.xml', root / 'install.xml')
        (root / builder.MANIFEST).write_bytes(self.manifest)
        # The candidate adapter's Git baseline is supplied by a stub in these mutation tests.
        with patch.object(builder, 'git', return_value=b'd' * 40 + b'\n'):
            return builder.Source(root, 'candidate')

    def rehash(self, source):
        manifest = json.loads((source.root / builder.MANIFEST).read_bytes())
        for record in manifest['files']:
            data = (source.root / record['path']).read_bytes()
            record.update(sha256=builder.sha(data), bytes=len(data))
        (source.root / builder.MANIFEST).write_text(json.dumps(manifest))

    @unittest.skipIf(SOURCE_ONLY, 'Source-only mode forbids ZIP generation')
    def test_archive_filename_version_exact_roots_hashes_and_forbidden_exclusion(self):
        destination, report = builder.build(ROOT, 'candidate', self.directory)
        self.assertEqual(report['filename'], 'CC_OpenCart.3.x_JET_v.' + self.version + '.ocmod.zip')
        self.assertEqual(report['status'], 'TEST / UNRELEASED')
        self.assertIsNone(report['git_tag'])
        self.assertTrue(report['working_tree_used'])
        self.assertEqual(destination, self.directory.resolve())
        self.assertEqual(report['output_layout'], 'flat')
        self.assertEqual({p.name for p in destination.iterdir()}, {report['filename'], report['filename'] + '.sha256'})
        data = (destination / report['filename']).read_bytes()
        self.assertEqual(hashlib.sha256(data).hexdigest(), report['sha256'])
        checksum = (destination / (report['filename'] + '.sha256')).read_text()
        self.assertEqual(checksum, report['sha256'] + '  ' + report['filename'] + '\n')
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            self.assertEqual(archive.namelist(), sorted(self.assets))
            self.assertEqual({p.split('/')[0] for p in archive.namelist()}, {'install.xml', 'upload'})
            for name in archive.namelist():
                self.assertTrue(builder.allowed(name), name)
                self.assertEqual(archive.read(name), self.assets[name], name)
            self.assertIn(builder.VERSION_SOURCE, archive.namelist())
            self.assertTrue(any('/PHPMailer/' in p for p in archive.namelist()))
        # Two full builds from the same uncommitted source produce identical ZIP bytes.
        other, _ = builder.build(ROOT, 'candidate', self.directory / 'repeat')
        self.assertEqual(data, (other / report['filename']).read_bytes())
        self.assertEqual(builder.validate(self.source)[1], self.assets, 'packaging did not write runtime sources')
        with self.assertRaisesRegex(builder.BuildError, 'already exists'):
            builder.build(ROOT, 'candidate', self.directory)
        self.assertEqual(data, (destination / report['filename']).read_bytes())

    @unittest.skipIf(SOURCE_ONLY, 'Source-only mode forbids ZIP generation')
    def test_determinism_independent_of_inventory_order(self):
        self.assertEqual(builder.archive_bytes(self.assets), builder.archive_bytes(dict(reversed(list(self.assets.items())))))

    def test_missing_source(self):
        source = self.copy_source()
        (source.root / builder.VERSION_SOURCE).unlink()
        with self.assertRaisesRegex(builder.BuildError, 'manifest mismatch'):
            builder.validate(source)

    def test_unexpected_runtime_source(self):
        source = self.copy_source()
        (source.root / 'upload/unapproved.php').write_text('<?php')
        with self.assertRaisesRegex(builder.BuildError, 'unexpected'):
            builder.validate(source)

    def test_source_hash_tampering(self):
        source = self.copy_source()
        (source.root / 'install.xml').write_bytes(self.assets['install.xml'] + b'\n')
        with self.assertRaisesRegex(builder.BuildError, 'hash/size'):
            builder.validate(source)

    def test_version_mismatch(self):
        source = self.copy_source()
        path = source.root / builder.VERSION_SOURCE
        path.write_bytes(path.read_bytes().replace(self.version.encode(), b'2.0.99'))
        self.rehash(source)
        with self.assertRaisesRegex(builder.BuildError, 'Version mismatch'):
            builder.validate(source)

    def test_forbidden_manifest_entry(self):
        for name in ['../install.xml', '/install.xml', 'upload/.env', 'upload/x:Zone.Identifier',
                     'upload/cache/test.php', 'tools/build-installer.py', 'upload/credentials.php', 'upload/x.php.log',
                     'upload/config.php', 'upload/admin/config.php', 'upload/install.php']:
            self.assertFalse(builder.allowed(name), name)
        source = self.copy_source()
        manifest = json.loads(self.manifest)
        manifest['files'].append({'path': 'upload/.env', 'sha256': '0' * 64, 'bytes': 0})
        (source.root / builder.MANIFEST).write_text(json.dumps(manifest))
        with self.assertRaisesRegex(builder.BuildError, 'forbidden'):
            builder.validate(source)

    def test_duplicate_manifest_entry(self):
        source = self.copy_source()
        manifest = json.loads(self.manifest)
        manifest['files'].append(manifest['files'][0])
        (source.root / builder.MANIFEST).write_text(json.dumps(manifest))
        with self.assertRaisesRegex(builder.BuildError, 'Duplicate'):
            builder.validate(source)

    def test_missing_referenced_asset_even_after_manifest_edit(self):
        source = self.copy_source()
        name = 'upload/catalog/view/theme/default/image/jet_logo.png'
        (source.root / name).unlink()
        manifest = json.loads(self.manifest)
        manifest['files'] = [r for r in manifest['files'] if r['path'] != name]
        (source.root / builder.MANIFEST).write_text(json.dumps(manifest))
        with self.assertRaisesRegex(builder.BuildError, 'Missing referenced asset'):
            builder.validate(source)

    def test_refuse_output_inside_runtime_sources(self):
        with self.assertRaisesRegex(builder.BuildError, 'outside runtime'):
            builder.build(ROOT, 'candidate', ROOT / 'upload/build-output')

    def test_symlink_source(self):
        source = self.copy_source()
        path = source.root / builder.VERSION_SOURCE
        path.unlink()
        path.symlink_to(ROOT / builder.VERSION_SOURCE)
        with self.assertRaisesRegex(builder.BuildError, '[Ss]ymlink'):
            builder.validate(source)

    def test_invalid_php(self):
        source = self.copy_source()
        (source.root / 'upload/catalog/controller/extension/mt_jet_credit/success.php').write_text('<?php syntax broken !')
        self.rehash(source)
        with self.assertRaisesRegex(builder.BuildError, 'PHP syntax'):
            builder.validate(source)

    def test_invalid_xml(self):
        source = self.copy_source()
        (source.root / 'install.xml').write_text('<modification>')
        self.rehash(source)
        with self.assertRaisesRegex(builder.BuildError, 'Invalid install.xml'):
            builder.validate(source)

    @unittest.skipIf(SOURCE_ONLY, 'Source-only mode forbids ZIP generation')
    def test_archive_entry_corruption(self):
        other = dict(self.assets)
        other['upload/unexpected.php'] = b'<?php'
        with self.assertRaisesRegex(builder.BuildError, 'inventory'):
            builder.verify_archive(builder.archive_bytes(other), self.assets)

    def test_committed_source_ignores_working_tree(self):
        # Pin the original source: HEAD will include the approved manifest after checkpoint.
        commit = 'dd36a97a262fe1cd153bf4783dc9d8c583108a56'
        source = builder.Source(ROOT, 'release', commit)
        original = subprocess.check_output(['git', 'show', commit + ':install.xml'], cwd=ROOT)
        self.assertEqual(source.read('install.xml'), original)
        self.assertNotEqual(original, self.assets['install.xml'])
        # Existing baseline has no approved manifest: refuse, never borrow dirty files.
        with self.assertRaisesRegex(builder.BuildError, 'committed source'):
            builder.validate(source)
        with self.assertRaises(builder.BuildError):
            builder.Source(ROOT, 'release', 'main')

    @unittest.skipIf(SOURCE_ONLY, 'Source-only mode forbids ZIP generation')
    def test_release_pipeline_with_immutable_git_adapter_double(self):
        # Full release logic without creating a commit/tag. Real object reads tested above.
        blobs = dict(self.assets)
        blobs[builder.MANIFEST] = self.manifest
        objects = {builder.sha(data): data for data in blobs.values()}
        tree = b'\0'.join(('100644 blob ' + builder.sha(data) + '\t' + name).encode() for name, data in blobs.items()) + b'\0'
        def immutable_git(root, *args):
            if args[0] == 'check-ref-format': return b''
            if args[0] == 'rev-parse': return b'a' * 40 + b'\n'
            if args[0] == 'ls-tree': return tree
            if args[0] == 'cat-file': return objects[args[2]]
            self.fail('Unexpected Git operation: ' + repr(args))
        with patch.object(builder, 'git', side_effect=immutable_git):
            destination, report = builder.build(ROOT, 'release', self.directory, 'v2.0.0-test-fixture')
        self.assertFalse(report['working_tree_used'])
        self.assertEqual(report['git_tag'], 'v2.0.0-test-fixture')
        self.assertEqual(destination, self.directory.resolve())
        self.assertEqual(report['status'], 'COMMITTED BUILD / NOT PUBLISHED')
        builder.verify_archive((destination / report['filename']).read_bytes(), self.assets)
        # Flat layout must reject release-over-release and candidate-over-release too.
        before = {p.name:p.read_bytes() for p in destination.iterdir()}
        with patch.object(builder, 'git', side_effect=immutable_git):
            with self.assertRaisesRegex(builder.BuildError, 'already exists'):
                builder.build(ROOT, 'release', self.directory, 'v2.0.0-test-fixture')
        with self.assertRaisesRegex(builder.BuildError, 'already exists'):
            builder.build(ROOT, 'candidate', self.directory)
        self.assertEqual(before, {p.name:p.read_bytes() for p in destination.iterdir()})
        candidate_dir, _ = builder.build(ROOT, 'candidate', self.directory / 'candidate-first')
        prior = {p.name:p.read_bytes() for p in candidate_dir.iterdir()}
        with patch.object(builder, 'git', side_effect=immutable_git):
            with self.assertRaisesRegex(builder.BuildError, 'already exists'):
                builder.build(ROOT, 'release', candidate_dir, 'v2.0.0-test-fixture')
        self.assertEqual(prior, {p.name:p.read_bytes() for p in candidate_dir.iterdir()})

    def test_cli_defaults_to_flat_dist_for_both_modes(self):
        result = {'status':'test stub','filename':'fixture.zip','sha256':'0'*64}
        for mode, extra in [('candidate',[]),('release',['--ref','v2.0.0'])]:
            with patch.object(sys,'argv',['build-installer.py',mode,*extra]), patch.object(builder,'build',return_value=(self.directory,result)) as build, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(builder.main(),0)
            build.assert_called_once_with(ROOT,mode,ROOT/'dist','v2.0.0' if mode=='release' else None)

    def test_shell_default_and_explicit_isolated_output(self):
        fakebin=self.directory/'bin';fakebin.mkdir()
        interpreter=fakebin/'python3'
        interpreter.write_text('#!/usr/bin/python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
        interpreter.chmod(0o755)
        environment=dict(os.environ,PATH=str(fakebin)+os.pathsep+os.environ.get('PATH',''))
        for mode, extra in [('candidate',[]),('release',['--ref','v2.0.0'])]:
            command=['bash',str(ROOT/'tools/build-installer.sh'),mode,*extra]
            args=json.loads(subprocess.check_output(command,cwd=self.directory,env=environment))
            self.assertEqual(args,[str(ROOT/'tools/build-installer.py'),'--output',str(ROOT/'dist'),mode,*extra])
        result={'status':'test stub','filename':'fixture.zip','sha256':'0'*64}
        with patch.object(sys,'argv',['build-installer.py','--output',str(ROOT/'dist'),'candidate','--output',str(self.directory)]), patch.object(builder,'build',return_value=(self.directory,result)) as build, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(builder.main(),0)
        build.assert_called_once_with(ROOT,'candidate',self.directory,None)

    @unittest.skipIf(SOURCE_ONLY, 'Source-only mode forbids ZIP generation')
    def test_any_existing_bundle_component_blocks_publication(self):
        filename='CC_OpenCart.3.x_JET_v.'+self.version+'.ocmod.zip'
        for index,name in enumerate([filename,filename+'.sha256']):
            output=self.directory/str(index);output.mkdir()
            (output/name).write_bytes(b'prior content must survive')
            with self.assertRaisesRegex(builder.BuildError,'already exists'):
                builder.build(ROOT,'candidate',output)
            self.assertEqual({p.name:p.read_bytes() for p in output.iterdir()},{name:b'prior content must survive'})

    @unittest.skipIf(SOURCE_ONLY, 'Source-only mode forbids ZIP generation')
    def test_output_lock_prevents_concurrent_publication(self):
        (self.directory/'.jet-installer.lock').mkdir()
        with self.assertRaisesRegex(builder.BuildError,'locked'):
            builder.build(ROOT,'candidate',self.directory)
        self.assertEqual([p.name for p in self.directory.iterdir()],['.jet-installer.lock'])

    def test_canonical_generated_outputs_are_git_ignored(self):
        filename='CC_OpenCart.3.x_JET_v.'+self.version+'.ocmod.zip'
        names=['dist/'+filename,'dist/'+filename+'.sha256']
        result=subprocess.run(['git','check-ignore','--stdin'],input='\n'.join(names)+'\n',text=True,capture_output=True,cwd=ROOT)
        self.assertEqual(result.returncode,0)
        self.assertEqual(result.stdout.splitlines(),names)


if __name__ == '__main__':
    unittest.main(verbosity=2)
