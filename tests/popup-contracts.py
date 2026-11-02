#!/usr/bin/env python3
"""Static popup/reference contracts; browser pixels and full Twig remain owner E2E."""
import json,re,xml.etree.ElementTree as ET
from pathlib import Path
from html.parser import HTMLParser
ROOT=Path(__file__).resolve().parents[1]
css=(ROOT/'upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.css').read_text()
js=(ROOT/'upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js').read_text()
php=(ROOT/'upload/catalog/model/extension/mt_jet_credit/financial.php').read_text()
ref=json.loads((ROOT/'tests/fixtures/jet-woo-ui-reference.json').read_text())
checks=0
def check(value,label):
 global checks
 checks+=1
 if not value:raise AssertionError(label)
check(ref['module_version']=='2.0.0' and len(ref['sources'])==5,'actual pinned reference provenance')
scoped=css.split('/* FIX-04:')[1]
check(not re.search(r'^\s*\.[A-Za-z]',scoped,re.M),'no new unscoped theme class selectors')
check(css.count('{')==css.count('}'),'balanced CSS blocks')
for fragment in ['font-size: '+ref['metrics']['popup_labels']['desktop_font_size'],'font-weight: 500','font-size: 12pt','max-width: 767px','height: 48px','font-size: 20px','font-weight: 600','text-align: right !important','text-align: left !important','outline: 2px solid #166534','prefers-reduced-motion','pointer-events: none']:
 check(fragment in scoped,'scoped reference metric/accessibility '+fragment)
for fragment in ['width: 48px','height: 48px','border: 4px solid #d1fae5','border-top-color: #166534','0.8s linear infinite','rgba(255, 255, 255, 0.72)']:
 check(fragment in scoped,'Jet-Woo loader metric '+fragment)
check('jet_btn:disabled' in scoped and 'jet_btn:not(:disabled)' in scoped,'visual state follows native disabled property')
class Markup(HTMLParser):
 void={'input','img','link','br','hr','meta'}
 def __init__(self):super().__init__();self.stack=[];self.nodes=[]
 def handle_starttag(self,tag,attrs):
  self.nodes.append((tag,dict(attrs),list(self.stack)))
  if tag not in self.void:self.stack.append(tag)
 def handle_startendtag(self,tag,attrs):
  self.handle_starttag(tag,attrs)
  if tag not in self.void:self.handle_endtag(tag)
 def handle_endtag(self,tag):
  check(bool(self.stack)and self.stack[-1]==tag,'popup balanced '+tag)
  self.stack.pop()
xml=ET.parse(ROOT/'install.xml').getroot()
for path in ['catalog/view/theme/default/template/product/product.twig','catalog/view/theme/default/template/checkout/cart.twig']:
 text=next(f for f in xml.findall('file')if f.attrib['path']==path).find('operation/add').text
 check('style="opacity: 0.5;"'not in text,'no permanent dimming inline state '+path)
 check(text.count('class="jet_offer_loader"')==1 and 'role="status" aria-live="polite"'in text,'one accessible loader '+path)
 for field in ['jet_name','jet_lastname','jet_email','jet_phone']:check('{{ '+field+"|e('html') }}"in text,'escaped profile '+field)
 popup=text[text.index('<div id="jet-product-popup-container"'):(text.find('{% if jet_product_css') if '{% if jet_product_css' in text else len(text))]
 popup=re.sub(r'{{.*?}}','fixture',popup,flags=re.S);popup=re.sub(r'{%.*?%}','',popup,flags=re.S)
 parser=Markup();parser.feed(popup);check(not parser.stack,'complete popup nesting')
 controls={attrs['id']:attrs for tag,attrs,anc in parser.nodes if 'id'in attrs}
 check('jet_popup_notice' not in controls,'no status-message row above popup controls')
 check(controls['jet_egn'].get('value')=='','no national identity prefill')
 for field in ['jet_name','jet_lastname','jet_email','jet_phone']:
  check('readonly' not in controls[field] and 'disabled' not in controls[field],'prefilled profile remains editable '+field)
 check(controls['back2_jetcredit']['type']=='button' and controls['buy2_jetcredit']['type']=='button','navigation not native submit')
 check('disabled'in controls['buy2_jetcredit'],'server HTML initially fail closed')
 check('data-jet-installment="jet"'in text and 'data-jet-installment="jet_card"'in text,'whole installment row starts hidden per scheme')
check("'jet_sign' => 'евро'"in php and "json.currency !== 'EUR'"in js,'display label separated from technical EUR code')
check((ROOT/'upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.css').read_text().startswith("@import url('./creditjet_products.css');"),'shared cart popup loads the same scoped loader styles')
check('timeout: 30000'in js,'quote cannot spin indefinitely on stalled transport')
check("'#back2_jetcredit'"in js and 'function canSubmit()'in js and 'if (!canSubmit())'in js,'navigation and shared execution/UI predicate')
check('submittedRevision !== submissionRevision'in js,'stale submit callbacks ignored')
check('jet_popup_notice' not in js and 'jet_popup_notice' not in css,'no popup status writer or obsolete status styles')
# FIX-05 scope and automatic recalculation contracts.
for selector in ['input','select','textarea']:
 block=re.search(r'(#jet-product-popup-container #jet_step_2 input,.*?)\{([^}]+)\}',css,re.S)
 check('#jet-product-popup-container #jet_step_2 '+selector in block[1] and 'text-align: left !important' in block[2],'Step 2 control left alignment '+selector)
check('#jet-product-popup-container #jet_step_2 input::placeholder' in css and '#jet-product-popup-container #jet_step_2 textarea::placeholder' in css,'Step 2 placeholder isolation')
check(re.search(r'#jet-product-popup-container #jet_step_1 \.jet_input_text > div:first-child\s*\{[^}]*font-weight: 600',css,re.S),'Step 1 displayed numeric values bold')
check(re.search(r'#jet-product-popup-container #jet_step_1 \.jet_column_left\s*\{[^}]*font-weight: 500',css,re.S),'Step 1 descriptive labels keep reference hierarchy')
check(re.search(r'#jet-product-popup-container #jet_step_1 select\.jet_input_text\s*\{[^}]*text-align: left',css,re.S),'term/scheme dropdown exception preserved')
for path in [ROOT/'install.xml',ROOT/'upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.css',ROOT/'upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.css',*list((ROOT/'upload/catalog/view/theme/default/template/extension/payment').glob('*.twig'))]:
 check('preizcisli' not in path.read_text(),'manual recalculation markup/styles removed '+path.name)
check('preizcisli' not in js and '}, 400)'in js,'manual handler removed; trailing-edge debounce configured')
check("'blur.jetcredit'"in js and "'keydown.jetcredit'"in js,'blur/keyboard handlers present')
print(f'T-POPUP-CONTRACTS PASS: {checks} checks; pinned reference/scoped CSS/markup/escaped data; no browser certification.')
