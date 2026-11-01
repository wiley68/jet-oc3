<?php
require __DIR__.'/submission-bootstrap.php';
require DIR_APPLICATION.'model/extension/mt_jet_credit/checkout.php';
function transition($r,$context,$intent,$token=null){return $r->get('submission')->newPurchase(array('jet_context'=>$context,'jet_product_id'=>'42','jet_intent'=>$intent,'jet_csrf'=>'csrf-one','jet_transition'=>$token??hash('sha256','synthetic transition')));}
function draftData($r,$scheme='jet'){
 $native=$r->get('model_checkout_order');$data=$native->order;unset($data['order_id'],$data['order_status_id']);$data['payment_code']=$scheme;$data['products']=array();
 foreach($native->products as$p){$p['option']=$native->getOrderOptions(11,$p['order_product_id']);$data['products'][]=$p;}$data['totals']=$native->totals;return$data;
}
function helper($r){return new ModelExtensionMtJetCreditCheckout($r);}
function thrown($callback){try{$callback();}catch(Throwable$e){fcheck(true,'controlled failed creation/persistence');return;}throw new RuntimeException('Expected failure');}
foreach(array(array('product','jet'),array('cart','jet'),array('checkout','jet'),array('checkout','jet_card'))as$path){
 foreach(array('automatic','manual')as$mode){
  list($context,$scheme)=$path;$r=sfixture();if($mode==='manual')$r->get('config')->set('module_mt_jet_credit_mail','');
  $old=sinput($r,$context,$scheme);$first=$r->get('submission')->submit($context,$scheme,$old);$before=ssends($r);$counter=$r->get('db')->counter;
  parse_str(parse_url($first['redirect'],PHP_URL_QUERY),$query);$receipt=$query['receipt'];
  $next=transition($r,$context,$old['jet_intent']);$id=$next['application_intent'];
  fcheck($id!==$old['jet_intent'],'explicit purchase creates a new immutable identity '.$context.'/'.$scheme.'/'.$mode);
  fcheck(transition($r,$context,$old['jet_intent'])['application_intent']===$id,'same transition retries return one successor');
  fcheck(transition($r,$context,$old['jet_intent'],hash('sha256','overlapping second click'))['application_intent']===$id,'different overlapping request keys cannot branch one predecessor');
  $r->get('session')->data['jetcredit_intents']=array();
  fcheck($r->get('submission')->identity()->intent($context,42)===$id,'durable generation survives stale/lost session hint');
  fcheck($r->get('submission')->receiptOutcome($receipt)['order_id']===$first['order_id'],'historical receipt survives active generation change');
  $r->get('config')->set('module_mt_jet_credit_mail','bank@example.test');
  fcheck(soutcome($r->get('submission')->submit($context,$scheme==='jet'?'jet_card':'jet',$old))===soutcome($first),'old form/scheme/recipient change replays old outcome');
  fcheck(ssends($r)===$before&&$r->get('db')->counter===$counter,'old completed form never re-executes after new purchase');
  $existing=$r->get('model_extension_mt_jet_credit_financial')->quote($context,$scheme,$old);
  fcheck($existing['application_intent']===$old['jet_intent']&&isset($existing['existing_outcome']),'old quote stays attached to old outcome');
  $r->get('session')->data['payment_method']['code']=$scheme;
  if($context==='checkout'){$r->get('request')->post=array('jet_intent'=>$old['jet_intent']);$createdBefore=$r->get('model_checkout_order')->created;fcheck(helper($r)->orderForIntent(draftData($r,$scheme))===$first['order_id']&&$r->get('model_checkout_order')->created===$createdBefore,'old native checkout POST remains historical after new generation');$r->get('request')->post=array('jet_intent'=>$id);}
  if($context==='checkout'){$r->get('db')->cartRows[1]=array('cart_id'=>'1','product_id'=>'42','quantity'=>'2','option'=>'{}','recurring_id'=>'0','customer_id'=>7,'session_id'=>'session-one');$data=draftData($r,$scheme);$newOrder=helper($r)->orderForIntent($data);$r->get('session')->data['order_id']=$newOrder;fcheck($newOrder!==$first['order_id'],'new checkout has independent reserved native order');}
  if($context==='cart'){$r->get('model_catalog_product')->products[43]+=array('name'=>'Second product','model'=>'SECOND');$r->get('cart')->products[0]['product_id']='43';$r->get('db')->cartRows[2]=array('cart_id'=>'2','product_id'=>'43','quantity'=>'2','option'=>'{}','recurring_id'=>'0','customer_id'=>7,'session_id'=>'session-one');$r->get('cart')->products[0]['cart_id']=2;}
  $second=sinput($r,$context,$scheme,array('jet_intent'=>$id,'jet_quantity'=>'3'));
  $stolen=$second;$stolen['jet_offer']=$old['jet_offer'];$created=$r->get('model_checkout_order')->created;
  freject(function()use($r,$context,$scheme,$stolen){$r->get('submission')->submit($context,$scheme,$stolen);},'stale_offer');
  fcheck($created===$r->get('model_checkout_order')->created&&ssends($r)===$before,'old signed offer cannot execute as new intent');
  $result=$r->get('submission')->submit($context,$scheme,$second);
  fcheck($result['code']==='transport_accepted'&&$result['order_id']!==$first['order_id'],'fresh verified new purchase executes independently');
  fcheck($r->get('db')->counter===$counter+1,'exactly one new automatic reference');
  fcheck(soutcome($r->get('submission')->submit($context,$scheme,$second))===soutcome($result),'new purchase replay remains idempotent');
  fcheck($r->get('submission')->receiptOutcome($receipt)['order_id']===$first['order_id'],'old receipt remains historical after second completion');
  $third=transition($r,$context,$id)['application_intent'];fcheck($third!==$id&&transition($r,$context,$old['jet_intent'])['application_intent']===$id,'old transition retry returns its original child, never the later active generation');
  $sent=ssends($r);foreach(array('customer','session')as$foreign){$oldCustomer=$r->get('customer')->id;$oldSession=$r->get('session')->id;if($foreign==='customer')$r->get('customer')->id=8;else$r->get('session')->id='foreign-session';freject(function()use($r,$context,$old){transition($r,$context,$old['jet_intent']);},'invalid_application_context');freject(function()use($r,$receipt){$r->get('submission')->receiptOutcome($receipt);});$r->get('customer')->id=$oldCustomer;$r->get('session')->id=$oldSession;}
  fcheck(ssends($r)===$sent,'foreign owners learn no historical outcome or execute mail');
 }
}
// Same product, changed required option, same session, explicit new generation.
$r=sfixture();$input=sinput($r);$r->get('submission')->submit('product','jet',$input);$id=transition($r,'product',$input['jet_intent'])['application_intent'];
$r->get('model_catalog_product')->options[42]=array(array('product_option_id'=>7,'type'=>'select','required'=>1,'product_option_value'=>array(array('product_option_value_id'=>8,'name'=>'New choice','price'=>'10','price_prefix'=>'+','subtract'=>0,'quantity'=>20))));
$input=sinput($r,'product','jet',array('jet_intent'=>$id,'option'=>array('7'=>'8')));fcheck($r->get('submission')->submit('product','jet',$input)['code']==='transport_accepted','same product changed options starts independent purchase');
// Ineligible prior claims can never be renewed.
foreach(array('unknown','order','history')as$failure){$r=sfixture();$input=sinput($r);if($failure==='unknown')$r->get('submission_mail_instances')->settings[0]=array('outcome'=>'throw');if($failure==='order')$r->get('model_checkout_order')->failCreate='partial';if($failure==='history')$r->get('model_checkout_order')->failHistory='after';$r->get('submission')->submit('product','jet',$input);$sent=ssends($r);freject(function()use($r,$input){transition($r,'product',$input['jet_intent']);},'review_required');fcheck(!$r->get('db')->purchaseRows&&ssends($r)===$sent,'held prior cannot be reset');}
// Exact source helper: same reservation, concurrent contender, valid reuse, mismatch refusal.
$r=sfixture();$data=draftData($r);$h=helper($r);$r->get('db')->purchaseOnInsert=function()use($h,$data){freject(function()use($h,$data){$h->orderForIntent($data);},'review_required');};
$id=$h->orderForIntent($data);fcheck($h->orderForIntent($data)===$id&&$r->get('model_checkout_order')->created===1,'one winner/native order and valid bound reuse');
$changed=$data;$changed['total']='999';freject(function()use($h,$changed){$h->orderForIntent($changed);},'stale_order');
$r->get('session')->data['payment_method']['code']='jet_card';$changed=$data;$changed['payment_code']='jet_card';freject(function()use($h,$changed){$h->orderForIntent($changed);},'stale_order');
fcheck($r->get('model_checkout_order')->created===1,'financial/method changes cannot create another native order');
$r->get('session')->data['payment_method']['code']='jet';$r->get('model_checkout_order')->orders[$id]['total']='1';freject(function()use($h,$data){$h->orderForIntent($data);},'stale_order');
foreach(array('before','partial','binding','reservation')as$failure){
 $r=sfixture();$data=draftData($r);$h=helper($r);
 if(in_array($failure,array('before','partial'),true))$r->get('model_checkout_order')->failCreate=$failure;
 if($failure==='binding')$r->get('db')->purchaseFailure="SET state='draft_bound'";
 if($failure==='reservation')$r->get('db')->purchaseOnInsert=function(){throw new RuntimeException('crash after reservation before native creation');};
 thrown(function()use($h,$data){$h->orderForIntent($data);});$created=$r->get('model_checkout_order')->created;$physical=count($r->get('model_checkout_order')->orders);
 $r->get('model_checkout_order')->failCreate=null;
 freject(function()use($h,$data){$h->orderForIntent($data);},'review_required');
 $changed=$data;$changed['total']='999';freject(function()use($h,$changed){$h->orderForIntent($changed);},'review_required');
 fcheck($r->get('model_checkout_order')->created===$created&&count($r->get('model_checkout_order')->orders)===$physical&&ssends($r)===0,'crash boundary has no automatic addOrder takeover or delivery '.$failure);
}
// Transition boundary guards through the public action; no unsafe GET or bad CSRF.
$r=sfixture();$input=sinput($r);$r->get('submission')->submit('product','jet',$input);
$r->get('request')->post=array('jet_context'=>'product','jet_product_id'=>'42','jet_intent'=>$input['jet_intent'],'jet_csrf'=>'bad','jet_transition'=>hash('sha256','request'));
$r->get('request')->server['HTTPS']='on';(new ControllerExtensionMtJetCreditJetCredit($r))->new_purchase();fcheck(in_array('HTTP/1.1 400 Bad Request',$r->get('response')->headers,true),'public new purchase rejects bad CSRF');
$r->get('request')->server['REQUEST_METHOD']='GET';(new ControllerExtensionMtJetCreditJetCredit($r))->new_purchase();fcheck(in_array('HTTP/1.1 405 Method Not Allowed',$r->get('response')->headers,true),'new purchase POST only');
// Overlap delivered after the first commit but before its caller returns: same child.
$r=sfixture();$old=sinput($r);$r->get('submission')->submit('product','jet',$old);$overlap=null;
$r->get('db')->onCommit=function()use($r,$old,&$overlap){$overlap=transition($r,'product',$old['jet_intent'],hash('sha256','second overlapping token'));};
$first=transition($r,'product',$old['jet_intent']);fcheck($overlap['application_intent']===$first['application_intent']&&count($r->get('db')->purchaseRows)===1,'overlapping transition callers observe one committed successor');
foreach(array('rollback','commit_unknown')as$failure){
 $r=sfixture();$old=sinput($r);$r->get('submission')->submit('product','jet',$old);
 if($failure==='rollback')$r->get('db')->purchaseFailure="VALUES ('generation'";else$r->get('db')->failure=array('contains'=>'COMMIT','after'=>true);
 freject(function()use($r,$old){transition($r,'product',$old['jet_intent']);},'submission_storage_unavailable');
 $child=transition($r,'product',$old['jet_intent']);fcheck(count($r->get('db')->purchaseRows)===1&&$child['application_intent']!==$old['jet_intent'],'retry after rollback/uncertain commit has one successor');
}
// Guest ownership never falls back to an order ID. Expired receipts create nothing.
$r=sfixture();$r->get('customer')->id=0;$old=sinput($r);$out=$r->get('submission')->submit('product','jet',$old);parse_str(parse_url($out['redirect'],PHP_URL_QUERY),$query);
$pieces=explode('.',$query['receipt']);$payload=json_decode(base64_decode(strtr($pieces[0],'-_','+/')),true);$payload['expires']=time()-1;$encoded=rtrim(strtr(base64_encode(json_encode($payload)),'+/','-_'),'=');$signer=new JetCreditOffer(JetCreditOffer::configuredSecret($r->get('config')));$expired=$encoded.'.'.$signer->digest(array('receipt-v1',$encoded));
freject(function()use($r,$expired){$r->get('submission')->receiptOutcome($expired);},'invalid_receipt');$r->get('session')->id='lost-guest-session';
freject(function()use($r,$query){$r->get('submission')->receiptOutcome($query['receipt']);});freject(function()use($r,$old){transition($r,'product',$old['jet_intent']);});
fcheck(!$r->get('db')->purchaseRows,'lost guest/expired receipt cannot create generations');
// Reusing an old completed ancestor cannot jump over a held successor.
$r=sfixture();$r->get('config')->set('module_mt_jet_credit_mail','');$old=sinput($r);$r->get('submission')->submit('product','jet',$old);$child=transition($r,'product',$old['jet_intent'])['application_intent'];
$r->get('config')->set('module_mt_jet_credit_mail','bank@example.test');$next=sinput($r,'product','jet',array('jet_intent'=>$child));$r->get('submission_mail_instances')->settings[2]=array('outcome'=>'throw');$r->get('submission')->submit('product','jet',$next);$sent=ssends($r);$counter=$r->get('db')->counter;
fcheck(transition($r,'product',$old['jet_intent'],hash('sha256','another ancestor retry'))['application_intent']===$child,'ancestor retry cannot bypass an unknown successor');
freject(function()use($r,$child){transition($r,'product',$child);},'review_required');fcheck(count($r->get('db')->purchaseRows)===1&&ssends($r)===$sent&&$r->get('db')->counter===$counter,'held successor never resets, branches, resends or consumes another reference');
// Exact new state guards reject the old draft before a usable binding exists.
$r=sfixture();$data=draftData($r);$r->get('db')->purchaseOnInsert=function(){throw new RuntimeException('reserved but not created');};thrown(function()use($r,$data){helper($r)->orderForIntent($data);});
$identity=$r->get('submission')->identity();$intent=$identity->intent('checkout',null);$r->get('request')->server['HTTPS']='on';$r->get('request')->post=finput(array('jet_intent'=>$intent));
(new ControllerExtensionPaymentJet($r))->confirm();$response=json_decode($r->get('response')->output,true);fcheck($response['code']==='review_required'&&in_array('HTTP/1.1 409 Conflict',$r->get('response')->headers,true)&&ssends($r)===0,'unbound draft cannot become a usable submission');
echo "T-NEW-PURCHASE / T-CHECKOUT-BINDING PASS: $fchecks checks; actual identity/repository/service/helper/controllers; shared SQL-row and native order/mail doubles. Real InnoDB contention DEFERRED.\n";
