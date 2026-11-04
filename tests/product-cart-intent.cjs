'use strict';
const fs=require('fs');
const run=require('./calculator-recalculation.cjs');
let checks=0;
const check=(value,label)=>{++checks;if(!value)throw Error(label);};
for(const page of ['products','cart']){
 const source=fs.readFileSync(`upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_${page}.js`,'utf8');
 const css=fs.readFileSync(`upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_${page}.css`,'utf8');
 run({},(environment,quote)=>{
  const offer=(id='c',ref='a')=>({...quote(),jet_intent_id:id.repeat(48),jet_quote_ref:ref.repeat(48)});
  const sends=e=>e.requests.filter(r=>r.config.url.endsWith(page==='cart'?'/jet_send_cart':'/jet_send'));
  const fill=e=>{for(const [key,value] of Object.entries({jet_name:'Test',jet_lastname:'User',jet_egn:'9001010000',jet_phone:'+359888888888',jet_email:'test@example.invalid'}))e.get('#'+key).val(value);};
  {
   const e=environment(source,false,css);check(e.requests[0].config.data.values.jet_intent_id==='','new form asks server for an unpredictable identity');
   e.get('#jet_parva').val('10');e.trigger('#jet_parva','input');e.tick(400);
   check(e.requests.length===1,'overlapping initial calculation waits for server identity');
   e.requests[0].resolve(offer());check(e.requests.length===2&&e.requests[1].config.data.values.jet_intent_id==='c'.repeat(48),'queued recalculation uses first server identity');
   e.requests[1].resolve(offer('c','b'));fill(e);e.trigger('#buy2_jetcredit','click');
   check(sends(e).length===1&&sends(e)[0].config.data.values.jet_intent_id==='c'.repeat(48),'submission carries accepted intent');
   sends(e)[0].resolve({success:'unsuccess',jet_submission_review:true});
   check(e.get('#jet_alert_box').children[0].text().startsWith('Резултатът от изпращането не е потвърден.'),'processing/review uses approved uncertainty text');
   e.get('#jet_alert_box').children[1].click();e.tick(60000);e.trigger('#buy2_jetcredit','click');check(sends(e).length===1,'review response never automatically or manually resubmits same page');
  }
  {
   const a=environment(source,false,css),b=environment(source,false,css);a.requests[0].resolve(offer('c'));b.requests[0].resolve(offer('d'));
   for(const e of [a,b]){fill(e);e.trigger('#buy2_jetcredit','click');}
   check(sends(a)[0].config.data.values.jet_intent_id!==sends(b)[0].config.data.values.jet_intent_id,'independent forms preserve independent server identities');
  }
  {
   const e=environment(source,false,css);e.requests[0].resolve({...offer(),jet_intent_id:undefined});fill(e);e.trigger('#buy2_jetcredit','click');check(!sends(e).length,'quote without durable identity never submits');
  }
  {
   const e=environment(source,false,css);e.requests[0].resolve({...offer(),jet_intent_id:undefined});e.faults.ajax=true;e.trigger('#btn_preizcisli','click');
   e.trigger('#btn_preizcisli','click');check(e.requests.length===2,'synchronous calculation failure does not strand intent bootstrap');
  }
  {
   const e=environment(source,false,css);e.requests[0].resolve(offer());e.trigger('#close_jetcredit','click');e.trigger('#btn_jet','click');
   check(e.requests.at(-1).config.data.values.jet_intent_id==='','independently reopened form requests a new intent');
  }
  {
   const e=environment(source,false,css);e.trigger('#close_jetcredit','click');e.trigger('#btn_jet','click');e.requests[0].resolve(offer());
   check(e.requests.length===2&&e.requests[1].config.data.values.jet_intent_id==='','closed-form response cannot attach its old identity to reopened form');
  }
 });
}
console.log(`PASS ${checks} Product/Cart intent lifecycle JS checks; DOM/AJAX doubles only`);
