<?php
require __DIR__ . '/financial-bootstrap.php';
// OC3 fixtures were captured from the original 1.9.1 formula, independently of Woo.
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/financial-oc3.json'), true);
foreach ($fixtures['cases'] as $row) {
	$result = JetCreditFinancial::calculate($row['A'], $row['D'], $row['term'], $row['percent'], 75, false);
	foreach (array('monthly', 'total', 'glp', 'gpr') as $field) { fcheck($result[$field] === $row[$field], 'OC3 golden ' . $field); }
}
foreach (array('74.99', '75.00', '75.01') as $amount) {
	if ($amount === '74.99') { freject(function () use ($amount) { JetCreditFinancial::calculate($amount, '0', '3', '1.40'); }, 'principal_below_minimum'); }
	else { fcheck(JetCreditFinancial::calculate($amount, '0', '3', '1.40')['principal'] === $amount, 'principal boundary'); }
}
fcheck(JetCreditFinancial::initialTerm('124.99', 12) === 9 && JetCreditFinancial::initialTerm('125.00', 12) === 12 && JetCreditFinancial::initialTerm('125.01', 12) === 12, '125 initial term on A');
foreach (array('200.99' => 5, '201.00' => 8, '201.01' => 8, '300.99' => 8, '301.00' => 10, '301.01' => 10) as $amount => $count) {
	fcheck(count(JetCreditFinancial::terms($amount)) === $count, 'term threshold ' . $amount);
}
foreach (JetCreditFinancial::TERMS as $term) { fcheck(JetCreditFinancial::calculate('1000', '25', $term, '1.40')['term'] === $term, 'allowed term ' . $term); }
foreach (array(0, -1, 1, 2, 4, 7, 11, 13, 25, 37, '12.0', '012', '12e0', array(12)) as $term) { freject(function () use ($term) { JetCreditFinancial::calculate('1000', '0', $term, '1.40'); }); }
foreach (array('-1', '300', '301', '0.001', 'NaN', 'INF', '1e2', true, array(), null) as $down) { freject(function () use ($down) { JetCreditFinancial::calculate('300', $down, 12, '1.40'); }); }
fcheck(JetCreditFinancial::calculate('201', '100', 24, '1.40', 75, false)['term'] === 24, 'extended term on A not P');
fcheck(JetCreditFinancial::calculate('301', '226', 36, '1.40', 75, false)['principal'] === '75.00', 'long term on A not P');
freject(function () { JetCreditFinancial::calculate('201', '126.01', 12, '1.40'); }, 'principal_below_minimum');
freject(function () { JetCreditFinancial::calculate('99.99', '0', 3, '1.40', '100'); }, 'principal_below_minimum');
fcheck(JetCreditFinancial::minimum('20') === 75.0 && JetCreditFinancial::minimum('100') === 100.0, 'configured minimum can only raise floor');
freject(function () { JetCreditFinancial::calculate('89.95', '0', 9, '0'); }, 'monthly_below_minimum');
fcheck(JetCreditFinancial::calculate('89.96', '0', 9, '0')['monthly'] === '10.00', 'displayed monthly boundary uses rounding');
$preview = JetCreditFinancial::calculate('75', '0', 9, '1.40', 75, false);
fcheck($preview['monthly'] === '9.38' && !$preview['eligible'], 'initial nine-month preview may be ineligible');
foreach (array(NAN, INF, -INF, '1e9', ' 75 ', '75,00', '99999999999999999999999', array(), true, null) as $number) { freject(function () use ($number) { JetCreditFinancial::number($number); }); }

