#!/usr/bin/env python3
"""Pinned native source application using OC3's line-search/ignoreif semantics."""
from pathlib import Path
import hashlib, json, subprocess, tempfile, xml.etree.ElementTree as ET
ROOT = Path(__file__).resolve().parents[1]
checks = 0

def check(value, label):
    global checks
    checks += 1
    if not value: raise AssertionError(label)

def apply(version, sources=None):
    xml = ET.parse(ROOT / 'install.xml').getroot()
    originals = {str(p.relative_to(ROOT / 'tests/fixtures/oc3' / version / 'upload')): p.read_text()
                 for p in (ROOT / 'tests/fixtures/oc3' / version / 'upload').rglob('*') if p.is_file()}
    modified = dict(originals if sources is None else sources)
    for file in xml.findall('file'):
        path = file.attrib['path']
        check('*' not in path and 'error' not in file.attrib, 'explicit default-theme target / operation error scope')
        check(path in modified, 'pinned source exists: ' + path)
        for op in file.findall('operation'):
            check(op.attrib.get('error') == 'abort', 'operation-level error=abort')
            ignore = op.findtext('ignoreif')
            check(bool(ignore), 'explicit idempotence marker')
            source = modified[path]
            if ignore in source: continue
            needle = op.findtext('search').strip()
            hits = [i for i, line in enumerate(source.splitlines()) if needle.lower() in line.lower()]
            check(len(hits) == 1, 'unambiguous native anchor: ' + path + ': ' + needle)
            lines = source.splitlines(keepends=True); index = hits[0]
            addition = op.findtext('add').strip() + '\n'; position = op.find('add').attrib['position']
            if position == 'before': lines.insert(index, addition)
            elif position == 'after': lines.insert(index + 1, addition)
            else: lines[index] = lines[index].replace(needle, addition.rstrip('\n'))
            modified[path] = ''.join(lines)
    return modified

