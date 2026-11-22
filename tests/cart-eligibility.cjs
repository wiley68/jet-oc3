'use strict';
const fs = require('fs');
const run = require('./calculator-recalculation.cjs');
const source = fs.readFileSync('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.js', 'utf8');
const css = fs.readFileSync('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.css', 'utf8');
const message = 'За избраните условия продуктите в количката не могат да бъдат закупени заедно на изплащане. Моля, изберете друг срок или условия.\n\nАко желаете, можете да закупите на изплащане всеки продукт поотделно.';
let checks = 0;
const check = (value, label) => { ++checks; if (!value) throw Error(label); };
const cartMarkup = fs.readFileSync('install.xml', 'utf8').split('<file path="catalog/view/theme/*/template/checkout/cart.twig"')[1];
check(!cartMarkup.includes('jet_cart_scheme_status'), 'Cart has no inline scheme message');
check(/<div id="jet_step_1">\s*<div class="jet_row">/.test(cartMarkup), 'status preserves the mobile first-row CSS selector');
run({}, (environment, quote) => {
 const preview = (card, term, monthly = card ? '60.00' : '100.00') => ({ jet_card: card, jet_vnoski: term, jet_priceall: '600.00',
  ['jet_vnoska' + (card ? '_card' : '')]: monthly, ['jet_vnoska' + (card ? '_card' : '') + '_second']: '0.00' });
 const offer = (id = 'c', ref = 'a', extra = {}) => ({ ...quote(), jet_intent_id: id.repeat(48), jet_quote_ref: ref.repeat(48), ...extra });
 const open = (jet = preview(0, 12), card = preview(1, 12), activeCard = '1') => {
  const e = environment(source, false, css, { '#jet_card_in': activeCard });
  check(e.requests.length === 1 && e.requests[0].config.data.values.jet_cart_discovery === '1', 'initial request uses discovery');
  check(!e.get('#btn_jet').visible && !e.get('#btn_jet_card').visible, 'entry buttons wait for authoritative discovery');
  e.requests[0].resolve({ success: 'success', jet_cart_schemes: { jet, jet_card: card } });
  check(e.requests.length === 1, 'discovery does not bootstrap an authenticated offer');
  e.trigger('#buy2_jetcredit', 'click');
  check(e.requests.length === 1, 'preview cannot submit');
  return e;
 };
 const sends = e => e.requests.filter(r => r.config.url.endsWith('/jet_send_cart'));
 const acceptConditions = e => {
  for (const id of ['uslovia', 'uslovia1', 'uslovia2']) { e.get('#' + id).prop('checked', true); e.trigger('#' + id, 'click'); }
 };
 const fill = e => {
  for (const [id, value] of Object.entries({ jet_name:'Test', jet_lastname:'User', jet_egn:'9001010000', jet_phone:'+359888888888', jet_email:'test@example.invalid' })) e.get('#' + id).val(value);
 };
 for (const terms of [[12, 12], [3, 6], [12, null], [null, 9], [null, null]]) {
  const e = open(terms[0] ? preview(0, terms[0]) : false, terms[1] ? preview(1, terms[1]) : false);
  check(e.get('#jet-product-button-container').visible === terms.some(Boolean), 'common container follows availability of either channel');
  for (const [card, term] of terms.entries()) {
   const suffix = card ? '_card' : '';
   check(e.get('#btn_jet' + suffix).visible === Boolean(term), 'independent button availability');
   check(e.get('#btn_jet' + suffix + ' closest .jet_table_img').visible === Boolean(term), 'standard button table and installment row follow only their own channel');
   if (!term) { const count = e.requests.length; e.trigger('#btn_jet' + suffix, 'click'); check(e.requests.length === count, 'unavailable channel cannot open'); continue; }
   check(Number(e.get('#jet_vnoski_text' + suffix).text()) === term && e.get('#jet_vnoska' + suffix).text() === (card ? '60.00' : '100.00'), 'each button has its own term and results');
   e.get('#jet_vnoski').val('36'); e.get('#jet_parva').val('75');
   e.trigger('#btn_jet' + suffix, 'click');
   const data = e.requests.at(-1).config.data.values;
   check(data.jet_card === String(card) && data.jet_vnoski === String(term) && data.jet_parva === '0.00', 'opening loads the chosen initial scheme');
   check(!data.jet_cart_discovery && data.jet_intent_id === '' && data.jet_previous_quote === '' && data.jet_cart_lineage === '', 'opening authenticates a fresh channel-specific offer');
  }
 }
 {
  const e = open(preview(0, 12), preview(1, 12), '0');
  check(e.get('#btn_jet').visible && !e.get('#btn_jet_card').visible, 'inactive Card remains hidden even with a malformed extra preview');
 }
 {
  const e = open(preview(0, 3), preview(1, 6));
  e.trigger('#btn_jet', 'click'); e.requests.at(-1).resolve(offer('c', 'a', { jet_vnoska:'210.00' }));
  check(e.get('#jet-product-popup-container').visible && e.get('#jet_vnoska_popup').text() === '210.00', 'Jet opening uses freshly calculated Jet values');
  e.get('#jet_vnoski').val('9'); e.trigger('#jet_vnoski', 'change'); e.requests.at(-1).resolve(offer('c', 'b'));
  e.trigger('#close_jetcredit', 'click');
  const count = e.requests.length;
  check(!e.get('#jet-product-popup-container').visible, 'close hides the shared popup');
  e.trigger('#btn_jet_card', 'click');
  const data = e.requests.at(-1).config.data.values;
  check(e.requests.length === count + 1 && data.jet_vnoski === '6' && data.jet_intent_id === '' && data.jet_previous_quote === '', 'Card inherits neither custom Jet term nor identity');
  e.requests.at(-1).resolve(offer('d', 'd', { jet_vnoska_card:'110.00' }));
  check(e.get('#jet_vnoska_popup').text() === '110.00' && e.get('#jet_vnoski').val() === '6', 'Card popup uses only Card results');
  check(e.get('#jet_vnoski_text').text() === 3 && e.get('#jet_vnoski_text_card').text() === 6, 'popup edits do not overwrite initial button schemes');
 }
 {
  const e = open(); e.trigger('#btn_jet', 'click'); e.requests.at(-1).resolve(offer()); acceptConditions(e);
  e.get('#jet_vnoski').val('9'); e.trigger('#jet_vnoski', 'change');
  check(e.get('#buy_jetcredit').prop('disabled') && e.get('#buy2_jetcredit').prop('disabled'), 'term edit immediately invalidates both continuation controls');
  const count = e.requests.length; const request = e.requests.at(-1);
  check(request.config.data.values.jet_vnoski === '9' && !request.config.data.values.jet_cart_discovery, 'customer term checks exact conditions');
  request.resolve({ success:'unsuccess', jet_scheme_invalid:true, jet_promotion_excluded:true });
  check(e.requests.length === count && e.get('#jet_vnoski').val() === '9', 'invalid customer term never triggers a replacement search');
  check(e.get('#jet-product-popup-container').visible && e.get('#jet_scheme_message').text() === message && Boolean(e.get('#jet_alert_box').length), 'exact refusal text and open popup');
  check(!e.get('#jet_vnoski').prop('disabled') && !e.get('#jet_parva').prop('disabled') && e.get('#jet_vnoska_popup').text() === '', 'conditions stay editable and stale figures are cleared');
  fill(e); e.trigger('#buy2_jetcredit', 'click'); check(!sends(e).length, 'invalid scheme cannot submit');
  e.get('#jet_vnoski').val('12'); e.trigger('#jet_vnoski', 'change'); e.requests.at(-1).resolve(offer('c', 'b'));
  check(!Boolean(e.get('#jet_alert_box').length) && !e.get('#buy_jetcredit').prop('disabled'), 'valid correction clears refusal and restores continuation');
  const before = e.requests.length;
  e.get('#jet_parva').val('101'); e.trigger('#jet_parva', 'input'); e.tick(399);
  check(e.requests.length === before && e.get('#buy_jetcredit').prop('disabled'), 'downpayment preserves debounce and immediate invalidation');
  e.tick(1); check(e.requests.at(-1).config.data.values.jet_parva === '101.00', 'debounced downpayment is canonical');
  e.requests.at(-1).resolve({ success:'unsuccess', jet_scheme_invalid:true });
  check(e.get('#jet_scheme_message').text() === message && e.get('#jet_vnoski').val() === '12', 'threshold crossing refuses the same term');
  e.get('#jet_parva').val('100'); e.trigger('#jet_parva', 'input'); e.tick(400); e.requests.at(-1).resolve(offer('c', 'd'));
  check(!Boolean(e.get('#jet_alert_box').length) && !e.get('#buy_jetcredit').prop('disabled'), 'return across threshold restores valid exact scheme');
 }
 {
  const e = open(); e.trigger('#btn_jet', 'click'); e.requests.at(-1).resolve(offer()); acceptConditions(e);
  e.get('#jet_vnoski').val('9'); e.trigger('#jet_vnoski', 'change'); const old = e.requests.at(-1);
  e.get('#jet_vnoski').val('6'); e.trigger('#jet_vnoski', 'change');
  e.requests.at(-1).resolve({ success:'unsuccess', jet_scheme_invalid:true }); old.resolve(offer('c', 'b'));
  check(Boolean(e.get('#jet_alert_box').length) && e.get('#jet_vnoska_popup').text() === '' && e.get('#buy_jetcredit').prop('disabled'), 'late success cannot restore invalid latest scheme');
  e.get('#jet_vnoski').val('9'); e.trigger('#jet_vnoski', 'change'); const rejected = e.requests.at(-1);
  e.get('#jet_vnoski').val('12'); e.trigger('#jet_vnoski', 'change'); e.requests.at(-1).resolve(offer('c', 'd'));
  rejected.resolve({ success:'unsuccess', jet_scheme_invalid:true });
  check(!Boolean(e.get('#jet_alert_box').length) && !e.get('#buy_jetcredit').prop('disabled'), 'late refusal cannot overwrite valid latest scheme');
 }
 {
  const e = open(preview(0, 3), preview(1, 6));
  e.trigger('#btn_jet', 'click'); const jet = e.requests.at(-1);
  const count = e.requests.length; e.trigger('#btn_jet', 'click');
  check(e.requests.length === count, 'repeated pending opening does not create another authenticated offer');
  e.trigger('#btn_jet_card', 'click'); const card = e.requests.at(-1);
  jet.resolve(offer('c', 'a')); check(e.loading() && e.get('#jet_vnoska_popup').text() === '', 'late Jet bootstrap cannot attach identity or hide Card loader');
  card.resolve(offer('d', 'd', { jet_vnoska_card:'110.00' })); fill(e); acceptConditions(e); e.trigger('#buy2_jetcredit', 'click');
  check(sends(e).length === 1 && sends(e)[0].config.data.values.jet_card === '1' && sends(e)[0].config.data.values.jet_intent_id === 'd'.repeat(48), 'submission carries only the selected Card identity and quote');
 }
});
console.log(`PASS ${checks} Cart discovery/channel/refusal/recovery/race JS checks`);
