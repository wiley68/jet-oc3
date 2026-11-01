<?php
// Execute OCMOD-applied official OC3 controllers, real public Jet controllers and
// real Jet services. DB/order/SMTP are explicit doubles; no network or real mail.
require __DIR__ . '/submission-bootstrap.php';
define('VERSION', $argv[1]);
$GLOBALS['jet_native_root'] = $argv[2];
function modification($path) { return $GLOBALS['jet_native_root'] . '/' . substr($path, strlen(DIR_APPLICATION) - strlen('catalog/')); }
class Language {
	public function __construct($code = '') {} public function load($code) {}
	public function get($key) { return $key === 'date_format_short' ? 'Y-m-d' : $key; }
}
class Mail {
	public static $sent = 0;
	public $parameter, $smtp_hostname, $smtp_username, $smtp_password, $smtp_port, $smtp_timeout;
	public function __construct($engine = '') {} public function setTo($v) {} public function setFrom($v) {} public function setSender($v) {} public function setSubject($v) {} public function setText($v) {} public function setHtml($v) {}
	public function send() { ++self::$sent; }
}
require_once $GLOBALS['jet_native_root'] . '/system/library/request.php';
require_once modification(DIR_APPLICATION . 'controller/mail/order.php');
require_once modification(DIR_APPLICATION . 'controller/checkout/confirm.php');
require DIR_APPLICATION . 'model/extension/mt_jet_credit/checkout.php';
require DIR_APPLICATION . 'controller/extension/mt_jet_credit/success.php';
class NativeCustomerMailProbe extends ControllerMailOrder {
	public $customerCalls = 0;
	public function add($order, $status, $comment, $notify) { ++$this->customerCalls; }
	public function edit($order, $status, $comment) { ++$this->customerCalls; }
}
class NativeHistoryOrder extends SOrder {
	private $registry;
	public function __construct($r) { parent::__construct($r); $this->registry = $r; }
	public function getOrderVouchers($id) { return array(); }
	public function addOrderHistory($id, $status, $comment, $notify) {
		$controller = new NativeCustomerMailProbe($this->registry); $route = 'checkout/order/addOrderHistory'; $args = array($id,$status,$comment,$notify);
		$controller->index($route, $args); $controller->alert($route, $args);
		fcheck($controller->customerCalls === 0 && Mail::$sent === 0, 'actual modified native customer AND merchant callbacks suppressed during Jet finalization');
		parent::addOrderHistory($id,$status,$comment,$notify);
	}
}
function nfixture() {
	$r = sfixture(); $r->get('request')->server['HTTPS']=true; $r->set('jetcredit_native_mail_scope', new JetCreditNativeMailScope());
	$r->set('model_extension_mt_jet_credit_submission', $r->get('submission'));
	$r->set('language', new Language());
	$r->get('config')->values += array('config_mail_alert' => array('order'), 'config_mail_alert_email' => '', 'config_mail_parameter' => '', 'config_mail_smtp_password' => '');
	$old = $r->get('model_checkout_order'); $order = new NativeHistoryOrder($r); $order->order = $old->order; $order->products = $old->products; $order->totals = $old->totals;
	$order->order += array('store_name' => 'Fixture Shop', 'date_added' => '2026-11-01', 'comment' => '');
	$r->set('model_checkout_order', $order);
	return $r;
}
$scope = new JetCreditNativeMailScope(); fcheck($scope->available(), 'capability proven from actual modified native index/alert AND checkout fixture files');
// Exercise original native callbacks outside the exact Jet-owned scope.
$r = nfixture(); $r->get('model_checkout_order')->order['payment_code'] = 'cod';
$probe = new NativeCustomerMailProbe($r); $route = ''; $args = array(11,3,'',false);
$probe->index($route,$args); $probe->alert($route,$args);
fcheck($probe->customerCalls === 1 && Mail::$sent === 1, 'normal non-Jet initial customer AND merchant notifications preserved');
Mail::$sent = 0;
$r->get('jetcredit_native_mail_scope')->run(12, 'jet', function () use ($probe, &$route, &$args) { $probe->index($route,$args); });
fcheck($probe->customerCalls === 2, 'different order scope does not suppress native callback');
try { $scope->run(11,'jet',function () { throw new RuntimeException('fixture'); }); } catch (RuntimeException $ignored) {}
fcheck(!$scope->owns(11), 'native scope cleanup after exception');

