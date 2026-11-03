<?php
// JSON bridge to the real Product endpoints; all services are local doubles.
error_reporting(E_ALL & ~E_DEPRECATED);
define('DIR_APPLICATION', dirname(__DIR__) . '/upload/catalog/');
define('DB_PREFIX', 'oc_');
#[AllowDynamicProperties] class Controller {}
#[AllowDynamicProperties] class Model {}
require DIR_APPLICATION . 'controller/extension/mt_jet_credit/jetcredit.php';
require DIR_APPLICATION . 'model/extension/mt_jet_credit/jetcredit.php';

class F02Config {
    public $values;
    function __construct($input) {
        $this->values = ['config_currency' => $input['base_currency'] ?? 'EUR', 'config_tax' => $input['tax'] ?? false,
            'module_mt_jet_credit_price' => $input['minimum'] ?? 75, 'module_mt_jet_credit_vnoski' => 12,
            'module_mt_jet_credit_purcent' => 1.4, 'module_mt_jet_credit_purcent_card' => 1.0];
    }
    function get($key) { return $this->values[$key] ?? null; }
}
class F02Currency {
    function convert($value, $from, $to) {
        $rates = ['EUR' => 1, 'USD' => 2];
        return $value * $rates[$to] / $rates[$from];
    }
}
class F02Tax {
    function calculate($value, $class, $enabled) { return $enabled ? $value * 1.2 : $value; }
}
class F02Products {
    public $input;
    function __construct($input) { $this->input = $input; }
    function getProduct($id) { return ['price' => $this->input['base_price'], 'special' => 0, 'tax_class_id' => 1]; }
    function getProductOptions($id) { return $this->input['options']; }
}
class F02DB {
    public $input, $queries = [];
    function __construct($input) { $this->input = $input; }
    function escape($value) { return str_replace("'", "\\'", $value); }
    function query($sql) {
        $this->queries[] = $sql;
        if (!preg_match('/AND ([0-9.eE+-]+) >= jet_product_price/', $sql, $amount)
            || !preg_match('/FIND_IN_SET\((\d+),/', $sql, $term)) throw new RuntimeException('Unexpected promotion query');
        // One active fixture promotion; the production getPromo SQL supplies its principal and term.
        $matched = isset($this->input['promo_min']) && (float)$amount[1] >= $this->input['promo_min']
            && (int)$term[1] === ($this->input['promo_term'] ?? 12);
        return (object)['num_rows' => $matched ? 1 : 0, 'row' => ['jet_product_percent' => $this->input['promo_percent'] ?? 0.5]];
    }
}
class F02Response {
    public $output;
    function addHeader($value) {}
    function setOutput($value) { $this->output = $value; }
}
class F02Loader { function model($name) {} }

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$controller = new ControllerExtensionMtJetCreditJetCredit();
$controller->config = new F02Config($input);
$controller->currency = new F02Currency();
$controller->tax = new F02Tax();
$controller->session = (object)['data' => ['currency' => 'EUR']];
$controller->load = new F02Loader();
$controller->response = new F02Response();
if ($input['mode'] === 'options') {
    $controller->model_catalog_product = new F02Products($input);
    $controller->request = (object)['post' => ['product_id' => 1, 'option' => $input['selected']]];
    $controller->jet_check();
    $base = $controller->tax->calculate($input['base_price'], 1, $controller->config->get('config_tax'));
    echo json_encode(['base_eur' => $controller->currency->convert($base, $input['base_currency'], 'EUR'),
        'options' => json_decode($controller->response->output, true)], JSON_THROW_ON_ERROR);
} elseif ($input['mode'] === 'quote') {
    $model = new ModelExtensionMtJetCreditJetcredit();
    $model->config = $controller->config;
    $model->db = new F02DB($input);
    $controller->model_extension_mt_jet_credit_jetcredit = $model;
    $controller->request = (object)['post' => $input['post']];
    $controller->jet_calculate();
    echo json_encode(['quote' => json_decode($controller->response->output, true), 'promo_sql' => $model->db->queries], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown F02 fixture mode');
}
