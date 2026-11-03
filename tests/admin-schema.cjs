'use strict';

// Dependency-free DOM/event/AJAX and HTML-attribute renderer doubles; no browser/store requests.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const template = fs.readFileSync('upload/admin/view/template/extension/module/mt_jet_credit.twig', 'utf8');
let checks = 0;
const check = (condition, message) => { checks++; assert.ok(condition, message); };

const attribute = "{{ schemes.jet_product_id|e('html_attr') }}";
const buttonTemplate = template.match(/<button\b[^>]*data-jet-schema-delete[^>]*>[\s\S]*?<\/button>/)?.[0];
const inputTemplate = template.match(/<input\b[^>]*readonly[^>]*schemes\.jet_product_id[^>]*>/)?.[0];
check(!!buttonTemplate && !!inputTemplate, 'identifier markup exists');
check(buttonTemplate.includes('data-jet-product-id="' + attribute + '"'), 'identifier uses explicit html_attr escaping');
check(inputTemplate.includes('value="' + attribute + '"'), 'readonly value uses explicit html_attr escaping');
check(!buttonTemplate.includes('onclick'), 'inline executable identifier removed');
for (const markup of ['type="button"', 'data-toggle="tooltip"', 'title="Изтрий филтъра."', 'class="btn btn-danger"', '<i class="fa fa-trash"></i>&nbsp;Изтрий']) {
 check(buttonTemplate.includes(markup), 'original button presentation retained: ' + markup);
}
check(!template.includes('../index.php?route=extension/mt_jet_credit/jetcredit/'), 'obsolete catalog AJAX URLs removed');
for (const action of ['createSchema', 'deleteSchema']) {
 check(template.includes('url: {{ ' + action + '_url_json|raw }},'), 'controller JSON URL used for ' + action);
}
const script = template.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\{\{([\s\S]*?)\}\}/g, (_, expression) => {
 if (expression.includes('styles_for_js') || expression.includes('labels_json')) return '{}';
 if (expression.includes('_url_json')) return JSON.stringify('https://test.invalid/index.php?route=admin&user_token=token');
 if (expression.includes('default(0)')) return '0';
 throw new Error('Unexpected Twig expression in admin JavaScript: ' + expression);
});
new vm.Script(script);
check(true, 'complete admin JavaScript compiles with representative Twig values');

// Simulates the native html_attr filter contract and HTML parser entity decoding, not Twig itself.
const escapeAttribute = (value) => value.replace(/[^a-zA-Z0-9,._-]/gu, (char) => '&#x' + char.codePointAt(0).toString(16) + ';');
const decodeAttribute = (value) => value.replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)));
function render(markup, id) { return markup.replace(attribute, escapeAttribute(id)); }
function parseAttributes(markup) {
 const startTag = markup.match(/^<\w+\b([^>]*)>/)[1];
 const result = {};
 const pattern = /([\w-]+)(?:="([^"]*)")?/g;
 let match;
 while ((match = pattern.exec(startTag))) result[match[1]] = decodeAttribute(match[2] || '');
 return result;
}

const marker = "\t$(document).off('click.jetSchemaDelete'";
const start = template.indexOf(marker);
const functionStart = template.indexOf('\tconst deleteSchema =', start);
check(start !== -1 && functionStart > start, 'single delegated handler precedes delete function');
const binding = template.slice(start, functionStart);
const url = 'https://test.invalid/shop/renamed-admin/index.php?route=extension/module/mt_jet_credit/deleteSchema&user_token=token';
const source = template.slice(start, template.indexOf('</script>', start))
 .replace('{{ deleteSchema_url_json|raw }}', JSON.stringify(url));
check(!source.includes('{{'), 'tested delete JavaScript has no unresolved Twig');
new vm.Script(source);

const document = {}, handlers = new Map(), requests = [], alerts = [];
let reloads = 0;
const context = vm.createContext({ document, window: { location: { reload() { reloads++; } } }, alert: (text) => alerts.push(text) });
function $(target) {
 if (target === document) return {
  off(name, selector) { handlers.delete(name + ':' + selector); return this; },
  on(name, selector, fn) { handlers.set(name + ':' + selector, fn); return this; }
 };
 return { attr(name) { return target.attributes[name]; } };
}
$.ajax = (config) => requests.push(config);
context.$ = $;
vm.runInContext(source, context);
vm.runInContext(binding, context);
vm.runInContext(binding, context);
check(handlers.size === 1, 'rebinding retains one namespaced delegated handler');

function activate(button, kind) {
 let prevented = false;
 // Native type=button activation (including Enter/Space) emits click, never form submit.
 check(button.attributes.type === 'button', kind + ' keeps native non-submit button type');
 const event = { preventDefault() { prevented = true; } };
 for (const handler of handlers.values()) handler.call(button, event);
 check(prevented, kind + ' prevents default');
}
for (const id of ['42', '*', '00042', "a'b", 'a"b', 'a\\b', '&#39;', '&quot;', '</script>', "');globalThis.x=1;//", '"><img src=x>', 'я']) {
 const buttonMarkup = render(buttonTemplate, id);
 const attrs = parseAttributes(buttonMarkup);
 const inputAttrs = parseAttributes(render(inputTemplate, id));
 check(attrs['data-jet-product-id'] === id, 'button identifier exact round-trip: ' + id);
 check(inputAttrs.value === id, 'readonly identifier exact round-trip: ' + id);
 check(!Object.keys(attrs).some((name) => /^on/i.test(name)), 'no executable attributes created');
 check(!Object.keys(inputAttrs).some((name) => /^on/i.test(name)), 'readonly value cannot create executable attributes');
 check(!buttonMarkup.includes('<img') && !buttonMarkup.includes('</script>'), 'payload stays within quoted attribute');
 for (const kind of ['pointer', 'keyboard']) {
  const before = requests.length;
  activate({ attributes: attrs }, kind);
  check(requests.length === before + 1, 'one activation sends exactly one request');
  const request = requests.at(-1);
  check(request.data.mt_jet_credit_product_id === id, 'AJAX receives exact string');
  check(request.type === 'post' && request.dataType === 'json' && request.url === url, 'native admin POST JSON contract preserved');
 }
}
check(context.x === undefined, 'identifier never evaluated as JavaScript');
const request = requests.at(-1);
request.success({ success: 'success' });
check(alerts.at(-1) === 'Успешно изтрихте филтъра.' && reloads === 1, 'successful delete alert/reload preserved');
request.success({ success: 'unsuccess' });
check(reloads === 1 && alerts.length === 1, 'failed operation cannot trigger success UI');
// jQuery dataType=json rejects the native HTML login/permission response before success is called.
try { JSON.parse('<html>native login</html>'); assert.fail('HTML must not parse as JSON'); } catch (error) { check(error instanceof SyntaxError, 'expired login HTML is a JSON parse failure'); }
check(reloads === 1 && alerts.length === 1, 'expired login never reports success');
console.log(`PASS ${checks} admin schema DOM/AJAX/context checks; renderer and DOM doubles only`);
