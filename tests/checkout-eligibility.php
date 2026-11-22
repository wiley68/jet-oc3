<?php
// Real Checkout controllers/model with the existing native Cart and promotion SQL doubles.
require __DIR__ . '/cart-financial.php';
require $repo . '/upload/catalog/model/extension/payment/jet.php';
require $repo . '/upload/catalog/model/extension/payment/jet_card.php';
require $repo . '/upload/catalog/controller/extension/payment/jet.php';
require $repo . '/upload/catalog/controller/extension/payment/jet_card.php';
$checkoutEligibilityStart = $checks;

class CheckoutEligibilityDB extends CartFinancialDB {
    public $geoMatches = 1;
    function query($sql) {
        if (strpos($sql, 'zone_to_geo_zone') === false) return parent::query($sql);
        $this->queries[] = $sql;
        return (object)['num_rows'=>$this->geoMatches];
    }
}
class CheckoutEligibilityOrders {
    public $context, $code, $overrides = [], $reads = 0, $histories = [], $orders = [];
    function getOrder($id) {
        ++$this->reads;
        return array_replace(['order_id'=>1, 'store_id'=>0, 'customer_id'=>0,
            'payment_code'=>$this->code, 'payment_country_id'=>1, 'payment_zone_id'=>1,
            'currency_code'=>'EUR', 'currency_value'=>1, 'total'=>$this->context['raw_amount'],
            'firstname'=>'Test', 'lastname'=>'User', 'telephone'=>'+359888888888', 'email'=>'client@example.invalid'], $this->overrides);
    }
    function getOrderProducts($id) {
        $rows = [];
        $prices = explode('_', $this->context['products_pr']);
        foreach ($this->context['products'] as $i=>$product) $rows[] = array_replace($product,
            ['order_product_id'=>$i+1, 'tax'=>(float)$prices[$i] - $product['price']]);
        return $rows;
    }
    function getOrderOptions($id, $line) { return $this->context['products'][$line-1]['option']; }
    function addOrderHistory(...$args) { $this->histories[] = $args; }
    function addOrder($data) { throw new RuntimeException('Checkout must not create an order'); }
}
class CheckoutEligibilityLoader {
    private $native;
    function __construct($native) { $this->native = $native; }
    function language($route) {}
    function model($route) {
        if (!in_array($route, ['checkout/order', 'extension/payment/jet', 'extension/payment/jet_card', 'extension/mt_jet_credit/jetcredit'], true)) $this->native->model($route);
    }
    function view($route, $data) { return $data; }
}
class CheckoutEligibilityJet extends ControllerExtensionPaymentJet {
    protected function submissionService() { return $this->registry->get('test_submission_service'); }
    protected function submissionOwner() { return $this->session->getId(); }
}
class CheckoutEligibilityCard extends ControllerExtensionPaymentJetCard {
    protected function submissionService() { return $this->registry->get('test_submission_service'); }
    protected function submissionOwner() { return $this->session->getId(); }
}
function checkoutEligibilityEnvironment($code = 'jet', $rules = [], $ids = ['1', '2']) {
    CartFinancialMailer::$messages = [];
    [$calc, $r] = cartEnvironment(basketCart($ids, $rules));
    $context = $r->get('model_extension_mt_jet_credit_jetcredit')->getCartContext();
    $db = new CheckoutEligibilityDB;
    foreach (get_object_vars($r->get('db')) as $key=>$value) $db->$key = $value;
    $r->set('db', $db);
    $r->set('load', new CheckoutEligibilityLoader($r->get('load')));
    $r->set('url', new class { function link($route, ...$args) { return $route; } });
    foreach (['payment_jet_status'=>1, 'payment_jet_card_status'=>1, 'config_store_id'=>0] as $key=>$value) $r->get('config')->set($key, $value);
    $orders = new CheckoutEligibilityOrders; $orders->context = $context; $orders->code = $code;
    $r->set('model_checkout_order', $orders);
    $r->set('model_extension_payment_jet', new ModelExtensionPaymentJet($r));
    $r->set('model_extension_payment_jet_card', new ModelExtensionPaymentJetCard($r));
    $r->get('session')->data['order_id'] = 1;
    $r->get('session')->data['guest'] = ['email'=>'client@example.invalid'];
    $r->get('session')->data['payment_method'] = ['code'=>$code];
    $r->get('session')->data['payment_methods'] = ['jet'=>['code'=>'jet'], 'jet_card'=>['code'=>'jet_card'], 'cod'=>['code'=>'cod']];
    return [$code === 'jet' ? new CheckoutEligibilityJet($r) : new CheckoutEligibilityCard($r), $calc, $r];
}
function checkoutEligibilityQuote($calc, $r, $code, $term = 12, $down = '100') {
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    $snapshot = $model->getCheckoutSnapshot($code);
    $input = ['jet_checkout_context'=>$code, 'jet_priceall'=>$model->checkoutFixed((float)(string)$snapshot['amount']), 'jet_vnoski'=>(string)$term, 'jet_parva'=>$down];
    foreach (['products'=>'products_key', 'products_qt'=>'products_qt', 'products_pr'=>'products_pr', 'products_vr'=>'products_vr'] as $field=>$key) $input['jet_'.$field] = $snapshot[$key];
    cartWire($r, $input); $calc->jet_calculate_cart();
    $quote = json_decode($r->get('response')->output, true);
    check($quote['success'] === 'success', 'legitimate exact Checkout quote');
    $post = [$code.'_vnoski'=>(string)$term, $code.'_parva'=>$down, $code.'_egn'=>'9001010000'];
    foreach (['products'=>'products_key', 'products_qt'=>'products_qt', 'products_pr'=>'products_pr', 'products_vr'=>'products_vr'] as $field=>$key) $post[$code.'_'.$field] = $snapshot[$key];
    foreach (['priceall', 'total_credit_price', 'vnoska', 'gpr', 'glp', 'obshto'] as $field) $post[$code.'_'.$field] = $quote['jet_'.$field.($code === 'jet_card' && !in_array($field, ['priceall', 'total_credit_price'], true) ? '_card' : '')];
    cartWire($r, $post);
    return $quote;
}
function checkoutEligibilityEffects($r) {
    return serialize([$r->get('test_submission_memory')->meta, $r->get('test_submission_memory')->ledger,
        $r->get('model_checkout_order')->histories, $r->get('model_checkout_order')->orders, $r->get('db')->writes, CartFinancialMailer::$messages]);
}
function checkoutEligibilityReject($controller, $r, $label) {
    CartFinancialMailer::$messages = [];
    $before = checkoutEligibilityEffects($r);
    $controller->confirm(); $out = json_decode($r->get('response')->output, true);
    check($out['success'] === 'unsuccess' && checkoutEligibilityEffects($r) === $before, 'refusal has no new order/bank/history/ledger effects: '.$label);
    return $out;
}

