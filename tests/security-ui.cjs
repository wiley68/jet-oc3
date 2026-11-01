'use strict';
// Dependency-free Node tests with a small jQuery/DOM double. Never sends AJAX.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = process.env.JET_OC3_ROOT || process.cwd();
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const scripts = text => [...text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]);
const renderedScript = text => text.replace(/\{\{\s*mt_jet_button_scheme_\w+\|raw\s*\}\}/g, '{}');
let checks = 0;
const check = (condition, label) => { ++checks; assert.ok(condition, label); };

function harness({runReady = true, options = false, inputGroup = false} = {}) {
    const requests = [], alerts = [], logs = [], inserted = [], handlers = [], elements = new Map(), timers = [];
    const optionNodes = [];
    const document = {querySelectorAll: () => optionNodes};
    const location = {origin: 'https://test.invalid', protocol: 'https:', href: 'https://test.invalid/index.php', reloadCount: 0, reload() { this.reloadCount++; }};
    class Element {
        constructor(key) { this.key = key; this.length = 1; this.value = '0'; this.attributes = {}; this.properties = {}; }
        ready(fn) { if (runReady) fn($); return this; }
        on(event, selector, fn) {
            if (typeof selector === 'function') fn = selector;
            handlers.push({key: this.key, event, selector: typeof selector === 'string' ? selector : null, fn});
            return this;
        }
        off() { return this; }
        empty() { return this; }
        trigger() { return this; }
        val(value) { if (value === undefined) return this.value; this.value = value; return this; }
        attr(name, value) { if (value === undefined) return this.attributes[name]; this.attributes[name] = value; return this; }
        prop(name, value) { if (value === undefined) return this.properties[name]; this.properties[name] = value; return this; }
        text(value) { if (value === undefined) return this.value; this.value = value; this.isText = true; return this; }
        each() { return this; }
        find() { return this; }
        filter() { return this; }
        parent() { return this; }
        hasClass() { return inputGroup; }
        is() { return false; }
        eq() { return this; }
        first() { return this; }
        addClass(value) { this.className = value; return this; }
        removeClass() { return this; }
        remove() { return this; }
        show() { return this; }
        hide() { return this; }
        css() { return this; }
        toggle() { return this; }
        tab() { return this; }
        prependTo() { return this; }
        append(value) { inserted.push(value); return this; }
        after(value) { inserted.push(value); return this; }
        button(value) { this.buttonState = value; return this; }
    }
    function $(key, properties) {
        if (typeof key === 'function') { if (runReady) key($); return; }
        if (key instanceof Element) return key;
        const lookup = key === document ? 'document' : key;
        if (!elements.has(lookup)) {
            const element = new Element(lookup);
            if (typeof key === 'string' && key.includes('[name="quantity"]')) element.length = 0;
            elements.set(lookup, element);
        }
        const element = elements.get(lookup);
        if (properties && Object.hasOwn(properties, 'text')) element.text(properties.text);
        return element;
    }
    $.ajax = request => { requests.push(request); request.abort = () => { request.aborted = true; }; return request; };
    class FakeFormData { constructor() { this.fields = {}; } append(name, value) { this.fields[name] = value; } }
    document.addEventListener = () => {};
    $('#jet_price').val('500');
    $('#jet_parva').val('0');
    $('#jet_vnoski').val('12');
    const context = vm.createContext({jQuery: $, $, document, window: {location,crypto:{getRandomValues(array){array.fill(7);return array;}}}, location,
        alert: message => alerts.push(message), FormData: FakeFormData,
        sessionStorage: {getItem() { return null; }, setItem() {}},
        console: {error: (...args) => logs.push(args), log: (...args) => logs.push(args)},
        URL, setTimeout(fn, delay) { timers.push({fn, delay}); return timers.length; }, clearTimeout() {}});
    return {$, context, requests, alerts, logs, handlers, inserted, location, timers, optionNodes,
        fire(key, event = 'click', selector = null, target) {
            const handler = handlers.find(h => h.key === key && h.event === event && h.selector === selector);
            assert.ok(handler, `handler ${key} ${event} ${selector}`);
            handler.fn.call(target, {preventDefault() {}});
        }};
}

// Syntax-check all module JS and all modified inline script blocks without executing
// financial submissions or depending on an installed browser/Twig renderer.
for (const file of ['creditjet_products.js', 'creditjet_cart.js', 'creditjet_offers.js']) {
    new vm.Script(read('upload/catalog/view/theme/default/template/extension/mt_jet_credit/' + file));
    ++checks;
}
const adminFile = 'upload/admin/view/template/extension/module/mt_jet_credit.twig';
const paymentFiles = ['jet', 'jet_card'].map(name => `upload/catalog/view/theme/default/template/extension/payment/${name}.twig`);
for (const file of [adminFile, ...paymentFiles]) {
    for (const script of scripts(read(file))) { new vm.Script(renderedScript(script)); ++checks; }
}