def main():
    manifest = json.loads((ROOT / 'tests/fixtures/oc3/manifest.json').read_text())
    for entry in manifest['files']:
        file = ROOT / 'tests/fixtures/oc3' / entry['version'] / entry['path']
        check(hashlib.sha256(file.read_bytes()).hexdigest() == entry['sha256'], 'pinned upstream bytes: ' + str(file))
    events=json.loads((ROOT/'tests/fixtures/oc3/events.json').read_text())
    for entry in events['versions'].values():
        check(any("addOrderHistory/before', 'mail/order'" in line for line in entry['lines']), 'native customer mail is a before event')
        check(any("addOrderHistory/before', 'mail/order/alert'" in line for line in entry['lines']), 'native merchant mail is a before event')
        check(any("addOrderHistory/after', 'extension/total/voucher/send'" in line for line in entry['lines']), 'gift voucher after-mail explicitly accounted for')
    xml = ET.parse(ROOT / 'install.xml').getroot()
    snippets = 0
    for op in xml.findall('.//operation'):
        body = op.findtext('add')
        if ('$this->' in body and '{{' not in body) or 'public const ' in body:
            r = subprocess.run(['php','-l'], input=('<?php\nclass Snippet {\npublic function test(&$route, &$args) {\n' + body + '\n}}').encode(), capture_output=True)
            # Class-level constants belong outside methods.
            if 'public const ' in body:
                r = subprocess.run(['php','-l'], input=('<?php\nclass Snippet {\n' + body + '\n}').encode(), capture_output=True)
            check(r.returncode == 0, 'injected PHP syntax: ' + body[:70]); snippets += 1
    for version in ['3.0.3.8','3.0.5.1']:
        modified = apply(version)
        check(apply(version, modified) == modified, 'second application inserts nothing: ' + version)
        with tempfile.TemporaryDirectory(prefix='jet-ocmod-') as temp:
            dest = Path(temp)
            for path, text in modified.items():
                file = dest / path; file.parent.mkdir(parents=True, exist_ok=True); file.write_text(text)
                if file.suffix == '.php':
                    result = subprocess.run(['php','-l',str(file)], capture_output=True)
                    check(result.returncode == 0, 'full modified native PHP syntax: ' + path)
            admin = subprocess.run(['php', str(ROOT / 'tests/admin-install.php'), str(dest)], capture_output=True, text=True)
            check(admin.returncode == 0, 'native admin install flow: ' + admin.stdout + admin.stderr)
            print(admin.stdout.strip())
            if (ROOT / 'tests/native-integration.php').exists():
                r = subprocess.run(['php', str(ROOT / 'tests/native-integration.php'), version, str(dest)], capture_output=True, text=True)
                check(r.returncode == 0, 'native fixture execution ' + version + ': ' + r.stdout + r.stderr)
                print(r.stdout.strip())
                # Version labels on representative source prove policy, not actual patch certification.
                for label in ['3.0.3.9', '3.99.99']:
                    sample = subprocess.run(['php', str(ROOT / 'tests/native-integration.php'), label, str(dest)], capture_output=True, text=True)
                    check(sample.returncode == 0, 'OC3 capability acceptance on representative source ' + label + ': ' + sample.stdout + sample.stderr)
                for runtime_version in [version, '4.0.0']:
                    mail_file = dest / 'catalog/controller/mail/order.php'
                    original_mail = mail_file.read_text()
                    if runtime_version == version:
                        mail_file.write_text(original_mail.replace('JETCREDIT:NATIVE-ALERT:v1','MISSING-ALERT-GUARD'))
                    negative = subprocess.run(['php', str(ROOT / 'tests/native-unavailable.php'), runtime_version, str(dest)], capture_output=True, text=True)
                    mail_file.write_text(original_mail)
                    check(negative.returncode == 0, 'missing/unsupported capability gates: ' + negative.stdout + negative.stderr)
                # Keep capability constants/markers but remove executable protection.
                mail_file = dest / 'catalog/controller/mail/order.php'
                original_mail = mail_file.read_text()
                mail_file.write_text(original_mail.replace('$jet_scope->suppresses(', '$jet_scope->owns('))
                negative = subprocess.run(['php', str(ROOT / 'tests/native-unavailable.php'), version, str(dest)], capture_output=True, text=True)
                check(negative.returncode == 0, 'marker-only mail capability refused: ' + negative.stdout + negative.stderr)
                mail_file.write_text(original_mail)
                checkout_file = dest / 'catalog/controller/checkout/confirm.php'
                original_checkout = checkout_file.read_text()
                checkout_file.write_text(original_checkout.replace('->orderForIntent(', '->missingReservation('))
                negative = subprocess.run(['php', str(ROOT / 'tests/native-unavailable.php'), version, str(dest)], capture_output=True, text=True)
                check(negative.returncode == 0, 'marker-only checkout capability refused: ' + negative.stdout + negative.stderr)
                checkout_file.write_text(original_checkout.replace("$jet_diagnostics->stage('model_loading');", ""))
                negative = subprocess.run(['php', str(ROOT / 'tests/native-unavailable.php'), version, str(dest)], capture_output=True, text=True)
                check(negative.returncode == 0, 'partial diagnostic checkout replacement refused: ' + negative.stdout + negative.stderr)
                checkout_file.write_text(original_checkout)
        broken = dict(modified)
        # A missing anchor on original source must abort, never silently skip a guard.
        original = (ROOT / 'tests/fixtures/oc3' / version / 'upload/catalog/controller/mail/order.php').read_text()
        broken['catalog/controller/mail/order.php'] = original.replace('public function alert(&$route, &$args) {', 'public function incompatibleAlert() {')
        try: apply(version, broken)
        except AssertionError: pass
        else: raise AssertionError('missing native alert anchor did not abort')
    print(f'T-OCMOD PASS: {checks} checks; exact 3.0.3.8 / 3.0.5.1 default-theme snapshots; {snippets} PHP snippets.')
if __name__ == '__main__': main()