foreach (array('product','cart','checkout') as $context) {
	foreach (array('jet','jet_card') as $scheme) {
		foreach (array('automatic','manual') as $mode) {
			$r = nfixture(); if ($mode === 'manual') { $r->get('config')->values['module_mt_jet_credit_mail'] = ''; }
			$input = sinput($r,$context,$scheme); $input['jet_card'] = $scheme === 'jet_card' ? '1' : '0'; $r->get('request')->post = $input;
			if ($context === 'checkout') { $controller = $scheme === 'jet' ? new ControllerExtensionPaymentJet($r) : new ControllerExtensionPaymentJetCard($r); $method = 'confirm'; }
			else { $controller = new ControllerExtensionMtJetCreditJetCredit($r); $method = $context === 'product' ? 'jet_send' : 'jet_send_cart'; }
			$controller->$method(); $result = json_decode($r->get('response')->output,true);
			fcheck($result['success'] === 'success' && $result['code'] === ($mode === 'manual' ? 'manual_registered' : 'transport_accepted'), 'actual public path active with native capabilities ' . $context . '/' . $scheme . '/' . $mode);
			fcheck(in_array('HTTP/1.1 200 OK',$r->get('response')->headers,true), 'controlled successful HTTP status');
			$sent = ssends($r); $created = $r->get('model_checkout_order')->created;
			$controller->$method(); $replay = json_decode($r->get('response')->output,true);
			fcheck($replay['code'] === $result['code'] && $replay['order_id'] === $result['order_id'] && ssends($r) === $sent && $r->get('model_checkout_order')->created === $created, 'public replay has no repeated side effects');
			parse_str(parse_url($result['redirect'],PHP_URL_QUERY),$query);
			fcheck($r->get('submission')->receiptOutcome($query['receipt'])['code'] === $result['code'], 'receipt requires durable completed claim');
			$r->get('session')->data['order_id'] = 999; $cart = $r->get('db')->cartRows;
			$r->get('request')->get = array('receipt' => $query['receipt']); $r->set('document',new class { public function setTitle($value) {} });
			(new ControllerExtensionMtJetCreditSuccess($r))->index();
			fcheck($r->get('session')->data['order_id'] === 999 && $r->get('db')->cartRows === $cart, 'owned success page never clears another tab cart/order');
			$r->get('customer')->id = 8;
			freject(function () use ($r,$query) { $r->get('submission')->receiptOutcome($query['receipt']); }, 'invalid_receipt');
		}
	}
}
// Public unknown/finalization-failed states cannot issue a success receipt/redirect.
foreach (array('unknown','history','invalid_recipient','consent','tampered') as $failure) {
	$r = nfixture(); $input = sinput($r); $r->get('request')->post = $input;
	if ($failure === 'unknown') { $r->get('submission_mail_instances')->settings[0] = array('outcome'=>'throw'); }
	if ($failure === 'history') { $r->get('model_checkout_order')->failHistory = 'after'; }
	if ($failure === 'invalid_recipient') { $r->get('config')->values['module_mt_jet_credit_mail'] = 'bad'; }
	if ($failure === 'consent') { $r->get('request')->post['jet_uslovia2'] = '0'; }
	if ($failure === 'tampered') { $r->get('request')->post['jet_priceall'] = '1'; }
	(new ControllerExtensionMtJetCreditJetCredit($r))->jet_send(); $result = json_decode($r->get('response')->output,true);
	fcheck($result['success'] === 'unsuccess' && !isset($result['redirect']) && strpos($r->get('response')->output,'9001010000') === false, 'public failure cannot redirect or leak PII: '.$failure);
}
// Execute the real modified native checkout/confirm::index, including its
// native total/order construction, then the public gateway confirm endpoint.
function checkoutFixture() {
 $r = nfixture();
 $cart = $r->get('cart');
 $r->set('cart',new class($cart) extends FCart {
  public function __construct($cart) { $this->products=$cart->products; }
  public function hasShipping() { return true; } public function hasProducts() { return count($this->products)>0; }
 });
 $r->get('cart')->products[0] += array('name'=>'Уред','model'=>'MODEL','reward'=>0,'download'=>array(),'subtract'=>1);
 $address = array('firstname'=>'Иван','lastname'=>'Петров','company'=>'','address_1'=>'Address','address_2'=>'','city'=>'City','postcode'=>'1000','country'=>'Bulgaria','country_id'=>33,'zone'=>'Sofia','zone_id'=>1,'address_format'=>'','custom_field'=>array());
 $r->get('session')->data['payment_address']=$address; $r->get('session')->data['shipping_address']=$address;
 $r->get('session')->data['shipping_method']=array('title'=>'Fixture shipping','code'=>'flat.flat');
 $r->get('session')->data['payment_method']=array('code'=>'jet','title'=>'Jet'); $r->get('session')->data['comment']='';
 $r->get('request')->server += array('HTTPS'=>false,'REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'fixture'); $r->get('request')->cookie=array();
 $r->set('model_account_customer',new class { public function getCustomer($id) { return array('customer_group_id'=>1,'firstname'=>'Иван','lastname'=>'Петров','email'=>'private@example.test','telephone'=>'+359888123456','custom_field'=>'{}'); } });
 $r->set('model_extension_mt_jet_credit_checkout',new ModelExtensionMtJetCreditCheckout($r));
 return $r;
}
if (!defined('HTTP_SERVER')) { define('HTTP_SERVER','https://test.invalid/'); define('HTTPS_SERVER','https://test.invalid/'); }
$r=checkoutFixture(); $confirm=new ControllerCheckoutConfirm($r);
$confirm->index(); $first=$r->get('session')->data['order_id']; $confirm->index();
fcheck($r->get('model_checkout_order')->created===1 && $r->get('session')->data['order_id']===$first,'actual native confirm refresh reuses unchanged Jet draft');
$firstQuote=sinput($r,'checkout','jet');
$r->get('session')->data['payment_method']=array('code'=>'jet_card','title'=>'Jet card'); $confirm->index();
fcheck($r->get('model_checkout_order')->created===1 && $r->get('session')->data['order_id']===$first,'changed gateway refuses a second draft under the same intent');
$r->get('session')->data['payment_method']=array('code'=>'jet','title'=>'Jet');$confirm->index();$second=$first;
$input=sinput($r,'checkout','jet');fcheck($input['jet_intent']===$firstQuote['jet_intent'],'gateway refusal preserves checkout intent');
$r->get('request')->post=$input; (new ControllerExtensionPaymentJet($r))->confirm(); $outcome=json_decode($r->get('response')->output,true);
fcheck($outcome['success']==='success' && $outcome['order_id']===$second,'actual native checkout order reaches public secure submission');
$created=$r->get('model_checkout_order')->created; $sent=ssends($r);
$r->get('model_extension_total_shipping')->delta=1; $r->get('session')->data['payment_method']=array('code'=>'jet','title'=>'Jet'); $confirm->index();
fcheck($r->get('model_checkout_order')->created===$created && $r->get('session')->data['order_id']===$second,'completed claim pins order despite changed totals/refresh/gateway');
(new ControllerExtensionPaymentJet($r))->confirm(); $replay=json_decode($r->get('response')->output,true);
fcheck($replay['code']===$outcome['code'] && $replay['order_id']===$second && ssends($r)===$sent,'native gateway-switch replay cannot create another application/reference/mail');
foreach (array('unknown','history') as $failure) {
 $r=checkoutFixture(); $confirm=new ControllerCheckoutConfirm($r); $confirm->index(); $input=sinput($r,'checkout','jet'); $r->get('request')->post=$input;
 if ($failure==='unknown') { $r->get('submission_mail_instances')->settings[0]=array('outcome'=>'throw'); } else { $r->get('model_checkout_order')->failHistory='after'; }
 (new ControllerExtensionPaymentJet($r))->confirm(); $result=json_decode($r->get('response')->output,true); $created=$r->get('model_checkout_order')->created; $sent=ssends($r);
 $confirm->index(); (new ControllerExtensionPaymentJet($r))->confirm(); $retry=json_decode($r->get('response')->output,true);
 fcheck(!isset($result['redirect']) && !isset($retry['redirect']) && $retry['code']==='review_required' && ssends($r)===$sent && $r->get('model_checkout_order')->created===$created,'native uncertain checkout remains blocked without order/mail retry: '.$failure);
}
// Existing completed/uncertain claims are detected by a quote refresh before
// fresh order/offer validation; no false new application is shown after back.
$r=checkoutFixture();$confirm=new ControllerCheckoutConfirm($r);$confirm->index();$input=sinput($r,'checkout','jet');$r->get('request')->post=$input;
(new ControllerExtensionPaymentJet($r))->confirm();$before=ssends($r);$created=$r->get('model_checkout_order')->created;
$confirm->index();$existing=$r->get('model_extension_mt_jet_credit_financial')->quote('checkout','jet_card',finput());
fcheck($existing['existing_outcome']==='transport_accepted'&&isset($existing['redirect'])&&ssends($r)===$before&&$r->get('model_checkout_order')->created===$created,'back/refresh quote returns existing safe receipt without bank/notification retry');
$r=checkoutFixture();$r->get('session')->data['payment_method']=array('code'=>'cod','title'=>'Cash');$r->set('jetcredit_runtime_loaded',false);
$confirm=new ControllerCheckoutConfirm($r);$confirm->index();$confirm->index();
fcheck($r->get('model_checkout_order')->created===2&&ssends($r)===0,'actual native non-Jet checkout draft behavior unchanged without module installation');
foreach (array('product','cart','checkout') as $context) {
 $r=nfixture();$input=sinput($r,$context,'jet');$r->get('request')->post=$input;
 $r->get('request')->post['jet_csrf']='forged';
 $controller=$context==='checkout'?new ControllerExtensionPaymentJet($r):new ControllerExtensionMtJetCreditJetCredit($r);
 $method=$context==='checkout'?'confirm':($context==='cart'?'jet_send_cart':'jet_send');$controller->$method();
 fcheck(json_decode($r->get('response')->output,true)['code']==='invalid_token'&&!$r->get('db')->claims&&ssends($r)===0,'public CSRF failure precedes claims/order/bank');
 $r->get('request')->server['REQUEST_METHOD']='GET';$controller->$method();
 fcheck(in_array('HTTP/1.1 405 Method Not Allowed',$r->get('response')->headers,true),'public method validation');
}
$r=nfixture();$r->get('session')->data['vouchers']=array(array('amount'=>50,'to_email'=>'private@example.test'));
freject(function () use ($r) {$r->get('model_extension_mt_jet_credit_financial')->cartSnapshot();},'unsupported_pricing');
fcheck(!$r->get('db')->claims&&Mail::$sent===0,'purchased gift vouchers cannot trigger additional native voucher mail through financing');
$r=nfixture();$input=sinput($r);$r->get('request')->post=$input;$r->get('request')->server['HTTPS']=false;
(new ControllerExtensionMtJetCreditJetCredit($r))->jet_send();
fcheck(in_array('HTTP/1.1 503 Service Unavailable',$r->get('response')->headers,true)&&!$r->get('db')->claims&&ssends($r)===0,'HTTP transport cannot activate personal-data submission');
// Real Request::clean() is part of the HTTP boundary: it HTML-encodes both
// strings and nested option values. Exercise native arrays and legacy JSON.
$cleaner=(new ReflectionClass('Request'))->newInstanceWithoutConstructor();
foreach (array('native_array','legacy_json') as $wireFormat) {
 $r=nfixture();
 $r->get('model_catalog_product')->products[42]['name']='Уред &amp; &lt;пакет&gt;';
 $r->get('model_catalog_product')->options[42]=array(array('product_option_id'=>10,'name'=>'Текст','required'=>true,'type'=>'text','product_option_value'=>array()));
 $text='Гравиране "А & Б" <txt>';
 $input=sinput($r,'product','jet',array('option'=>array(10=>$text),'jet_name'=>'Иван & <test>'));
 if ($wireFormat==='legacy_json') { $input['option']=json_encode($input['option'],JSON_UNESCAPED_UNICODE); }
 $r->get('request')->post=$cleaner->clean($input);
 (new ControllerExtensionMtJetCreditJetCredit($r))->jet_send();$result=json_decode($r->get('response')->output,true);
 fcheck($result['success']==='success','actual native Request encoding accepted: '.$wireFormat);
 $order=$r->get('model_checkout_order')->getOrder($result['order_id']);$options=$r->get('model_checkout_order')->getOrderOptions($result['order_id'],1);
 fcheck(strpos($order['firstname'],'&lt;test&gt;')!==false&&strpos($order['firstname'],'<test>')===false,'native order personal fields retain safe storage encoding');
 fcheck($options[0]['value']===htmlspecialchars($text,ENT_COMPAT,'UTF-8'),'native option storage encoding preserved for normal OC3 output');
 $bank=$r->get('submission_mail_instances')->items[0];
 fcheck(strpos($bank->Body,$text)!==false&&$bank->isHtml===false,'bank format receives validated plaintext, not HTML or double-encoded options');
}
echo "T-NATIVE-MAIL / T-SUBMIT " . VERSION . " PASS: $fchecks checks; actual OCMOD-applied native callbacks and public controllers; DB/order/SMTP doubles.\n";
