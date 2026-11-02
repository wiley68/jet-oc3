<?php
// Included by native-integration.php after loading the actual OCMOD-applied confirm.
class Fix07Log {
 public $records=array(); public $fail=false;
 public function write($line){if($this->fail){throw new RuntimeException('PRIVATE logger');}$this->records[]=json_decode(substr($line,strlen('JET_DIAG ')),true);}
}
function fix07Fixture($scheme='jet',$enabled=true){
 $r=checkoutFixture();$r->get('request')->server['REQUEST_METHOD']='GET';
 $r->get('config')->set('module_mt_jet_credit_diagnostics',$enabled?'1':'0');$r->set('log',new Fix07Log());
 $r->get('session')->data['payment_method']=array('code'=>$scheme,'title'=>$scheme==='jet'?'Jet':'Jet card');
 unset($r->get('session')->data['order_id']);return$r;
}
function fix07Confirm($r){$r->set('response',new FResponse());(new ControllerCheckoutConfirm($r))->index();}
function fix07Flags($event,$expected){
 foreach(array('reservation_reached','reservation_confirmed','native_order_creation_started','draft_binding_confirmed')as$i=>$key){fcheck($event[$key]===$expected[$i],'accurate checkout lifecycle '.$key);}
}
function fix07Safe($r){
 fcheck(count($r->get('db')->claims)===0&&$r->get('db')->counter===16&&ssends($r)===0,'confirm diagnostics never create claim/reference/email');
}
foreach(array('jet','jet_card')as$scheme){
 $r=fix07Fixture($scheme);fix07Confirm($r);$id=$r->get('session')->data['order_id'];
 fcheck($id>0&&$r->get('model_checkout_order')->created===1&&count($r->get('log')->records)===0,'native success without diagnostic noise');
 $d=$r->get('jetcredit_diagnostics');$d->capture(new LogicException('PRIVATE test-only inspection'));
 $e=$r->get('log')->records[0];fcheck($e['stage']==='return','return stage after successful binding');fix07Flags($e,array(true,true,true,true));
 fix07Confirm($r);fcheck($r->get('session')->data['order_id']===$id&&$r->get('model_checkout_order')->created===1,'valid native reuse has no duplicate order');
 $r->get('jetcredit_diagnostics')->capture(new LogicException('PRIVATE test-only inspection'));
 $e=$r->get('log')->records[1];fcheck($e['stage']==='return','return stage after valid reuse');fix07Flags($e,array(true,false,false,true));fix07Safe($r);
}
$cases=array(
 'loader'=>array('model_loading','unexpected_exception',array(false,false,false,false),0,0),
 'context'=>array('context','invalid_order_context',array(false,false,false,false),0,0),
 'runtime'=>array('runtime','native_mail_guard_unavailable',array(false,false,false,false),0,0),
 'financing'=>array('financing_readiness','financing_unavailable',array(false,false,false,false),0,0),
 'storage'=>array('storage','submission_storage_unavailable',array(false,false,false,false),0,0),
 'authorization'=>array('authorization','invalid_application_context',array(false,false,false,false),0,0),
 'claim_lookup'=>array('claim_lookup','unexpected_exception',array(false,false,false,false),0,0),
 'reservation'=>array('draft_reservation','unexpected_exception',array(true,false,false,false),0,0),
 'reservation_after'=>array('draft_reservation','unexpected_exception',array(true,false,false,false),0,1),
 'unfinished'=>array('draft_reuse','review_required',array(true,false,false,false),0,1),
 'scheme'=>array('draft_reuse','stale_order',array(true,false,false,false),1,1),
 'digest'=>array('draft_reuse','stale_order',array(true,false,false,false),1,1),
 'create_before'=>array('native_order_creation','unexpected_exception',array(true,true,true,false),1,1),
 'create_partial'=>array('native_order_creation','unexpected_exception',array(true,true,true,false),1,1),
 'verify'=>array('persisted_verification','invalid_persisted_order',array(true,true,true,false),1,1),
 'binding'=>array('draft_binding','unexpected_exception',array(true,true,true,false),1,1),
 'binding_after'=>array('draft_binding','unexpected_exception',array(true,true,true,false),1,1)
);
foreach($cases as$case=>$expected){
 $r=fix07Fixture();$db=$r->get('db');$native=$r->get('model_checkout_order');
 if($case==='loader'){$r->set('load',new class extends FLoad{public function model($path){if($path==='extension/mt_jet_credit/checkout'){throw new RuntimeException('PRIVATE 9001010000 customer@example.test SQL password signed.offer');}parent::model($path);}});}
 if($case==='context'){$r->set('load',new class($r) extends FLoad{private $r;public function __construct($r){$this->r=$r;}public function model($path){parent::model($path);if($path==='extension/mt_jet_credit/checkout'){$this->r->get('session')->data['payment_method']['code']='jet_card';}}});}
 if($case==='runtime'){$r->set('jetcredit_native_mail_scope',new class extends JetCreditNativeMailScope{public function available(){return false;}});}
 if($case==='financing'){$r->get('config')->set('module_mt_jet_credit_status','0');}
 if($case==='storage'){$db->failure=array('contains'=>'SELECT schema_version','after'=>false);}
 if($case==='authorization'){$r->get('request')->post['jet_intent']='PRIVATE';}
 if($case==='claim_lookup'){$db->failure=array('contains'=>"WHERE record_type='claim'",'after'=>false);}
 if($case==='reservation'){$db->purchaseFailure="VALUES ('draft'";}
 if($case==='reservation_after'){$db->purchaseOnInsert=function(){throw new RuntimeException('PRIVATE ambiguous reservation result');};}
 if($case==='unfinished'){
  $db->purchaseOnInsert=function(){throw new RuntimeException('PRIVATE earlier interruption');};fix07Confirm($r);$r->get('log')->records=array();
 }
 if($case==='scheme'||$case==='digest'){
  fix07Confirm($r);$r->get('log')->records=array();
  if($case==='scheme'){$r->get('session')->data['payment_method']=array('code'=>'jet_card','title'=>'Jet card');}
  else{$r->get('session')->data['comment']='PRIVATE changed request';}
 }
 if($case==='create_before'){$native->failCreate='before';}
 if($case==='create_partial'){$native->failCreate='partial';}
 if($case==='verify'){$native->corrupt=true;}
 if($case==='binding'){$db->purchaseFailure="SET state='draft_bound'";}
 if($case==='binding_after'){
  $db=new class extends SDb{public function query($sql){$result=parent::query($sql);if(strpos($sql,"SET state='draft_bound'")!==false){throw new RuntimeException('PRIVATE ambiguous binding result');}return$result;}};$r->set('db',$db);
 }
 fix07Confirm($r);$response=$r->get('response');
 fcheck($response->output==='Финансирането временно не е достъпно.'&&in_array('HTTP/1.1 503 Service Unavailable',$response->headers,true),'unchanged native 503 and public body '.$case);
 fcheck(count($r->get('log')->records)===1,'one checkout record '.$case);$e=$r->get('log')->records[0];
 fcheck($e['operation']==='checkout_confirm'&&$e['stage']===$expected[0]&&$e['code']===$expected[1],'exact stage/code '.$case.' / '.$e['stage'].' / '.$e['code']);
 fix07Flags($e,$expected[2]);
 fcheck($native->created===$expected[3]&&count($db->purchaseRows)===$expected[4],'unchanged native create/draft counts '.$case);
 fcheck(in_array('X-Jet-Correlation-ID: '.$e['correlation_id'],$response->headers,true)&&preg_match('/^[a-f0-9]{32}$/D',$e['correlation_id']),'safe header/log correlation');
 $encoded=json_encode($e);foreach(array('PRIVATE','9001010000','customer@example.test','signed.offer','password','Иван','session-one')as$secret){fcheck(strpos($encoded,$secret)===false,'no sensitive diagnostic content');}
 foreach(array('order_id','intent','request_digest','session_id','message','trace','sql','body')as$key){fcheck(!array_key_exists($key,$e),'no sensitive diagnostic keys');}
 fix07Safe($r);
 if($case==='binding_after'){fix07Confirm($r);fcheck($native->created===1&&count($db->purchaseRows)===1&&$r->get('response')->output!=='Финансирането временно не е достъпно.','ambiguous completed binding can be verified/reused without new creation');fix07Safe($r);}
 if(in_array($case,array('create_before','create_partial','verify','binding'),true)){
  $created=$native->created;$native->failCreate=null;$native->corrupt=false;fix07Confirm($r);
  fcheck($native->created===$created&&count($db->purchaseRows)===1,'unresolved reservation has no takeover/retry');fix07Safe($r);
 }
}
foreach(array('disabled','logger_failure')as$mode){
 $r=fix07Fixture('jet',$mode!=='disabled');$r->get('log')->fail=$mode==='logger_failure';$r->get('model_checkout_order')->failCreate='partial';
 fix07Confirm($r);fcheck($r->get('response')->output==='Финансирането временно не е достъпно.'&&count($r->get('log')->records)===0,'disabled/failed logger preserves native public error');
 fcheck($r->get('model_checkout_order')->created===1&&count($r->get('db')->purchaseRows)===1,'disabled/failed logger preserves side effects');
 if($mode==='disabled'){fcheck(count(array_filter($r->get('response')->headers,function($h){return strpos($h,'X-Jet-Correlation-ID:')===0;}))===0,'no correlation header when disabled');}
 fix07Safe($r);
}
$r=fix07Fixture();$r->get('session')->data['payment_method']=array('code'=>'cod','title'=>'Cash');fix07Confirm($r);
fcheck($r->get('model_checkout_order')->created===1&&count($r->get('db')->purchaseRows)===0&&count($r->get('log')->records)===0,'non-Jet path bypasses diagnostics/reservation');fix07Safe($r);
