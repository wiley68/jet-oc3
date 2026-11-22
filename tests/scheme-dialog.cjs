'use strict';
const fs = require('fs');
const run = require('./calculator-recalculation.cjs');
const message = 'За избраните условия продуктите в количката не могат да бъдат закупени заедно на изплащане. Моля, изберете друг срок или условия.\n\nАко желаете, можете да закупите на изплащане всеки продукт поотделно.';
let checks = 0;
const check = (value, label) => { ++checks; if (!value) throw Error(label); };
const directory = 'upload/catalog/view/theme/default/template/extension/';
run({}, (environment, quote) => {
 for (const page of ['cart', 'jet', 'jet_card']) for (const cartCard of (page === 'cart' ? [0, 1] : [0])) {
  const cart = page === 'cart', code = cart ? 'jet' : page;
  const template = fs.readFileSync(directory + (cart ? 'mt_jet_credit/creditjet_cart.js' : 'payment/' + page + '.twig'), 'utf8');
  const source = cart ? template : template.slice(template.indexOf('jQuery(document).ready'), template.lastIndexOf('//--></script>'));
  const ids = cart ? null : new Set([...template.split('<script')[0].matchAll(/\bid="([\w-]+)"/g)].map(match => '#' + match[1]));
  const initial = Object.fromEntries(Object.entries({price:'600',parva:'0',vnoski:'12',products:'1_2',products_qt:'1_1',products_pr:'300_300',products_vr:'red_blue',egn:'9001010000'}).map(([key,value]) => ['#'+code+'_'+key,value]));
  const e = environment(source, false, cart ? fs.readFileSync(directory+'mt_jet_credit/creditjet_cart.css','utf8') : '', {...initial,'#jet_card_in':'1'}, ids);
  if (cart) {
   const preview = index => ({jet_card:index,jet_vnoski:12,jet_priceall:'600.00',['jet_vnoska'+(index ? '_card' : '')]:'60.00'});
   e.requests[0].resolve({success:'success',jet_cart_schemes:{jet:preview(0),jet_card:preview(1)}});
   e.trigger(cartCard ? '#btn_jet_card' : '#btn_jet','click');
  } else {
   check(!/class="[^"]*jet_offer_result[^"]*" hidden/.test(template), page+': all rows exist visibly before any AJAX result');
   e.get('#'+code+'_uslovia').prop('checked',true);e.get('#'+code+'_uslovia1').prop('checked',true);
  }
  const accept = request => request.resolve(cart ? quote() : {...quote(),__mockQuote:false,
   jet_priceall:'600.00',jet_vnoska_card:'50.00',jet_gpr_card:'10',jet_glp_card:'10',jet_obshto_card:'700',jet_obshto_card_second:'700',jet_vnoska_card_second:'0'});
  const term = '#'+code+'_vnoski', down = '#'+code+'_parva', confirm = cart ? '#buy_jetcredit' : '#button-confirm';
  const select = value => {e.get(term).val(value);e.get(term).focus();e.trigger(term,'change');return e.requests.at(-1);};
  const reject = request => request.resolve({success:'unsuccess',jet_scheme_invalid:true});
  const retry = () => {
   if (cart) e.trigger(term,'change');
   else e.trigger(page==='jet' ? '#btn_preizcisli' : '#btn_card_preizcisli','click');
   return e.requests.at(-1);
  };
  accept(e.requests.at(-1));
  check(!e.get('#jet_alert_box').attributes?.['data-jet-scheme-dialog'], page+': valid initial offer has no information dialog');
  reject(select('9'));
  const box=e.get('#jet_alert_box'), button=box.children.at(-1);
  check(box.length===1 && box.children.length===3, page+': single dialog with title, message and button only');
  check(box.children[0].text()==='Информация' && box.children[1].text()===message, page+': exact shared title and two-paragraph message');
  check(box.children.filter(child=>child.key.startsWith('<button')).length===1 && button.text()==='Добре' && button.attributes.type==='button', page+': one non-submit acknowledgement button');
  check(box.attributes.role==='dialog' && box.attributes['aria-modal']==='true' && box.attributes['aria-labelledby']==='jet_scheme_title' && box.attributes['aria-describedby']==='jet_scheme_message', page+': dialog has accessible labels');
  check(button.focused && e.get('#jet_alert_overlay').hasClass('show'), page+': focus and backdrop activate');
  check(Number(box.attributes.css.zIndex)>Number(e.get('#jet_alert_overlay').css('zIndex')) && Number(e.get('#jet_alert_overlay').css('zIndex'))>4000000, page+': information stacks above Cart popup');
  check(box.css('maxWidth')==='calc(100vw - 32px)' && box.css('maxHeight')==='calc(100vh - 32px)' && box.css('overflowY')==='auto', page+': narrow and short screens retain the button');
  check(e.get(confirm).prop('disabled') && e.get('#'+code+(cart ? '_vnoska_popup' : '_vnoska')).text()==='', page+': refusal cannot display an active offer or continue');
  if (cart) check(e.get('#jet-product-popup-container').visible, 'Cart: underlying financial popup stays open');
  else check(!e.get('.jet_offer_result').prop('hidden') && e.get('#jet_offer_status').text()==='', page+': complete financial rows and no inline refusal');
  e.get(down).focus();check(button.focused && !e.get(down).focused,page+': focus cannot escape the information dialog');
  for (const shiftKey of [false,true]) {
   const event=e.trigger('#jet_alert_box','keydown',{key:'Tab',shiftKey});
   check(event.defaultPrevented && button.focused,page+': Tab and Shift+Tab stay on the only button');
  }
  // Duplicate callbacks cannot add a second active box.
  const request=e.requests.at(-1);request.config.success({success:'unsuccess',jet_scheme_invalid:true});
  check(e.get('#jet_alert_box')===box && box.length===1,page+': repeated refusal retains one active dialog');
  button.click();
  check(!box.length && !e.get('#jet_alert_overlay').hasClass('show') && e.get(term).focused,page+': acknowledgement closes only information and restores the financial control');
  if (cart) check(e.get('#jet-product-popup-container').visible,'Cart: acknowledgement preserves the main popup');
  reject(retry());check(!e.get('#jet_alert_box').length,page+': unchanged rejected conditions do not reopen information');
  const count=e.requests.length;e.trigger(confirm,'click');check(e.requests.length===count,page+': acknowledgement never grants continuation');
  reject(select('6'));check(e.get('#jet_alert_box').length===1 && e.get('#jet_alert_box')!==box,page+': new invalid customer choice opens fresh information');
  const escape=e.trigger('#jet_alert_box','keydown',{key:'Escape'});
  check(escape.defaultPrevented && !e.get('#jet_alert_box').length && e.get(term).focused,page+': Escape returns to controls without closing the financial popup');
  e.get(down).val('10');e.trigger(down,'input');
  if(cart) e.tick(400);else retry();reject(e.requests.at(-1));
  check(e.get('#jet_alert_box').length===1,page+': changed downpayment can show the refusal again');
  accept(select('12'));
  check(!e.get('#jet_alert_box').length && e.get('#'+code+(cart ? '_vnoska_popup' : '_vnoska')).text()==='50.00',page+': valid correction dismisses information and restores figures');
  if(!cart) check(!e.get(confirm).prop('disabled'),page+': valid correction restores confirmation');
  // Cart also requires the original consent checkboxes before continuation.
  if(cart){for(const id of ['uslovia','uslovia1','uslovia2']){e.get('#'+id).prop('checked',true);e.trigger('#'+id,'click');}check(!e.get(confirm).prop('disabled'),'Cart: valid correction restores continuation with consent');}
  const stale=select('9');accept(select('12'));reject(stale);
  check(!e.get('#jet_alert_box').length,page+': delayed refusal never opens over a newer valid offer');
  const old=select('9');reject(select('6'));accept(old);
  check(e.get('#jet_alert_box').length===1 && e.get(confirm).prop('disabled'),page+': delayed success cannot clear a newer refusal');
  if(cart){e.get('#jet_alert_box').children.at(-1).click();e.trigger('#close_jetcredit','click');check(!e.get('#jet-product-popup-container').visible && !e.get('#jet_alert_overlay').hasClass('show'),'Cart: normal popup close still clears backdrop');}
  else{e.switchPayment(page==='jet'?'jet_card':'jet');check(!e.get('#jet_alert_box').length && !e.get('#jet_alert_overlay').hasClass('show'),page+': method change dismisses information and backdrop');}
  if(!cart){
   const first=environment(source,false,'',initial,ids);reject(first.requests[0]);
   check(first.get('#jet_alert_box').length===1 && !first.get('.jet_offer_result').prop('hidden') && first.get('#button-confirm').prop('disabled'),page+': refusal on the first response still preserves the complete block');
   first.get('#jet_alert_box').children.at(-1).click();
   check(first.get(term).focused,page+': initial refusal returns focus to financial controls');
   const detached=environment(source,false,'',initial,ids), pending=detached.requests[0];detached.replacePanel();reject(pending);
   check(!detached.get('#jet_alert_box').attributes?.['data-jet-scheme-dialog'],page+': detached panel refusal cannot open a dialog');
  }else{
   e.get(cartCard ? '#btn_jet_card' : '#btn_jet').focus();
   e.trigger(cartCard ? '#btn_jet_card' : '#btn_jet','click');const initialRejection=e.requests.at(-1);reject(initialRejection);e.get('#jet_alert_box').children.at(-1).click();
   check(e.get(term).focused,'Cart: initial refusal returns focus inside the popup rather than to the entry button');
   const pending=select('9');e.trigger('#close_jetcredit','click');reject(pending);
   check(!e.get('#jet_alert_box').length && !e.get('#jet-product-popup-container').visible,'Cart: late refusal after closing cannot reopen either dialog');
  }
 }
});
console.log(`PASS ${checks} separate scheme information dialog checks`);
