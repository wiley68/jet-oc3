#!/usr/bin/env python3
"""Offline source/fixture contracts. This never connects to MySQL or SMTP."""
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
checks = 0

def check(value, message):
    global checks
    checks += 1
    if not value:
        raise AssertionError(message)

# Pin the captured bytes; never regenerate expected messages from the new formatter.
for name, expected in {
    'bank-oc3.json': '1943d5638547d29f63e940415886078d02d8fea03fc4c2cb76aebb0698b5c836',
    'bank-oc3-cart-options.json': '602e133e851b3a06f06791cb7fa13ef5cdbb5c43aab48e3be9d8adf6b17b2153',
    'financial-oc3.json': 'a9ae6c9c98c66de3dc1d9888575aa614066375f791b40a5aa2e983d48483a9cc',
}.items():
    data = (ROOT / 'tests/fixtures' / name).read_bytes()
    check(hashlib.sha256(data).hexdigest() == expected, 'immutable fixture: ' + name)
    fixture = json.loads(data)
    if not name.startswith('bank-'):
        continue
    for case in fixture['cases']:
        path = ('mt_jet_credit/jetcredit' if case['context'] != 'checkout'
                else 'payment/' + case['scheme'])
        original = subprocess.check_output(['git', 'show', fixture['baseline'] +
            ':upload/catalog/controller/extension/' + path + '.php'], cwd=ROOT).decode()
        offset = original.index('public function jet_send_cart') if case['context'] == 'cart' else 0
        start = original.index('$body = "Данни за потребителя:', offset)
        body_fragment = original[start:original.index('$jet_count =', start)]
        check(hashlib.sha256(body_fragment.encode()).hexdigest() == case['original_fragment_sha256'],
              'captured fragment matches original baseline: ' + path)

runtime = ROOT / 'upload/system/library/jetcredit'
store = (runtime / 'submission_store.php').read_text()
schema = (runtime / 'submission_schema.php').read_text()
workflow = (runtime / 'submission.php').read_text()
mail = (runtime / 'submission_mail.php').read_text()
order = (ROOT / 'upload/catalog/model/extension/mt_jet_credit/submission_order.php').read_text()
check('ON DUPLICATE KEY UPDATE submission_id=submission_id' in store, 'atomic insert with DB uniqueness')
check(store[store.index('public function reserve('):].count('FOR UPDATE') == 2 and "query('COMMIT')" in store and "query('ROLLBACK')" in store,
      'claim/counter locked and committed in one transaction')
check('module_mt_jet_credit_count' not in store + workflow, 'legacy settings counter never authoritative')
check('->addCC(' not in mail and '->addBCC(' not in mail, 'no cross-category CC/BCC')
check('->postSend()' in mail and '$mail->SMTPDebug = 0' in mail, 'explicit transport boundary and debug off')
check('ErrorInfo' not in mail and 'getMessage()' not in mail + workflow,
      'no raw transport exceptions/body debugging')
check('->clear(' not in order and 'BINARY `option`=' in order and 'AND quantity=' in order,
      'cart cleanup compares exact rows instead of clearing cart')
check('CREATE TABLE' not in store + workflow and 'DROP TABLE' not in schema + store,
      'no runtime DDL or destructive claim cleanup')
check("ControllerMailOrder::JETCREDIT_GUARD_REVISION" in (runtime / 'native_mail_scope.php').read_text(),
      'activation requires actual native guard capability')
check("'submission_enabled' => !empty($this->request->server['HTTPS'])" in (ROOT / 'upload/catalog/model/extension/mt_jet_credit/financial.php').read_text(),
      'quote advertises activation only after runtime readiness')
for path in ['upload/catalog/controller/extension/mt_jet_credit/jetcredit.php',
             'upload/catalog/controller/extension/payment/jet.php',
             'upload/catalog/controller/extension/payment/jet_card.php']:
    source = (ROOT / path).read_text()
    check('JetCreditSubmissionHttp::handle' in source, 'public route delegates to shared secure HTTP boundary: ' + path)
    check('PHPMailer' not in source and 'jet_credit_count' not in source, 'no legacy mail/counter fallback: ' + path)

facade = (ROOT / 'upload/catalog/model/extension/mt_jet_credit/submission.php').read_text()
check('new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT)' in facade,
      'production claim repository owns an independent native DB connection')

# Validate new runtime/test PHP source with the available interpreter.
paths = sorted((ROOT / 'upload').rglob('*.php')) + sorted((ROOT / 'tests').rglob('*.php'))
for path in paths:
    result = subprocess.run(['php', '-l', str(path)], capture_output=True, text=True)
    check(result.returncode == 0, 'PHP syntax: ' + str(path.relative_to(ROOT)))
check(subprocess.run(['git', 'diff', '--check'], cwd=ROOT, capture_output=True).returncode == 0,
      'git diff --check')
print(f'IMP-03 CONTRACTS PASS: {checks} checks; {len(paths)} PHP files linted; fixture provenance/SQL/privacy/gates.')
print('DEFERRED: real InnoDB contention/DDL, browser and real store E2E. Native source fixtures are tested by ocmod.py.')
