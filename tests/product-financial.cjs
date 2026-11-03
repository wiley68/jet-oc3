'use strict';
// Shared real-frontend DOM/AJAX doubles, with no browser network or submission transport.
const fs = require('fs');
const run = require('./calculator-recalculation.cjs');
const source = fs.readFileSync('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.js', 'utf8');
const css = fs.readFileSync('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.css', 'utf8');
let checks = 0;
const check = (value, label) => { checks++; if (!value) throw Error(label); };
run({}, (environment, quote) => {
 const offer = (ref = 'a', extra = {}) => ({ ...quote(), jet_quote_ref: ref.repeat(48), jet_vnoska_card: '45.00', jet_gpr_card: '8.00', jet_glp_card: '7.00', jet_obshto_card: '650.00', ...extra });
 const prepare = (options = false) => {
  const e = environment(source, options, css);
  if (options) e.requests[0].resolve({ success: 1, optionresult: [] });
  e.requests.at(-1).resolve(offer());
  for (const [key, value] of Object.entries({ jet_name: 'Test', jet_lastname: 'User', jet_egn: '9001010000', jet_phone: '+359888888888', jet_email: 'test@example.invalid' })) e.get('#' + key).val(value);
  return e;
 };
 const sends = e => e.requests.filter(r => r.config.url.endsWith('/jet_send'));
 for (const options of [false, true]) {
  const e = prepare(options);
  const data = e.requests.find(r => r.config.url.endsWith('/jet_calculate')).config.data.values;
  check(data.jet_quantity === '1' && data.jet_options === '[]', 'complete quantity/selection transport without options');
  e.trigger('#buy2_jetcredit', 'click');
  const payload = sends(e)[0].config.data.values;
  check(payload.jet_quote_ref === 'a'.repeat(48) && payload.jet_options === '[]', 'accepted session quote accompanies submission');
 }
 for (const [raw, expected] of [['12.345','12.35'], ['1.005','1.00'], ['2.675','2.67'], ['0.125','0.13'], ['','0.00']]) {
  const e = prepare();
  e.get('#jet_parva').val(raw); e.trigger('#jet_parva', 'input');
  check(e.get('#jet_parva').val() === raw, 'no input rewrite per keystroke');
  e.tick(399); check(e.requests.length === 1, '400 ms debounce retained');
  e.tick(1); const calculation = e.requests.at(-1);
  check(calculation.config.data.values.jet_parva === expected && calculation.config.data.values.jet_downpayment_raw === raw, 'canonical and raw downpayment transported');
  calculation.resolve(offer('b'));
  e.trigger('#buy_jetcredit', 'click');
  check(e.get('#jet_step_2').visible && e.get('#jet_parva').val() === expected, 'confirmation displays normalized downpayment');
  e.trigger('#buy2_jetcredit', 'click');
  check(sends(e)[0].config.data.values.jet_parva === expected, 'same normalized downpayment submitted');
 }
 for (const raw of ['-0.004', '-1', 'NaN', 'Infinity', '12x', '600', '599.999']) {
  const e = prepare(); e.get('#jet_parva').val(raw); e.trigger('#jet_parva','input'); e.tick(400);
  check(e.requests.length === 1, 'invalid/nonpositive-principal input not calculated: ' + raw);
  e.trigger('#buy2_jetcredit','click'); check(!sends(e).length, 'old quote cannot submit invalid downpayment');
 }
 for (const ref of [undefined, '', 'invalid', 'b'.repeat(47)]) {
  const e = environment(source, false, css);
  e.requests[0].resolve(offer('a', { jet_quote_ref: ref }));
  for (const [key, value] of Object.entries({jet_name:'Test',jet_lastname:'User',jet_egn:'9001010000',jet_phone:'+359888888888',jet_email:'test@example.invalid'})) e.get('#'+key).val(value);
  e.trigger('#buy_jetcredit','click');check(!e.get('#jet_step_2').visible, 'missing/malformed quote cannot confirm');
  e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'missing/malformed quote never submitted');
 }
 for (const type of ['text','textarea','file','date','datetime','time','select','radio','checkbox']) {
  const e = prepare(true);
  const name = 'option[1]' + (type === 'checkbox' ? '[]' : '');
  e.get('__option_entries').val(JSON.stringify([{ name, value: 'new-' + type }]));
  // Includes programmatic file-code changes which emit no native change event.
  e.trigger('#buy2_jetcredit','click');
  check(!sends(e).length && e.requests.at(-1).config.url.endsWith('/jet_check'), 'same-price/native content change requires a new quote: ' + type);
  e.requests.at(-1).resolve({success:1,optionresult:[]});
  const calculation=e.requests.at(-1);
  check(JSON.parse(calculation.config.data.values.jet_options)[0].value==='new-'+type, 'full raw option contents transported: '+type);
  e.get('__option_entries').val(JSON.stringify([{name,value:'changed-during-request'}]));
  calculation.resolve(offer('b'));
  e.trigger('#buy2_jetcredit','click');check(!sends(e).length, 'response snapshot mismatch cannot restore a quote: '+type);
 }
 {
  const e=prepare(true);e.inputOption();e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'input invalidates immediately before native change');
 }
 {
  const e=prepare();e.get('input[name="quantity"]').val('2');e.trigger('[name="quantity"]','input');
  e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'quantity input invalidates without blur/change');
  check(e.requests.at(-1).config.data.values.jet_quantity==='2','quantity captured without truncation');
 }
 {
  const e=prepare();e.get('#jet_parva').val('10');e.trigger('#jet_parva','input');e.tick(400);const old=e.requests.at(-1);
  e.get('#jet_parva').val('20');e.trigger('#jet_parva','input');e.tick(400);const latest=e.requests.at(-1);
  latest.resolve(offer('c'));old.resolve(offer('b'));
  e.trigger('#buy2_jetcredit','click');check(sends(e)[0].config.data.values.jet_quote_ref==='c'.repeat(48),'out-of-order response cannot replace latest quote');
 }
 {
  const a=prepare(),b=prepare();b.trigger('#btn_preizcisli','click');b.requests.at(-1).resolve(offer('b'));
  a.trigger('#buy2_jetcredit','click');b.trigger('#buy2_jetcredit','click');
  check(sends(a)[0].config.data.values.jet_quote_ref==='a'.repeat(48) && sends(b)[0].config.data.values.jet_quote_ref==='b'.repeat(48),'independent tabs retain independent references');
  check(b.requests[1].config.data.values.jet_previous_quote==='a'.repeat(48),'recalculation replaces only the supplied prior reference');
 }
 {
  const e=prepare();e.trigger('#btn_preizcisli','click');e.requests.at(-1).resolve({success:'unsuccess'});
  e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'failed recalculation cannot reuse old quote');
 }
 {
  const e=prepare();e.trigger('#buy2_jetcredit','click');
  sends(e)[0].resolve({success:'unsuccess',jet_quote_invalid:true});
  check(e.get('#jet_alert_box').children[0].text()==='Не можете да изпратите Вашата заявка за лизинг към ПБ Лични Финанси. Моля опитайте по-късно.','invalid quote uses the existing unsuccessful message');
  e.get('#jet_alert_box').children[1].click();
  check(e.get('#jet_step_1').visible && !e.get('#jet_step_2').visible && e.requests.at(-1).config.url.endsWith('/jet_calculate'),'dismissed rejection returns to quote review');
  check(sends(e).length===1,'recovery never automatically submits another application');
  e.requests.at(-1).resolve(offer('b'));
  e.trigger('#buy_jetcredit','click');e.trigger('#buy2_jetcredit','click');
  check(sends(e).length===2 && sends(e)[1].config.data.values.jet_quote_ref==='b'.repeat(48),'new reviewed quote can be submitted manually');
 }
 {
  const e=environment(source,true,css);check(e.loading(),'initial option lookup loader');
  e.inputOption();e.requests[0].reject();check(!e.loading(),'input invalidation cannot leave a stale loader stuck');
 }
});
console.log(`PASS ${checks} Product quote/normalization/stale-state frontend checks`);
