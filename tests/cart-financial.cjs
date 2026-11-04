'use strict';
const fs = require('fs');
const run = require('./calculator-recalculation.cjs');
const source = fs.readFileSync('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.js','utf8');
const css = fs.readFileSync('upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.css','utf8');
let checks=0;
const check=(value,label)=>{checks++;if(!value)throw Error(label);};
run({},(environment,quote)=>{
 const offer=(ref='a',extra={})=>({...quote(),jet_quote_ref:ref.repeat(48),...extra});
 const prepare=()=>{
  const e=environment(source,false,css);e.requests[0].resolve(offer());
  for(const [k,v] of Object.entries({jet_name:'Test',jet_lastname:'User',jet_egn:'9001010000',jet_phone:'+359888888888',jet_email:'test@example.invalid'}))e.get('#'+k).val(v);
  return e;
 };
 const sends=e=>e.requests.filter(r=>r.config.url.endsWith('/jet_send_cart'));
 for(const [raw,canonical] of [['12.345','12.35'],['1.005','1.00'],['2.675','2.67'],['0.125','0.13']]){
  const e=prepare();e.get('#jet_parva').val(raw);e.trigger('#jet_parva','input');
  e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'input invalidates before debounce');
  e.tick(400);const r=e.requests.at(-1);check(r.config.data.values.jet_parva===canonical,'independent canonical golden '+raw);
  r.resolve(offer('c'));e.trigger('#buy_jetcredit','click');check(e.get('#jet_parva').val()===canonical,'confirmation canonicalizes input');
  e.trigger('#buy2_jetcredit','click');check(sends(e)[0].config.data.values.jet_quote_ref==='c'.repeat(48),'bound quote transported');
 }
 for(const field of ['jet_products','jet_products_qt','jet_products_pr','jet_products_vr','jet_cart_rows']){
  const e=prepare();e.get('#'+field).val('changed');e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'changed row transport blocks submission '+field);
 }
 for(const extra of [{jet_quote_ref:''},{jet_quote_ref:'x'},{jet_cart_lineage:''},{jet_show_button:false}]){
  const e=environment(source,false,css);e.requests[0].resolve(offer('a',extra));e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'unconfirmed quote blocked');
 }
 {
  const e=prepare();e.get('#jet_parva').val('10');e.trigger('#jet_parva','input');e.tick(400);const old=e.requests.at(-1);
  e.get('#jet_parva').val('20');e.trigger('#jet_parva','input');e.tick(400);const latest=e.requests.at(-1);
  latest.resolve(offer('d'));old.resolve(offer('c'));e.trigger('#buy2_jetcredit','click');check(sends(e)[0].config.data.values.jet_quote_ref==='d'.repeat(48),'out of order response cannot replace latest quote');
 }
 {
  const a=prepare(),b=prepare();b.trigger('#btn_preizcisli','click');const r=b.requests.at(-1);
  check(r.config.data.values.jet_previous_quote==='a'.repeat(48)&&r.config.data.values.jet_cart_lineage==='b'.repeat(48),'explicit prior lineage transport');
  r.resolve(offer('d'));a.trigger('#buy2_jetcredit','click');b.trigger('#buy2_jetcredit','click');
  check(sends(a)[0].config.data.values.jet_quote_ref==='a'.repeat(48)&&sends(b)[0].config.data.values.jet_quote_ref==='d'.repeat(48),'independent browser tabs');
 }
 for(const uncertain of [false,true]){
  const e=prepare();e.trigger('#buy2_jetcredit','click');sends(e)[0].resolve({success:'unsuccess',jet_quote_invalid:true,jet_cart_stale:true,...(uncertain?{order_id:1,code:'bank_uncertain'}:{})});
  const message=e.get('#jet_alert_box').children[0].text();
  check(uncertain?message.startsWith('Резултатът от изпращането не е потвърден.'):message==='Условията на количката са променени. Заявката за финансиране не е изпратена. Моля, обновете количката и направете ново изчисление на финансирането.','stale distinguished from uncertain side effects');
  e.tick(60000);check(sends(e).length===1,'no automatic submission retry');
 }
});
console.log(`PASS ${checks} Cart frontend quote/canonical/race/stale checks`);