$allTerms = '3_6_9_10_12_15_18_24_30_36';
foreach ([
    [[], [12, 12]],
    [[basketRule('1', '-1', 1, '12'), basketRule('1', '1.20', 2, '3')], [3, 6]],
    [[basketRule('1', '-1', 1, '12')], [3, 3]],
    [[basketRule('1', '1.20', 1, $allTerms)], [12, null]],
    [[basketRule('1', '0.80', 1, $allTerms)], [null, 12]],
    [[basketRule('1', '0.00', 1, $allTerms)], [null, null]],
    [[basketRule('*', '0.00', 1, $allTerms)], [12, 12]],
    [[basketRule('*', '-1', 1, $allTerms)], [null, null]],
] as [$rules, $terms]) foreach (['jet', 'jet_card'] as $card=>$code) {
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code, $rules);
    $payment = $r->get('model_extension_payment_'.$code);
    $listed = $payment->getMethod(['country_id'=>1, 'zone_id'=>1], 1200);
    check((bool)$listed === ($terms[$card] !== null), 'independent native listing '.$code);
    if (!$listed) {
        check(!isset($r->get('session')->data['payment_method']) && !isset($r->get('session')->data['payment_methods'][$code]), 'listing invalidates only an unavailable current selection');
        // Model a late open from the previously rendered list independently of listing cleanup.
        $r->get('session')->data['payment_method'] = ['code'=>$code];
        $r->get('session')->data['payment_methods'][$code] = ['code'=>$code];
    }
    $reads = $r->get('model_checkout_order')->reads;
    $view = $controller->index();
    check($r->get('model_checkout_order')->reads === $reads + 1, 'opening resolves the persisted order once without recursion');
    if ($terms[$card] !== null) {
        check(is_array($view) && $view[$code.'_vnoski'] === $terms[$card], 'opening renders own discovered term');
        check(isset($r->get('session')->data['payment_methods']['jet'], $r->get('session')->data['payment_methods']['jet_card']), 'available method does not remove either cached channel');
    } else {
        $other = $card ? 'jet' : 'jet_card';
        check(is_string($view) && strpos($view, 'checkout/checkout') !== false && !isset($r->get('session')->data['payment_method']) && !isset($r->get('session')->data['payment_methods'][$code]), 'unavailable selected method invalidated');
        check(array_keys($r->get('session')->data['payment_methods']) === [$other, 'cod'], 'only own method removed; opposite and unrelated methods preserved');
    }
    check(!$r->get('test_submission_memory')->ledger && !CartFinancialMailer::$messages, 'listing/opening has no submission effects');
}

