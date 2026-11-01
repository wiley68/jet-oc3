#!/usr/bin/env python3
import json, re, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
checks=0

def check(value,label):
    global checks
    checks+=1
    if not value: raise AssertionError(label)

runtime=list((ROOT/'upload').rglob('*.php'))
for path in runtime:
    text=path.read_text()
    check('FILTER_SANITIZE_STRING' not in text,'no deprecated sanitizer: '+str(path))
    for match in re.finditer(r"require(?:_once)?\s+(DIR_SYSTEM|DIR_APPLICATION|__DIR__)\s*\.\s*'([^']+)'",text):
        root=ROOT/'upload/system' if match[1]=='DIR_SYSTEM' else (ROOT/('upload/admin' if '/admin/' in str(path) else 'upload/catalog') if match[1]=='DIR_APPLICATION' else path.parent)
        target=root/match[2].lstrip('/')
        check(target.is_file(),'resolved runtime include: '+str(target))
for code in ['bg-bg','en-gb','bg','en','bulgarian','english']:
    for route in ['payment/jet','payment/jet_card','mt_jet_credit/success']:
        check((ROOT/f'upload/catalog/language/{code}/extension/{route}.php').is_file(),'catalog route language: '+code+'/'+route)
for path in (ROOT/'upload/catalog/view/theme/default/template').rglob('*.twig'):
    for expression in re.findall(r'\{\{\s*(.*?)\s*\}\}',path.read_text()):
        check("|e('html')" in expression or expression in {'header','footer','column_left','column_right','content_top','content_bottom'},'owned Twig escaped output: '+expression)
# Preserve every existing admin language variant byte-for-byte.
for path in (ROOT/'upload/admin/language').rglob('*.php'):
    original=subprocess.check_output(['git','show','dd36a97a262fe1cd153bf4783dc9d8c583108a56:'+path.relative_to(ROOT).as_posix()],cwd=ROOT)
    check(path.read_bytes()==original,'existing admin language preserved: '+str(path))
result=subprocess.run(['python3',str(ROOT/'tests/release-manifest.py')],capture_output=True,text=True)
check(result.returncode==0,result.stdout+result.stderr)
print(result.stdout.strip())
print(f'T-COMPAT PASS: {checks} checks; include/language/Twig/manifest contracts. PHP/native execution tested separately.')