$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$q = $f->quote('product', 'jet', finput(array('jet_priceall' => '1', 'jet_vnoska' => '0.01')));
fcheck($q['calculation']['amount'] === '240.00' && $q['calculation']['monthly'] === '23.36', 'client prices ignored; server product/tax used');
fcheck($r->get('cart')->products[0]['quantity'] === '2', 'product quote does not mutate cart');
$card = $f->quote('product', 'jet_card', finput());
fcheck($card['calculation']['monthly'] === '22.40' && $card['scheme'] === 'jet_card', 'card coefficient');
$r->get('model_catalog_product')->discounts[42] = array(array('quantity' => '3', 'priority' => '1', 'price' => '90'), array('quantity' => '5', 'priority' => '1', 'price' => '80'));
fcheck($f->product('42', '5', array())['amount'] === '480.00', 'quantity discount');
$r->get('model_catalog_product')->products[42]['special'] = '85';
fcheck($f->product('42', '5', array())['amount'] === '510.00', 'special overrides quantity discount like OC3');
$r->get('model_catalog_product')->options[42] = array(array('product_option_id' => 10, 'required' => true, 'type' => 'select', 'product_option_value' => array(array('product_option_value_id' => 100, 'name' => 'Plus', 'quantity' => 100, 'subtract' => 1, 'price' => '10', 'price_prefix' => '+'), array('product_option_value_id' => 101, 'name' => 'Minus', 'quantity' => 100, 'subtract' => 1, 'price' => '5', 'price_prefix' => '-'))));
fcheck($f->product('42', '2', array('10' => '100'))['amount'] === '228.00', 'positive option modifier');
fcheck($f->product('42', '2', array('10' => '101'))['amount'] === '192.00', 'negative option modifier');
foreach (array(array(), array(10 => 999), array(11 => 100), array(10 => array(100)), '{bad', str_repeat('[', 17000)) as $options) { freject(function () use ($f, $options) { $f->product('42', '2', $options); }); }
$r->get('model_catalog_product')->options[42][0]['product_option_value'][0]['quantity'] = 1;
freject(function () use ($f) { $f->product('42', '2', array(10 => 100)); }, 'invalid_options');
$r->get('model_catalog_product')->options[42] = array();
$r->get('model_catalog_product')->products[42]['quantity'] = 1;
freject(function () use ($f) { $f->product('42', '2', array()); }, 'out_of_stock');
$r->get('model_catalog_product')->products[42]['quantity'] = 100;
$r->get('model_catalog_product')->profiles[42] = array(array('id' => 1));
freject(function () use ($f) { $f->product('42', '2', array()); }, 'unsupported_pricing');

$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$cart = $f->cartSnapshot();
fcheck($cart['amount'] === '235.00', 'native subtotal/coupon/shipping/handling/tax total');
fcheck(array_column($cart['totals'], 'value', 'code')['tax'] === '38.0000', 'native coupon tax adjustment retained');
$order = $f->orderSnapshot('jet');
fcheck($order['amount'] === '235.00' && $order['lines'][0]['net'] === '100.00000000', 'order financial values preserved');
foreach (array('product', 'cart', 'checkout') as $context) {
	foreach (array('jet', 'jet_card') as $scheme) {
		$r->get('session')->data['payment_method']['code'] = $scheme; $r->get('model_checkout_order')->order['payment_code'] = $scheme;
		$input = finput(); $q = $f->quote($context, $scheme, $input); $input['jet_offer'] = $q['offer'];
		fcheck($f->verifySubmission($context, $scheme, $input)['calculation'] === $q['calculation'], 'verified source ' . $context . '/' . $scheme);
		$input['jet_priceall'] = '1'; freject(function () use ($f, $context, $scheme, $input) { $f->verifySubmission($context, $scheme, $input); }, 'stale_offer');
	}
}
$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$r->get('model_setting_extension')->codes[] = 'unknown_fee'; $r->get('config')->values['total_unknown_fee_status'] = 1;
freject(function () use ($f) { $f->cartSnapshot(); }, 'unsupported_pricing');
fcheck(!in_array('extension/total/unknown_fee', $r->get('load')->models, true), 'unknown extension not invoked');
$r = ffixture(); $r->get('model_extension_total_total')->delta = 1;
freject(function () use ($r) { $r->get('model_extension_mt_jet_credit_financial')->cartSnapshot(); }, 'invalid_total');

function promoRow($selector, $percent, $id = 1) { return array('id' => $id, 'jet_product_id' => $selector, 'jet_product_percent' => $percent, 'jet_product_meseci' => '3_6_9_10_12_15_18_24_30_36', 'jet_product_price' => '75', 'jet_product_start' => '2026-01-01', 'jet_product_end' => '2026-12-31'); }
$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$r->get('db')->promos = array(promoRow('*', '1.00'), promoRow('42', '0.80', 2));
fcheck($f->quote('product', 'jet', finput())['calculation']['percent'] === '0.80', 'specific promo before global');
$r->get('cart')->products[] = array_merge($r->get('cart')->products[0], array('product_id' => '43'));
$r->get('db')->promos[] = promoRow('42_43', '0.99', 3);
fcheck($f->quote('cart', 'jet', finput())['calculation']['percent'] === '0.99', 'composite selector remains 42_43');
$r->get('db')->promos[2]['jet_product_percent'] = '-1.00';
freject(function () use ($f) { $f->quote('cart', 'jet', finput()); }, 'financing_unavailable');
$r = ffixture(); $r->get('config')->values['module_mt_jet_credit_purcent'] = 'NaN';
freject(function () use ($r) { $r->get('model_extension_mt_jet_credit_financial')->quote('product', 'jet', finput()); }, 'invalid_number');

