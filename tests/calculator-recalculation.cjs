'use strict';

// Dependency-free DOM, clock and AJAX doubles; no store, mail or order access.
module.exports = function runCalculatorRegression(sources) {
 let checks = 0;
 const check = (condition, message) => { checks++; if (!condition) throw new Error(message); };
 function environment(source, options = false) {
  let now = 0, timerId = 0, loaderCount = 0;
  const timers = new Map(), elements = new Map(), requests = [], documentEvents = {}, optionEvents = {};
  const document = {
   querySelectorAll: (selector) => !options ? [] : selector.includes('id*=') ? [{}] : selector.includes('id^=') ? [{ addEventListener: (name, fn) => { optionEvents[name] = fn; } }] : [],
   addEventListener: (name, fn) => (documentEvents[name] ||= []).push(fn)
  };
  class Element {
   constructor(key, attributes = {}) { this.key = key; this.length = 1; this.attributes = attributes; this.value = ''; this.content = attributes.text || ''; this.events = {}; this.classes = new Set(); this.children = []; this.visible = false; }
   val(value) { if (value === undefined) return this.value; this.value = String(value); return this; }
   text(value) { if (value === undefined) return this.content; this.content = value; return this; }
   ready(fn) { fn($); return this; }
   first() { return this; } eq() { return this; }
   find(selector) {
    if (selector === '.jet_offer_loader') return { length: loaderCount };
    if (selector === 'option') return { each() {} };
    return get(selector);
   }
   on(names, fn) { for (const name of names.split(' ')) (this.events[name] ||= []).push(fn); return this; }
   off(namespace) { for (const name of Object.keys(this.events)) if (name.includes(namespace)) delete this.events[name]; return this; }
   append(child) { if (child instanceof Element) { this.children.push(child); if (child.attributes.id) elements.set('#' + child.attributes.id, child); } return this; }
   appendTo() { if (this.attributes.class === 'jet_offer_loader') loaderCount++; return this; }
   prependTo() { return this; } remove() { this.length = 0; return this; } parent() { return this; } after(markup) { (this.afterMarkup ||= []).push(markup); return this; }
   toggleClass(name, enabled) { enabled ? this.classes.add(name) : this.classes.delete(name); return this; }
   addClass(name) { this.classes.add(name); return this; }
   removeClass(name) { this.classes.delete(name); return this; }
   hasClass(name) { return this.classes.has(name); }
   attr(name, value) { if (value === undefined) return this.attributes[name]; this.attributes[name] = value; return this; }
   prop() { return this; } css() { return this; } show() { this.visible = true; return this; } hide() { this.visible = false; return this; }
   is() { return false; } click() { trigger(this.key, 'click'); return this; }
  }
  const get = (key) => { if (!elements.has(key)) elements.set(key, new Element(key)); return elements.get(key); };
  function $(key, attrs) {
   if (key === '#jet_alert_box:empty') { const alert = get('#jet_alert_box'); return !alert.children.length && !alert.content ? alert : { remove() {} }; }
   return typeof key === 'string' && key.startsWith('<') ? new Element(key, attrs) : key instanceof Element ? key : get(key); }
  const schedule = (fn, delay) => { const id = ++timerId; timers.set(id, { at: now + delay, fn }); return id; };
  const clear = (id) => timers.delete(id);
  $.ajax = (config) => {
   const request = { config, finished: false };
   let timeout;
   request.resolve = (data) => { if (request.finished) return; request.finished = true; clear(timeout); config.success?.(data); config.complete?.(); };
   request.reject = (reason = 'error') => { if (request.finished) return; request.finished = true; clear(timeout); config.error?.({ statusText: reason }); config.complete?.(); };
   if (config.timeout) timeout = schedule(() => request.reject('timeout'), config.timeout);
   requests.push(request); return request;
  };
  class Data { constructor() { this.values = {}; } append(name, value) { this.values[name] = String(value); } }
  const initial = { '#jet_price': '600', '#jet_parva': '0', '#jet_vnoski': '12', '#jet_product_id': '1', '#jet_card_in': '0', 'input[name="quantity"]': '1', '#jet_products': '1', '#jet_products_qt': '1', '#jet_products_pr': '600' };
  for (const [key, value] of Object.entries(initial)) get(key).value = value;
  const placeholder = get('#jet_alert_box'); placeholder.attributes.id = 'jet_alert_box'; placeholder.length = 1;
  function trigger(key, name) {
   const element = get(key), event = { target: { id: key.slice(1), value: element.value } };
   for (const [registered, handlers] of Object.entries(element.events)) if (registered.split('.')[0] === name) for (const fn of handlers) fn.call(element, event);
   for (const fn of documentEvents[name] || []) fn(event);
  }
  function tick(ms) {
   const end = now + ms;
   while (true) {
    const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
    if (!next) break;
    now = next[1].at; timers.delete(next[0]); next[1].fn();
   }
   now = end;
  }
  new Function('jQuery', 'document', 'FormData', 'setTimeout', 'clearTimeout', 'console', 'window', source)($, document, Data, schedule, clear, { error() {} }, {});
  return { requests, trigger, tick, get, changeOption: () => optionEvents.change?.(), loaderCount: () => loaderCount, loading: () => get('#jet-product-popup-container').classes.has('jet_offer_loading') };
 }
 const quote = (value = '50.00') => ({ success: 'success', jet_show_button: true, jet_vnoska: value, jet_vnoska_second: '0', jet_priceall: '600', jet_priceall_second: '600', jet_total_credit_price: '600', jet_total_credit_price_second: '600', jet_gpr: '10', jet_glp: '10', jet_obshto: '700', jet_obshto_second: '700' });
 for (const page of ['products', 'cart']) {
  const source = sources[page];
  let e = environment(source);
  check(e.requests.length === 1 && e.loading(), page + ': initial request and loader');
  e.requests[0].resolve(quote());
  check(!e.loading() && e.get('#jet_vnoska_popup').text() === '50.00', 'success releases loader');
  const enter = (value, event = 'input') => { e.get('#jet_parva').val(value); e.trigger('#jet_parva', event); };
  enter('10'); e.tick(200); enter('100'); e.tick(200); enter('150'); enter('150', 'change');
  e.tick(399); check(e.requests.length === 1, 'exact 400 ms trailing debounce');
  e.tick(1); check(e.requests.length === 2 && e.loading(), 'only final rapid input request');
  check(e.requests[1].config.data.values.jet_parva === '150.00', 'latest amount; unchanged payload rounding');
  check(e.get('#jet_parva').val() === '150' && e.get('#jet_vnoski').val() === '12', 'form values preserved');
  e.requests[1].resolve(quote('45.00'));
  enter('150', 'change'); enter('150.00'); e.tick(400);
  check(e.requests.length === 2, 'unchanged amount and input/change deduplicated');
  enter('160'); e.tick(400); const old = e.requests.at(-1);
  enter('170'); old.resolve(quote('STALE DURING DEBOUNCE'));
  check(e.get('#jet_vnoska_popup').text() === '45.00', 'old response invalidated immediately on input');
  e.tick(400); const latest = e.requests.at(-1);
  latest.resolve(quote('42.00')); check(!e.loading(), 'latest success releases loader');
  enter('180'); e.tick(400); const first = e.requests.at(-1);
  enter('190'); e.tick(400); const second = e.requests.at(-1);
  first.resolve(quote('STALE'));
  check(e.loading() && e.get('#jet_vnoska_popup').text() === '42.00', 'stale success/complete cannot overwrite or hide newer loader');
  second.resolve(quote('40.00'));
  enter('200'); e.tick(100); const count = e.requests.length;
  e.get('#jet_vnoski').val('18'); e.trigger('#jet_vnoski', 'change');
  check(e.requests.length === count + 1 && e.requests.at(-1).config.data.values.jet_vnoski === '18', 'term recalculates immediately');
  e.tick(400); check(e.requests.length === count + 1, 'term cancels pending debounce');
  e.requests.at(-1).resolve(quote());
  enter('210'); e.tick(100); const manualCount = e.requests.length;
  e.trigger('#btn_preizcisli', 'click');
  check(e.requests.length === manualCount + 1, 'manual button remains immediate');
  e.tick(400); check(e.requests.length === manualCount + 1, 'manual button cancels pending debounce');
  e.requests.at(-1).reject(); check(!e.loading(), 'AJAX error hides loader');
  e.trigger('#btn_preizcisli', 'click'); check(e.loading(), 'loader reappears after failure');
  check(e.requests.at(-1).config.timeout === 30000, 'finite request timeout');
  e.tick(30000); check(!e.loading(), 'timeout hides loader');
  e.trigger('#btn_preizcisli', 'click'); e.requests.at(-1).resolve({ success: false });
  check(!e.loading(), 'unsuccessful application response hides loader');
  check(e.loaderCount() === 1, 'single loader instance across calculations');
  const handlers = e.get('#jet_parva').events;
  check(Object.keys(handlers).length === (page === 'cart' ? 3 : 2) && Object.values(handlers).every((list) => list.length === 1), 'one input and one change handler');
  // Compare legacy invalid/empty behavior against HEAD rather than introducing rules.
  for (const value of (page === 'cart' ? ['invalid', '700', '-10'] : ['', 'invalid', '700', '-10'])) {
   const current = environment(source), baseline = environment(sources['baseline_' + page]);
   current.requests[0].resolve(quote()); baseline.requests[0].resolve(quote());
   for (const env of [current, baseline]) { env.get('#jet_parva').val(value); env.trigger('#btn_preizcisli', 'click'); }
   check(current.requests.length === baseline.requests.length, page + ': original validation request count for ' + value);
   check(JSON.stringify(current.requests.at(-1).config.data.values) === JSON.stringify(baseline.requests.at(-1).config.data.values), 'original invalid/empty/negative payload preserved');
   if (current.requests.length === 1) check(!current.loading(), 'validation rejection hides loader');
  }
 }
 // Product opening uses the existing server option result before showing financing.
 const missing = { error: { option: { 1: 'Option required!' } } };
 for (const button of ['#btn_jet', '#btn_jet_card']) {
  const plain = environment(sources.products);
  plain.requests[0].resolve(quote()); plain.trigger(button, 'click');
  check(plain.get('#jet-product-popup-container').visible && plain.requests.length === 2, 'no options opens immediately');
  for (const errors of [missing, { success: 1, error: { option: { 2: 'Second required!' } }, optionresult: [] }]) {
   const product = environment(sources.products, true);
   product.requests[0].resolve({ success: 1, optionresult: [] }); product.requests[1].resolve(quote());
   product.get('#input-option1').val('selected'); product.get('#jet_parva').val('100');
   const initialLoaders = product.loaderCount();
   product.trigger(button, 'click');
   check(!product.get('#jet-product-popup-container').visible && !product.loading(), 'options lookup precedes popup and loader');
   product.requests.at(-1).resolve(errors);
   check(product.requests.length === 3, 'blocked opening sends lookup only, no calculation');
   check(!product.get('#jet-product-popup-container').visible && !product.loading(), 'missing/partial required options: information only');
   check(product.loaderCount() === initialLoaders, 'blocked opening creates no loader');
   const alert = product.get('#jet_alert_box');
   check(alert.length === 1 && alert.children[0].text() === 'Моля, изберете първо опция от възможните за продукта!', 'exact existing information text');
   check(product.get('#jet_alert_overlay').classes.has('show'), 'existing information backdrop');
   product.trigger(button, 'click'); product.requests.at(-1).resolve(errors);
   check(product.get('#jet_alert_box') === alert, 'repeated blocked clicks do not duplicate information popup');
   // Dynamic buttons do not have IDs: invoke their actual close handler.
   for (const fn of alert.children.find((child) => child.key.startsWith('<button')).events.click || []) fn();
   check(!product.get('#jet_alert_overlay').classes.has('show') && !product.get('#jet_alert_box').length, 'existing close removes popup and backdrop');
   check(product.requests.length === 4, 'closing blocked information does not calculate even with nonzero downpayment');
   check(product.get('#input-option1').val() === 'selected', 'selected product options untouched');
   product.trigger(button, 'click'); product.requests.at(-1).resolve({ success: 1, optionresult: [] });
   check(product.get('#jet-product-popup-container').visible && product.loading(), 'selecting missing option allows normal popup/calculation');
   product.requests.at(-1).resolve(quote()); check(!product.loading(), 'successful opening finishes loader normally');
  }
  for (const type of ['select', 'radio', 'checkbox', 'text', 'textarea', 'file', 'date', 'datetime', 'time']) {
   const product = environment(sources.products, true);
   product.requests[0].resolve({ success: 1, optionresult: [] }); product.requests[1].resolve(quote());
   product.trigger(button, 'click');
   product.requests.at(-1).resolve({ success: 1, optionresult: [{ product_option_id: 1, product_option_id_check: type === 'checkbox' ? ['1'] : '1', type, product_option_value: [] }] });
   check(product.get('#jet-product-popup-container').visible && product.requests.at(-1).config.url.endsWith('/jet_calculate'), 'existing option result accepted: ' + type);
  }
  const optional = environment(sources.products, true);
  optional.requests[0].resolve({ success: 1, optionresult: [] }); optional.requests[1].resolve(quote());
  optional.trigger(button, 'click'); optional.requests.at(-1).resolve({ success: 1, optionresult: [] });
  check(optional.get('#jet-product-popup-container').visible, 'unselected optional option does not block');
  const pending = environment(sources.products, true);
  pending.trigger(button, 'click'); pending.requests[0].resolve({ success: 1, optionresult: [] });
  check(pending.requests.length === 2 && !pending.get('#jet-product-popup-container').visible, 'old lookup cannot open financing after a newer click');
  pending.requests[1].reject();
  check(!pending.get('#jet-product-popup-container').visible && !pending.loading(), 'failed opening lookup leaves no popup or loader');
 }
 // Real template placeholder and missing-option installment presentation.
 for (const button of ['#btn_jet', '#btn_jet_card']) {
  const product = environment(sources.products, true);
  const placeholder = product.get('#jet_alert_box');
  check(placeholder.length === 1 && !placeholder.children.length, 'template empty alert placeholder is present');
  product.requests[0].resolve(missing);
  check(product.requests.length === 1, 'initial missing required options cause no calculation request');
  check(product.get('#jet-product-button-container').visible, 'missing required options leave financing buttons available for information click');
  check(product.get('#jet-product-button-container').classes.has('jet_options_pending'), 'both installment previews hidden while required options missing');
  product.trigger(button, 'click'); product.requests.at(-1).resolve(missing);
  const alert = product.get('#jet_alert_box');
  check(alert !== placeholder && placeholder.length === 0, 'empty template placeholder cannot suppress information popup');
  check(alert.children.filter((child) => child.key.startsWith('<button')).length === 1 && alert.children[1].text() === 'Добре', 'information has only Добре action');
  check(alert.children[0].text() === 'Моля, изберете първо опция от възможните за продукта!', 'exact requested Woo information text');
  check(product.get('#input-option1').afterMarkup.some((html) => html.includes('Option required!')), 'native OpenCart field error retained');
  check(product.get('.text-danger').classes.has('has-error'), 'native OpenCart highlighting retained');
  check(product.requests.length === 2 && !product.get('#jet-product-popup-container').visible && !product.loading(), 'information click never opens or calculates financing');
  for (const fn of alert.children[1].events.click) fn();
  check(product.requests.length === 2 && !product.get('#jet_alert_overlay').classes.has('show'), 'information close neither calculates nor submits');
  product.trigger(button, 'click'); product.requests.at(-1).resolve({ success: 1, optionresult: [] });
  check(product.get('#jet-product-button-container').classes.has('jet_options_pending'), 'preview stays hidden until fresh valid calculation');
  product.requests.at(-1).resolve(quote('77.00'));
  check(!product.get('#jet-product-button-container').classes.has('jet_options_pending'), 'fresh valid quote restores original presentation');
  // Simulate the existing option-change path through a recalculation.
  product.changeOption(); const validation = product.requests.at(-1);
  check(product.get('#jet-product-button-container').classes.has('jet_options_pending'), 'new selection immediately hides stale installments');
  validation.resolve({ success: 1, optionresult: [] }); const stale = product.requests.at(-1);
  product.get('#input-option1').val(''); product.changeOption(); product.requests.at(-1).resolve(missing);
  stale.resolve(quote('STALE'));
  check(product.get('#jet-product-button-container').classes.has('jet_options_pending') && product.get('#jet_vnoska_popup').text() === '77.00', 'deselection blocks stale quote and preview restoration');
 }
 // Cart empty values are numeric zero, while editing remains uninterrupted.
 for (const empty of ['', '   ', '\t\r\n']) {
  const cart = environment(sources.cart);
  cart.requests[0].resolve(quote());
  cart.get('#jet_parva').val('100'); cart.trigger('#jet_parva', 'input'); cart.tick(400);
  const prior = cart.requests.at(-1);
  cart.get('#jet_parva').val(empty); cart.trigger('#jet_parva', 'input'); cart.trigger('#jet_parva', 'change');
  prior.resolve(quote('STALE BEFORE CLEAR'));
  check(cart.get('#jet_vnoska_popup').text() === '50.00', 'clearing invalidates old response');
  check(cart.get('#jet_parva').val() === empty, 'no field rewriting during typing');
  cart.tick(399); check(cart.requests.length === 2, 'empty value retains 400 ms debounce');
  cart.tick(1); check(cart.requests.length === 3 && cart.loading(), 'deletion recalculates normally with loader');
  check(cart.requests.at(-1).config.data.values.jet_parva === '0.00', 'empty/whitespace sent as numeric zero');
  cart.requests.at(-1).resolve(quote('55.00')); check(!cart.loading(), 'empty success hides loader');
  cart.trigger('#jet_parva', 'blur'); check(String(cart.get('#jet_parva').val()) === '0', 'empty blur displays zero');
  cart.trigger('#jet_parva', 'change'); cart.tick(400);
  check(cart.requests.length === 3, 'blur and subsequent change do not duplicate zero calculation');
  cart.trigger('#btn_preizcisli', 'click');
  check(cart.requests.at(-1).config.data.values.jet_parva === '0.00', 'legitimate zero remains valid');
 }
 for (const empty of ['', '  ']) {
  const cart = environment(sources.cart); cart.requests[0].resolve(quote());
  cart.get('#jet_parva').val('100'); cart.trigger('#jet_parva', 'input'); cart.tick(400); cart.requests.at(-1).resolve(quote());
  cart.get('#jet_parva').val(empty); cart.trigger('#jet_parva', 'input');
  cart.trigger('#jet_parva', 'blur'); cart.trigger('#jet_parva', 'change'); cart.tick(400);
  check(cart.requests.length === 3 && cart.requests.at(-1).config.data.values.jet_parva === '0.00', 'blur before debounce preserves one zero request');
 }
 // Product options lookup and calculation form one loader lifecycle.
 let e = environment(sources.products, true);
 check(e.requests[0].config.url.endsWith('/jet_check') && e.loading(), 'loader covers initial options lookup');
 e.requests[0].resolve({ success: 1, optionresult: [] });
 check(e.requests.length === 2 && e.loading(), 'lookup completion does not hide calculator loader');
 e.requests[1].resolve(quote()); check(!e.loading(), 'product chain completes');
 e = environment(sources.products, true);
 e.get('#jet_parva').val('100'); e.trigger('#jet_parva', 'input'); e.tick(400);
 e.requests[0].resolve({ success: 1, optionresult: [] });
 check(e.requests.length === 2 && e.loading(), 'stale option response starts no calculator request');
 e.requests[1].resolve({ success: 1, optionresult: [] });
 check(e.requests.length === 3 && e.loading(), 'latest options start calculator');
 e.requests[2].reject(); check(!e.loading(), 'product calculation failure releases loader');
 for (const result of ['error', 'timeout', 'unsuccess']) {
  e = environment(sources.products, true);
  if (result === 'error') e.requests[0].reject();
  if (result === 'timeout') e.tick(30000);
  if (result === 'unsuccess') e.requests[0].resolve({ success: false });
  check(!e.loading(), 'options lookup releases loader: ' + result);
 }
 return checks;
};

if (require.main === module) {
 const fs = require('fs');
 const childProcess = require('child_process');
 const sources = {};
 for (const page of ['products', 'cart']) {
  const path = `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_${page}.js`;
  sources[page] = fs.readFileSync(path, 'utf8');
  sources['baseline_' + page] = childProcess.execFileSync('git', ['show', 'HEAD:' + path], { encoding: 'utf8' });
 }
 console.log(`PASS ${module.exports(sources)} calculator regression checks`);
}
