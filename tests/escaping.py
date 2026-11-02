#!/usr/bin/env python3
"""IMP-01 output contracts and scope checks; not a substitute for live Twig/OC3."""
import html
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
BASELINE = 'dd36a97a262fe1cd153bf4783dc9d8c583108a56'
checks = 0


def check(condition, label):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(label)


def source(path):
    return (ROOT / path).read_bytes().decode('utf-8')


def baseline(path):
    return subprocess.check_output(['git', 'show', f'{BASELINE}:{path}'], cwd=ROOT).decode('utf-8')


class Markup(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tags = []
        self.attrs = []
        self.text = []

    def handle_starttag(self, tag, attrs):
        self.tags.append(tag)
        self.attrs.extend(attrs)

    def handle_data(self, data):
        self.text.append(data)


admin = source('upload/admin/view/template/extension/module/mt_jet_credit.twig')
xml = ET.fromstring(source('install.xml'))
old_xml = ET.fromstring(baseline('install.xml'))
twig_blocks = [node.text or '' for node in xml.findall('.//add') if '{{' in (node.text or '')]
templates = [admin] + twig_blocks + [source(f'upload/{side}/view/{base}/extension/payment/{name}.twig')
    for side, base in [('admin', 'template'), ('catalog', 'theme/default/template')] for name in ['jet', 'jet_card']]
templates.append(source('upload/catalog/view/theme/default/template/extension/mt_jet_credit/success.twig'))
trusted_html = {'header', 'column_left', 'column_right', 'content_top', 'content_bottom', 'footer'}
trusted_json = {'mt_jet_button_scheme_styles_for_js|raw', 'mt_jet_button_scheme_labels_json|raw'}
for template in templates:
    for expression in re.findall(r'\{\{\s*(.*?)\s*\}\}', template):
        static_preview = re.fullmatch(r"module_mt_jet_credit_button_type == 'wide' \? '(?:none|block)' : '(?:none|block)'", expression)
        check(expression in trusted_html or expression in trusted_json or "|e('html')" in expression or static_preview,
              f'unescaped output: {expression}')
    check(not re.search(r'\son\w+\s*=\s*["\'][^\n>]*\{\{', template, re.I), 'no dynamic inline event attributes')

check('../index.php' not in admin, 'no storefront URL workaround')
check('onclick="deleteSchema' not in admin and 'data-promotion-id="{{ schemes.id|' in admin,
      'row ID in escaped data attribute')
check(".on('click', '.jet-delete-promotion'" in admin and 'promotion_id: id' in admin,
      'delegated row deletion')
for action in ['create', 'delete']:
    check(f"$('#form-html').attr('data-jet-promotion-{action}-url')" in admin, 'URL read from DOM attribute')
    controller = source('upload/admin/controller/extension/module/mt_jet_credit.php')
    method = 'createSchema' if action == 'create' else 'deleteSchema'
    check(f"$this->url->link('extension/module/mt_jet_credit/{method}'" in controller, 'native admin link generation')
check('JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT' in controller, 'script-safe JSON encoding')
for script in re.findall(r'<script\b[^>]*>(.*?)</script>', admin, re.S):
    check(set(re.findall(r'\{\{\s*(.*?)\s*\}\}', script)).issubset(trusted_json), 'no untrusted executable Twig expressions')

# Exercise the PHP encoding contract used by Twig HTML escaping and the controller's
# JSON_HEX flags. Full Twig compilation is deliberately not simulated here.
payloads = ["'", '"', '<img src=x onerror=alert(1)>', '</script><script>alert(1)</script>',
            'Български € 日本語', 'a\n\r\t\x00b', '&quot; &#39; & < >', "42');alert(1);//"]
php = '''$items = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = array(); foreach ($items as $text) {
 $json = json_encode(array(array('value' => $text)), JSON_UNESCAPED_UNICODE);
 $script = json_encode(array($text), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
 $out[] = array('attribute' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
 'json_attribute' => htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'script' => $script);
} echo json_encode($out);'''
encoded = json.loads(subprocess.check_output(['php', '-r', php], input=json.dumps(payloads).encode(), cwd=ROOT))
for payload, item in zip(payloads, encoded):
    for quote in ['"', "'"]:
        parser = Markup()
        parser.feed(f'<input value={quote}{item["attribute"]}{quote}>')
        check(parser.tags == ['input'] and len(parser.attrs) == 1, 'hostile attribute creates no elements/events')
        check(parser.attrs[0] == ('value', payload), 'HTML entity round trip')
        parser = Markup()
        parser.feed(f'<input value={quote}{item["json_attribute"]}{quote}>')
        check(parser.tags == ['input'] and len(parser.attrs) == 1, 'JSON attribute cannot break markup')
        check(json.loads(parser.attrs[0][1]) == [{'value': payload}], 'JSON round trip without double escaping')
    parser = Markup()
    parser.feed('<span>' + item['attribute'] + '</span>')
    check(parser.tags == ['span'] and ''.join(parser.text) == payload, 'plain text safe and preserved')
    check('</script' not in item['script'].lower() and json.loads(item['script']) == [payload], 'inline JSON script-safe and lossless')

# Verify the actual promotion row template with hostile historical DB strings,
# applying only its explicit simple HTML filters (not claiming full Twig rendering).
row = admin[admin.index('{% for schemes in module_mt_jet_schemes %}'):admin.index('{% endfor %}', admin.index('{% for schemes in module_mt_jet_schemes %}'))]
for payload, item in zip(payloads, encoded):
    rendered = re.sub(r"\{\{\s*schemes\.\w+\|e\('html'\)\s*\}\}", lambda m: item['attribute'], row)
    parser = Markup(); parser.feed(rendered)
    check('script' not in parser.tags and 'img' not in parser.tags, 'historical promotion string cannot add markup')
    check(not any(name.lower().startswith('on') for name, _ in parser.attrs), 'historical promotion string cannot add handler')

# Native OC3 URL links can contain &amp; already. Normalize once before escaping.
url = 'https://store/admin/index.php?route=extension/module/mt_jet_credit&user_token=a_b'
for value in [url, url.replace('&', '&amp;')]:
    escaped = html.escape(value.replace('&amp;', '&'), quote=True)
    parser = Markup(); parser.feed(f'<a href="{escaped}"></a>')
    check(parser.attrs == [('href', url)], 'URL token survives one browser entity decode')

# IMP-02 changes financial/submission behavior. Preserve IMP-01 authorization and
# escaping contracts, while testing the explicitly staged replacement flow.
shared = source('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js')
check('responseText' not in shared and 'console.log' not in shared, 'no raw HTTP/JSON output')
check('jet_offer: state.offer' in shared and 'jet_intent: state.intent' in shared,
      'active UI sends signed offer and stable intent')
protected = json.loads(source('tests/fixtures/imp01-protected.json'))
path = 'upload/admin/controller/extension/module/mt_jet_credit.php'
mutations = source(path)[source(path).index('\tpublic function createSchema()'):source(path).index('\tpublic function install()')]
check(hashlib.sha256(mutations.encode()).hexdigest() == protected['admin_mutations'], 'IMP-01 admin guards preserved byte-for-byte')
check(hashlib.sha256((ROOT / 'upload/admin/model/extension/module/jetcredit.php').read_bytes()).hexdigest() == protected['admin_model'], 'IMP-01 validation/model preserved byte-for-byte')
check('DROP TABLE' not in source(path) and 'deleteSetting(' not in source(path), 'uninstall preserves business data')
check('module_mt_jet_credit_count' not in admin, 'counter removed from settings POST')
for tag in ['name', 'code', 'author', 'link']:
    check(xml.findtext(tag) == old_xml.findtext(tag), 'OCMOD metadata unchanged: ' + tag)
version = re.search(r"public const VERSION = '([0-9.]+)';", source('upload/system/library/jetcredit/version.php'))[1]
check(xml.findtext('version') == version + ' for OpenCart 3.x', 'explicit IMP-05A candidate version matches authoritative runtime version')
check(len(xml.findall('file')) == 8 and len(xml.findall('.//operation')) == 11, 'view plus native mail/checkout operations')
for file in xml.findall('file'):
    check('error' not in file.attrib and '*' not in file.attrib['path'], 'operation error scope / explicit default theme')
    for operation in file.findall('operation'):
        check(operation.attrib.get('error') == 'abort' and operation.findtext('ignoreif'), 'abort and idempotence marker')
active = '\n'.join(source(str(path.relative_to(ROOT))) for path in (ROOT / 'upload').rglob('*')
                   if path.is_file() and path.suffix in {'.php', '.twig', '.js'}) + source('install.xml')
for obsolete in ['module_mt_jet_credit_eur', '1.95583', 'BGN']:
    check(obsolete not in active, 'obsolete financial currency removed: ' + obsolete)
print(f'T-ESCAPE / scope PASS: {checks} assertions; XML and injected PHP parsed; IMP-01 guards preserved.')
print('DEFERRED: full Twig engine, browser and live OC3 integration (dependencies not installed).')