// Binding and source changes invalidate an otherwise authentic offer.
foreach (array('session', 'customer', 'base', 'session_currency', 'rate', 'currency_disabled', 'currency_value', 'secret', 'recipient', 'minimum', 'tax', 'option', 'product_price', 'scheme', 'csrf') as $change) {
	$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial'); $q = $f->quote('product', 'jet', finput()); $input = finput(array('jet_offer' => $q['offer'])); $scheme = 'jet';
	switch ($change) {
		case 'session': $r->get('session')->id = 'session-two'; break;
		case 'customer': $r->get('customer')->id = 8; break;
		case 'base': $r->get('config')->values['config_currency'] = 'BGN'; break;
		case 'session_currency': $r->get('session')->data['currency'] = 'USD'; break;
		case 'rate': $r->get('config')->values['module_mt_jet_credit_purcent'] = '1.20'; break;
		case 'currency_disabled': $r->get('db')->currency['status'] = '0'; break;
		case 'currency_value': $r->get('currency')->value = 2; break;
		case 'secret': $r->get('config')->values['jetcredit_offer_secret'] = null; break;
		case 'recipient': $r->get('config')->values['module_mt_jet_credit_mail'] = ''; break;
		case 'minimum': $r->get('config')->values['module_mt_jet_credit_price'] = '100'; break;
		case 'tax': $r->get('tax')->rate = .1; break;
		case 'option': $input['option'] = array(999 => 'bad'); break;
		case 'product_price': $r->get('model_catalog_product')->products[42]['price'] = '120'; break;
		case 'scheme': $scheme = 'jet_card'; break;
		case 'csrf': $input['jet_csrf'] = 'wrong'; break;
	}
	freject(function () use ($f, $input, $scheme) { $f->verifySubmission('product', $scheme, $input); });
}
$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$q = $f->quote('checkout', 'jet', finput());
$input = finput(array('jet_offer' => $q['offer']));
$r->get('session')->data['order_id'] = '12';
freject(function () use ($f, $input) { $f->verifySubmission('checkout', 'jet', $input); }, 'invalid_order_context');
$r->get('session')->data['order_id'] = '11'; $r->get('model_checkout_order')->order['total'] = '236';
freject(function () use ($f, $input) { $f->verifySubmission('checkout', 'jet', $input); }, 'invalid_total');
$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$q = $f->quote('cart', 'jet', finput()); $input = finput(array('jet_offer' => $q['offer']));
$r->get('model_extension_total_shipping')->delta = 1;
freject(function () use ($f, $input) { $f->verifySubmission('cart', 'jet', $input); }, 'stale_offer');

$now = 1000; $offer = new JetCreditOffer(hash('sha256', 'clock fixture'), function () use (&$now) { return $now; });
$calc = JetCreditFinancial::calculate(100, 0, 3, 1.4);
$token = $offer->issue(array('id' => 'session','application_intent'=>hash('sha256','synthetic application'),'generation'=>1), array('Private Name', 'Private Address', 'EGN 0102030405'), array('p' => 1), 'bank@example.test', $calc);
$decoded = base64_decode(strtr(explode('.', $token)[0], '-_', '+/'));
foreach (array('Private', '0102030405', 'bank@example.test', 'session') as $private) { fcheck(strpos($decoded, $private) === false, 'PII/session/recipient absent in offer payload'); }
fcheck($offer->verify($token, array('id' => 'session','application_intent'=>hash('sha256','synthetic application'),'generation'=>1), array('Private Name', 'Private Address', 'EGN 0102030405'), array('p' => 1), 'bank@example.test', $calc) === $calc, 'authentic offer');
freject(function () use ($offer, $token, $calc) { $offer->verify(substr($token, 0, -1) . ($token[-1] === '0' ? '1' : '0'), array('id' => 'session','application_intent'=>hash('sha256','synthetic application'),'generation'=>1), array(), array(), '', $calc); }, 'invalid_offer');
$now = 2800;
freject(function () use ($offer, $token, $calc) { $offer->verify($token, array('id' => 'session','application_intent'=>hash('sha256','synthetic application'),'generation'=>1), array(), array(), '', $calc); }, 'stale_offer');
$now = 999;
freject(function () use ($offer, $token, $calc) { $offer->verify($token, array('id' => 'session','application_intent'=>hash('sha256','synthetic application'),'generation'=>1), array(), array(), '', $calc); }, 'stale_offer');