// Execute the actual delegated admin handlers, including URLs with an unbroken token.
const admin = harness({runReady: false});
const createUrl = 'https://test.invalid/admin/index.php?route=extension/module/mt_jet_credit/createSchema&user_token=current-token';
const deleteUrl = createUrl.replace('createSchema', 'deleteSchema');
admin.$('#form-html').attr('data-jet-promotion-create-url', createUrl).attr('data-jet-promotion-delete-url', deleteUrl);
for (const [field, value] of Object.entries({id: '42_43', percent: '1.40', meseci: '3_6_12', price: '75.00', start: '2026-01-01', end: '2027-01-01'})) {
    admin.$('#module_mt_jet_credit_product_' + field + (field === 'percent' ? ' option:selected' : '')).val(value);
}
vm.runInContext(renderedScript(scripts(read(adminFile)).join('\n')), admin.context);
admin.fire('document', 'click', '#module_mt_jet_credit_btn_add');
let request = admin.requests.at(-1);
check(request.url === createUrl && request.type === 'post', 'create uses tokenized admin POST URL');
check(request.data.mt_jet_credit_product_id === '42_43', 'composite create value remains a string');
request.success({success: 'success', exist: ''});
check(admin.location.reloadCount === 1, 'successful create reloads');
request.success({success: 'success', exist: 'Вече имате такъв филтър!'});
check(admin.alerts.at(-1) === 'Вече имате такъв филтър!', 'duplicate GUI remains operable');
const button = admin.$('delete-button').attr('data-promotion-id', '7');
admin.fire('document', 'click', '.jet-delete-promotion', button);
request = admin.requests.at(-1);
check(request.url === deleteUrl && request.data.promotion_id === '7', 'delete sends row ID to admin');
check(Object.keys(request.data).length === 1, 'delete does not send product selector or store scope');
request.success({success: 'success'});
check(admin.location.reloadCount === 3, 'successful delete reloads');
request.error({responseText: 'PRIVATE /srv/store/config.php 1234567890'});
check(!admin.alerts.at(-1).includes('PRIVATE'), 'admin errors are fixed text');

