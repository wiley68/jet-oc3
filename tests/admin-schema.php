<?php
// Local registry, native-startup contract and SQL doubles. No database or store access.
error_reporting(E_ALL & ~E_DEPRECATED);
define('DB_PREFIX', 'oc_');
define('DIR_APPLICATION', dirname(__DIR__) . '/upload/catalog/');
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
define('HTTP_CATALOG', 'http://test.invalid/shop/');
define('HTTPS_CATALOG', 'https://test.invalid/shop/');
#[AllowDynamicProperties] class Controller {}
#[AllowDynamicProperties] class Model {}
function utf8_strlen($value) { return mb_strlen($value, 'UTF-8'); }
require dirname(__DIR__) . '/upload/admin/controller/extension/module/mt_jet_credit.php';
require dirname(__DIR__) . '/upload/admin/model/extension/module/jetcredit.php';
require dirname(__DIR__) . '/upload/catalog/controller/extension/mt_jet_credit/jetcredit.php';
require dirname(__DIR__) . '/upload/catalog/model/extension/mt_jet_credit/jetcredit.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
class SchemaDBDouble {
    public $rows = [], $queries = [], $escaped = [], $affected = 0;
    function escape($value) {
        $this->escaped[] = $value;
        return strtr($value, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"]);
    }
    private function literal($value) {
        return preg_replace_callback('/\\\\(.)/s', function ($m) {
            return ['0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a"][$m[1]] ?? $m[1];
        }, $value);
    }
    function query($sql) {
        $this->queries[] = $sql;
        $this->affected = 0;
        $literal = "'((?:[^'\\\\]|\\\\.)*)'";
        $result = [];
        if (preg_match('/^DELETE FROM oc_jet_kop WHERE jet_product_id = ' . $literal . '$/sD', $sql, $m)) {
            $id = $this->literal($m[1]);
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($id) {
                if ($row['jet_product_id'] === $id) { $this->affected++; return false; }
                return true;
            }));
        } elseif (preg_match('/^\s*SELECT \* FROM `oc_jet_kop`\s+WHERE jet_product_id = ' . $literal . '\s*$/sD', $sql, $m)) {
            $id = $this->literal($m[1]);
            $result = array_values(array_filter($this->rows, function ($row) use ($id) { return $row['jet_product_id'] === $id; }));
        } elseif (preg_match('/^\s*INSERT INTO `oc_jet_kop`\s+SET\s+/s', $sql)) {
            preg_match_all('/(jet_product_\w+)\s*=\s*' . $literal . '/s', $sql, $matches, PREG_SET_ORDER);
            $row = [];
            foreach ($matches as $match) { $row[$match[1]] = $this->literal($match[2]); }
            check(count($row) === 6, 'create preserves all six fields');
            $this->rows[] = $row;
            $this->affected = 1;
        } elseif ($sql === "SHOW TABLES LIKE 'oc_jet_kop'") {
            $result = [['table' => 'oc_jet_kop']];
        } elseif ($sql === 'SELECT * FROM oc_jet_kop') {
            $result = $this->rows;
        } else {
            throw new RuntimeException('Unexpected SQL or condition escaped its single literal: ' . $sql);
        }
        return (object) ['rows' => $result, 'num_rows' => count($result)];
    }
    function countAffected() { return $this->affected; }
}
class SchemaResponseDouble {
    public $output = '', $headers = [];
    function addHeader($header) { $this->headers[] = $header; }
    function setOutput($output) { $this->output = $output; }
}
class SchemaUserDouble {
    public $logged = true, $access = true, $modify = true;
    function isLogged() { return $this->logged; }
    function hasPermission($type, $route) {
        check($route === 'extension/module/mt_jet_credit', 'native module permission name');
        return $this->$type;
    }
}
class SchemaURLDouble {
    public $base;
    function __construct($base) { $this->base = $base; }
    function link($route, $args, $secure) {
        check($secure === true, 'native URL service receives secure flag');
        return $this->base . 'index.php?route=' . $route . '&amp;' . $args;
    }
}
class SchemaLoaderDouble {
    public $controller, $model, $loaded = [], $viewData;
    function __construct($controller, $model) { $this->controller = $controller; $this->model = $model; }
    function model($route) {
        $this->loaded[] = $route;
        if ($route === 'extension/module/jetcredit') {
            $this->controller->model_extension_module_jetcredit = $this->model;
        } elseif ($route !== 'setting/setting') {
            throw new RuntimeException('Unexpected model route: ' . $route);
        }
    }
    function language($route) {}
    function controller($route) { return ''; }
    function view($route, $data) { $this->viewData = $data; return 'admin view double'; }
}
function environment($id = '42', $method = 'POST') {
    $c = new ControllerExtensionModuleMTJetCredit();
    $db = new SchemaDBDouble();
    $model = new ModelExtensionModuleJetcredit(); $model->db = $db;
    $c->load = new SchemaLoaderDouble($c, $model);
    $c->request = (object) ['post' => ['mt_jet_credit_product_id' => $id], 'get' => ['user_token' => 'token-123'], 'server' => ['REQUEST_METHOD' => $method, 'HTTPS' => 'on']];
    $c->response = new SchemaResponseDouble();
    $c->user = new SchemaUserDouble();
    $c->session = (object) ['data' => ['user_token' => 'token-123']];
    return [$c, $db, $model];
}
// Models the existing OC3 startup/login + startup/permission boundary, not custom module authentication.
function dispatchAdmin($c, $action) {
    if (!$c->user->isLogged() || !isset($c->request->get['user_token'], $c->session->data['user_token']) || $c->request->get['user_token'] !== $c->session->data['user_token']) {
        $c->response->setOutput('<html>native login double</html>');
    } elseif (!$c->user->hasPermission('access', 'extension/module/mt_jet_credit')) {
        $c->response->setOutput('<html>native permission double</html>');
    } else {
        $c->$action();
    }
}
function createPayload($id) {
    return ['mt_jet_credit_product_id' => $id, 'mt_jet_credit_product_percent' => '0.80', 'mt_jet_credit_product_meseci' => '6_12', 'mt_jet_credit_product_price' => '100.00', 'mt_jet_credit_product_start' => '2026-01-01', 'mt_jet_credit_product_end' => '2026-12-31'];
}
foreach (['createSchema', 'deleteSchema'] as $action) {
    foreach (['guest', 'storefront', 'missing-token', 'invalid-token', 'expired', 'no-access', 'no-modify', 'GET'] as $case) {
        [$c, $db] = environment();
        if ($case === 'guest' || $case === 'storefront') { $c->user->logged = false; $c->session->data = ['customer_id' => 7]; }
        if ($case === 'missing-token') { unset($c->request->get['user_token']); }
        if ($case === 'invalid-token') { $c->request->get['user_token'] = 'wrong'; }
        if ($case === 'expired') { $c->session->data = []; }
        if ($case === 'no-access') { $c->user->access = false; }
        if ($case === 'no-modify') { $c->user->modify = false; }
        if ($case === 'GET') { $c->request->server['REQUEST_METHOD'] = 'GET'; }
        dispatchAdmin($c, $action);
        check($db->queries === [], "$action $case performs no query");
        check($c->load->loaded === [], "$action $case does not load mutation model");
        check((json_decode($c->response->output, true)['success'] ?? '') !== 'success', "$action $case cannot report success");
        if ($case === 'GET') { check(in_array('HTTP/1.1 405 Method Not Allowed', $c->response->headers), 'GET returns 405'); }
        if ($case === 'no-modify') { check(in_array('HTTP/1.1 403 Forbidden', $c->response->headers), 'modify denial returns 403'); }
    }
    // Direct invocation still enforces module-owned POST/modify checks; native startup owns login/token/access.
    foreach (['GET', 'no-modify'] as $case) {
        [$c, $db] = environment();
        if ($case === 'GET') { $c->request->server['REQUEST_METHOD'] = 'GET'; } else { $c->user->modify = false; }
        $c->$action();
        check($db->queries === [], "$action direct $case is denied");
    }
}

