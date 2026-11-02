<?php
require __DIR__ . '/submission-bootstrap.php';
// Native OC3 currency-service double; rates are synthetic, never store configuration.
foreach (array('EUR'=>1, 'RON'=>2, 'USD'=>0.5, 'BGN'=>1) as $base=>$rate) {
 foreach (array('product','cart','checkout') as $context) {
  foreach (array('jet','jet_card') as $scheme) {
   $r=sfixture(); $r->get('config')->values['config_currency']=$base;
   $r->get('currency')->value=$rate; $r->get('db')->currency['value']=(string)$rate;
   $r->get('model_checkout_order')->order['currency_value']=(string)$rate;
   $f=$r->get('model_extension_mt_jet_credit_financial');
   $input=sinput($r,$context,$scheme); $source=$f->source($context,$scheme,$input);
   $raw=$context==='product'?240:235;
   fcheck((float)$source['base_amount']===$raw*1.0 && (float)$source['amount']===(float)($raw*$rate),'base/EUR source separation '.$base.'/'.$context);
   $result=$r->get('submission')->submit($context,$scheme,$input);
   fcheck($result['success']==='success','non-EUR base submission '.$base.'/'.$context.'/'.$scheme);
   $order=$r->get('model_checkout_order')->getOrder($result['order_id']);
   fcheck((float)$order['total']===$raw*1.0 && (float)$order['currency_value']===$rate*1.0 && $order['currency_code']==='EUR','native total remains base with actual EUR metadata');
   $products=$r->get('model_extension_mt_jet_credit_submission_order')->bankProducts($result['order_id'],$source);
   fcheck((float)$products[0]['unit']===(float)(120*$rate),'bank unit EUR exactly once');
   $before=ssends($r); $r->get('session')->data['currency']='USD';
   fcheck($r->get('submission')->submit($context,$scheme,$input)['success']==='success' && ssends($r)===$before,'completed replay survives currency switch without sending');
  }
 }
}
foreach (array('RON','USD','BGN') as $session) {
 $r=sfixture();$r->get('session')->data['currency']=$session;
 freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->ready();},'non_eur_currency');
 foreach(array('jet','jet_card')as$scheme){$model=$scheme==='jet'?new ModelExtensionPaymentJet($r):new ModelExtensionPaymentJetCard($r);fcheck($model->getMethod(array('country_id'=>33,'zone_id'=>0),240)===array(),'non-EUR session payment hidden');}
}
foreach(array(0,-1,NAN,INF)as$rate){
 $r=sfixture();$r->get('currency')->value=$rate;
 freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->ready();});
}
$r=sfixture();$f=$r->get('model_extension_mt_jet_credit_financial');$input=sinput($r,'product','jet');
$r->get('currency')->value=2;$r->get('db')->currency['value']='2';
freject(function()use($f,$input){$f->verifySubmission('product','jet',$input);},'stale_offer');
fcheck(!$r->get('db')->claims && ssends($r)===0,'rate change cannot acquire claim or send');
// Change with equal rounded amount still invalidates conversion context.
$r=sfixture();$f=$r->get('model_extension_mt_jet_credit_financial');$input=sinput($r,'product','jet');
$r->get('currency')->value=1.00000001;$r->get('db')->currency['value']='1.00000001';
freject(function()use($f,$input){$f->verifySubmission('product','jet',$input);},'stale_offer');
// Financing floor is EUR, native payment minimum remains in base units.
$r=sfixture();$r->get('config')->values['config_currency']='RON';$r->get('currency')->value=0.5;$r->get('db')->currency['value']='0.5';
$m=new ModelExtensionPaymentJet($r);$address=array('country_id'=>33,'zone_id'=>0);
fcheck($m->getMethod($address,100)===array(),'100 base / 50 EUR below financing floor');
fcheck($m->getMethod($address,200)!==array(),'200 base / 100 EUR financing available');
$r->get('config')->values['payment_jet_total']='250';fcheck($m->getMethod($address,200)===array(),'native payment floor is base');
// Reject new public requests before claims/orders/mail when session changes.
foreach(array('product','cart','checkout')as$context){
 $r=sfixture();$input=sinput($r,$context,'jet');$r->get('session')->data['currency']='RON';
 freject(function()use($r,$context,$input){$r->get('submission')->submit($context,'jet',$input);},'non_eur_currency');
 fcheck(!$r->get('db')->claims && !$r->get('model_checkout_order')->created && ssends($r)===0,'new non-EUR attempt has no execution side effects');
}
foreach(array('missing','inactive','base_zero')as$failure){
 $r=sfixture();
 if($failure==='missing'){$r->get('currency')->exists=false;}
 if($failure==='inactive'){$r->get('db')->currency['status']='0';}
 if($failure==='base_zero'){$r->get('config')->values['config_currency']='RON';$r->get('currency')->baseValue=0;}
 freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->ready();});
}
$r=sfixture();$r->get('config')->values['config_currency']='RON';$r->get('currency')->baseValue=2;$r->get('db')->baseCurrencyValue=2;
fcheck($r->get('model_extension_mt_jet_credit_financial')->eurAmount(240)==='120.00','native API from/to ratio, not EUR value multiplication');
$r=sfixture();$r->get('config')->values['config_currency']='RON';$r->get('currency')->value=2;$r->get('db')->currency['value']='2';
$product=$r->get('model_catalog_product');$product->discounts[42]=array(array('quantity'=>'3','priority'=>'1','price'=>'90'));
$f=$r->get('model_extension_mt_jet_credit_financial');fcheck($f->product(42,3,array())['amount']==='648.00','quantity discount and tax before conversion');
$product->products[42]['special']='85';
$product->options[42]=array(array('product_option_id'=>10,'required'=>true,'type'=>'select','product_option_value'=>array(array('product_option_value_id'=>100,'name'=>'Plus','quantity'=>100,'subtract'=>1,'price'=>'10','price_prefix'=>'+'))));
fcheck($f->product(42,3,array(10=>100))['amount']==='684.00','special/options/tax then one conversion');
$r=sfixture();$r->get('config')->values['config_currency']='RON';$r->get('db')->baseCurrencyStatus=0;
freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->ready();},'invalid_base_currency');
echo "T-CURRENCY PASS: $fchecks checks; synthetic native currency/order/DB/mail services, no external access.\n";
