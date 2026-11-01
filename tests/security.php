<?php
/** IMP-01 isolated tests. No OpenCart, database connection or mail transport. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

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
class FakeResponse {
	public $headers = array();
	public $output = '';
	public function addHeader($value) { $this->headers[] = $value; }
	public function setOutput($value) { $this->output = $value; }
}
class FakeUser {
	public $logged = true;
	public $modify = true;
	public function isLogged() { return $this->logged; }
	public function hasPermission($type, $route) {
		return $this->modify && $type === 'modify' && $route === 'extension/module/mt_jet_credit';
	}
}
class FakeConfig {
	public $store = 0;
	public function get($key) { return $key === 'config_store_id' ? $this->store : null; }
}
class FakeLoad {
	public $calls = array();
	public $viewData;
	public function model($route) { $this->calls[] = $route; }
	public function language($route) {}
	public function controller($route) { return '<div>trusted layout</div>'; }
	public function view($route, $data) { $this->viewData = $data; return 'rendered-view-placeholder'; }
}
class FakeDocument {
	public function setTitle($title) {}
}
class FakeUrl {
	public function link($route, $query, $secure) { return 'https://test.invalid/admin/index.php?route=' . $route . '&amp;' . $query; }
}
class FakeDb {
	public $queries = array();
	public $writes = array();
	public $rows = array();
	public $products = array(42, 43);
	public $affected = 0;
	public $fail = false;
	public function escape($value) { return str_replace(array('\\', "'"), array('\\\\', "\\'"), $value); }
	public function countAffected() { return $this->affected; }
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->fail) { throw new RuntimeException('PRIVATE SQL /srv/store/config.php EGN: 1234567890'); }
		$this->affected = 0;
		$rows = array();
		if (strpos($sql, 'SELECT p.product_id') === 0) {
			preg_match("/p.product_id = '([0-9]+)'/", $sql, $id);
			if (in_array((int)$id[1], $this->products, true) && strpos($sql, "p2s.store_id = '0'") !== false) { $rows[] = array('product_id' => (int)$id[1]); }
		} elseif (strpos($sql, 'SELECT id FROM') === 0) {
			preg_match("/jet_product_id = '([^']+)'/", $sql, $selector);
			$rows = array_values(array_filter($this->rows, function ($row) use ($selector) { return $row['jet_product_id'] === $selector[1]; }));
		} elseif (strpos($sql, 'INSERT INTO') === 0) {
			$this->writes[] = $sql;
			preg_match_all("/jet_product_([a-z]+) = '([^']*)'/", $sql, $fields, PREG_SET_ORDER);
			$row = array('id' => count($this->rows) + 1);
			foreach ($fields as $field) { $row['jet_product_' . $field[1]] = $field[2]; }
			$this->rows[] = $row;
			$this->affected = 1;
		} elseif (strpos($sql, 'DELETE FROM') === 0) {
			$this->writes[] = $sql;
			if (!preg_match("/^DELETE FROM oc_jet_kop WHERE id = '([1-9][0-9]*)'$/D", $sql, $id)) { throw new RuntimeException('Unexpected DELETE syntax'); }
			$before = count($this->rows);
			$this->rows = array_values(array_filter($this->rows, function ($row) use ($id) { return $row['id'] !== (int)$id[1]; }));
			$this->affected = $before - count($this->rows);
		} elseif (strpos($sql, 'SHOW TABLES') === 0) {
			$rows[] = array('table' => 'oc_jet_kop');
		} elseif (strpos($sql, 'SELECT * FROM oc_jet_kop') === 0) {
			$rows = $this->rows;
		} elseif (strpos(ltrim($sql), 'SELECT jet_product_percent') === 0) {
			$rows = $this->rows;
		} else { throw new RuntimeException('Unexpected query in isolated harness'); }
		return (object)array('num_rows' => count($rows), 'rows' => $rows, 'row' => $rows[0] ?? array());
	}
}

define('DB_PREFIX', 'oc_');
define('DIR_SYSTEM', __DIR__ . '/../upload/system/');
require __DIR__ . '/../upload/admin/model/extension/module/jetcredit.php';
require __DIR__ . '/../upload/admin/controller/extension/module/mt_jet_credit.php';
require __DIR__ . '/../upload/catalog/model/extension/mt_jet_credit/jetcredit.php';

$checks = 0;
function check($condition, $label) {
	global $checks;
	++$checks;
	if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}
function fixture() {
	$r = new Registry();
	$r->set('db', new FakeDb());
	$r->set('user', new FakeUser());
	$r->set('response', new FakeResponse());
	$r->set('load', new FakeLoad());
	$r->set('document', new FakeDocument());
	$r->set('url', new FakeUrl());
	$r->set('config', new FakeConfig());
	$r->set('session', (object)array('data' => array('user_token' => 'current-token')));
	$r->set('request', (object)array('server' => array('REQUEST_METHOD' => 'POST'), 'get' => array('user_token' => 'current-token'), 'post' => array()));
	$r->set('model_extension_module_jetcredit', new ModelExtensionModuleJetcredit($r));
	return $r;
}
function promotion($selector = '42_43') {
	return array('mt_jet_credit_product_id' => $selector, 'mt_jet_credit_product_percent' => '1.40', 'mt_jet_credit_product_meseci' => '3_6_9_10_12_15_18_24_30_36', 'mt_jet_credit_product_price' => '75.00', 'mt_jet_credit_product_start' => '2026-01-01', 'mt_jet_credit_product_end' => '2027-12-31');
}
function callRoute($r, $route) {
	(new ControllerExtensionModuleMTJetCredit($r))->$route();
	return json_decode($r->get('response')->output, true, 512, JSON_THROW_ON_ERROR);
}

// Missing methods are not routable by the standard OC3 action dispatcher.
$source = file_get_contents(__DIR__ . '/../upload/catalog/controller/extension/mt_jet_credit/jetcredit.php');
$tokens = token_get_all($source);
$functions = array();
for ($i = 0; $i < count($tokens); ++$i) {
	if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
		for ($j = $i + 1; $j < count($tokens); ++$j) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $functions[] = $tokens[$j][1]; break; }
		}
	}
}
check(!in_array('createSchema', $functions, true) && !in_array('deleteSchema', $functions, true), 'public catalog mutation routes removed');
check(!method_exists(ModelExtensionMtJetCreditJetcredit::class, 'addKop') && !method_exists(ModelExtensionMtJetCreditJetcredit::class, 'deleteKop'), 'catalog model is read-only');

foreach (array('createSchema', 'deleteSchema') as $route) {
	foreach (array('anonymous', 'missing_token', 'wrong_token', 'array_token', 'empty_session_token', 'readonly', 'get', 'post_store', 'get_store', 'other_store') as $case) {
		$r = fixture();
		$r->get('request')->post = $route === 'createSchema' ? promotion() : array('promotion_id' => '7');
		switch ($case) {
			case 'anonymous': $r->get('user')->logged = false; break;
			case 'missing_token': unset($r->get('request')->get['user_token']); break;
			case 'wrong_token': $r->get('request')->get['user_token'] = 'old-token'; break;
			case 'array_token': $r->get('request')->get['user_token'] = array('current-token'); break;
			case 'empty_session_token': $r->get('session')->data['user_token'] = ''; break;
			case 'readonly': $r->get('user')->modify = false; break;
			case 'get': $r->get('request')->server['REQUEST_METHOD'] = 'GET'; break;
			case 'post_store': $r->get('request')->post['store_id'] = 1; break;
			case 'get_store': $r->get('request')->get['store_id'] = 1; break;
			case 'other_store': $r->get('config')->store = 1; break;
		}
		$result = callRoute($r, $route);
		check($result['success'] === 'unsuccess', $route . ' rejects ' . $case);
		check($r->get('db')->queries === array() && $r->get('load')->calls === array(), $route . ' rejects before model/DB: ' . $case);
		check(in_array('HTTP/1.1 ' . ($case === 'get' ? '405 Method Not Allowed' : '403 Forbidden'), $r->get('response')->headers, true), 'controlled HTTP status');
	}
}

foreach (array(null, '', '0', '-1', '+1', '01', '1.0', '1e1', '7 OR 1=1', "7' OR '1'='1", '2147483648', '99999999999999999999', array('7'), true, 7.0) as $id) {
	$r = fixture(); $r->get('request')->post = array('promotion_id' => $id);
	check(callRoute($r, 'deleteSchema')['code'] === 'invalid_promotion', 'invalid row ID rejected');
	check($r->get('db')->queries === array(), 'invalid ID never reaches SQL');
}
$r = fixture();
$r->get('db')->rows = array(array('id' => 7, 'jet_product_id' => '42'), array('id' => 8, 'jet_product_id' => '42'));
$r->get('request')->post = array('promotion_id' => '7', 'mt_jet_credit_product_id' => "42' OR 1=1 --");
check(callRoute($r, 'deleteSchema')['success'] === 'success', 'authorized row delete accepted');
check(count($r->get('db')->rows) === 1 && $r->get('db')->rows[0]['id'] === 8, 'delete only selected row, not product selector');

foreach (array('*', '42', '42_43', '42_42') as $selector) {
	$r = fixture(); $r->get('request')->post = promotion($selector);
	check(callRoute($r, 'createSchema')['success'] === 'success', 'authorized create ' . $selector);
	check($r->get('db')->rows[0]['jet_product_id'] === $selector, 'selector preserved as string');
	check(count($r->get('db')->writes) === 1, 'one insert');
	check(callRoute($r, 'createSchema')['exist'] !== '', 'legacy duplicate response preserved');
	check(count($r->get('db')->writes) === 1, 'sequential duplicate does not insert');
}
$invalid = array(
	'id' => array('', '0', '-1', '042', '42__43', '42_', '_42', '42-43', '42,43', '42_0', '2147483648', '1234567890_1234567890', "42' OR 1=1 --", '<script>', '</script>', array('42')),
	'percent' => array('-2', '-1.01', '1000', '1.401', 'NaN', 'INF', '1e2', "0'; DELETE", array('1.4')),
	'meseci' => array('', '0', '7', '03', '3_7', '3__6', '3,6', '3_6_', str_repeat('3_', 30) . '3'),
	'price' => array('-1', '100000000', '0.001', 'NaN', 'INF', '1e2', '75,00', "1' OR 1=1", array('75')),
	'start' => array('2026-02-29', '2026-13-01', '2026-1-01', '0000-00-00', '999-01-01', '2028-01-01'),
	'end' => array('2026-02-30', '2025-12-31', '2027-12-31 OR 1=1')
);
foreach ($invalid as $field => $values) {
	foreach ($values as $value) {
		$r = fixture(); $data = promotion(); $data['mt_jet_credit_product_' . $field] = $value; $r->get('request')->post = $data;
		check(callRoute($r, 'createSchema')['code'] === 'invalid_promotion', 'invalid create field ' . $field);
		check($r->get('db')->queries === array(), 'all syntax validation before DB: ' . $field);
	}
}
foreach (array('id', 'percent', 'meseci', 'price', 'start', 'end') as $field) {
	$r = fixture(); $data = promotion(); unset($data['mt_jet_credit_product_' . $field]); $r->get('request')->post = $data;
	check(callRoute($r, 'createSchema')['code'] === 'invalid_promotion' && $r->get('db')->queries === array(), 'missing field rejected before DB');
}
foreach (array('999', '42_999') as $selector) {
	$r = fixture(); $r->get('request')->post = promotion($selector);
	check(callRoute($r, 'createSchema')['code'] === 'invalid_promotion', 'unavailable/cross-store product rejected');
	check($r->get('db')->writes === array(), 'product rejection has no write');
}
foreach (array('-1.00', '0.00', '0.80', '1.40', '999.99') as $percent) {
	$r = fixture(); $data = promotion('*'); $data['mt_jet_credit_product_percent'] = $percent; $data['mt_jet_credit_product_price'] = '99999999.99'; $data['mt_jet_credit_product_start'] = '2024-02-29'; $r->get('request')->post = $data;
	check(callRoute($r, 'createSchema')['success'] === 'success', 'valid decimal/date storage boundaries');
}
foreach (array('createSchema', 'deleteSchema') as $route) {
	$r = fixture(); $r->get('db')->fail = true; $r->get('request')->post = $route === 'createSchema' ? promotion() : array('promotion_id' => '7');
	check(callRoute($r, $route)['code'] === 'promotion_failed', 'DB exception isolated');
	check(strpos($r->get('response')->output, 'PRIVATE') === false && strpos($r->get('response')->output, '1234567890') === false, 'no SQL/path/PII in JSON');
}
// Direct model calls must also reject invalid identifiers, independently of the controller.
$r = fixture();
try { $r->get('model_extension_module_jetcredit')->deleteKop('1 OR 1=1'); check(false, 'model reject'); }
catch (InvalidArgumentException $e) { check($r->get('db')->queries === array(), 'model validates before SQL'); }
$r = fixture(); $r->get('db')->rows = array(array('jet_product_percent' => '0.80'));
$catalog = new ModelExtensionMtJetCreditJetcredit($r);
$promo = $catalog->getPromo('42_43', 12, 200);
check($promo['jet_purcent'] === '0.80' && $promo['jet_purcent_card'] === '0.80', 'catalog promotion selection retains raw decimal for strict financial validation');
check(strpos($r->get('db')->queries[0], "jet_product_id = '42_43'") !== false && strpos($r->get('db')->queries[0], 'id ASC') !== false, 'composite lookup and priority preserved');

// Exercise actual index() URL/JSON preparation; do not emulate a full Twig engine.

define('HTTP_CATALOG', 'https://test.invalid/');
define('HTTPS_CATALOG', 'https://test.invalid/');
require_once DIR_SYSTEM . 'library/jet_button_scheme.php';
$schemes = new ReflectionProperty(JetButtonScheme::class, 'schemes');
$schemes->setAccessible(true);
$original = $schemes->getValue();
$hostile = '</script><script>alert(1)</script>\'" Български';
$modified = $original; $modified[0]['label'] = $hostile;
$schemes->setValue(null, $modified);
$r = fixture(); $r->get('request')->server['REQUEST_METHOD'] = 'GET';
(new ControllerExtensionModuleMTJetCredit($r))->index();
$view = $r->get('load')->viewData;
foreach (array('create' => 'createSchema', 'delete' => 'deleteSchema') as $action => $method) {
	$url = $view['jet_promotion_' . $action . '_url'];
	check(strpos($url, '/admin/index.php?route=extension/module/mt_jet_credit/' . $method . '&user_token=current-token') !== false, 'actual index native admin URL');
	check(strpos($url, '&amp;') === false, 'URL normalized before Twig escaping');
}
check(strpos($view['mt_jet_button_scheme_labels_json'], '</script>') === false, 'actual controller JSON cannot close script');
check(json_decode($view['mt_jet_button_scheme_labels_json'], true)[0] === $hostile, 'controller JSON preserves quote/Unicode label');
$schemes->setValue(null, $original);
echo "T-SEC PASS: $checks assertions; isolated mocks, no live database/mail.\n";
