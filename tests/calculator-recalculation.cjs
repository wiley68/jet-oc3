'use strict';

// Dependency-free DOM, clock and AJAX doubles; no store, mail or order access.
module.exports = function runCalculatorRegression(sources, productTests = null) {
 let checks = 0;
 const check = (condition, message) => { checks++; if (!condition) throw new Error(message); };
 function environment(source, options = false, css = '', initialValues = {}) {
  let now = 0, timerId = 0, loaderCount = 0;
  const timers = new Map(), elements = new Map(), requests = [], documentEvents = {}, optionEvents = {}, captureEvents = {};
  const faults = {}, alerts = [], loaderNodes = [];
  const document = {
   querySelectorAll: (selector) => !options ? [] : selector.includes('id*=') ? [{}] : selector.includes('id^=') ? [{ addEventListener: (name, fn) => { optionEvents[name] = fn; } }] : [],
   addEventListener: (name, fn) => (documentEvents[name] ||= []).push(fn),
   getElementById: (id) => get('#' + id)
  };
  class Element {
   constructor(key, attributes = {}) { this.key = key; this.length = 1; this.attributes = attributes; this.value = ''; this.content = attributes.text || ''; this.events = {}; this.classes = new Set(); this.children = []; this.visible = false; this[0] = this; this.props = {}; this.classList = { add: (name) => this.classes.add(name), contains: (name) => this.classes.has(name) }; }
   val(value) { if (value === undefined) return this.value; this.value = String(value); return this; }
   serializeArray() { return JSON.parse(get('__option_entries').value || '[]'); }
   text(value) { if (value === undefined) return this.content; this.content = value; return this; }
   ready(fn) { fn($); return this; }
   first() { return this; } eq() { return this; }
   find(selector) {
    if (selector === '.jet_offer_loader') return { length: loaderCount };
    if (selector === 'option') return { each(fn) {
     for (const term of ['3', '6', '9', '10', '12', '15', '18', '24', '30', '36']) {
      const option = get('#jet_vnoski option[value="' + term + '"]').val(term);
      fn.call(option);
     }
    } };
    const element = get(selector); element.parentNode = this; return element;
   }
   on(names, fn) { for (const name of names.split(' ')) (this.events[name] ||= []).push(fn); return this; }
   off(namespace) { for (const name of Object.keys(this.events)) if (name.includes(namespace)) delete this.events[name]; return this; }
   append(child) { if (child instanceof Element) { this.children.push(child); if (child.attributes.id) elements.set('#' + child.attributes.id, child); } return this; }
   appendTo(parent) { this.parentNode = typeof parent === 'string' ? get(parent) : parent; if (this.attributes.class === 'jet_offer_loader') { loaderCount++; loaderNodes.push(this); } return this; }
   prependTo() { return this; } remove() { this.length = 0; return this; } parent() { return this; } after(markup) { (this.afterMarkup ||= []).push(markup); return this; }
   toggleClass(name, enabled) { enabled ? this.classes.add(name) : this.classes.delete(name); return this; }
   addClass(name) { this.classes.add(name); return this; }
   removeClass(name) { this.classes.delete(name); return this; }
   hasClass(name) { return this.classes.has(name); }
   attr(name, value) { if (value === undefined) return this.attributes[name]; this.attributes[name] = value; return this; }
   button() { return this; }
   removeAttr(name) { delete this.attributes[name]; return this; }
   prop(name, value) { if (value === undefined) return this.props[name]; this.props[name] = value; return this; }
   contains(target) { return this === target || this.children.some((child) => child.contains(target)); }
   querySelector() { return this.children.find((child) => child.key.startsWith('<button')); }
   focus() { this.focused = true; } blur() { this.focused = false; } css() { return this; } show() { this.visible = true; return this; } hide() { this.visible = false; return this; }
   is() { return false; } click() { dispatch(this, 'click'); return this; }
  }
  const get = (key) => { if (!elements.has(key)) elements.set(key, new Element(key)); return elements.get(key); };
  function $(key, attrs) {
   if (key === '#jet-product-popup-container .jet_offer_loader > span' && loaderNodes.length) return loaderNodes[0].children[0];
   if (key === '#jet_alert_box:empty') { const alert = get('#jet_alert_box'); return !alert.children.length && !alert.content ? alert : { remove() {} }; }
   return typeof key === 'string' && key.startsWith('<') ? new Element(key, attrs) : key instanceof Element ? key : get(key); }
  const schedule = (fn, delay) => { const id = ++timerId; timers.set(id, { at: now + delay, fn }); return id; };
  const clear = (id) => timers.delete(id);
  $.ajax = (config) => {
   if (faults.ajax) { faults.ajax = false; throw new Error('mock synchronous AJAX failure'); }
   const request = { config, finished: false };
   let timeout;
   request.resolve = (data) => { if (request.finished) return; request.finished = true; clear(timeout); if (data?.__mockQuote && /\/jet_calculate(?:_cart)?$/.test(config.url)) data = { ...data, jet_parva: config.data.values.jet_parva, jet_vnoski: config.data.values.jet_vnoski, jet_card: config.data.values.jet_card || 0 }; config.success?.(data); config.complete?.(); };
   request.reject = (reason = 'error') => { if (request.finished) return; request.finished = true; clear(timeout); config.error?.({ statusText: reason }); config.complete?.(); };
   if (config.timeout) timeout = schedule(() => request.reject('timeout'), config.timeout);
   requests.push(request); return request;
  };
  class Data { constructor() { if (faults.data) { faults.data = false; throw new Error('mock FormData failure'); } this.values = {}; } append(name, value) { this.values[name] = String(value); } }
  const initial = { '#jet_price': '600', '#jet_parva': '0', '#jet_vnoski': '12', '#jet_product_id': '1', '#jet_card_in': '0', 'input[name="quantity"]': '1', '#jet_products': '1', '#jet_products_qt': '1', '#jet_products_pr': '600', ...initialValues };
  for (const [key, value] of Object.entries(initial)) get(key).value = value;
  const placeholder = get('#jet_alert_box'); placeholder.attributes.id = 'jet_alert_box'; placeholder.length = 1;
  function trigger(key, name) { return dispatch(get(key), name); }
  function dispatch(element, name) {
   element.id = element.key.slice(1);
   const event = { target: element, cancelable: true, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
   for (const fn of captureEvents[name] || []) { fn(event); if (event.stopped) return event; }
   for (const [registered, handlers] of Object.entries(element.events)) if (registered.split('.')[0] === name) for (const fn of handlers) fn.call(element, event);
   for (const fn of documentEvents[name] || []) fn(event);
   return event;
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
  const cssProperty = (selector, property) => {
   const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
   const rule = css.match(new RegExp(escaped + '\\s*\\{([^}]+)\\}'))?.[1] || '';
   return rule.split(';').map((entry) => entry.split(':').map((part) => part.trim())).find((entry) => entry[0] === property)?.[1]?.replace(/\s*!important$/, '') || '';
  };
  const loaderLayout = () => {
   const popup = get('#jet-product-popup-container'), loader = loaderNodes[0];
   const parentCorrect = !!loader && loader.parentNode === get('.modalpayment-content_jet') && loader.parentNode.parentNode === popup;
   const display = popup.classes.has('jet_offer_loading') ? cssProperty('#jet-product-popup-container.jet_offer_loading .jet_offer_loader', 'display') : cssProperty('#jet-product-popup-container .jet_offer_loader', 'display');
   const covered = get('#jet_alert_overlay').classes.has('show') && Number.parseInt(cssProperty('.jet_alert_overlay', 'z-index')) > Number.parseInt(cssProperty('.modalpayment_jet', 'z-index'));
   return { visible: popup.visible && parentCorrect && display === 'flex' && !covered, parentCorrect,
    relativeParent: cssProperty('.modalpayment-content_jet', 'position') === 'relative',
    loaderZ: cssProperty('#jet-product-popup-container .jet_offer_loader', 'z-index'),
    width: cssProperty('#jet-product-popup-container .jet_offer_loader::after', 'width'),
    animation: cssProperty('#jet-product-popup-container .jet_offer_loader::after', 'animation'),
    text: loader?.children[0]?.text() };
  };
  new Function('jQuery', 'document', 'FormData', 'setTimeout', 'clearTimeout', 'console', 'window', 'alert', 'location', source)($, document, Data, schedule, clear, { error() {}, log() {} }, { addEventListener: (name, fn) => (captureEvents[name] ||= []).push(fn), location: { origin: 'http://test.invalid', href: '' } }, (message) => alerts.push(message), {});
  return { requests, trigger, tick, get, faults, alerts, loaderLayout, changeOption: () => optionEvents.change?.(), inputOption: () => optionEvents.input?.(), loaderCount: () => loaderCount, loading: () => get('#jet-product-popup-container').classes.has('jet_offer_loading') };
 }
 const quote = (value = '50.00') => ({ success: 'success', __mockQuote: true, jet_quote_ref: 'a'.repeat(48), jet_cart_lineage: 'b'.repeat(48), jet_parva: '0.00', jet_show_button: true, jet_vnoska: value, jet_vnoska_second: '0', jet_priceall: '600', jet_priceall_second: '600', jet_total_credit_price: '600', jet_total_credit_price_second: '600', jet_gpr: '10', jet_glp: '10', jet_obshto: '700', jet_obshto_second: '700' });
 if (productTests) return productTests(environment, quote);
 // F02: execute the real option/quantity JavaScript and PHP calculator, never a submission transport.
 const { execFileSync } = require('child_process');
 const phpCache = new Map();
 const productEndpoint = (input) => {
  const key = JSON.stringify(input);
  if (!phpCache.has(key)) phpCache.set(key, JSON.parse(execFileSync('php', ['tests/product-option-prefix.php'], { input: key, encoding: 'utf8' })));
  return phpCache.get(key);
 };
 const f02Start = checks;
 const prefixCases = [
  { name: 'select positive', type: 'select', adjustments: [['+', 10]], unit: 610, unchanged: true },
  { name: 'select negative', type: 'select', adjustments: [['-', 10]], unit: 590 },
  { name: 'radio positive', type: 'radio', adjustments: [['+', 10]], unit: 610, unchanged: true },
  { name: 'radio negative', type: 'radio', adjustments: [['-', 10]], unit: 590 },
  { name: 'positive zero', type: 'select', adjustments: [['+', 0]], unit: 600, unchanged: true },
  { name: 'negative zero', type: 'radio', adjustments: [['-', 0]], unit: 600, unchanged: true },
  { name: 'checkbox mixed', type: 'checkbox', adjustments: [['+', 10], ['-', 5]], unit: 605 },
  { name: 'checkbox negatives', type: 'checkbox', adjustments: [['-', 10], ['-', 5], ['-', 2]], unit: 583 },
  { name: 'checkbox zeros', type: 'checkbox', adjustments: [['+', 0], ['-', 0]], unit: 600, unchanged: true },
  { name: 'no options', adjustments: [], unit: 600, unchanged: true },
 ];
 for (const type of ['select', 'checkbox']) for (const prefix of [undefined, '', '?', null]) {
  prefixCases.push({ name: type + ' preserved prefix ' + String(prefix), type, adjustments: [[prefix, 10]], unit: 610, unchanged: true });
 }
 const scenarios = [];
 for (const base_currency of ['EUR', 'USD']) for (const quantity of [1, 2, 4]) for (const item of prefixCases) {
  scenarios.push({ ...item, base_currency, quantity });
 }
 for (const [base, unit] of [[410, 400], [411, 401], [610, 600], [611, 601]]) {
  scenarios.push({ name: 'corrected term boundary ' + unit, type: 'select', adjustments: [['-', 10]], base, unit, quantity: 1, base_currency: 'EUR' });
 }
 scenarios.push(
  { name: 'minimum follows negative', type: 'select', adjustments: [['-', 10]], unit: 590, minimum: 595 },
  { name: 'minimum positive', type: 'select', adjustments: [['+', 10]], unit: 610, minimum: 595, unchanged: true },
  { name: 'promotion hidden above threshold', type: 'select', adjustments: [['+', 10]], unit: 610, promo_percent: -1, unchanged: true },
  { name: 'negative leaves hidden promotion', type: 'select', adjustments: [['-', 10]], unit: 590, promo_percent: -1 },
  { name: 'selected 18 months', type: 'radio', adjustments: [['-', 10]], base: 700, unit: 690, term: 18 },
  { name: 'taxed EUR negative', type: 'select', adjustments: [['-', 10]], base: 500, unit: 588, tax: true },
  { name: 'taxed non-EUR negative', type: 'checkbox', adjustments: [['-', 10]], base: 500, unit: 588, tax: true, base_currency: 'USD' }
 );
 for (const scenario of scenarios) for (const card of [0, 1]) {
  const quantity = scenario.quantity ?? 1, term = scenario.term ?? 12, base_currency = scenario.base_currency ?? 'EUR';
  const factor = base_currency === 'EUR' ? 1 : 2, hasOptions = scenario.adjustments.length > 0;
  const values = scenario.adjustments.map(([prefix, price], index) => ({ product_option_value_id: String(index + 11), option_value_id: String(index + 101), name: 'Value ' + index, price: price * factor, ...(prefix === undefined ? {} : { price_prefix: prefix }) }));
  // An unselected priced value catches duplicate/all-values accumulation.
  values.push({ product_option_value_id: '99', option_value_id: '199', name: 'Unselected', price: 99 * factor, price_prefix: '+' });
  const selected = scenario.type === 'checkbox' ? scenario.adjustments.map((_, index) => String(index + 11)) : '11';
  const nativeFixture = { mode: 'options', base_currency, base_price: (scenario.base ?? 600) * factor, tax: scenario.tax ?? false,
   options: hasOptions ? [{ product_option_id: '1', option_id: '1', name: 'Option', type: scenario.type, required: true, product_option_value: values }] : [], selected: hasOptions ? { 1: selected } : {} };
  const fixture = productEndpoint(nativeFixture);
  const optionEntries = hasOptions ? (Array.isArray(selected) ? selected : [selected]).map(value => ({ name: 'option[1]' + (Array.isArray(selected) ? '[]' : ''), value })) : [];
  if (hasOptions) check(fixture.options.optionresult[0].product_option_value[0].price === scenario.adjustments[0][1] * (scenario.tax ? 1.2 : 1), scenario.name + ': jet_check already returns EUR option price');
  const amount = scenario.unit * quantity, label = scenario.name + '/' + base_currency + '/q' + quantity + '/card' + card;
  const execute = (source) => {
   const e = environment(source, hasOptions, sources.css_products, { '#jet_price': String(fixture.base_eur), 'input[name="quantity"]': String(quantity), '#jet_vnoski': String(term), '#jet_card_in': '1', '__option_entries': JSON.stringify(optionEntries) });
   const settle = () => {
    if (hasOptions) e.requests.at(-1).resolve(fixture.options);
    const calculation = e.requests.at(-1);
    check(calculation.config.url.endsWith('/jet_calculate'), label + ': existing Product calculator');
    check(calculation.config.data.values.jet_priceall === amount.toFixed(2), label + ': signed adjustment before quantity, no second conversion');
    check(calculation.config.data.values.jet_vnoski === String(term), label + ': selected term retained');
    const response = productEndpoint({ ...nativeFixture, mode: 'quote', post: { ...calculation.config.data.values, jet_quantity: String(quantity), jet_options: JSON.stringify(optionEntries) }, minimum: scenario.minimum ?? 75, promo_min: 600, promo_term: 12, promo_percent: scenario.promo_percent ?? 0.5 });
    check(response.promo_sql.length === 1 && response.promo_sql[0].includes('AND ' + amount + ' >= jet_product_price'), label + ': real getPromo receives corrected principal');
    calculation.resolve(response.quote);
    return response.quote;
   };
   const preview = settle();
   const promoMatched = amount >= 600 && term === 12, hiddenPromo = promoMatched && scenario.promo_percent === -1;
   check(e.get('#jet-product-button-container').visible === (!hiddenPromo && amount >= (scenario.minimum ?? 75)), label + ': promotion/minimum button eligibility');
   check(e.get('#jet_vnoska').text() === preview.jet_vnoska && e.get('#jet_vnoska_card').text() === preview.jet_vnoska_card, label + ': both button installment previews');
   for (const boundaryTerm of ['15', '18', '24', '30', '36']) check(e.get('#jet_vnoski option[value="' + boundaryTerm + '"]').prop('disabled') === (amount < (['30', '36'].includes(boundaryTerm) ? 601 : 401)), label + ': corrected amount controls term ' + boundaryTerm);
   e.trigger(card ? '#btn_jet_card' : '#btn_jet', 'click');
   const popup = settle(), suffix = card ? '_card' : '';
   const percent = promoMatched && !hiddenPromo ? (scenario.promo_percent ?? 0.5) : (card ? 1.0 : 1.4);
   check(popup['jet_vnoska' + suffix] === ((amount / term) * (1 + term * percent / 100)).toFixed(2), label + ': unchanged financing formula/promotion');
   check(e.get('#jet-product-popup-container').visible && e.get('#jet_priceall').text() === amount.toFixed(2), label + ': popup displays corrected amount');
   check(e.get('#jet_vnoska_popup').text() === popup['jet_vnoska' + suffix], label + ': selected installment displayed');
   check(e.requests.filter((request) => request.config.url.endsWith('/jet_calculate')).length === 2, label + ': one calculation per preview/open');
   if (!preview.jet_show_button) return { preview, popup };
   e.trigger('#buy_jetcredit', 'click');
   check(e.get('#jet_step_2').visible, label + ': existing Step 2 transition');
   for (const [key, value] of Object.entries({ jet_name: 'Test', jet_lastname: 'User', jet_egn: '9001010000', jet_phone: '+359888888888', jet_email: 'test@example.invalid' })) e.get('#' + key).val(value);
   e.trigger('#buy2_jetcredit', 'click');
   const submission = e.requests.at(-1);
   check(submission.config.url.endsWith('/jet_send'), label + ': mocked Product submission endpoint');
   const payload = submission.config.data.values;
   check(payload.jet_priceall === amount.toFixed(2) && payload.jet_quantity === String(quantity) && payload.jet_vnoski === String(term) && payload.jet_card === String(card), label + ': displayed amount/quantity/term/card sent unchanged');
   for (const field of ['vnoska', 'obshto', 'gpr', 'glp']) check(payload['jet_' + field] === popup['jet_' + field + suffix], label + ': submitted displayed ' + field);
   check(payload.jet_total_credit_price === popup.jet_total_credit_price && Number(payload.jet_parva) === 0, label + ': existing principal/downpayment payload');
   check(JSON.parse(payload.jet_variation_id).length === scenario.adjustments.length, label + ': selections not duplicated');
   return { preview, popup, payload };
  };
  const current = execute(sources.products);
  const financialOnly = value => JSON.stringify(value, (key, item) => ['jet_quote_ref','jet_options','jet_quantity','jet_downpayment_raw','jet_previous_quote','jet_parva'].includes(key) ? undefined : item);
  if (scenario.unchanged) check(financialOnly(current) === financialOnly(execute(sources.baseline_products)), label + ': positive/zero/no-option/fallback golden unchanged');
 }
 console.log(`PASS ${checks - f02Start} F02 prefix/quantity/currency/preview/popup/submission checks`);
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
   if (page === 'products') { check(current.requests.length === (value.trim() === '' ? 2 : 1), 'Product blank normalization/invalid negative rejection'); continue; }
   if (page === 'cart') { check(current.requests.length === 1 && !current.loading(), 'Cart invalid/negative/nonpositive-principal input rejected before transport: ' + value); continue; }
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
 // Step 2: one pending request, captured interaction lock and existing information result.
 for (const page of ['products', 'cart']) {
  const prepare = () => {
   const e = environment(sources[page], false, sources['css_' + page]); e.requests[0].resolve(quote());
   e.get('#jet-product-popup-container').show(); e.get('#jet_step_1').hide(); e.get('#jet_step_2').show();
   for (const [key, value] of Object.entries({ jet_name: 'Test', jet_lastname: 'User', jet_egn: '9001010000', jet_phone: '+359888888888', jet_email: 'test@example.invalid' })) e.get('#' + key).val(value);
   return e;
  };
  let e = prepare();
  e.trigger('#buy2_jetcredit', 'click');
  check(e.requests.length === 2 && e.requests[1].config.url.endsWith('/jet_send' + (page === 'cart' ? '_cart' : '')), 'Step 2 starts existing endpoint');
  check(e.loaderLayout().visible && e.loaderLayout().text === 'Изпращане на заявката. Моля, изчакайте…', 'Step 2 pending loader is CSS-visible, with existing text');
  check(e.loaderLayout().parentCorrect && e.loaderLayout().relativeParent && e.loaderLayout().loaderZ === '2', 'loader covers relative popup content above its children');
  check(e.loaderLayout().width === '48px' && e.loaderLayout().animation === 'jet-offer-spin 0.8s linear infinite', 'existing spinner dimensions/animation retained');
  check(e.requests[1].config.async !== false, 'submission uses asynchronous AJAX');
  e.tick(16); check(e.loaderLayout().visible && !e.requests[1].finished, 'pending AJAX yields a frame with visible loader');
  e.tick(500); check(e.loaderLayout().visible, 'loader remains visible while submission pending');
  check(e.get('#buy2_jetcredit').prop('disabled') && e.get('#buy2_jetcredit').attr('aria-busy') === 'true', 'submit marked pending');
  check(e.trigger('#buy2_jetcredit', 'click').stopped && e.requests.length === 2, 'repeated clicks blocked');
  check(e.trigger('#close_jetcredit', 'click').stopped && e.trigger('#jet_parva', 'input').stopped && e.trigger('#btn_preizcisli', 'click').stopped, 'popup cannot close/change/recalculate while pending');
  e.requests[1].resolve({ success: 'success', code: 'bank_sent', order_id: 99 });
  let alert = e.get('#jet_alert_box');
  check(alert.children[0].text().startsWith('Успешно изпратихте Вашата заявка'), 'existing success message unchanged');
  check(!e.loaderLayout().visible && e.get('#jet_alert_overlay').classes.has('show') && !e.get('#jet_alert_overlay').text() && e.trigger('#buy2_jetcredit', 'click').stopped, 'lock held until result popup handled');
  alert.children[1].click();
  check(!e.get('#jet_alert_overlay').classes.has('show') && !e.get('#buy2_jetcredit').attr('aria-busy'), 'confirmation dismissal releases interaction/loader');
  for (const kind of ['failure', 'bank_failed', 'bank_uncertain', 'network', 'timeout', 'malformed', 'sync_data', 'sync_ajax']) {
   e = prepare(); if (kind === 'sync_data') e.faults.data = true; if (kind === 'sync_ajax') e.faults.ajax = true;
   e.trigger('#buy2_jetcredit', 'click');
   const request = e.requests[1];
   if (kind === 'failure') request.resolve({ success: 'unsuccess' });
   if (kind === 'bank_failed') request.resolve({ success: 'unsuccess', code: 'bank_failed', order_id: 99 });
   if (kind === 'bank_uncertain') request.resolve({ success: 'unsuccess', code: 'bank_uncertain', order_id: 99 });
   if (kind === 'network') request.reject();
   if (kind === 'timeout') e.tick(30000);
   if (kind === 'malformed') request.resolve({});
   alert = e.get('#jet_alert_box');
   check(!e.loaderLayout().visible && e.get('#jet_alert_overlay').classes.has('show') && alert.children.length === 2, 'error/uncertainty always resolves to existing information popup: ' + kind);
   if (!['failure', 'bank_failed', 'sync_data'].includes(kind)) check(alert.children[0].text().startsWith('Резултатът от изпращането не е потвърден.'), 'uncertain result never claimed as definite failure');
   alert.children[1].click();
   const requests = e.requests.length; e.tick(60000);
   check(e.requests.length === requests && !e.get('#jet_alert_overlay').classes.has('show'), 'no permanent overlay or automatic retry: ' + kind);
   e.trigger('#buy2_jetcredit', 'click');
   check(e.requests.length === requests + (['failure', 'sync_data'].includes(kind) ? 1 : 0), 'only confirmed pre-order failure permits manual new attempt');
   check(!e.trigger('#close_jetcredit', 'click')?.stopped || ['failure', 'sync_data'].includes(kind), 'review state blocks resend, not whole popup');
  }
 }
 for (const page of ['products', 'cart']) {
  const old = environment(sources['visibility_baseline_' + page] || sources['baseline_' + page], false, sources['css_' + page]);
  old.requests[0].resolve(quote()); old.get('#jet-product-popup-container').show();
  for (const [key, value] of Object.entries({ jet_name: 'Test', jet_lastname: 'User', jet_egn: '9001010000', jet_phone: '+359888888888', jet_email: 'test@example.invalid' })) old.get('#' + key).val(value);
  old.trigger('#buy2_jetcredit', 'click');
  check(!old.loaderLayout().visible, 'committed baseline reproduces absent Step 2 spinner');
 }
 // Native Checkout has no Step 2 popup; its existing confirm request stays isolated.
 for (const mode of ['jet', 'jet_card']) {
  if (!sources['checkout_' + mode]) continue;
  const template = sources['checkout_' + mode];
  const snippet = template.slice(template.indexOf('\tlet jetConfirmPending'), template.lastIndexOf('\n\tjetCalculate();'));
  const source = 'jQuery(document).ready(function($) { const jetShowCustomAlert = (message) => alert(message);\n' + snippet + '\n});';
  for (const kind of ['success', 'failure', 'bank_uncertain', 'network', 'timeout', 'malformed', 'sync_data', 'sync_ajax']) {
   const e = environment(source);
   if (kind === 'sync_data') e.faults.data = true; if (kind === 'sync_ajax') e.faults.ajax = true;
   e.trigger('#button-confirm', 'click');
   if (!kind.startsWith('sync_')) {
    check(e.requests.length === 1 && e.requests[0].config.url.endsWith('/' + mode + '/confirm'), 'native confirm endpoint preserved');
    e.trigger('#button-confirm', 'click'); check(e.requests.length === 1, 'native double confirm blocked');
    if (kind === 'success') e.requests[0].resolve({ success: 'success', code: 'bank_sent', redirect: 'checkout/success' });
    if (kind === 'failure') e.requests[0].resolve({ success: 'unsuccess' });
    if (kind === 'bank_uncertain') e.requests[0].resolve({ success: 'unsuccess', code: 'bank_uncertain', order_id: 1 });
    if (kind === 'network') e.requests[0].reject();
    if (kind === 'timeout') e.tick(30000);
    if (kind === 'malformed') e.requests[0].resolve({});
   }
   if (['bank_uncertain', 'network', 'timeout', 'malformed', 'sync_ajax'].includes(kind)) check(e.alerts.at(-1).startsWith('Резултатът от изпращането не е потвърден.'), 'native uncertainty reported without delivery claim');
   const before = e.requests.length; e.tick(60000); check(e.requests.length === before, 'native no automatic retry');
   e.trigger('#button-confirm', 'click');
   check(e.requests.length === before + (['failure', 'sync_data'].includes(kind) ? 1 : 0), 'native confirmed failure retryable; accepted/uncertain result never resent');
  }
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
  sources['visibility_baseline_' + page] = childProcess.execFileSync('git', ['show', 'ad392b78dfa8f764fca50e43b0ab827d6f0486a5:' + path], { encoding: 'utf8' });
  sources['css_' + page] = fs.readFileSync(`upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_${page}.css`, 'utf8');
  sources['baseline_' + page] = childProcess.execFileSync('git', ['show', 'HEAD:' + path], { encoding: 'utf8' });
 }
 for (const mode of ['jet', 'jet_card']) sources['checkout_' + mode] = fs.readFileSync(`upload/catalog/view/theme/default/template/extension/payment/${mode}.twig`, 'utf8');
 console.log(`PASS ${module.exports(sources)} calculator regression checks`);
}
