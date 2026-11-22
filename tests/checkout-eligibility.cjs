'use strict';
const fs = require('fs');
const run = require('./calculator-recalculation.cjs');
const message = 'За избраните условия продуктите в количката не могат да бъдат закупени заедно на изплащане. Моля, изберете друг срок или условия.\n\nАко желаете, можете да закупите на изплащане всеки продукт поотделно.';
let checks = 0;
const check = (value, label) => { ++checks; if (!value) throw Error(label); };
for (const code of ['jet', 'jet_card']) {
 const template = fs.readFileSync(`upload/catalog/view/theme/default/template/extension/payment/${code}.twig`, 'utf8');
 const source = template.slice(template.indexOf('jQuery(document).ready'), template.lastIndexOf('//--></script>'));
 const ids = new Set(Array.from(template.split('<script')[0].matchAll(/\bid="([\w-]+)"/g), match => '#' + match[1]));
 const recalculate = code === 'jet' ? '#btn_preizcisli' : '#btn_card_preizcisli';
 check(template.includes('id="jet_offer_status" role="status" style="white-space:pre-line;"'), 'refusal preserves its two paragraphs');
 run({}, environment => {
  const open = (term = code === 'jet' ? '3' : '6') => {
   const initial = { price:'600', parva:'0', vnoski:term, products:'1_2', products_qt:'1_1', products_pr:'300.00_300.00', products_vr:'red_blue', egn:'9001010000' };
   const e = environment(source, false, '', Object.fromEntries(Object.entries(initial).map(([key,value]) => ['#'+code+'_'+key,value])), ids);
   e.get('#'+code+'_uslovia').prop('checked',true); e.get('#'+code+'_uslovia1').prop('checked',true);
   return e;
  };
  const offer = request => {
   const data = request.config.data.values, suffix = code === 'jet_card' ? '_card' : '';
   const out = { success:'success', jet_priceall:data.jet_priceall, jet_priceall_second:data.jet_priceall, jet_total_credit_price:'600.00', jet_total_credit_price_second:'600.00' };
   for (const [key,value] of Object.entries({ vnoska:'100.00', gpr:'10.00', glp:'9.00', obshto:'700.00' })) out['jet_'+key+suffix] = value;
   out['jet_vnoska'+suffix+'_second']='0.00'; out['jet_obshto'+suffix+'_second']='700.00';
   return out;
  };
  const accept = request => request.resolve(offer(request));
  const select = (e, term) => { e.get('#'+code+'_vnoski').val(term); e.trigger('#'+code+'_vnoski','change'); return e.requests.at(-1); };
  const e = open();
  check(e.requests[0].config.data.values.jet_vnoski === (code === 'jet' ? '3' : '6'), 'initial calculation uses this channel rendered winner');
  check(e.requests[0].config.data.values.jet_checkout_context === code && !e.requests[0].config.data.values.jet_cart_discovery, 'Checkout calculation remains separate from Cart discovery');
  accept(e.requests[0]);
  check(!e.get('#button-confirm').prop('disabled') && !e.get('.jet_offer_result').prop('hidden'), 'chosen-channel-only response enables confirmation');
  select(e,'9');
  check(e.get('#button-confirm').prop('disabled') && !e.get('.jet_offer_result').prop('hidden') && e.get('#'+code+'_vnoska').text() === '', 'customer edit clears the old offer and preserves rows');
  const count=e.requests.length;
  e.requests.at(-1).resolve({success:'unsuccess',jet_scheme_invalid:true});
  check(e.requests.length===count && e.get('#'+code+'_vnoski').val()==='9', 'refusal never searches or replaces the customer term');
  check(e.get('#jet_scheme_message').text()===message && e.get('#jet_alert_box').length && e.get('#jet_offer_status').prop('hidden'), 'exact scheme refusal text');
  check(!e.get('#'+code+'_vnoski').prop('disabled') && !e.get('#'+code+'_parva').prop('disabled'), 'conditions stay editable');
  e.trigger('#button-confirm','click'); check(e.requests.length===count,'invalid offer cannot submit');
  accept(select(e,'12'));
  check(e.get('#jet_offer_status').text()==='' && !e.get('#button-confirm').prop('disabled'), 'valid correction clears refusal and restores confirmation');
  const before=e.requests.length;
  e.get('#'+code+'_parva').val('101');e.trigger('#'+code+'_parva','input');
  check(e.requests.length===before && e.get('#button-confirm').prop('disabled'), 'downpayment preserves existing manual recalculation trigger');
  e.trigger(recalculate,'click');
  check(e.requests.at(-1).config.data.values.jet_parva==='101.00' && e.requests.at(-1).config.data.values.jet_vnoski==='12', 'downpayment recalculates exact chosen term');
  e.requests.at(-1).resolve({success:'unsuccess',jet_promotion_excluded:true,jet_scheme_invalid:true});
  check(e.get('#jet_scheme_message').text()===message && e.get('#button-confirm').prop('disabled'), 'threshold refusal uses the same text');
  e.get('#'+code+'_parva').val('100');e.trigger('#'+code+'_parva','input');e.trigger(recalculate,'click');accept(e.requests.at(-1));
  check(e.get('#jet_offer_status').text()==='' && !e.get('#button-confirm').prop('disabled'), 'valid threshold correction recovers');

  const stale=select(e,'9');
  select(e,'6').resolve({success:'unsuccess',jet_scheme_invalid:true}); accept(stale);
  check(e.get('#jet_scheme_message').text()===message && e.get('#button-confirm').prop('disabled'), 'late success cannot restore an invalid latest offer');
  const rejected=select(e,'9');accept(select(e,'12'));rejected.resolve({success:'unsuccess',jet_scheme_invalid:true});
  check(e.get('#jet_offer_status').text()==='' && !e.get('#button-confirm').prop('disabled'), 'late refusal cannot overwrite a valid latest offer');

  const detached=select(e,'9');e.switchPayment(code === 'jet' ? 'jet_card' : 'jet');accept(detached);
  check(e.get('#button-confirm').prop('disabled'),'opposite method selection rejects pending response');
  e.switchPayment(code);accept(e.requests.at(-1));
  check(e.get('#'+code+'_vnoski').val()==='9' && !e.get('#button-confirm').prop('disabled'),'returning checks the same customer conditions');
  e.trigger('#button-confirm','click');const confirm=e.requests.at(-1);
  check(confirm.config.url.endsWith('/'+code+'/confirm') && confirm.config.data.values[code+'_vnoski']==='9','native confirmation uses selected channel and exact term');
  const pending=e.requests.length;e.trigger('#button-confirm','click');check(e.requests.length===pending,'duplicate confirmation blocked');
  confirm.resolve({success:'unsuccess',jet_quote_invalid:true,jet_scheme_invalid:true});
  check(e.get('#jet_scheme_message').text()===message && e.get('#button-confirm').prop('disabled'),'final server scheme refusal invalidates the offer with exact text');
  e.trigger(recalculate,'click');accept(e.requests.at(-1));
  check(!e.get('#button-confirm').prop('disabled'),'rejected final offer requires a fresh calculation to recover');
  e.trigger('#button-confirm','click');e.requests.at(-1).resolve({success:'success',code:'bank_sent',redirect:'checkout/success'});
  const sent=e.requests.length;e.trigger('#button-confirm','click');check(e.requests.length===sent,'accepted result cannot be submitted again from the same panel');
  const replaced=open();const old=replaced.requests[0];replaced.replacePanel();accept(old);
  check(replaced.get('#button-confirm').prop('disabled') !== false,'detached panel cannot restore replacement controls');
 });
}
console.log(`PASS ${checks} Checkout channel/exact-condition/refusal/recovery/race JS checks`);