foreach (['42', '*', '00042'] as $id) {
    [$c, $db] = environment($id);
    $c->request->post = createPayload($id);
    dispatchAdmin($c, 'createSchema');
    check(json_decode($c->response->output, true) === ['success' => 'success', 'exist' => ''], 'authorized create succeeds without index');
    check($db->rows[0]['jet_product_id'] === $id, 'created identifier preserved');
    check($db->rows[0]['jet_product_meseci'] === '6_12' && $db->rows[0]['jet_product_percent'] === '0.80', 'promotion fields preserved');
    dispatchAdmin($c, 'createSchema');
    check(json_decode($c->response->output, true) === ['success' => 'success', 'exist' => 'Вече имате такъв филтър!'], 'duplicate response preserved');
    check(count($db->rows) === 1, 'duplicate does not insert another row');
    $c->deleteSchema();
    check(json_decode($c->response->output, true) === ['success' => 'success'] && $db->rows === [], 'authorized delete succeeds without index');
    $c->deleteSchema();
    check(json_decode($c->response->output, true) === ['success' => 'unsuccess'], 'not found response preserved');
}
$valid = ['42', '*', '00042', str_repeat('a', 20), str_repeat('я', 20), "a'b", 'a\\b', "' OR 1=1 --", "\\' OR '1'='1", 'a"b', '&#39;', ' ', "a\0b", "a\nb"];
foreach ($valid as $id) {
    foreach ([1, 3] as $matches) {
        [$c, $db, $model] = environment($id);
        $db->rows = array_merge(array_fill(0, $matches, ['jet_product_id' => $id]), [['jet_product_id' => 'unrelated']]);
        dispatchAdmin($c, 'deleteSchema');
        check(json_decode($c->response->output, true) === ['success' => 'success'], 'valid string delete succeeds');
        check($db->affected === $matches, 'all matching rows deleted');
        check($db->rows === [['jet_product_id' => 'unrelated']], 'unrelated rows preserved');
        check($db->escaped === [$id], 'DB escape receives exact string');
        check($model->deleteKop($id) === 0, 'direct model not-found returns zero');
        $db->rows[] = ['jet_product_id' => $id];
        check($model->deleteKop($id) === 1, 'direct model valid identifier supported');
    }
}
foreach (['', null, [], ['42'], 42, true, new stdClass(), str_repeat('a', 21), str_repeat('я', 21)] as $id) {
    [$c, $db, $model] = environment($id);
    $c->deleteSchema();
    check(json_decode($c->response->output, true) === ['success' => 'unsuccess'], 'invalid identifier rejected by controller');
    check($c->load->loaded === [] && $db->queries === [], 'invalid identifier never reaches DB');
    check($model->deleteKop($id) === 0 && $db->queries === [], 'direct model invalid identifier rejected');
}
[$c, $db] = environment(); unset($c->request->post['mt_jet_credit_product_id']); $c->deleteSchema();
check($db->queries === [], 'missing identifier never reaches DB');
foreach (['createSchema', 'deleteSchema'] as $action) {
    check(!method_exists('ControllerExtensionMtJetCreditJetCredit', $action), 'obsolete public catalog action removed');
}
foreach (['addKop', 'deleteKop'] as $method) {
    check(!method_exists('ModelExtensionMtJetCreditJetcredit', $method), 'catalog mutation method removed');
}
foreach (['https://test.invalid/renamed-admin/', 'https://test.invalid/shop/secure-admin/'] as $base) {
    [$c, $db] = environment('', 'GET');
    $c->url = new SchemaURLDouble($base);
    $c->config = new class { function get($key) { return ''; } };
    $c->document = new class { function setTitle($title) {} };
    $c->index();
    foreach (['createSchema', 'deleteSchema'] as $action) {
        $encoded = $c->load->viewData[$action . '_url_json'];
        $url = json_decode($encoded, true);
        check($url === $base . 'index.php?route=extension/module/mt_jet_credit/' . $action . '&user_token=token-123', 'native admin AJAX URL preserves base and raw ampersand');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        check(($query['user_token'] ?? '') === 'token-123' && !isset($query['amp;user_token']), 'native token parameter remains intact');
        check(strpos($encoded, '<') === false && strpos($encoded, '&') === false, 'JSON URL safe for script context');
    }
}
echo "PASS $checks admin schema authorization/SQL checks; native startup contract double and in-memory SQL only\n";
