'use strict';
// Execute the actual Checkout calculation/confirm JavaScript with DOM and AJAX doubles.
const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
let checks = 0;
const check = (condition, message) => { checks++; assert.ok(condition, message); };
if (process.argv.includes('--rounding')) {
 const rows = JSON.parse(fs.readFileSync(0, 'utf8'));
 for (const row of rows) check(row.actual === Number(row.browser).toFixed(2), 'PHP/browser serialization: ' + row.browser);
 console.log(`PASS ${checks} real JavaScript rounding golden cases`);
} else {
 for (const code of ['jet', 'jet_card']) {
  const template = fs.readFileSync(`upload/catalog/view/theme/default/template/extension/payment/${code}.twig`, 'utf8');
  const calculation = template.slice(template.indexOf('\tconst jetCalculate ='), template.indexOf('\n\tconst jetHideOptions401 ='));
  const confirmation = template.slice(template.indexOf('\tlet jetConfirmPending'), template.lastIndexOf('\n\tjetCalculate();'));
  for (const raw of ['500', '500.005', '400.9999', '600.9999', '1000']) {
   const elements = new Map(), requests = [], thresholds = [];
   function $(key) {
    if (!elements.has(key)) elements.set(key, {
     length: 1, value: '', content: '', events: {},
     val(value) { if (value === undefined) return this.value; this.value = String(value); return this; },
     text(value) { if (value === undefined) return this.content; this.content = String(value); return this; },
     on(name, fn) { this.events[name] = fn; return this; },
     prop() { return this; }, button() { return this; }
    });
    return elements.get(key);
   }
   $.ajax = (request) => requests.push(request);
   class Data { constructor() { this.values = {}; } append(key, value) { this.values[key] = String(value); } }
   for (const [field, value] of Object.entries({ price: raw, parva: '0', vnoski: '12', products: '1', products_qt: '2', products_pr: '250.00', products_vr: 'red', egn: '1234567890' })) $('#' + code + '_' + field).val(value);
   const context = vm.createContext({ $, FormData: Data, console, alert() {}, window: { location: {} },
    jetHideOptions401() {}, jetHideOptions601() {},
    jetShowOptions401() { thresholds.push(401); }, jetShowOptions601() { thresholds.push(601); },
    jetShowCustomAlert() {} });
   vm.runInContext(calculation + '\njetCalculate();\n' + confirmation, context);
   const request = requests[0];
   check(request.config === undefined && request.url.endsWith('/jet_calculate_cart'), 'existing calculator endpoint');
   check(request.data.values.jet_checkout_context === code, 'explicit context for ' + code);
   check(request.data.values.jet_priceall === Number(raw).toFixed(2), 'single existing serialization of raw snapshot');
   check(thresholds.includes(401) === (Number(raw) >= 401) && thresholds.includes(601) === (Number(raw) >= 601), 'existing unrounded term thresholds');
   const response = { success: 'success', jet_priceall: Number(raw).toFixed(2), jet_total_credit_price: Number(raw).toFixed(2) };
   for (const suffix of ['', '_card']) {
    Object.assign(response, { ['jet_vnoska' + suffix]: suffix ? '45.67' : '47.67', ['jet_gpr' + suffix]: '10.00', ['jet_glp' + suffix]: '9.00', ['jet_obshto' + suffix]: '572.00' });
   }
   request.success(response);
   $('#button-confirm').events.click();
   const sent = requests[1];
   check(sent.url.endsWith('/' + code + '/confirm'), 'existing confirmation route');
   check(sent.data.values[code + '_priceall'] === $('#' + code + '_priceall').text(), 'submission sends exact displayed amount');
   check(sent.data.values[code + '_priceall'] === Number(raw).toFixed(2), 'no second currency conversion');
   check(sent.data.values[code + '_vnoska'] === response[code === 'jet' ? 'jet_vnoska' : 'jet_vnoska_card'], 'displayed installment preserved');
   $('#button-confirm').events.click();
   check(requests.length === 2, 'existing duplicate-confirm lock retained');
  }
 }
 console.log(`PASS ${checks} real Checkout JavaScript DOM/AJAX snapshot checks`);
}