// Incomplete dependency integration still returns a controlled 503 at all HTTP entry points.
foreach (array(array('ControllerExtensionMtJetCreditJetCredit', 'jet_send'), array('ControllerExtensionMtJetCreditJetCredit', 'jet_send_cart'), array('ControllerExtensionPaymentJet', 'confirm'), array('ControllerExtensionPaymentJetCard', 'confirm')) as $path) {
	$r = ffixture(); $before = count($r->get('db')->queries); $r->get('load')->models = array(); $r->get('request')->post = finput(array('jet_offer' => 'malicious old flow'));
	(new $path[0]($r))->{$path[1]}();
	$result = json_decode($r->get('response')->output, true);
	fcheck($result === array('success' => 'unsuccess', 'code' => 'submission_unavailable'), 'submission staging gate');
	fcheck(count($r->get('db')->queries) === $before, 'missing integration touches no DB/mail');
}
// Policy rejection is independent of the selected promotional percentage.
foreach (array(15, 18, 24, 30, 36) as $term) { freject(function () use ($term) { JetCreditFinancial::calculate('200.99', '0', $term, '0'); }, 'invalid_term'); }
foreach (array(30, 36) as $term) { freject(function () use ($term) { JetCreditFinancial::calculate('300.99', '0', $term, '0'); }, 'invalid_term'); }
$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$row = promoRow('42', '0.00'); $row['jet_product_price'] = '200'; $row['jet_product_start'] = $row['jet_product_end'] = '2026-11-01';
$r->get('db')->promos = array($row);
fcheck($f->quote('product', 'jet', finput(array('jet_parva' => '40')))['calculation']['percent'] === '0.00', 'inclusive promo dates and P equality');
fcheck($f->quote('product', 'jet', finput(array('jet_parva' => '40.01')))['calculation']['percent'] === '1.40', 'promo threshold uses P not A');
$r->get('db')->promos[0]['jet_product_percent'] = '0.8junk';
freject(function () use ($f) { $f->quote('product', 'jet', finput()); }, 'invalid_number');

$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$spec = array('product_option_id' => 10, 'required' => true, 'type' => 'checkbox', 'product_option_value' => array(array('product_option_value_id' => 100, 'name' => 'A', 'quantity' => 100, 'subtract' => 1, 'price' => '10', 'price_prefix' => '+'), array('product_option_value_id' => 101, 'name' => 'B', 'quantity' => 100, 'subtract' => 1, 'price' => '5', 'price_prefix' => '+')));
$r->get('model_catalog_product')->options[42] = array($spec);
fcheck($f->product('42', '2', array(10 => array(100, 101)))['amount'] === '276.00', 'checkbox modifiers accumulate');
freject(function () use ($f) { $f->product('42', '2', array(10 => array(100, 100))); }, 'invalid_options');
$r->get('model_catalog_product')->options[42][0]['type'] = 'text';
$q = $f->quote('product', 'jet', finput(array('option' => array(10 => 'Private Name'))));
fcheck(strpos(base64_decode(strtr(explode('.', $q['offer'])[0], '-_', '+/')), 'Private Name') === false, 'text-option PII hashed, not embedded');
$input = finput(array('option' => array(10 => 'Changed'), 'jet_offer' => $q['offer']));
freject(function () use ($f, $input) { $f->verifySubmission('product', 'jet', $input); }, 'stale_offer');
$r->get('model_catalog_product')->options[42][0]['type'] = 'file';
freject(function () use ($f) { $f->product('42', '2', array(10 => 'upload-token')); }, 'unsupported_pricing');

