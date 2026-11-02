<?php
require __DIR__ . '/submission-bootstrap.php';
class Fix06Log {
	public $records = array(); public $fail = false;
	public function write($line) { if ($this->fail) { throw new RuntimeException('PRIVATE logger failure'); } $this->records[] = json_decode(substr($line, strlen('JET_DIAG ')), true); }
}
function fix06Fixture($enabled = true) {
	$r = sfixture(); $r->get('request')->server['HTTPS'] = 'on'; $r->get('config')->set('module_mt_jet_credit_diagnostics', $enabled ? '1' : '0');
	$r->set('log', new Fix06Log()); return $r;
}
function fix06Quote($r, $context, $scheme) {
	$r->get('session')->data['payment_method']['code'] = $scheme;
	$r->get('model_checkout_order')->order['payment_code'] = $scheme;
	$r->get('request')->post = finput(array('jet_context'=>$context, 'jet_card'=>$scheme === 'jet_card' ? '1' : '0', 'jet_vnoski'=>''));
	(new ControllerExtensionMtJetCreditJetCredit($r))->jet_calculate_cart();
	return json_decode($r->get('response')->output, true);
}
function fix06NoEffects($r) {
	fcheck(count($r->get('db')->claims) === 0 && $r->get('model_checkout_order')->created === 0 && $r->get('db')->counter === 16 && ssends($r) === 0, 'diagnostic failure path has no claims/orders/references/mail');
}
foreach (array('jet','jet_card') as $scheme) {
	foreach (array(0, '0', 1, '1') as $minimum) {
		$r = fix06Fixture(); $r->get('model_catalog_product')->products[42]['minimum'] = $minimum;
		fcheck($r->get('model_extension_mt_jet_credit_financial')->product('42','2',array())['amount'] === '240.00', 'product native minimum parity');
		$r->get('cart')->products[0]['minimum'] = $minimum;
		foreach (array('cart','checkout') as $context) { $j = fix06Quote($r,$context,$scheme); fcheck($j['success'] === 'success' && !isset($j['correlation_id']), 'cart/checkout native minimum parity both schemes'); }
		fix06NoEffects($r);
	}
	foreach (array(null,'','00','01','1.0','-1',false,1.0,array(),2147483648) as $minimum) {
		$r = fix06Fixture(); $r->get('cart')->products[0]['minimum'] = $minimum;
		$j = fix06Quote($r,'checkout',$scheme); fcheck($j['code'] === 'invalid_integer', 'invalid minimum formats remain rejected'); fix06NoEffects($r);
		$r->get('model_catalog_product')->products[42]['minimum'] = $minimum;
		freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->product('42','2',array());}, 'invalid_integer');
	}
	$r=fix06Fixture();$r->get('model_catalog_product')->products[42]['minimum']=3;freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->product('42','2',array());},'invalid_quantity');$r->get('cart')->products[0]['minimum']=3;fcheck(fix06Quote($r,'checkout',$scheme)['code']==='invalid_cart','effective minimum still enforced');
	$r=fix06Fixture();freject(function()use($r){$r->get('model_extension_mt_jet_credit_financial')->product('42','0',array());},'invalid_integer');$r->get('cart')->products[0]['quantity']='0';fcheck(fix06Quote($r,'checkout',$scheme)['code']==='invalid_integer','zero purchase quantity rejected');
}
$cases = array(
	array('session_order_id','missing',function($r){unset($r->get('session')->data['order_id']);}),
	array('session_order_id','empty',function($r){$r->get('session')->data['order_id']='';}),
	array('session_order_id','zero',function($r){$r->get('session')->data['order_id']='0';}),
	array('session_order_id','wrong_type',function($r){$r->get('session')->data['order_id']=array('PRIVATE');}),
	array('session_order_id','invalid_format',function($r){$r->get('session')->data['order_id']='PRIVATE';}),
	array('session_order_id','out_of_range',function($r){$r->get('session')->data['order_id']='2147483648';}),
	array('order_product_id','zero',function($r){$r->get('model_checkout_order')->products[0]['product_id']='0';}),
	array('order_quantity','zero',function($r){$r->get('model_checkout_order')->products[0]['quantity']='0';}),
	array('cart_product_id','zero',function($r){$r->get('cart')->products[0]['product_id']='0';}),
	array('cart_quantity','zero',function($r){$r->get('cart')->products[0]['quantity']='0';}),
	array('cart_minimum','invalid_format',function($r){$r->get('cart')->products[0]['minimum']='PRIVATE';}),
	array('cart_id','zero',function($r){$r->get('cart')->products[0]['cart_id']='0';}),
	array('option_id','zero',function($r){$r->get('model_checkout_order')->options=array(array('product_option_id'=>'0','product_option_value_id'=>'100','type'=>'select','value'=>'PRIVATE'));}),
	array('option_value_id','zero',function($r){$r->get('model_catalog_product')->options[42]=array(array('product_option_id'=>10,'required'=>true,'type'=>'select','product_option_value'=>array()));$r->get('cart')->products[0]['option']=array(array('product_option_id'=>10,'product_option_value_id'=>'0','type'=>'select','value'=>'PRIVATE'));}),
	array('reward','invalid_format',function($r){$r->get('model_checkout_order')->products[0]['reward']='PRIVATE';}),
	array('initial_term','zero',function($r){$r->get('config')->set('module_mt_jet_credit_vnoski','0');}),
	array('currency_id','zero',function($r){$r->set('currency',new class extends FCurrency{public function getId($code){return 0;}});})
);
foreach ($cases as $case) {
	$r=fix06Fixture();$case[2]($r);$j=fix06Quote($r,'checkout','jet');
	fcheck($j['code']==='invalid_integer' && in_array('HTTP/1.1 400 Bad Request',$r->get('response')->headers,true), 'quote preserves HTTP/code');
	$records=$r->get('log')->records;fcheck(count($records)===1,'one diagnostic event');$event=$records[0];
	fcheck($event['field']===$case[0] && $event['category']===$case[1], 'exact field/category '.$case[0]);
	fcheck($event['operation']==='quote_checkout' && $event['stage']==='financial_verification','quote stage');
	fcheck($j['correlation_id']===$event['correlation_id'] && preg_match('/^[a-f0-9]{32}$/D',$j['correlation_id']),'browser/log correlation');
	fcheck(!strpos(json_encode($event),'PRIVATE') && !isset($j['field']) && !isset($j['exception_class']), 'no rejected values or internal details in browser');fix06NoEffects($r);
}
class Fix06StatusDb extends SDb {
	public function query($sql) { if (strpos($sql,'SELECT order_status_id FROM')===0) { return (object)array('num_rows'=>0,'rows'=>array(),'row'=>array()); } return parent::query($sql); }
}
foreach (array('status','prepare','disabled','logger_failure') as $failure) {
	$r=fix06Fixture($failure!=='disabled');$input=sinput($r);$r->get('request')->post=$input;
	if ($failure==='status') { $r->set('db',new Fix06StatusDb()); }
	else { $r->set('model_extension_mt_jet_credit_submission_order',new class($r) extends ModelExtensionMtJetCreditSubmissionOrder {
		public function prepare($context,$scheme,$source,$person,$intent) { throw new RuntimeException('PRIVATE customer@example.test 9001010000 secret signed.offer SQL password'); }
	}); }
	if ($failure==='logger_failure') { $r->get('log')->fail=true; }
	(new ControllerExtensionMtJetCreditJetCredit($r))->jet_send();$j=json_decode($r->get('response')->output,true);
	fcheck($j['code']==='submission_unavailable' && in_array('HTTP/1.1 503 Service Unavailable',$r->get('response')->headers,true),'503 contract preserved');
	if ($failure==='status' || $failure==='prepare') {
		$e=$r->get('log')->records[0];fcheck(count($r->get('log')->records)===1,'model/HTTP capture deduplicated');
		fcheck($e['stage']===($failure==='status'?'order_status_readiness':'order_preparation'),'original stage before mapping: '.$failure.' / '.$e['stage'].' / '.$e['code']);
		fcheck($e['code']===($failure==='status'?'invalid_order_status':'unexpected_exception'),'original bounded code');
		fcheck(!$e['claim_reached'] && !$e['claim_confirmed'] && !$e['order_creation_began'],'preclaim flags');
		fcheck(!strpos(json_encode($e),'PRIVATE') && !strpos(json_encode($j),'PRIVATE'),'no exception text');
	} else { fcheck(count($r->get('log')->records)===0,'disabled/throwing logger no output'); }
	if ($failure==='disabled') { fcheck(!isset($j['correlation_id']),'disabled mode exact public body'); }
	fix06NoEffects($r);
}
foreach(array(false,true) as $loggerFailure){
 $r=fix06Fixture();$input=sinput($r);$r->get('request')->post=$input;$r->get('log')->fail=$loggerFailure;
 $r->get('model_checkout_order')->failHistory='before';
 (new ControllerExtensionMtJetCreditJetCredit($r))->jet_send();$j=json_decode($r->get('response')->output,true);
 fcheck($j['code']==='review_required' && count($r->get('db')->claims)===1 && $r->get('model_checkout_order')->created===1 && $r->get('db')->counter===17 && ssends($r)===1,'postclaim logger failure never changes side effects');
 if(!$loggerFailure){$e=$r->get('log')->records[0];fcheck($e['claim_reached']&&$e['claim_confirmed']&&$e['order_creation_began']&&$e['stage']==='finalization','confirmed effect flags');}
}
foreach(array(false,true) as $enabled){$r=fix06Fixture($enabled);$input=sinput($r);$r->get('request')->post=$input;(new ControllerExtensionMtJetCreditJetCredit($r))->jet_send();$j=json_decode($r->get('response')->output,true);fcheck($j['code']==='transport_accepted'&&count($r->get('db')->claims)===1&&$r->get('model_checkout_order')->created===1&&$r->get('db')->counter===17&&ssends($r)===3,'enabled/disabled success identical effects');}


$r=fix06Fixture();$r->get('log')->fail=true;unset($r->get('session')->data['order_id']);
fcheck(fix06Quote($r,'checkout','jet')['code']==='invalid_integer','quote logger exception preserves integer error');fix06NoEffects($r);
echo "FIX-06 PASS: $fchecks checks; in-memory OC3/SQL/mail doubles only.\n";