foreach (['jet', 'jet_card'] as $code) {
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code);
    $r->get('config')->set('module_mt_jet_credit_vnoski', 24);
    check($controller->index()[$code.'_vnoski'] === 24, 'valid configured initial term retained');
    $r->get('config')->set('module_mt_jet_credit_vnoski', 6);
    $r->get('model_checkout_order')->overrides['total'] = 124.99;
    check($controller->index()[$code.'_vnoski'] === 6, 'Checkout below 125 keeps configured 6 instead of Cart initial 9');
    $r->get('config')->set('module_mt_jet_credit_vnoski', 15);
    $r->get('model_checkout_order')->overrides['total'] = 400.9999;
    check($controller->index()[$code.'_vnoski'] === 3, 'unrounded snapshot threshold controls initial search');

    // Listing uses native Cart before order creation; opening/calculation must not.
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code, [basketRule('1', '0.50', 1, '12'), basketRule('2', '0.99', 2, '12')]);
    check($r->get('model_extension_payment_'.$code)->getMethod(['country_id'=>1, 'zone_id'=>1], 1200)['code'] === $code, 'invalid initial term still has a listed fallback');
    $r->set('cart', new class { function getProducts() { throw new RuntimeException('Live Cart must not supply Checkout context'); } });
    check($controller->index()[$code.'_vnoski'] === 3, 'opening uses persisted product IDs and total');
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    $snapshot = $model->getCheckoutSnapshot($code);
    $result = $model->checkoutQuoteResult($snapshot, ['jet_priceall'=>'1200.00', 'jet_vnoski'=>'12', 'jet_parva'=>'0']);
    check(!$result['quote'] && $result['invalid'] && !$result['excluded'], 'exact rate mismatch has no term fallback');
    $quote = checkoutEligibilityQuote($calc, $r, $code, 3, '0');
    check($quote['jet_priceall'] === '1200.00', 'exact quote ignores live Cart');
    $controller->confirm();
    check(json_decode($r->get('response')->output, true)['success'] === 'success', 'final confirmation also uses saved order without reading live Cart');

    // An initial-availability failure must not veto a valid already chosen scheme.
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code, [basketRule('*', '-1', 1, $allTerms, 1100)]);
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    check($model->checkoutInitialPromotionExcluded(['1', '2'], 1200, $code), 'no initial scheme at zero downpayment');
    $before = count($r->get('db')->queries);
    checkoutEligibilityQuote($calc, $r, $code, 12, '100.01');
    $probes = array_values(array_filter(array_slice($r->get('db')->queries, $before), function ($sql) { return strpos($sql, 'FROM oc_jet_kop') !== false; }));
    check(count($probes) === 2 && strpos($probes[0], 'FIND_IN_SET(12,') !== false && strpos($probes[1], 'FIND_IN_SET(12,') !== false, 'exact calculation checks only selected term; no recursive availability search');
    $controller->confirm();
    check(json_decode($r->get('response')->output, true)['success'] === 'success', 'valid exact scheme confirms independently of zero-down initial availability');

    // Both channels re-evaluate promotions when downpayment crosses their threshold.
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code, [basketRule('1', '0.50', 1, '12', 1100), basketRule('2', '0.50', 2)]);
    $model = $r->get('model_extension_mt_jet_credit_jetcredit'); $snapshot = $model->getCheckoutSnapshot($code);
    $effects = checkoutEligibilityEffects($r);
    foreach (['100'=>true, '101'=>false, '100.00'=>true] as $down=>$valid) {
        cartWire($r, ['jet_checkout_context'=>$code, 'jet_priceall'=>'1200.00', 'jet_vnoski'=>'12', 'jet_parva'=>(string)$down]);
        $calc->jet_calculate_cart(); $out = json_decode($r->get('response')->output, true);
        check(($out['success'] === 'success') === $valid, 'Checkout exact threshold refusal and recovery');
        if ($valid) check($out['jet_vnoska'.($code === 'jet_card' ? '_card' : '')] === '97.17', 'threshold quote retains existing monthly formula');
        else check($out['jet_scheme_invalid'] && !$out['jet_promotion_excluded'], 'threshold mismatch has a business refusal marker');
        check(checkoutEligibilityEffects($r) === $effects, 'Checkout recalculation has no order/bank/ledger effects');
    }

    // Equal rates from a standard setting and a filter can be confirmed per channel.
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code, [basketRule('1', $code === 'jet' ? '1.20' : '0.80')]);
    checkoutEligibilityQuote($calc, $r, $code);
    $controller->confirm();
    check(json_decode($r->get('response')->output, true)['success'] === 'success', 'mixed-source equal effective rates confirm for '.$code);

    // Status, geography and minimums are kept independently from scheme discovery.
    foreach (['status', 'zone', 'minimum', 'currency'] as $failure) {
        [$controller, $calc, $r] = checkoutEligibilityEnvironment($code);
        if ($failure === 'status') $r->get('config')->set('payment_'.$code.'_status', 0);
        if ($failure === 'zone') { $r->get('config')->set('payment_'.$code.'_geo_zone_id', 7); $r->get('db')->geoMatches = 0; }
        if ($failure === 'minimum') $r->get('config')->set('payment_'.$code.'_total', 1201);
        if ($failure === 'currency') $r->get('session')->data['currency'] = 'USD';
        check($r->get('model_extension_payment_'.$code)->getMethod(['country_id'=>1, 'zone_id'=>1], 1200) === [], 'native availability keeps '.$failure);
        $controller->index(); $other = $code === 'jet' ? 'jet_card' : 'jet';
        check(!isset($r->get('session')->data['payment_method']) && isset($r->get('session')->data['payment_methods'][$other], $r->get('session')->data['payment_methods']['cod']), 'unavailable reopen clears only own selected method');
    }

    // Validation reads current filters, with no new effects for invalid/stale data.
    foreach (['mismatch', 'excluded', 'expired', 'amount', 'term', 'downpayment', 'installment', 'owner', 'store', 'method'] as $change) {
        [$controller, $calc, $r] = checkoutEligibilityEnvironment($code, [basketRule('1', '0.50'), basketRule('2', '0.50', 2)]);
        checkoutEligibilityQuote($calc, $r, $code);
        if ($change === 'mismatch') $r->get('db')->promotionRules[1]['jet_product_percent'] = '0.99';
        if ($change === 'excluded') $r->get('db')->promotionRules[1]['jet_product_percent'] = '-1';
        if ($change === 'expired') $r->get('db')->promotionRules[1]['jet_product_end'] = '2026-05-31';
        if ($change === 'amount') $r->get('request')->post[$code.'_priceall'] = '1';
        if ($change === 'term') $r->get('request')->post[$code.'_vnoski'] = '999';
        if ($change === 'downpayment') $r->get('request')->post[$code.'_parva'] = '1200';
        if ($change === 'installment') $r->get('request')->post[$code.'_vnoska'] = '1';
        if ($change === 'owner') $r->get('session')->data['guest']['email'] = 'foreign@example.invalid';
        if ($change === 'store') $r->get('model_checkout_order')->overrides['store_id'] = 9;
        if ($change === 'method') $r->get('session')->data['payment_method']['code'] = 'cod';
        $out = checkoutEligibilityReject($controller, $r, $change);
        if (in_array($change, ['mismatch', 'excluded', 'expired'], true)) check($out['jet_scheme_invalid'], 'final business refusal is distinguished for the UI');
    }

    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code);
    checkoutEligibilityQuote($calc, $r, $code);
    $controller->confirm(); $accepted = $r->get('response')->output;
    check(json_decode($accepted, true)['success'] === 'success' && count(CartFinancialMailer::$messages) === 3, 'valid native Checkout sends independent notifications once');
    check(count($r->get('model_checkout_order')->histories) === 1 && !$r->get('model_checkout_order')->orders, 'existing native order lifecycle retained');
    $r->get('db')->promotionRules = [basketRule('*', '-1', 1, $allTerms)];
    $effects = checkoutEligibilityEffects($r);
    $controller->confirm();
    check($accepted === $r->get('response')->output && checkoutEligibilityEffects($r) === $effects, 'accepted replay is not re-executed or revalidated after filter change');
    CartFinancialMailer::$messages = [];
}

