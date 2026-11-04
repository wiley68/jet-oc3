<?php
// ARCH-02: representative native OC fixture with in-memory SQL; public evaluator exercised.
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($level, $message, $file, $line) {
    if (!(error_reporting() & $level)) return true;
    throw new ErrorException($message, 0, $level, $file, $line);
});
define('VERSION', getenv('JET_TEST_OC_VERSION') ?: '3.0.3.8');
define('DB_PREFIX', 'oc_');
$scratch = sys_get_temp_dir() . '/jet-cart-native-' . bin2hex(random_bytes(6)) . '/';
$fixtureRoot = $scratch . 'native/';
$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/opencart-3.0.3.8.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixture['files'] as $path => $source) {
    if (!is_dir(dirname($fixtureRoot.$path))) mkdir(dirname($fixtureRoot.$path), 0700, true);
    file_put_contents($fixtureRoot.$path, $source);
}
register_shutdown_function(function () use ($scratch) {
    if (!is_dir($scratch)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($scratch);
});
define('DIR_SYSTEM', $fixtureRoot . 'system/');
define('DIR_APPLICATION', $fixtureRoot . 'catalog/');
// A valid configured language root need not be catalog/language.
define('DIR_LANGUAGE', $fixtureRoot . 'merchant-languages/');
foreach ($fixture['files'] as $path => $source) if (strpos($path, 'catalog/language/') === 0) {
    $target = DIR_LANGUAGE . substr($path, strlen('catalog/language/'));
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
    file_put_contents($target, $source);
}
define('DIR_MODIFICATION', $scratch . 'modification/');
require DIR_SYSTEM . 'startup.php'; // Native function/autoload/engine setup only; never framework/start().
error_reporting(E_ALL & ~E_DEPRECATED);
foreach (['config', 'language', 'request', 'session', 'db', 'db/mysqli', 'cart/cart', 'cart/customer', 'cart/tax', 'cart/currency', 'cart/weight'] as $file) require DIR_SYSTEM . 'library/' . $file . '.php';
require dirname(__DIR__) . '/upload/catalog/model/extension/mt_jet_credit/jetcredit.php';
$checks = 0;
function check($value, $label) { global $checks; ++$checks; if (!$value) throw new RuntimeException($label); }
function blank($class) { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function put($object, $key, $value) {
    if ((new ReflectionObject($object))->hasProperty($key)) {
        $p = new ReflectionProperty($object, $key); $p->setAccessible(true); $p->setValue($object, $value);
    } else { $object->$key = $value; }
}
function internal($model, $name, ...$args) {
    $m = new ReflectionMethod($model, $name); $m->setAccessible(true); return $m->invokeArgs($model, $args);
}
class CartNativeMemoryDB {
    public $queries = [], $writes = [], $extensionOrder = [], $coupon = true, $voucher = true, $balance = 75, $invalidRow = false, $empty = false, $price = 250, $throwAfterVoucher = false, $translations = [], $taxNames = [];
    function escape($value) { return str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value); }
    function query($sql) {
        $this->queries[] = $sql;
        if (!preg_match('/^SELECT /', $sql)) { $this->writes[] = $sql; return true; }
        $rows = [];
        if (strpos($sql, 'FROM oc_extension ') !== false) {
            $codes = $this->extensionOrder ?: ['total', 'voucher', 'tax', 'reward', 'coupon', 'shipping', 'sub_total'];
            foreach ($codes as $index => $code) $rows[] = ['extension_id' => $index + 1, 'type' => 'total', 'code' => $code];
        } elseif (strpos($sql, 'FROM oc_cart WHERE') !== false) {
            if (!$this->empty) $rows[] = ['cart_id' => 11, 'product_id' => 1, 'quantity' => 2, 'option' => '{}', 'recurring_id' => 0];
        } elseif (strpos($sql, 'FROM oc_product_to_store ') !== false) {
            if (!$this->invalidRow) $rows[] = ['product_id' => 1, 'name' => 'Продукт & "A"', 'model' => 'P', 'shipping' => 1, 'image' => '', 'quantity' => 10,
                'minimum' => 1, 'subtract' => 1, 'price' => $this->price, 'points' => 50, 'tax_class_id' => 1,
                'weight' => 1, 'weight_class_id' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'length_class_id' => 1];
        } elseif (strpos($sql, 'FROM `oc_coupon` WHERE code') !== false) {
            if ($this->coupon) $rows[] = ['coupon_id' => 1, 'code' => 'C10', 'name' => 'Coupon', 'type' => 'P', 'discount' => 10, 'shipping' => 0,
                'total' => 0, 'uses_total' => 0, 'uses_customer' => 0, 'logged' => 0, 'date_start' => '', 'date_end' => '', 'status' => 1, 'date_added' => ''];
        } elseif (strpos($sql, 'FROM oc_voucher v ') !== false) {
            if ($this->voucher) $rows[] = ['voucher_id' => 1, 'order_id' => 0, 'code' => 'V75', 'from_name' => 'A', 'from_email' => 'a@example.invalid',
                'to_name' => 'B', 'to_email' => 'b@example.invalid', 'voucher_theme_id' => 1, 'theme' => 'Gift', 'message' => '', 'image' => '', 'amount' => 75, 'status' => 1, 'date_added' => ''];
        } elseif (strpos($sql, 'FROM `oc_voucher_history` ') !== false) {
            $rows[] = ['total' => $this->balance - 75];
        } elseif (strpos($sql, 'FROM oc_customer_reward ') !== false) {
            $rows[] = ['total' => 200];
        } elseif (strpos($sql, 'SELECT name FROM oc_tax_rate ') !== false) {
            if ($this->throwAfterVoucher && strpos($sql, "'99'") !== false) throw new RuntimeException('injected after voucher cleanup');
            preg_match("/tax_rate_id = '(\\d+)'/", $sql, $match);
            $rows[] = ['name' => $this->taxNames[(int)($match[1] ?? 0)] ?? (strpos($sql, "'2'") !== false ? 'Fixed' : 'VAT')];
        } elseif (strpos($sql, 'FROM oc_translation ') !== false) {
            $rows = $this->translations;
        } elseif (strpos($sql, 'COUNT(*)') !== false) {
            $rows[] = ['total' => 0];
        }
        return (object)['rows' => $rows, 'row' => $rows ? $rows[0] : [], 'num_rows' => count($rows)];
    }
}
function environment($languageCode = 'en-gb', $languageId = 1) {
    $r = new Registry(); $config = new Config();
    foreach (['config_store_id' => 0, 'config_language_id' => $languageId, 'config_language' => $languageCode, 'config_customer_group_id' => 1,
        'config_currency' => 'EUR', 'config_customer_price' => 0, 'config_tax' => 1] as $k => $v) $config->set($k, $v);
    foreach (['sub_total' => 1, 'shipping' => 3, 'coupon' => 4, 'reward' => 5, 'tax' => 6, 'voucher' => 7, 'total' => 9] as $code => $sort) {
        $config->set('total_' . $code . '_status', 1); $config->set('total_' . $code . '_sort_order', $sort);
    }
    $session = blank('Session'); put($session, 'session_id', 'memory-only');
    $session->data = ['language' => $languageCode, 'currency' => 'EUR', 'coupon' => 'C10', 'voucher' => 'V75', 'reward' => 50,
        'shipping_method' => ['title' => 'Shipping', 'cost' => 10, 'tax_class_id' => 1],
        'jet_product_quotes' => ['product' => ['amount' => '1.00']], 'jet_cart_quotes' => ['tabA' => ['amount' => '177.00'], 'tabB' => ['amount' => '177.00']]];
    $db = new CartNativeMemoryDB(); $request = blank('Request');
    $language = new Language($languageCode); $language->data['existing'] = 'unchanged';
    $nested = new Language($languageCode); $nested->data['x'] = 'original'; $language->data['nested'] = $nested;
    $customer = blank('Cart\Customer'); put($customer, 'customer_id', 0);
    foreach (compact('config', 'session', 'db', 'request') as $k => $v) put($customer, $k, $v);
    $tax = blank('Cart\Tax'); put($tax, 'config', $config); put($tax, 'db', $db);
    put($tax, 'tax_rates', [1 => [1 => ['tax_rate_id' => 1, 'name' => 'VAT', 'rate' => 20, 'type' => 'P', 'priority' => 1]]]);
    $currency = blank('Cart\Currency'); put($currency, 'db', $db); put($currency, 'language', $language);
    put($currency, 'currencies', ['EUR' => ['value' => 1], 'USD' => ['value' => 2]]);
    $weight = blank('Cart\Weight'); put($weight, 'db', $db); put($weight, 'config', $config);
    $cart = blank('Cart\Cart');
    foreach (compact('config', 'session', 'db', 'customer', 'tax', 'weight') as $k => $v) put($cart, $k, $v);
    foreach (compact('config', 'session', 'db', 'request', 'language', 'customer', 'tax', 'currency', 'weight', 'cart') as $k => $v) $r->set($k, $v);
    $r->set('load', new Loader($r)); $r->set('event', new Event($r));
    $r->get('event')->register('language/*/after', new Action('event/translation'));
    return [new ModelExtensionMtJetCreditJetcredit($r), $r];
}
function evaluate($model) { return $model->evaluateCartNativeTotals(); }
[$m, $r] = environment(); $before = serialize($r->get('session')->data);
$result = evaluate($m);
check($result['success'], 'native totals succeed: ' . json_encode($result));
// Independent arithmetic: 500 + 10 - 50 - 250 + 42 - 75, not evaluator-derived.
check($result['amount_eur'] === 177.0, 'independent native golden amount 177');
check(array_column($result['totals'], 'code') === ['sub_total','shipping','coupon','reward','tax','voucher','total'], 'discovery/configured order and output sort');
check(serialize($r->get('session')->data) === $before, 'ordinary successful pass preserves session');
check(evaluate($m) === $result, 'stable repeated evaluation');
[$m, $r] = environment();
unset($r->get('session')->data['coupon'], $r->get('session')->data['reward'], $r->get('session')->data['voucher']);
put($r->get('tax'), 'tax_rates', []);
check((float)evaluate($m)['amount_eur'] === 510.0, 'no-tax native context golden');
put($r->get('tax'), 'tax_rates', [1 => [1 => ['tax_rate_id'=>1,'name'=>'VAT','rate'=>20,'type'=>'P','priority'=>1],
    2 => ['tax_rate_id'=>2,'name'=>'Fixed','rate'=>2,'type'=>'F','priority'=>1]]]);
// 500 products + 10 shipping + 100 product VAT + 2 shipping VAT + 4 product fixed + 2 shipping fixed.
check(evaluate($m)['amount_eur'] === 618.0, 'fixed and percentage tax native golden');

[$m, $r] = environment(); $r->get('config')->set('total_tax_sort_order', 10);
check(evaluate($m)['amount_eur'] === 42.0, 'verified native tax last record selected instead of total=177');
[$m, $r] = environment(); $r->get('config')->set('total_shipping_sort_order', 9);
unset($r->get('session')->data['coupon'], $r->get('session')->data['reward'], $r->get('session')->data['voucher']);
// Equal extension orders compare the returned records too: extension_id=1 (total)
// runs before extension_id=6 (shipping). The total record therefore captures 600.
check(evaluate($m)['amount_eur'] === 600.0, 'equal configured orders follow native secondary array sorting');
[$m, $r] = environment(); $r->get('config')->set('config_currency', 'USD');
check(evaluate($m)['amount_eur'] === 88.5, 'native USD to EUR conversion without added rounding');
put($r->get('currency'), 'currencies', ['EUR' => ['value' => 1], 'USD' => ['value' => 4]]);
check(evaluate($m)['amount_eur'] === 44.25, 'new evaluation reflects current conversion rates');
put($r->get('currency'), 'currencies', ['EUR' => ['value' => 1], 'USD' => ['value' => 0]]);
check(evaluate($m)['code'] === 'invalid_currency', 'invalid rate fails without amount');

[$m, $r] = environment(); $r->get('db')->coupon = false;
check(evaluate($m)['amount_eur'] === 237.0 && isset($r->get('session')->data['coupon']), 'expired native coupon omitted without session mutation');
foreach (['disabled','exhausted','zero'] as $kind) {
    [$m,$r]=environment();
    if ($kind==='disabled') $r->get('db')->voucher=false;
    if ($kind==='exhausted') $r->get('db')->balance=0;
    if ($kind==='zero') { $r->get('db')->price=0; $r->get('session')->data['shipping_method']['cost']=0; unset($r->get('session')->data['coupon'],$r->get('session')->data['reward']); }
    $result=evaluate($m);
    check($result['success'] && !isset($r->get('session')->data['voucher']), 'native voucher cleanup accepted '.$kind);
    check($result['context']['session']['voucher']===null, 'post-normalization context '.$kind);
}
[$m,$r]=environment(); $r->get('db')->invalidRow=true;
check(evaluate($m)['code']==='unsupported_cart' && count($r->get('db')->writes)===1, 'native invalid-item DELETE accepted, empty basket rejected');
[$m,$r]=environment(); $r->get('db')->empty=true;
check(evaluate($m)['code']==='unsupported_cart','empty basket');
[$m,$r]=environment(); $r->get('session')->data['vouchers']=[['amount'=>20]];
check(evaluate($m)['code']==='unsupported_cart','purchased vouchers remain outside existing Cart financing contract');
[$m,$r]=environment();
foreach (['sub_total','shipping','coupon','reward','tax','voucher','total'] as $code) $r->get('config')->set('total_'.$code.'_status',0);
check(evaluate($m)['code']==='missing_totals','no amount fallback');
[$m,$r]=environment();$r->get('config')->set('config_customer_price',1);
check(evaluate($m)['code']==='prices_hidden','price visibility');

// Custom model runs through the native Loader and Proxy, with no source profile.
file_put_contents(DIR_APPLICATION.'model/extension/total/merchant.php', <<<'CUSTOM'
<?php
class ModelExtensionTotalMerchant extends Model {
 public function getTotal($data) {
  $GLOBALS['merchant_calls']++;
  if (isset($GLOBALS['merchant_hook'])) ($GLOBALS['merchant_hook'])($this->registry);
  if (isset($GLOBALS['merchant_rows'])) { $data['totals']=$GLOBALS['merchant_rows']; return; }
  $data['totals'][]=['code'=>'merchant','title'=>'Merchant','value'=>$GLOBALS['merchant_amount'],'sort_order'=>99];
 }
}
CUSTOM
);
function merchantEnvironment() {
 [$m,$r]=environment();
 $r->get('db')->extensionOrder=['total','voucher','tax','reward','coupon','shipping','sub_total','merchant'];
 $r->get('config')->set('total_merchant_status',1);$r->get('config')->set('total_merchant_sort_order',99);
 $GLOBALS['merchant_calls']=0;$GLOBALS['merchant_amount']=211.0;unset($GLOBALS['merchant_hook'],$GLOBALS['merchant_rows']);
 return [$m,$r];
}
[$m,$r]=merchantEnvironment();$r->get('request')->get=['route'=>'extension/mt_jet_credit/jetcredit/jet_calculate_cart'];
check(evaluate($m)['amount_eur']===211.0 && $GLOBALS['merchant_calls']===1,'custom total last, one native pass');
check($r->get('request')->get['route']==='extension/mt_jet_credit/jetcredit/jet_calculate_cart','real request route preserved');
$GLOBALS['merchant_amount']=INF;check(evaluate($m)['code']==='invalid_total','nonfinite custom result');
$GLOBALS['merchant_amount']='browser-invalid';check(evaluate($m)['code']==='invalid_total','nonnumeric custom result');
[$m,$r]=merchantEnvironment();$GLOBALS['merchant_hook']=function($registry) {
 $nested=(new ModelExtensionMtJetCreditJetcredit($registry))->evaluateCartNativeTotals();
 check($nested===['success'=>false,'code'=>'evaluation_failed'],'recursive model instance rejected');
};
check(evaluate($m)['code']==='evaluation_failed' && $GLOBALS['merchant_calls']===1,'outer pass poisoned, no recursive totals');
unset($GLOBALS['merchant_hook']);check(evaluate($m)['success'],'guard released after recursion');
$GLOBALS['merchant_hook']=function($registry){throw new RuntimeException('merchant exception');};
check(evaluate($m)['code']==='evaluation_failed','extension exception fails closed');
unset($GLOBALS['merchant_hook']);check(evaluate($m)['success'],'guard released after exception');
foreach (['shipping','price','rate','customer','configuration'] as $change) {
 [$m,$r]=merchantEnvironment();$GLOBALS['merchant_hook']=function($registry) use($change) {
  if($change==='shipping')$registry->get('session')->data['shipping_method']['cost']=33;
  if($change==='price')$registry->get('db')->price=999;
  if($change==='rate')put($registry->get('currency'),'currencies',['EUR'=>['value'=>2],'USD'=>['value'=>2]]);
  if($change==='customer')put($registry->get('customer'),'customer_id',5);
  if($change==='configuration')$registry->get('config')->set('total_tax_status',0);
 };
 check(evaluate($m)['code']==='evaluation_session_changed','mid-pass ambiguity rejected '.$change);
}
unset($GLOBALS['merchant_hook']);
// Native translation code and IDs are trusted data/application behavior.
foreach (['bg-bg','merchant-code'] as $i=>$code) {
 [$m,$r]=environment($code,$i+2);
 check(evaluate($m)['amount_eur']===177.0,'language-independent native amount '.$code);
}
// Ordinary language PHP and loader events use the native trusted application path.
[$m,$r]=environment();
file_put_contents(DIR_LANGUAGE.'en-gb/extension/total/total.php',"<?php \$_['text_total']='Merchant total'; \$GLOBALS['native_language_loaded']=true;");
$result=evaluate($m);
check($result['success'] && !empty($GLOBALS['native_language_loaded']),'native language PHP executes normally');
check(array_column($result['totals'],'title','code')['total']==='Merchant total','native translated title preserved');
// Totals row ties retain native full-row secondary comparisons.
$rows=[['code'=>'z','title'=>'Z','value'=>7,'sort_order'=>5],['code'=>'a','title'=>'A','value'=>9,'sort_order'=>5]];
$sort=[5,5];array_multisort($sort,SORT_ASC,$rows);
[$m,$r]=merchantEnvironment();$GLOBALS['merchant_rows']=$rows;
$tieResult=evaluate($m);check(($tieResult['base_amount']??null)===end($rows)['value'],'equal resulting total rows follow native sorting '.json_encode($tieResult));
unset($GLOBALS['merchant_rows']);
// Data snapshots strip aliases; this is financial evidence, not execution isolation.
[$m,$r]=environment();$v=1;$data=['a'=>&$v,'nested'=>['b'=>&$v]];
$copy=internal($m,'cartSnapshotValue',$data);$v=2;
check($copy['a']===1 && $copy['nested']['b']===1,'snapshot does not retain references');
$cycle=[];$cycle['self']=&$cycle;
try {internal($m,'cartSnapshotValue',$cycle);check(false,'cycle accepted');} catch(JetCartTotalsEvaluationFailure $e){check(true,'bounded snapshot rejects cycle');}
echo "PASS $checks native Cart authority checks; representative fixture and SQL doubles\n";