// Actual shared quote + submission client. Server/SMTP remain capture doubles.
const shared = read('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js');
function reply(context, scheme, enabled = false, token = 'signed-offer') {
    return {success: 'success', currency: 'EUR', context, scheme, eligible: true,
        allowed_terms: [3, 6, 9, 10, 12], calculation: {amount: '240.00', principal: '240.00', monthly: '23.36',
            term: 12, gpr: '33.99', glp: '29.88', total: '280.32'},
        previews: {jet: {monthly: '23.36'}, jet_card: {monthly: '22.40'}}, offer: token,
        application_intent: 'stable-server-intent', submission_enabled: enabled, expires_in: 1800};
}
function mount(context, scheme, application = 'stable-server-intent') {
    const h = harness();
    const prefix = context === 'checkout' && scheme === 'jet_card' ? '#jet_card_' : '#jet_';
    h.$('#jet_finance_context').attr('data-context', context).attr('data-scheme', scheme).attr('data-csrf', 'csrf').attr('data-intent',application).attr('data-product-id', '42');
    h.$(prefix + 'parva').val('0'); h.$(prefix + 'vnoski').val('12');
    h.$('#product input[name="quantity"]').val('2');
    h.optionNodes.push({name: 'option[10]', value: '100', type: 'select-one'},
        {name: 'option[11][]', value: '101', type: 'checkbox', checked: true},
        {name: 'option[11][]', value: '102', type: 'checkbox', checked: false});
    vm.runInContext(shared, h.context);
    if (context === 'checkout') vm.runInContext(scripts(read(paymentFiles[scheme === 'jet_card' ? 1 : 0])).join('\n'), h.context);
    else vm.runInContext(read('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_' + (context === 'product' ? 'products' : 'cart') + '.js'), h.context);
    return {h, prefix};
}
for (const [context, scheme] of [['product', 'jet'], ['cart', 'jet'], ['checkout', 'jet'], ['checkout', 'jet_card']]) {
    const {h, prefix} = mount(context, scheme);
    request = h.requests.at(-1);
    check(request.url.endsWith(context === 'product' ? '/jet_calculate' : '/jet_calculate_cart'), 'native quote endpoint ' + context);
    check(request.data.jet_vnoski === '' && request.data.jet_csrf === 'csrf', 'server initial term / CSRF');
    check(!Object.keys(request.data).some(key => /price|purcent|total|vnoska|egn|name|email|phone/.test(key)), 'quote has no client financial authority or identity');
    if (context === 'product') {
        check(request.data.jet_quantity === '2' && request.data.jet_product_id === '42', 'product identity/quantity only');
        check(JSON.stringify(request.data.option) === JSON.stringify({'10': '100', '11': ['101']}), 'selected options only');
    }
    request.success(reply(context, scheme));
    check(h.$(prefix + 'priceall').value === '240.00' && h.$(prefix + 'priceall').isText, 'server amount inserted as text');
    check(h.$('#jet_finance_context').value === 'signed-offer' && h.timers.at(-1).delay === 1800000, 'signed token / expiry retained');
    check(h.$('#button-confirm').properties.disabled === true && h.$('#buy2_jetcredit').properties.disabled === true, 'unavailable dependency keeps submission disabled');
    const count = h.requests.length;
    h.fire('document', 'click.jetcredit', '#buy2_jetcredit, #button-confirm');
    check(h.requests.length === count, 'no submit without server activation');
    h.timers.at(-1).fn();
    check(h.$('#jet_finance_context').value === '' && h.$(prefix + 'priceall').value === '', 'expiry clears token and amounts');
    h.fire('document', 'change.jetcredit', prefix + 'vnoski');
    const newer = h.requests.at(-1); request.success(reply(context, scheme, true, 'stale'));
    check(h.$('#jet_finance_context').value === '', 'late quote cannot replace current state');
    const hostile = '<img src=x onerror=alert(1)> PRIVATE EGN 1234567890';
    newer.error({responseText: hostile, responseJSON: {code: hostile}});
    check(!String(h.$('#jet_finance_notice').value).includes('PRIVATE') && h.alerts.length === 0 && h.logs.length === 0, 'safe fixed diagnostics, no HTTP/PII output');
    h.fire('document', 'change.jetcredit', prefix + 'vnoski');
    h.requests.at(-1).success(reply(context, scheme, true));
    check(h.$('#button-confirm').properties.disabled === true, 'server capability still requires customer consent');
    for (const selector of ['#uslovia', '#uslovia1', '#uslovia2', prefix + 'uslovia', prefix + 'uslovia1']) h.$(selector).prop('checked', true);
    for (const [key, value] of Object.entries({name: 'Иван', lastname: 'Петров', phone: '+359888123456', email: 'ivan@example.test', egn: '9001010000', lname: ''})) h.$('#jet_' + key).val(value);
    h.$(prefix + 'egn').val('9001010000');
    h.fire('document','change.jetcredit input.jetcredit','#uslovia, #uslovia1, #uslovia2, #jet_name, #jet_lastname, #jet_phone, #jet_email, #jet_egn, #jet_uslovia, #jet_uslovia1, #jet_card_egn, #jet_card_uslovia, #jet_card_uslovia1');
    check(h.$('#button-confirm').properties.disabled === false,'capability and consent enable submission');
    if (context !== 'checkout') {
        const before = h.requests.length;
        h.fire('document', 'click.jetcredit', '#buy_jetcredit');
        check(h.requests.length === before, 'first popup step never sends bank application');
    }
    h.fire('document', 'click.jetcredit', '#buy2_jetcredit, #button-confirm');
    const submission = h.requests.at(-1);
    check(submission.url.endsWith(context === 'checkout' ? '/' + scheme + '/confirm' : context === 'product' ? '/jet_send' : '/jet_send_cart'), 'correct protected public submission route');
    check(submission.data.jet_intent === 'stable-server-intent' && submission.data.jet_offer === 'signed-offer' && submission.data.jet_csrf === 'csrf', 'intent/offer/CSRF submitted together');
    check(!Object.keys(submission.data).some(key => /price|purcent|total|vnoska/.test(key)), 'browser sends no bank totals/APR');
    check(submission.data[scheme === 'jet_card' && context === 'checkout' ? 'jet_card_uslovia' : 'jet_uslovia'] === '1', 'canonical server consent flag');
    const sentCount = h.requests.length;
    h.fire('document', 'click.jetcredit', '#buy2_jetcredit, #button-confirm');
    check(h.requests.length === sentCount, 'busy UI cannot submit twice');
    submission.error({status: 0, responseText: hostile});
    check(h.requests.length === sentCount && !String(h.$('#jet_finance_notice').value).includes('PRIVATE'), 'unknown network result has no automatic retry/raw output');
    h.fire('document', 'click.jetcredit', '#buy2_jetcredit, #button-confirm');
    const replay = h.requests.at(-1);
    check(replay.data === submission.data && replay.data.jet_intent === 'stable-server-intent', 'explicit status replay retains original intent/data');
    replay.success({success: 'success', code: context === 'cart' ? 'manual_registered' : 'transport_accepted', redirect: 'https://test.invalid/index.php?route=extension/mt_jet_credit/success&receipt=signed'});
    check(h.location.href.includes('route=extension/mt_jet_credit/success'), 'only owned safe receipt redirect used');
    check(h.$('#button-confirm').properties.disabled === true, 'completion locks submit UI');
}
// Quote refresh/back can return an existing durable outcome without another POST.
const {h: completed} = mount('checkout', 'jet');
completed.requests.at(-1).success({success:'success', application_intent:'stable-server-intent', existing_outcome:'manual_registered', redirect:'https://test.invalid/index.php?route=extension/mt_jet_credit/success&receipt=signed'});
check(completed.requests.length === 1 && !completed.location.href.includes('receipt=signed') && completed.$('#jet_finance_notice').isText, 'completed checkout displays historical outcome without automatic navigation or renewal');
const {h: hostileRedirect} = mount('checkout','jet');
hostileRedirect.requests.at(-1).success({success:'success',application_intent:'stable-server-intent',existing_outcome:'transport_accepted',redirect:'https://evil.invalid/index.php?route=extension/mt_jet_credit/success'});
check(hostileRedirect.location.href === 'https://test.invalid/index.php','foreign redirect rejected');
// Explicit new purchase: actual callbacks, four paths, delayed old responses and retry.
for (const [context, scheme] of [['product','jet'],['cart','jet'],['checkout','jet'],['checkout','jet_card']]) {
    const prior = 'a'.repeat(64), next = 'b'.repeat(64);
    const {h} = mount(context,scheme,prior), oldQuote = h.requests.at(-1);
    oldQuote.success({success:'success',application_intent:prior,existing_outcome:'manual_registered',redirect:'https://test.invalid/index.php?route=extension/mt_jet_credit/success&receipt=old'});
    check(h.requests.length===1 && !h.location.href.includes('receipt=old'),'revisit shows outcome without automatically starting a purchase');
    h.fire('document','click.jetcredit','#jet_new_purchase'); const firstTransition=h.requests.at(-1);
    check(firstTransition.url.endsWith('/new_purchase') && firstTransition.type==='POST' && firstTransition.data.jet_intent===prior && firstTransition.data.jet_csrf==='csrf','explicit CSRF-protected transition uses immutable prior intent');
    const count=h.requests.length;h.fire('document','click.jetcredit','#jet_new_purchase');check(h.requests.length===count,'busy new-purchase button cannot branch');
    firstTransition.error({status:0});check(h.$('#jet_finance_context').attributes['data-intent']===prior && !h.location.href.includes('receipt='),'failed transition has no false success or local renewal');
    h.fire('document','click.jetcredit','#jet_new_purchase'); const retry=h.requests.at(-1);
    check(retry.data.jet_transition===firstTransition.data.jet_transition,'uncertain transition retry preserves idempotency token');
    firstTransition.success({success:'success',previous_intent:prior,application_intent:next});
    check(h.$('#jet_finance_context').attributes['data-intent']===prior,'late prior transition callback ignored');
    retry.success({success:'success',previous_intent:prior,application_intent:next});
    if(context==='checkout'){const native=h.requests.at(-1);check(native.url.endsWith('route=checkout/confirm') && native.data.jet_intent===next,'new checkout requests a native server draft');native.success('<native checkout>');}
    const fresh=h.requests.at(-1);check(fresh.data.jet_intent===next,'fresh quote carries new immutable identity');
    oldQuote.success({...reply(context,scheme,true,'old-offer'),application_intent:prior});
    check(h.$('#jet_finance_context').attributes['data-intent']===next && h.$('#jet_finance_context').value==='','delayed old quote cannot overwrite a new generation');
    fresh.success({...reply(context,scheme,true,'fresh-offer'),application_intent:prior});
    check(h.$('#jet_finance_context').value==='','wrong-generation quote rejected even with current AJAX sequence');
    fresh.success({...reply(context,scheme,true,'fresh-offer'),application_intent:next});
    check(h.$('#jet_finance_context').value==='fresh-offer','fresh matching quote accepted');
}
console.log(`T-ESCAPE / JS PASS: ${checks} checks; actual admin/quote/submit/replay/error callbacks, no network.`);
