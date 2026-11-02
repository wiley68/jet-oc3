<?php
require __DIR__ . '/submission-bootstrap.php';
define('VERSION',$argv[1]);$GLOBALS['native_missing_root']=$argv[2];
function modification($path){return $GLOBALS['native_missing_root'].'/'.substr($path,strlen(DIR_APPLICATION)-strlen('catalog/'));}
foreach (array(array('product','jet','jet_send'),array('cart','jet','jet_send_cart'),array('checkout','jet','confirm'),array('checkout','jet_card','confirm')) as $path) {
 $r=sfixture();$r->get('request')->server['HTTPS']=true;$input=sinput($r,$path[0],$path[1]);$r->get('request')->post=$input;
 $r->set('jetcredit_native_mail_scope',new JetCreditNativeMailScope());
 $controller=$path[0]==='checkout'?($path[1]==='jet'?new ControllerExtensionPaymentJet($r):new ControllerExtensionPaymentJetCard($r)):new ControllerExtensionMtJetCreditJetCredit($r);
 $controller->{$path[2]}();$result=json_decode($r->get('response')->output,true);
 fcheck($result===array('success'=>'unsuccess','code'=>'native_mail_guard_unavailable')&&in_array('HTTP/1.1 503 Service Unavailable',$r->get('response')->headers,true),'unsupported/missing native capability keeps public route HTTP 503');
 fcheck(!$r->get('db')->claims&&$r->get('model_checkout_order')->created===0&&ssends($r)===0,'failed readiness has no claim/order/mail side effects');
}
echo "T-ACTIVATION-GATE PASS: $fchecks checks; incomplete/unsupported native integration fails closed.\n";