foreach (array('empty', 'stock', 'minimum', 'recurring') as $change) {
	$r = ffixture();
	if ($change === 'empty') { $r->get('cart')->products = array(); }
	if ($change === 'stock') { $r->get('cart')->stock = false; }
	if ($change === 'minimum') { $r->get('cart')->products[0]['minimum'] = 3; }
	if ($change === 'recurring') { $r->get('cart')->products[0]['recurring'] = array('id' => 1); }
	freject(function () use ($r) { $r->get('model_extension_mt_jet_credit_financial')->cartSnapshot(); }, 'invalid_cart');
}
foreach (array('customer_id' => 8, 'payment_code' => 'other', 'store_id' => 1, 'currency_code' => 'BGN', 'currency_value' => 2, 'order_status_id' => 5) as $key => $value) {
	$r = ffixture(); $r->get('model_checkout_order')->order[$key] = $value;
	freject(function () use ($r) { $r->get('model_extension_mt_jet_credit_financial')->orderSnapshot('jet'); }, 'invalid_order_context');
}
$r = ffixture(); $f = $r->get('model_extension_mt_jet_credit_financial');
$option = array('product_option_id' => 10, 'product_option_value_id' => 100, 'value' => 'Selected', 'type' => 'select');
$r->get('cart')->products[0]['option'] = array($option);
$r->get('model_checkout_order')->options = array($option);
$r->get('model_catalog_product')->options[42] = array(array('product_option_id' => 10, 'required' => true, 'type' => 'select', 'product_option_value' => array(array('product_option_value_id' => 100, 'name' => 'Selected', 'quantity' => 100, 'subtract' => 1, 'price' => '0', 'price_prefix' => '+'))));
fcheck($f->orderSnapshot('jet')['lines'][0]['options'][0]['product_option_value_id'] === 100, 'recorded order option matches current context');
$r->get('model_checkout_order')->options[0]['value'] = 'Changed';
freject(function () use ($f) { $f->orderSnapshot('jet'); }, 'stale_order');
$r = ffixture(); $r->get('customer')->id = 0; $r->get('model_checkout_order')->order['customer_id'] = 0;
$r->get('session')->data['guest'] = array('email' => 'private@example.test');
fcheck($r->get('model_extension_mt_jet_credit_financial')->orderSnapshot('jet')['amount'] === '235.00', 'guest order/session ownership');
$r->get('session')->data['guest']['email'] = 'another@example.test';
freject(function () use ($r) { $r->get('model_extension_mt_jet_credit_financial')->orderSnapshot('jet'); }, 'invalid_order_context');

foreach (array('jet', 'jet_card') as $scheme) {
	$r = ffixture(); $class = $scheme === 'jet' ? 'ModelExtensionPaymentJet' : 'ModelExtensionPaymentJetCard'; $model = new $class($r);
	fcheck($model->getMethod(array('country_id' => 33, 'zone_id' => 0), '75')['code'] === $scheme, 'EUR method eligible ' . $scheme);
	fcheck($model->getMethod(array('country_id' => 33, 'zone_id' => 0), '74.99') === array(), 'payment principal floor');
	$r->get('session')->data['currency'] = 'USD';
	fcheck($model->getMethod(array('country_id' => 33, 'zone_id' => 0), '235') === array(), 'non-EUR payment excluded');
	fcheck($r->get('model_extension_mt_jet_credit_financial')->frontendData('checkout', $scheme)['is_active_jet'] === false, 'non-EUR frontend closed');
}
$r = ffixture(); $r->get('request')->post = finput();
(new ControllerExtensionMtJetCreditJetCredit($r))->jet_calculate();
fcheck(json_decode($r->get('response')->output, true)['calculation']['amount'] === '240.00', 'actual controller quote');
$r->get('request')->server['REQUEST_METHOD'] = 'GET';
(new ControllerExtensionMtJetCreditJetCredit($r))->jet_calculate();
fcheck(json_decode($r->get('response')->output, true)['code'] === 'method_not_allowed', 'quote requires POST');
foreach (array('', 'secret', str_repeat('0', 64), array()) as $secret) { freject(function () use ($secret) { new JetCreditOffer($secret); }, 'offer_secret_unavailable'); }
foreach (array(null, array(), str_repeat('a', 8193), 'not.a.token') as $invalid) { freject(function () use ($offer, $invalid, $calc) { $offer->verify($invalid, array(), array(), array(), '', $calc); }, 'invalid_offer'); }
echo "IMP-02 FINANCIAL PASS: $fchecks assertions; OC3 doubles/goldens, no real DB/mail.\n";