// A late request for a detached/opposite panel must not invalidate the selected method.
[$controller, $calc, $r] = checkoutEligibilityEnvironment('jet');
$state = serialize($r->get('session')->data);
check((new CheckoutEligibilityCard($r))->index() === '' && serialize($r->get('session')->data) === $state, 'opposite stale index leaves current selection/cache intact');

// A filter can change between listing and opening; only the affected method disappears.
[$controller, $calc, $r] = checkoutEligibilityEnvironment('jet');
check($r->get('model_extension_payment_jet')->getMethod(['country_id'=>1, 'zone_id'=>1], 1200)['code'] === 'jet', 'method initially listed');
$r->get('db')->promotionRules = [basketRule('1', '0.80', 1, $allTerms)];
$controller->index();
check(!isset($r->get('session')->data['payment_method']) && isset($r->get('session')->data['payment_methods']['jet_card']), 'reopen detects changed eligibility independently');
$r->get('session')->data['payment_method'] = ['code'=>'jet_card']; $r->get('model_checkout_order')->code = 'jet_card';
check((new CheckoutEligibilityCard($r))->index()['jet_card_vnoski'] === 12, 'other channel remains selectable with its own saved order snapshot');

// Exercise real native Loader/Proxy dispatch, not only direct model doubles.
$proxySource = DIR_APPLICATION.'model/extension/mt_jet_credit/jetcredit.php';
mkdir(dirname($proxySource), 0700, true);
file_put_contents($proxySource, '<?php require_once '.var_export($repo.'/upload/catalog/model/extension/mt_jet_credit/jetcredit.php', true).';');
foreach (['jet', 'jet_card'] as $code) {
    [$controller, $calc, $r] = checkoutEligibilityEnvironment($code);
    $r->set('model_extension_mt_jet_credit_jetcredit', null);
    (new Loader($r))->model('extension/mt_jet_credit/jetcredit');
    check($r->get('model_extension_mt_jet_credit_jetcredit') instanceof Proxy, 'native model proxy installed');
    check($controller->index()[$code.'_vnoski'] === 12, 'native proxy resolves initial scheme');
    checkoutEligibilityQuote($calc, $r, $code);
    $controller->confirm();
    check(json_decode($r->get('response')->output, true)['success'] === 'success', 'native proxy validates selected scheme without reference output parameters');
}

echo 'PASS '.($checks-$checkoutEligibilityStart)." Checkout eligibility/discovery/session/snapshot/confirmation checks; native/SQL/order/mail doubles only\n";
