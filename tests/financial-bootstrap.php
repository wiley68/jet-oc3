<?php
// Isolated OC3 contracts. No real SQL, mail, HTTP or cart mutation.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('DB_PREFIX', 'oc_');
define('DIR_SYSTEM', __DIR__ . '/../upload/system/');
define('DIR_APPLICATION', __DIR__ . '/../upload/catalog/');
class Registry {
	private $data = array();
	public function get($key) { return $this->data[$key] ?? null; }
	public function set($key, $value) { $this->data[$key] = $value; }
}
class Controller {
	protected $registry;
	public function __construct($registry) { $this->registry = $registry; }
	public function __get($key) { return $this->registry->get($key); }
}
class Model extends Controller {}
class FConfig {
	public $values;
	public function __construct() {
		$this->values = array('jetcredit_install_marker' => 'jet-oc3-clean-v1', 'module_mt_jet_credit_id' => 'TEST-MERCHANT', 'config_email' => 'admin@example.test', 'config_currency' => 'EUR', 'config_store_id' => 0, 'config_customer_group_id' => 1, 'config_tax' => 1, 'config_stock_checkout' => 0, 'module_mt_jet_credit_status' => '1', 'module_mt_jet_credit_status_card' => '1', 'module_mt_jet_credit_cart_show' => '1', 'payment_jet_status' => '1', 'payment_jet_card_status' => '1', 'module_mt_jet_credit_price' => '75', 'module_mt_jet_credit_vnoski' => '12', 'module_mt_jet_credit_purcent' => '1.40', 'module_mt_jet_credit_purcent_card' => '1.00', 'module_mt_jet_credit_mail' => 'bank@example.test', 'jetcredit_offer_secret' => hash('sha256', 'isolated synthetic fixture key, not a deployed secret'), 'config_processing_status' => array(2), 'config_complete_status' => array(5));
		foreach (array('sub_total', 'coupon', 'shipping', 'handling', 'tax', 'total') as $i => $code) {
			$this->values['total_' . $code . '_status'] = '1';
			$this->values['total_' . $code . '_sort_order'] = $i + 1;
		}
	}
	public function get($key) { return $this->values[$key] ?? null; }
	public function set($key, $value) { $this->values[$key] = $value; }
}
class FSession {
	public $id = 'session-one';
	public $data = array('currency' => 'EUR', 'jetcredit_csrf' => 'csrf-one', 'order_id' => '11', 'payment_method' => array('code' => 'jet'), 'payment_address' => array('firstname' => 'Private Name', 'address_1' => 'Private Address', 'country_id' => 33));
	public function getId() { return $this->id; }
}
class FCustomer {
	public $firstname='Profile First'; public $lastname='Profile Last'; public $email='profile@example.test'; public $telephone='+359123456789';
	public function getFirstName(){return $this->firstname;} public function getLastName(){return $this->lastname;} public function getEmail(){return $this->email;} public function getTelephone(){return $this->telephone;}
	public $id = 7;
	public function isLogged() { return $this->id > 0; }
	public function getId() { return $this->id; }
}
class FCurrency {
	public $value = 1;
	public $exists = true;
	public function has($code) { return $this->exists && in_array($code, array('EUR','RON','USD','BGN'), true); }
	public $baseValue = 1;
	public function getValue($code) { return $code === 'EUR' ? $this->value : $this->baseValue; }
	public function convert($amount, $from, $to) { return $amount / $this->getValue($from) * $this->getValue($to); }
	public function getId($code) { return 1; }
	public function format($value, $code, $rate = 1) { return number_format((float)$value, 2, '.', ''); }
}
class FTax {
	public $rate = .2;
	public function getTax($net, $class) { return $class ? $net * $this->rate : 0; }
	public function calculate($net, $class, $enabled = true) { return $net + ($enabled ? $this->getTax($net,$class) : 0); }
}
require __DIR__ . '/purchase-db-double.php';
class FDb {
	use PurchaseDbDouble;
	public $baseCurrencyStatus = 1; public $baseCurrencyValue = 1;
	public $currency = array('currency_id' => 1, 'status' => '1', 'value' => '1');
	public $promos = array();
	public $queries = array();
	public function escape($value) { return str_replace("'", "\\'", $value); }
	public function query($sql) {
		$this->queries[] = $sql;
		if (strpos($sql,"record_type='generation'")!==false || strpos($sql,"record_type='draft'")!==false || strpos($sql,"VALUES ('generation'")!==false || strpos($sql,"VALUES ('draft'")!==false) { return $this->purchaseQuery($sql); }
		if (!preg_match('/^\s*SELECT /', $sql)) { throw new RuntimeException('WRITE ATTEMPT IN FINANCIAL TEST'); }
		$rows = array();
		if (strpos($sql, "currency WHERE code = 'EUR'") !== false) { $rows[] = $this->currency; }
		elseif (preg_match("/currency WHERE code = '(RON|USD|BGN)'/", $sql)) { $rows[] = array('currency_id'=>2,'status'=>$this->baseCurrencyStatus,'value'=>$this->baseCurrencyValue); }
		elseif (strpos($sql, 'FROM oc_jet_kop') !== false) {
			preg_match("/jet_product_id = '([^']+)'\)/", $sql, $selector);
			preg_match('/FIND_IN_SET\(([0-9]+),/', $sql, $term);
			preg_match('/AND ([0-9.eE+-]+) >= jet_product_price/', $sql, $amount);
			foreach ($this->promos as $row) {
				if (in_array($row['jet_product_id'], array('*', $selector[1]), true) && in_array($term[1], explode('_', $row['jet_product_meseci']), true) && (float)$amount[1] >= (float)$row['jet_product_price'] && $row['jet_product_start'] <= '2026-11-01' && $row['jet_product_end'] >= '2026-11-01') { $rows[] = $row; }
			}
			usort($rows, function ($a, $b) use ($selector) { return (($a['jet_product_id'] === $selector[1] ? 0 : 1) <=> ($b['jet_product_id'] === $selector[1] ? 0 : 1)) ?: ($a['id'] <=> $b['id']); });
			$rows = array_slice($rows, 0, 1);
		} elseif (strpos($sql, 'zone_to_geo_zone') !== false) { $rows[] = array('zone_id' => 0); }
		else { throw new RuntimeException('Unknown fixture SELECT'); }
		return (object)array('num_rows' => count($rows), 'rows' => $rows, 'row' => $rows[0] ?? array());
	}
}
class FProduct {
	public $products = array();
	public $options = array();
	public $discounts = array();
	public $profiles = array();
	public function __construct() {
		$this->products[42] = array('product_id' => 42, 'status' => 1, 'quantity' => 100, 'minimum' => 1, 'price' => '100', 'special' => null, 'tax_class_id' => 1);
		$this->products[43] = $this->products[42]; $this->products[43]['product_id'] = 43;
	}
	public function getProduct($id) { return $this->products[$id] ?? false; }
	public function getProductOptions($id) { return $this->options[$id] ?? array(); }
	public function getProductDiscounts($id) { return $this->discounts[$id] ?? array(); }
	public function getProfiles($id) { return $this->profiles[$id] ?? array(); }
}
class FCart {
	public $products;
	public $stock = true;
	public function __construct() { $this->products = array(array('product_id' => '42', 'quantity' => '2', 'minimum' => '1', 'price' => '100', 'total' => '200', 'tax_class_id' => 1, 'option' => array(), 'recurring' => false)); }
	public function getProducts() { return $this->products; }
	public function hasStock() { return $this->stock; }
	public function getTaxes() { return array(1 => 40); }
	public function clear() { throw new RuntimeException('Unexpected cart clear'); }
	public function add() { throw new RuntimeException('Unexpected cart add'); }
	public function remove() { throw new RuntimeException('Unexpected cart remove'); }
}
class FExtensions {
	public $codes = array('sub_total', 'coupon', 'shipping', 'handling', 'tax', 'total');
	public function getExtensions($type) { return array_map(function ($code) { return array('code' => $code); }, $this->codes); }
}
class FTotal {
	private $code;
	public $delta = 0;
	public function __construct($code) { $this->code = $code; }
	public function getTotal($data) {
		switch ($this->code) {
			case 'sub_total': $value = 200; break;
			case 'coupon': $value = -10; $data['taxes'][1] -= 2; break;
			case 'shipping': $value = 5; break;
			case 'handling': $value = 2; break;
			case 'tax': $value = array_sum($data['taxes']); break;
			case 'total': $value = $data['total']; break;
		}
		$value += $this->delta;
		if ($this->code !== 'total') { $data['total'] += $value; }
		$data['totals'][] = array('code' => $this->code, 'title' => $this->code, 'value' => $value, 'sort_order' => array_search($this->code, array('sub_total', 'coupon', 'shipping', 'handling', 'tax', 'total')) + 1);
	}
}
class FOrder {
	public $order = array('order_id' => 11, 'store_id' => 0, 'customer_id' => 7, 'email' => 'private@example.test', 'payment_code' => 'jet', 'currency_code' => 'EUR', 'currency_value' => '1', 'order_status_id' => 0, 'total' => '235.0000');
	public $products = array(array('order_product_id' => 1, 'product_id' => '42', 'quantity' => '2', 'price' => '100.0000', 'total' => '200.0000', 'tax' => '20.0000'));
	public $options = array();
	public $totals = array();
	public function getOrder($id) { return (int)$id === 11 ? $this->order : false; }
	public function getOrderProducts($id) { return $this->products; }
	public function getOrderOptions($id, $line) { return $this->options; }
	public function getOrderTotals($id) { return $this->totals; }
	public function getOrderVouchers($id) { return array(); }
	public function addOrder() { throw new RuntimeException('Unexpected order write'); }
}
class FResponse {
	public $headers = array(); public $output;
	public function addHeader($value) { $this->headers[] = $value; }
	public function setOutput($value) { $this->output = $value; }
}
class FLoad {
	public $models = array();
	public function model($path) { $this->models[] = $path; }
	public function language($path) { return array('button_confirm' => 'Потвърди', 'text_loading' => 'Обработване', 'heading_title' => 'Регистрирана заявка', 'text_manual' => 'Не е изпратена до банката.', 'text_accepted' => 'Transport accepted', 'button_continue' => 'Продължи', 'text_order' => 'Поръчка'); }
	public function controller($path) { return ''; }
	public function view($path, $data) { return $data; }
}
require DIR_SYSTEM . 'library/jetcredit/financial.php';
require DIR_SYSTEM . 'library/jetcredit/offer.php';
require DIR_SYSTEM . 'library/jetcredit/submission_store.php';
require DIR_APPLICATION . 'model/extension/mt_jet_credit/jetcredit.php';
require DIR_APPLICATION . 'model/extension/mt_jet_credit/financial.php';
require DIR_APPLICATION . 'controller/extension/mt_jet_credit/jetcredit.php';
require DIR_APPLICATION . 'controller/extension/payment/jet.php';
require DIR_APPLICATION . 'controller/extension/payment/jet_card.php';
require DIR_APPLICATION . 'model/extension/payment/jet.php';
require DIR_APPLICATION . 'model/extension/payment/jet_card.php';
class FScope extends JetCreditNativeMailScope { public function available() { return true; } }
class FUrl { public function link($route, $query = '', $secure = false) { return 'https://test.invalid/index.php?route=' . $route . ($query !== '' ? '&' . $query : ''); } }
function ffixture() {
	$r = new Registry();
	$r->set('model_extension_mt_jet_credit_submission',new class($r) { private $r; public function __construct($r){$this->r=$r;} public function identity(){return new JetCreditSubmissionIdentity($this->r->get('session'),new JetCreditOffer(JetCreditOffer::configuredSecret($this->r->get('config'))),new JetCreditSubmissionStore($this->r->get('db'),DB_PREFIX),$this->r->get('customer')->getId());} public function existingOutcome($context,$entity,$intent) { return null; } });
	$r->set('jetcredit_runtime_loaded', true); $r->set('jetcredit_native_mail_scope', new FScope()); $r->set('url', new FUrl());
	foreach (array('config' => new FConfig(), 'session' => new FSession(), 'customer' => new FCustomer(), 'currency' => new FCurrency(), 'tax' => new FTax(), 'db' => new FDb(), 'cart' => new FCart(), 'model_catalog_product' => new FProduct(), 'model_setting_extension' => new FExtensions(), 'model_checkout_order' => new FOrder(), 'load' => new FLoad(), 'response' => new FResponse(), 'request' => (object)array('server' => array('REQUEST_METHOD' => 'POST'), 'post' => array())) as $key => $value) { $r->set($key, $value); }
	foreach ($r->get('model_setting_extension')->codes as $code) { $r->set('model_extension_total_' . $code, new FTotal($code)); }
	$r->set('model_extension_mt_jet_credit_jetcredit', new ModelExtensionMtJetCreditJetcredit($r));
	$r->set('model_extension_mt_jet_credit_financial', new ModelExtensionMtJetCreditFinancial($r));
	$r->get('model_checkout_order')->totals = $r->get('model_extension_mt_jet_credit_financial')->cartSnapshot()['totals'];
	return $r;
}
function finput($extra = array()) { return array_merge(array('jet_csrf' => 'csrf-one', 'jet_product_id' => '42', 'jet_quantity' => '2', 'jet_parva' => '0', 'jet_vnoski' => '12', 'option' => array()), $extra); }
$fchecks = 0;
function fcheck($condition, $label) { global $fchecks; ++$fchecks; if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } }
function freject($callback, $codes = null) {
	try { $callback(); } catch (JetCreditFinancialException $e) { fcheck($codes === null || in_array($e->getMessage(), (array)$codes, true), 'rejection: ' . $e->getMessage()); return; }
	fcheck(false, 'expected financial rejection');
}
