'use strict';
// Execute the actual Checkout calculation/confirm JavaScript with DOM and AJAX doubles.
const fs = require('fs'), assert = require('assert/strict');
let checks = 0;
const check = (condition, message) => { checks++; assert.ok(condition, message); };
if (process.argv.includes('--rounding')) {
 const rows = JSON.parse(fs.readFileSync(0, 'utf8'));
 for (const row of rows) check(row.actual === Number(row.browser).toFixed(2), 'PHP/browser serialization: ' + row.browser);
 console.log(`PASS ${checks} real JavaScript rounding golden cases`);
} else {
 const run = require('./calculator-recalculation.cjs');
 for (const code of ['jet', 'jet_card']) {
  const template = fs.readFileSync(`upload/catalog/view/theme/default/template/extension/payment/${code}.twig`, 'utf8');
  const markupIds = new Set(Array.from(template.split('<script')[0].matchAll(/\bid="([\w-]+)"/g), match => '#' + match[1]));
  const recalculate = code === 'jet_card' ? '#btn_card_preizcisli' : '#btn_preizcisli';
  check(markupIds.has(recalculate), 'recalculation ID exists in the production template');
  const source = template.slice(template.indexOf('jQuery(document).ready'), template.lastIndexOf('//--></script>'));
  run({}, (environment, mockQuote) => {
   const open = (raw = '600', parva = '0') => {
    const e = environment(source, false, '', Object.fromEntries(Object.entries({
     price: raw, parva, vnoski: '12', products: '1_2', products_qt: '1_1', products_pr: '250.00_250.00',
     products_vr: 'red_blue', egn: '1234567890'
    }).map(([field, value]) => ['#' + code + '_' + field, value])), markupIds);
    e.get('#' + code + '_uslovia').prop('checked', true);
    e.get('#' + code + '_uslovia1').prop('checked', true);
    return e;
   };
   const accept = (request) => request.resolve(mockQuote());
   for (const raw of ['500', '500.005', '400.9999', '600.9999', '1000']) {
    const e = open(raw);
    const initial = e.requests[0];
    const values = initial.config.data.values;
    check(initial.config.url.endsWith('/jet_calculate_cart') && values.jet_checkout_context === code, 'existing calculation route and context');
    check(values.jet_priceall === Number(raw).toFixed(2) && values.jet_vnoski === '12' && !('jet_discovery' in values), 'rendered snapshot and silent initial discovery');
    for (const term of ['15', '18', '24', '30', '36']) {
     check(e.get('#' + code + '_vnoski option[value="' + term + '"]').prop('disabled') === (Number(raw) < (Number(term) >= 30 ? 601 : 401)), 'unrounded term thresholds');
    }
    e.trigger('#button-confirm', 'click');
    check(e.requests.length === 1, 'pending discovery cannot confirm');
    accept(initial, 3);
    check(e.get('#' + code + '_vnoski').val() === '12' && e.get('#jet_offer_status').text() === '', 'silent initial winner');
    e.trigger('#button-confirm', 'click');
    const confirmation = e.requests[1];
    const sent = confirmation.config.data.values;
    check(confirmation.config.url.endsWith('/' + code + '/confirm') && sent[code + '_vnoski'] === '12', 'existing route with winner term');
    check(sent[code + '_priceall'] === Number(raw).toFixed(2), 'no second currency conversion');
    check(sent[code + '_vnoska'] === e.get('#' + code + '_vnoska').text(), 'exact displayed installment submitted');
    check(sent[code + '_products'] === '1_2' && !Object.keys(sent).some(key => key.includes('intent') || key === 'order_id'), 'native submission transport preserved');
    e.trigger('#button-confirm', 'click');
    check(e.requests.length === 2, 'duplicate confirmation locked');
    confirmation.resolve({ success: 'unsuccess', jet_submission_review: true });
    e.trigger('#button-confirm', 'click');
    check(e.requests.length === 2, 'review state blocks repeat');
   }
   for (const [raw, canonical] of [['0', '0.00'], ['', '0.00'], ['  ', '0.00'], ['+0', '0.00'],
     [' +10.25 ', '10.25'], ['.5', '0.50'], ['10.', '10.00'], ['1.005', '1.00'], ['0.125', '0.13']]) {
    const payment = open('600', raw);
    const discovery = payment.requests[0];
    check(discovery.config.data.values.jet_parva === canonical, 'valid decimal discovery canonicalizes ' + JSON.stringify(raw));
    accept(discovery, 3);
    payment.trigger('#button-confirm', 'click');
    check(payment.requests[1].config.data.values[code + '_parva'] === canonical, 'confirmation preserves accepted value');
   }
   for (const raw of ['1e1', '10abc', '1,5', '1 0', '1.2.3', '-', '-0.001', '600']) {
    const payment = open('600', raw);
    payment.trigger('#button-confirm', 'click');
    check(payment.requests.length === 0 && payment.get('#button-confirm').prop('disabled'),
     'invalid raw input blocks discovery and confirmation ' + raw);
   }
   for (const [response, message] of [
    [{success: 'unsuccess'}, 'Проверете въведените данни'],
    [{success: 'unsuccess', jet_quote_invalid: true}, 'Проверете въведените данни'],
    [{success: 'unsuccess', jet_quote_error: true}, 'опитате отново']
   ]) {
    const payment = open();
    accept(payment.requests[0], 3);
    payment.trigger('#button-confirm', 'click');
    payment.requests[1].resolve(response);
    check(payment.get('#button-confirm').prop('disabled') && payment.get('.jet_offer_result').prop('hidden'),
     'rejection and AJAX completion leave the offer hidden and disabled');
    check(payment.get('#jet_offer_status').text().includes(message), 'business and technical rejection use distinct status');
    payment.trigger('#button-confirm', 'click');
    check(payment.requests.length === 2, 'cannot resend the rejected offer');
    payment.get('#' + code + '_parva').val('10.25');
    payment.trigger('#' + code + '_parva', 'input');
    payment.trigger(recalculate, 'click');
    check(payment.requests[2].config.data.values.jet_parva === '10.25', 'actual visible button recalculates after down payment change');
    accept(payment.requests[2], 3);
    check(!payment.get('#button-confirm').prop('disabled') && !payment.get('.jet_offer_result').prop('hidden'),
     'fresh offer restores confirmation and figures');
   }
   const e = open();
   accept(e.requests[0], 3);
   const select = (term) => {
    e.get('#' + code + '_vnoski').val(term); e.trigger('#' + code + '_vnoski', 'change');
    return e.requests.at(-1);
   };
   let request = select('9');
   check(request.config.data.values.jet_vnoski === '9', 'explicit term checked first');
   accept(request, 9);
   check(e.get('#jet_offer_status').text() === '', 'eligible explicit selection has no notice');
   request = select('12');
   check(e.get('#button-confirm').prop('disabled') && e.get('.jet_offer_result').prop('hidden'), 'change hides old figures immediately');
   accept(request);
   check(e.get('#' + code + '_vnoski').val() === '12' && e.get('#jet_offer_status').text() === '', 'exact term preserved without fallback notice');
   select('12').resolve({ success: 'unsuccess', jet_promotion_excluded: true });
   check(e.get('#jet_offer_status').text().includes('не е налично') && e.get('#button-confirm').prop('disabled'), 'confirmed unavailable has no confirmation control');
   const beforeRetry = e.requests.length;
   e.trigger(recalculate, 'click');
   check(e.requests.length === beforeRetry + 1, 'visible button starts a request');
   e.requests.at(-1).reject();
   e.trigger(recalculate, 'click');
   check(e.requests.length === beforeRetry + 2, 'visible button retries a technical error');
   e.requests.at(-1).reject();
   check(e.get('#jet_offer_status').text().includes('опитате отново'), 'technical error offers retry');
   const old = select('9');
   accept(select('6'), 6);
   accept(old, 9);
   check(e.get('#' + code + '_vnoski').val() === '6' && !e.get('#button-confirm').prop('disabled'), 'latest success restores confirmation');
   request = select('12');
   e.switchPayment(code === 'jet' ? 'jet_card' : 'jet');
   accept(request, 12);
   check(e.get('#button-confirm').prop('disabled'), 'method switch rejects late reply');
   e.switchPayment(code);
   check(e.requests.at(-1).config.data.values.jet_vnoski === '12' && !('jet_discovery' in e.requests.at(-1).config.data.values), 'returning to the method recalculates the selected term');
   request = e.requests.at(-1);
   e.replacePanel(); accept(request, 3);
   check(e.get('#' + code + '_vnoski').val() === '12', 'detached panel cannot update the replacement');
  });
 }
 console.log(`PASS ${checks} Checkout discovery, snapshot and confirmation JS checks`);
}
