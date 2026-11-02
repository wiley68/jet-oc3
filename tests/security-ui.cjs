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
    let now = 0;
    const optionNodes = [];
    const document = {querySelectorAll: () => optionNodes};
    const location = {origin: 'https://test.invalid', protocol: 'https:', href: 'https://test.invalid/index.php', reloadCount: 0, reload() { this.reloadCount++; }};
    class Element {
        constructor(key) { this.key = key; this.length = 1; this.value = '0'; this.attributes = {}; this.properties = {}; this.styles = {}; this.classes = new Set(); this.visible = true; }
        ready(fn) { if (runReady) fn($); return this; }
        on(event, selector, fn) {
            if (typeof selector === 'function') fn = selector;
            handlers.push({key: this.key, event, selector: typeof selector === 'string' ? selector : null, fn});
            return this;
        }
        off(namespace) { if (namespace) for (let i=handlers.length-1;i>=0;i--) if (handlers[i].key===this.key && handlers[i].event.includes(namespace)) handlers.splice(i,1); return this; }
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
        hasClass(value) { return inputGroup || this.classes.has(value); }
        is() { return false; }
        eq() { return this; }
        first() { return this; }
        addClass(value) { this.className = value; value.split(' ').forEach(x=>this.classes.add(x)); return this; }
        removeClass(value) { if(value)value.split(' ').forEach(x=>this.classes.delete(x));return this; }
        remove() { return this; }
        show() { this.visible=true; return this; }
        hide() { this.visible=false; return this; }
        css(name,value) { if(typeof name==='object')Object.assign(this.styles,name);else if(value===undefined)return this.styles[name];else this.styles[name]=value;return this; }
        toggle(value) { this.visible=!!value; return this; }
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
        URL, setTimeout(fn, delay) { timers.push({fn, delay, due:now+delay, cancelled:false, fired:false}); return timers.length; }, clearTimeout(id) { if(timers[id-1])timers[id-1].cancelled=true; }});
    return {$, context, requests, alerts, logs, handlers, inserted, location, timers, optionNodes,
        tick(ms) { const until=now+ms; for (;;) { const next=timers.filter(t=>!t.cancelled&&!t.fired&&t.due<=until).sort((a,b)=>a.due-b.due)[0]; if(!next)break; now=next.due; next.fired=true; next.fn(); } now=until; },
        fire(key, event = 'click', selector = null, target, extra = {}) {
            const handler = handlers.find(h => h.key === key && h.event === event && h.selector === selector);
            assert.ok(handler, `handler ${key} ${event} ${selector}`);
            handler.fn.call(target, {preventDefault() {}, ...extra});
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

// FIX-04: actual shared popup handlers, visual/real disabled state and loader lifecycle.
const formEvent = '#uslovia, #uslovia1, #uslovia2, #jet_name, #jet_lastname, #jet_phone, #jet_email, #jet_egn, #jet_uslovia, #jet_uslovia1, #jet_card_egn, #jet_card_uslovia, #jet_card_uslovia1';
for (const context of ['product','cart']) {
    const {h} = mount(context,'jet');
    const popup = h.$('#jet-product-popup-container');
    check(popup.hasClass('jet_offer_loading') && popup.attr('aria-busy')==='true','loader visible immediately on asynchronous quote');
    const first = h.requests.at(-1);
    h.fire('document','click.jetcredit','#btn_jet, #btn_jet_card',{id:'btn_jet'});
    const second = h.requests.at(-1);
    check(first.aborted && popup.hasClass('jet_offer_loading'),'replacement quote aborts old request without hiding new loader');
    first.error({status:0});
    check(popup.hasClass('jet_offer_loading'),'late old failure cannot hide new loader');
    second.success(reply(context,'jet',true));
    check(!popup.hasClass('jet_offer_loading') && popup.attr('aria-busy')==='false','latest success clears loader');
    check(h.$('#buy_jetcredit').prop('disabled')===true && h.$('#buy_jetcredit').css('opacity')===0.5,'step one requires both consents visually and functionally');
    h.$('#uslovia').prop('checked',true);h.$('#uslovia1').prop('checked',true);
    h.fire('document','change.jetcredit input.jetcredit',formEvent);
    check(h.$('#buy_jetcredit').prop('disabled')===false && h.$('#buy_jetcredit').css('opacity')===1,'ready step one is visually active');
    h.$('#jet_parva').val('25');h.$('#jet_vnoski').val('9');
    h.fire('document','change.jetcredit','#jet_vnoski');
    const selected=h.requests.at(-1);
    check(selected.data.jet_parva==='25' && selected.data.jet_vnoski==='9','changed financial selection sent for authoritative quote');
    const selectedReply=reply(context,'jet',true,'selected-offer');selectedReply.calculation.term=9;selected.success(selectedReply);
    const count=h.requests.length, offer=h.$('#jet_finance_context').val(), term=h.$('#jet_vnoski').val();
    h.fire('document','click.jetcredit','#buy_jetcredit');
    check(!h.$('#jet_step_1').visible && h.$('#jet_step_2').visible,'forward navigation switches steps');
    for(const [key,value]of Object.entries({name:'Иван',lastname:'Петров',email:'ivan@example.test',phone:'+359888123456',egn:'9001010000'}))h.$('#jet_'+key).val(value);
    h.$('#uslovia2').prop('checked',true);h.fire('document','change.jetcredit input.jetcredit',formEvent);
    check(h.$('#buy2_jetcredit').prop('disabled')===false && h.$('#buy2_jetcredit').css('opacity')===1,'personal fields and all consents enable actual/visual submit together');
    for(const checkbox of ['#uslovia','#uslovia1','#uslovia2']) {
        h.$(checkbox).prop('checked',false);h.fire('document','change.jetcredit input.jetcredit',formEvent);
        check(h.$('#buy2_jetcredit').prop('disabled')===true && h.$('#buy2_jetcredit').css('opacity')===0.5,'each consent revocation disables submit');
        h.fire('document','click.jetcredit','#buy2_jetcredit, #button-confirm');
        check(h.requests.length===count,'disabled submit cannot execute even with programmatic click');
        h.$(checkbox).prop('checked',true);
    }
    h.$('#jet_email').val('bad');h.fire('document','change.jetcredit input.jetcredit',formEvent);
    check(h.$('#buy2_jetcredit').prop('disabled')===true,'invalid email keeps submit disabled');h.$('#jet_email').val('ivan@example.test');
    for(let i=0;i<3;i++) {
        h.fire('document','click.jetcredit','#back2_jetcredit');
        check(h.$('#jet_step_1').visible && !h.$('#jet_step_2').visible,'back restores step one');
        h.fire('document','click.jetcredit','#buy_jetcredit');
    }
    check(h.requests.length===count && h.$('#jet_finance_context').val()===offer && h.$('#jet_vnoski').val()===term && h.$('#jet_parva').val()==='25','navigation preserves signed quote/term and performs no AJAX order/submission');
    check(h.$('#jet_name').val()==='Иван' && h.$('#jet_email').val()==='ivan@example.test','navigation never overwrites profile corrections');
    h.fire('document','change.jetcredit input.jetcredit',formEvent);
    h.fire('document','click.jetcredit','#buy2_jetcredit, #button-confirm');
    check(h.requests.length===count+1 && popup.hasClass('jet_offer_loading') && h.$('#buy2_jetcredit').prop('disabled')===true,'submission loading locks actual button');
    h.fire('document','click.jetcredit','#buy2_jetcredit, #button-confirm');
    check(h.requests.length===count+1,'loading submission cannot duplicate');
    h.requests.at(-1).error({status:400,responseJSON:{code:'invalid_customer_input'}});
    check(!popup.hasClass('jet_offer_loading'),'submission error clears loader');
}
for(const action of ['error','cancel','destroy','mismatch']) {
    const {h}=mount('product','jet'), quote=h.requests.at(-1), popup=h.$('#jet-product-popup-container');
    if(action==='error')quote.error({status:0});
    if(action==='mismatch')quote.success({...reply('product','jet',true),application_intent:'wrong'});
    if(action==='cancel')h.fire('document','click.jetcredit','#close_jetcredit, #back_jetcredit');
    if(action==='destroy')h.context.window.JetCreditOffers.mount().destroy();
    check(!popup.hasClass('jet_offer_loading') && popup.attr('aria-busy')==='false','loader cleared for '+action);
    if(action==='cancel'||action==='destroy') {
        quote.success(reply('product','jet',true,'late-token'));
        check(h.$('#jet_finance_context').val()==='' && !popup.hasClass('jet_offer_loading'),'late cancelled response cannot restore offer/loading');
    }
}
const {h:remount}=mount('product','jet');
remount.context.window.JetCreditOffers.mount();
check(remount.handlers.filter(h=>h.selector==='#back2_jetcredit').length===1,'remount has one backward handler');

const {h:lateSubmit}=mount('product','jet');
lateSubmit.requests.at(-1).success(reply('product','jet',true));
for(const selector of ['#uslovia','#uslovia1','#uslovia2'])lateSubmit.$(selector).prop('checked',true);
for(const [key,value]of Object.entries({name:'A',lastname:'B',phone:'123',email:'a@example.test',egn:'9001010000'}))lateSubmit.$('#jet_'+key).val(value);
lateSubmit.fire('document','click.jetcredit','#buy2_jetcredit, #button-confirm');const originalPost=lateSubmit.requests.at(-1);
originalPost.error({status:0});lateSubmit.fire('document','click.jetcredit','#buy2_jetcredit, #button-confirm');const replayPost=lateSubmit.requests.at(-1);
originalPost.success({success:'success',code:'manual_registered',redirect:'https://test.invalid/index.php?route=extension/mt_jet_credit/success&receipt=old'});
check(lateSubmit.$('#jet-product-popup-container').hasClass('jet_offer_loading') && !lateSubmit.location.href.includes('receipt=old'),'late first submit response cannot override active replay/loading');
replayPost.success({success:'success',code:'manual_registered',redirect:'https://test.invalid/index.php?route=extension/mt_jet_credit/success&receipt=current'});
check(!lateSubmit.$('#jet-product-popup-container').hasClass('jet_offer_loading') && lateSubmit.location.href.includes('receipt=current'),'latest replay completion clears loader and navigates');

// FIX-05: virtual clock exercises actual trailing-edge handlers, not wall-clock sleeps.
const downEvent='change.jetcredit input.jetcredit';
for(const [context,scheme]of [['product','jet'],['cart','jet'],['checkout','jet'],['checkout','jet_card']]) {
    const {h,prefix}=mount(context,scheme);
    const quote=(token='fresh',selectedScheme=scheme)=>reply(context,selectedScheme,true,token);
    h.requests.at(-1).success(quote('zero'));
    const edit=value=>{h.$(prefix+'parva').val(value);h.fire('document',downEvent,prefix+'parva');};
    const initial=h.requests.length, visible=h.$(prefix+'priceall').val();
    edit('1');h.tick(200);edit('12');h.tick(200);edit('12.5');
    check(h.requests.length===initial && h.$(prefix+'priceall').val()===visible,'typing retains last visual values without AJAX/flicker');
    check(h.$('#jet_finance_context').val()==='' && h.$('#buy2_jetcredit').prop('disabled')===true,'typing clears exposed token and disables stale submission immediately');
    check(!h.$('#jet_popup_notice').isText && h.$('#jet_finance_notice').val().includes('обновява'),'editing status stays on page without adding popup header text');
    check(h.$('#jet_finance_context').attr('data-quote-state')==='updating' && !h.$('#jet-product-popup-container').hasClass('jet_offer_loading'),'waiting debounce has explicit updating status without spinner flashing');
    h.tick(399);check(h.requests.length===initial,'trailing edge waits full 400 ms after latest edit');
    h.tick(1);check(h.requests.length===initial+1 && h.requests.at(-1).data.jet_parva==='12.5','one authoritative quote after burst uses latest amount');
    h.requests.at(-1).success(quote('positive'));
    edit('012.500');h.tick(400);
    check(h.requests.length===initial+1 && h.$('#jet_finance_context').val()==='positive','equivalent normalized amount reuses unexpired identical quote');
    edit('');h.tick(399);check(h.$(prefix+'parva').val()==='','empty field remains editable until debounce');
    h.tick(1);check(h.$(prefix+'parva').val()==='0' && h.requests.at(-1).data.jet_parva==='0','empty normalizes to zero and requests zero quote');
    h.requests.at(-1).success(quote('zero-again'));
    check(h.$(prefix+'priceall').val()==='240.00' && h.$('#jet_finance_context').val()==='zero-again','zero restores server result/token');
    const zeroCount=h.requests.length;edit('');h.tick(400);
    check(h.requests.length===zeroCount && h.$(prefix+'parva').val()==='0','empty equivalent to current zero avoids redundant recalculation');
    edit('5');h.tick(10);h.fire('document','blur.jetcredit',prefix+'parva');
    check(h.requests.length===zeroCount+1 && h.requests.at(-1).data.jet_parva==='5','blur flushes pending edit immediately');
    h.tick(800);check(h.requests.length===zeroCount+1,'blur cancels trailing timer');
    h.requests.at(-1).success(quote('five'));
    edit('6');h.fire('document','keydown.jetcredit',prefix+'parva',undefined,{key:'Enter'});
    const enter=h.requests.at(-1), enterCount=h.requests.length;
    check(enter.data.jet_parva==='6','Enter flushes latest amount without native submit');
    h.fire('document','blur.jetcredit',prefix+'parva');h.fire('document',downEvent,prefix+'parva');h.tick(800);
    check(h.requests.length===enterCount,'blur/change/Enter for identical in-flight value do not duplicate requests');
    enter.success(quote('six'));
    edit('7');h.tick(100);h.$(prefix+'vnoski').val('9');h.fire('document','change.jetcredit',prefix+'vnoski');
    const termRequest=h.requests.at(-1), termCount=h.requests.length;
    check(termRequest.data.jet_parva==='7' && termRequest.data.jet_vnoski==='9','term change resolves pending amount in one authoritative request');
    h.tick(800);check(h.requests.length===termCount,'term change cancels pending debounce');
    const termReply=quote('term-nine');termReply.calculation.term=9;termRequest.success(termReply);
    edit('8');h.tick(400);const stale=h.requests.at(-1);
    edit('9');h.tick(400);const current=h.requests.at(-1);
    check(stale.aborted,'new edit aborts stale quote');stale.success(quote('stale'));
    check(h.$('#jet_finance_context').val()==='' && h.$('#jet-product-popup-container').hasClass('jet_offer_loading'),'stale success cannot reactivate offer or hide current loader');
    current.error({status:400,responseJSON:{code:'invalid_down_payment'}});
    check(!h.$('#jet-product-popup-container').hasClass('jet_offer_loading') && h.$('#jet_finance_context').val()==='' && h.$('#buy2_jetcredit').prop('disabled')===true,'failed quote clears loading but never enables stale submit');
    check(h.$(prefix+'priceall').val()==='240.00' && h.$('#jet_finance_context').attr('data-quote-state')==='stale','failed quote preserves explicitly stale last visual results');
    edit('');h.tick(400);h.requests.at(-1).success(quote('recovered-zero'));
    check(h.$('#jet_finance_context').val()==='recovered-zero','empty-to-zero recovers after failure');
    if(context!=='checkout') {
        const before=h.requests.length;edit('10');h.tick(100);h.fire('document','click.jetcredit','#btn_jet, #btn_jet_card',{id:'btn_jet_card'});
        check(h.requests.length===before+1 && h.requests.at(-1).data.jet_parva==='10' && h.requests.at(-1).data.jet_card==='1','scheme switch resolves current down payment');
        h.tick(800);check(h.requests.length===before+1,'scheme switch cancels old edit timer');h.requests.at(-1).success(quote('card','jet_card'));
    }
    const beforeClose=h.requests.length;edit('11');h.fire('document','click.jetcredit','#close_jetcredit, #back_jetcredit');h.tick(1000);
    check(h.requests.length===beforeClose && !h.$('#jet-product-popup-container').hasClass('jet_offer_loading'),'close cancels pending timer');
    h.context.window.JetCreditOffers.mount();h.requests.at(-1).success(quote('reopened'));edit('12');
    const beforeDestroy=h.requests.length;h.context.window.JetCreditOffers.mount().destroy();const afterDestroy=h.requests.length;h.tick(1000);
    check(afterDestroy===beforeDestroy+1 && h.requests.length===afterDestroy,'destroy/remount clears old timers and creates no late recalculations');
}
// Disabled continuation cannot use old signed data, even if invoked programmatically.
const {h:continuation}=mount('product','jet');continuation.requests.at(-1).success(reply('product','jet',true,'before-edit'));
continuation.$('#uslovia').prop('checked',true);continuation.$('#uslovia1').prop('checked',true);
continuation.$('#jet_parva').val('15');continuation.fire('document',downEvent,'#jet_parva');
const beforeContinue=continuation.requests.length;continuation.fire('document','click.jetcredit','#buy_jetcredit');
check(continuation.requests.length===beforeContinue+1 && continuation.requests.at(-1).url.endsWith('/jet_calculate'),'continuation flushes quote rather than creating order/submission');
check(!continuation.$('#jet_step_2').visible || continuation.$('#jet_finance_context').val()==='','stale continuation cannot expose a usable offer');
continuation.requests.at(-1).success(reply('product','jet',true,'after-edit'));continuation.fire('document','click.jetcredit','#buy_jetcredit');
check(continuation.$('#jet_step_2').visible && continuation.requests.length===beforeContinue+1,'forward works after fresh authoritative quote');
for(const bad of ['-1','not-a-number','99999999999999999999']) {
    continuation.$('#jet_parva').val(bad);continuation.fire('document',downEvent,'#jet_parva');continuation.tick(400);
    check(continuation.requests.at(-1).data.jet_parva===bad,'invalid input is not silently converted to zero');
    continuation.requests.at(-1).error({status:400,responseJSON:{code:'invalid_money'}});
    check(continuation.$('#jet_finance_context').val()==='','server invalid amount refusal leaves no usable token');
}

const {h:expiredEdit}=mount('product','jet');expiredEdit.requests.at(-1).success(reply('product','jet',true,'expiring'));
const expiryDuringEdit=expiredEdit.timers.at(-1);expiredEdit.$('#jet_parva').val('0.00');expiredEdit.fire('document',downEvent,'#jet_parva');
expiryDuringEdit.fn();const expiredCount=expiredEdit.requests.length;expiredEdit.tick(400);
check(expiredEdit.requests.length===expiredCount+1 && expiredEdit.$('#jet_finance_context').val()==='','equivalent input cannot restore an expired signed offer');
const {h:longZero}=mount('product','jet');longZero.requests.at(-1).success(reply('product','jet',true,'valid-zero'));
longZero.$('#jet_parva').val('0'.repeat(41));longZero.fire('document',downEvent,'#jet_parva');const beforeLongZero=longZero.requests.length;longZero.tick(400);
check(longZero.requests.length===beforeLongZero+1 && longZero.requests.at(-1).data.jet_parva==='0'.repeat(41),'oversized numeric input reaches server validation instead of reusing normalized zero');
check(longZero.$('#jet_finance_context').val()==='' && longZero.$('#buy2_jetcredit').prop('disabled')===true,'oversized numeric input cannot restore valid zero offer');
const {h:submitEdit}=mount('product','jet');submitEdit.requests.at(-1).success(reply('product','jet',true,'old'));
submitEdit.$('#jet_parva').val('25');submitEdit.fire('document',downEvent,'#jet_parva');const submitEditCount=submitEdit.requests.length;
submitEdit.fire('document','click.jetcredit','#buy2_jetcredit, #button-confirm');
check(submitEdit.requests.length===submitEditCount+1 && submitEdit.requests.at(-1).url.endsWith('/jet_calculate'),'submit attempt flushes stale input only to quote, never submission');
check(submitEdit.$('#jet_finance_context').val()==='' && submitEdit.$('#buy2_jetcredit').prop('disabled')===true,'stale signed offer cannot be submitted while flush is pending');
console.log(`T-ESCAPE / JS PASS: ${checks} checks; actual admin/quote/submit/replay/error callbacks, no network.`);
