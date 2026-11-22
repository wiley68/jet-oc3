<?php
// Reuse the native Cart/SQL doubles and the unchanged v2.0.3 compatibility checks.
require __DIR__ . '/cart-financial.php';
$eligibilityStart = $checks;

function eligibilityEnvironment($ids = ['1', '2'], $rules = [], $jet = '1.40', $card = '1.00') {
    [, $r] = cartEnvironment(basketCart($ids, $rules));
    $r->get('config')->set('module_mt_jet_credit_purcent', $jet);
    $r->get('config')->set('module_mt_jet_credit_purcent_card', $card);
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    return [$model, $r, $model->getCartContext()];
}

// New eligibility is channel-specific; the legacy max/fallback tests above stay intact.
foreach ([
    [['1'], [], '1.40', '1.00', ['1.40', '1.00']],
    [['1'], [basketRule('1', '0.50')], '1.40', '1.00', ['0.50', '0.50']],
    [['1', '2'], [basketRule('1', '0.50'), basketRule('2', '0.5', 2)], '1.40', '1.00', ['0.50', '0.50']],
    [['1', '2'], [basketRule('1', '0.50'), basketRule('2', '0.99', 2)], '1.40', '1.00', ['different_rates', 'different_rates']],
    [['1', '2'], [basketRule('1', '1.40')], '1.40', '1.00', ['1.40', 'different_rates']],
    [['1', '2'], [basketRule('1', '1.00')], '1.40', '1.00', ['different_rates', '1.00']],
    [['1', '2'], [], '1.40', '1.00', ['1.40', '1.00']],
    [['1', '2'], [basketRule('1', '0.00'), basketRule('2', '0', 2)], '1.40', '1.00', ['0.00', '0.00']],
    [['1', '2'], [basketRule('1', '0.00'), basketRule('2', '1.00', 2)], '1.40', '1.00', ['different_rates', 'different_rates']],
    [['1', '2'], [basketRule('1', '0.00')], '0', '0.00', ['0.00', '0.00']],
    [['1'], [basketRule('1', '-1')], '1.40', '1.00', ['excluded', 'excluded']],
    // A later -1 takes precedence over an earlier rate mismatch, in either row order.
    [['1', '2', '3'], [basketRule('1', '0'), basketRule('2', '1', 2), basketRule('3', '-1', 3)], '1.40', '1.00', ['excluded', 'excluded']],
    [['3', '2', '1'], [basketRule('1', '0'), basketRule('2', '1', 2), basketRule('3', '-1', 3)], '1.40', '1.00', ['excluded', 'excluded']],
    // SQL selection is unchanged: specific before *, then first applicable ID.
    [['1'], [basketRule('*', '-1'), basketRule('1', '0.50', 2)], '1.40', '1.00', ['0.50', '0.50']],
    [['1'], [basketRule('1', '0.50'), basketRule('1', '-1', 2)], '1.40', '1.00', ['0.50', '0.50']],
    [['1'], [basketRule('1', '-1'), basketRule('1', '0.50', 2)], '1.40', '1.00', ['excluded', 'excluded']],
    [['1'], [basketRule('1', '-1', 1, '12', 0, '2026-05-31')], '1.40', '1.00', ['1.40', '1.00']],
    // Canonical DECIMAL(5,2) comparison, including configuration precision and ties.
    [['1', '2'], [basketRule('1', '1.40')], '1.404', '1.4', ['1.40', '1.40']],
    [['1', '2'], [basketRule('1', '1.40')], '1.405', '1.406', ['different_rates', 'different_rates']],
    [['1', '2'], [basketRule('1', '1.41')], '1.405', '1.414', ['1.41', '1.41']],
    [['1', '2'], [basketRule('1', '0.00')], '-0.004', '0.004', ['0.00', '0.00']],
] as [$ids, $rules, $jet, $card, $expected]) {
    [$model, $r, $context] = eligibilityEnvironment($ids, $rules, $jet, $card);
    foreach (['jet', 'jet_card'] as $index => $channel) {
        $result = $model->checkBasketScheme($context['product_ids'], $channel, $context['raw_amount'], 12, '0');
        $valid = !in_array($expected[$index], ['different_rates', 'excluded'], true);
        check($result['valid'] === $valid && $result['reason'] === ($valid ? 'valid' : $expected[$index]),
            'effective product rates: ' . json_encode([$ids, $rules, $channel, $jet, $card]));
        check($result['constraint'] === null && ($valid ? $result['scheme']['percent'] === $expected[$index] : $result['scheme'] === null),
            'canonical percent or explicit rejection');
    }
}

// Equal rounded monthly results must not conceal different effective percentages.
[$model] = eligibilityEnvironment(['1', '2'], [basketRule('1', '1.40', 1, '3'), basketRule('2', '1.41', 2, '3')]);
check($model->checkBasketScheme(['1', '2'], 'jet', 75, 3, '16.99')['reason'] === 'different_rates',
    '1.40 and 1.41 remain different even when both monthly results round to 20.15');

// Repeated native products and different quantities change the total, not the rate comparison.
[$model, $r] = eligibilityEnvironment(['1', '1', '2'], [basketRule('1', '1.40')]);
$r->get('db')->cartRows[0]['quantity'] = 1;
$r->get('db')->cartRows[1]['quantity'] = 3;
$context = $model->getCartContext();
check($context['product_ids'] === ['1', '1', '2'] && $context['products_qt'] === '1_3_2', 'native duplicate rows and quantities retained');
$result = $model->checkBasketScheme($context['product_ids'], 'jet', $context['raw_amount'], 12);
check($result['valid'] && $result['scheme']['amount'] === $context['amount'] && $result['scheme']['percent'] === '1.40', 'one established native total supplies quantity effects');
check(!$model->checkBasketScheme($context['product_ids'], 'jet_card', $context['raw_amount'], 12)['valid'], 'duplicate products do not hide Card mismatch');

// Downpayment changes the shared principal used by the existing promotion SQL.
[$model, $r] = eligibilityEnvironment(['1', '2'], [basketRule('1', '0.50', 1, '12', 1100), basketRule('2', '0.50', 2)]);
foreach (['jet', 'jet_card'] as $channel) {
    $valid = $model->checkBasketScheme(['1', '2'], $channel, 1200, 12, 100);
    check($valid['valid'] && $valid['scheme']['principal'] === '1100.00' && $valid['scheme']['monthly'] === '97.17', 'threshold equality and unchanged monthly golden');
    foreach (['100.01', '100.006', '101'] as $down) {
        check($model->checkBasketScheme(['1', '2'], $channel, 1200, 12, $down)['reason'] === 'different_rates', 'canonical downpayment crosses promotion threshold');
    }
        check($model->checkBasketScheme(['1', '2'], $channel, 1200, 9, 100)['valid'], 'promotion is term-specific');
}

// Exact validation never searches; initial discovery keeps a valid initial term.
[$model, $r, $context] = eligibilityEnvironment();
$before = count($r->get('db')->queries);
$result = $model->resolveInitialBasketScheme($context['product_ids'], 'jet', $context['raw_amount'], 12);
check($result['valid'] && $result['scheme']['term'] === 12, 'valid initial term retained before smaller terms');
check(count($r->get('db')->queries) === $before + 2, 'valid initial term only resolves its own products');
$r->get('db')->promotionRules = [basketRule('1', '-1', 1, '24_3')];
$before = count($r->get('db')->queries);
check($model->checkBasketScheme(['1', '2'], 'jet', 1200, 24)['reason'] === 'excluded', 'exact invalid scheme has no fallback');
check(count($r->get('db')->queries) === $before + 2, 'exact validation does not probe other terms');
$before = count($r->get('db')->queries);
$result = $model->resolveInitialBasketScheme(['1', '2'], 'jet', 1200, 24);
check($result['valid'] && $result['scheme']['term'] === 6, 'invalid initial term searches ascending from 3');
$terms = [];
foreach (array_slice($r->get('db')->queries, $before) as $sql) {
    preg_match('/FIND_IN_SET\((\d+),/', $sql, $match);
    $terms[] = (int)$match[1];
}
check($terms === [24, 24, 3, 3, 6, 6], 'initial term checked first, then ascending without repetition');

// Different first valid terms for the two channels, with no new totals/quotes/intents.
$r->get('db')->promotionRules = [basketRule('1', '-1', 1, '12'), basketRule('1', '1.40', 2, '3')];
$state = serialize([$r->get('session')->data, $r->get('test_submission_memory'), $r->get('model_checkout_order'), $r->get('cart')->clears, $r->get('db')->writes]);
$passes = nativePasses($r);
foreach (['jet'=>3, 'jet_card'=>6] as $channel => $term) {
    $result = $model->resolveInitialBasketScheme(['1', '2'], $channel, 1200, 12);
    check($result['valid'] && $result['scheme']['term'] === $term && $result['scheme']['channel'] === $channel, 'independent first valid term for ' . $channel);
}
check(nativePasses($r) === $passes, 'search reuses established context without totals evaluation');
check($state === serialize([$r->get('session')->data, $r->get('test_submission_memory'), $r->get('model_checkout_order'), $r->get('cart')->clears, $r->get('db')->writes]), 'candidate search has no session/submission/order/database writes');

$r->get('db')->promotionRules = [basketRule('*', '-1', 1, '3_6_9_10_12_15_18_24_30_36')];
foreach (['jet', 'jet_card'] as $channel) {
    $before = count($r->get('db')->queries);
    $result = $model->resolveInitialBasketScheme(['1', '2'], $channel, 1200, 12);
    check($result === ['valid'=>false, 'reason'=>'no_scheme', 'constraint'=>null, 'scheme'=>null], 'explicit no-scheme result for ' . $channel);
    check(count($r->get('db')->queries) === $before + 20, 'all ten terms checked once; -1 excludes only a candidate');
}

// Existing financial boundaries use unrounded total, rounded principal/monthly.
[$model, $r] = eligibilityEnvironment(['1'], [], '0', '0');
foreach ([
    [74.9999, 3, 0, 'minimum_amount'], [75, 3, 0, null],
    [124.99, 3, 0, null], [125, 3, 0, null],
    [400.9999, 15, 0, 'term'], [401, 15, 0, null],
    [400.9999, 18, 0, 'term'], [401, 18, 0, null],
    [400.9999, 24, 0, 'term'], [401, 24, 0, 'monthly'],
    [600.9999, 30, 0, 'term'], [601, 30, 0, null],
    [600.9999, 36, 0, 'term'], [601, 36, 0, 'monthly'],
    [75, 3, 15, null], [75, 3, '15.01', null], [75, 3, '15.02', 'monthly'],
    [1200, 3, 1140, null], // Minimum total is not a new minimum principal.
    [1200, 12, -0.001, 'downpayment'], [1200, 12, 1200, 'downpayment'],
    [1200, 12, '1199.999', 'downpayment'],
] as [$amount, $term, $down, $constraint]) {
    $result = $model->checkBasketScheme(['1'], 'jet', $amount, $term, $down);
    check($result['valid'] === ($constraint === null) && $result['constraint'] === $constraint,
        'financial boundary ' . json_encode([$amount, $term, $down, $constraint]));
    if ($constraint !== null) check($result['reason'] === 'financial_constraint' && $result['scheme'] === null, 'financial failure distinguished from promotion rejection');
}
check($model->checkBasketScheme(['1'], 'jet', 75, 3, '15.01')['scheme']['monthly'] === '20.00', 'rounded installment of exactly 20 EUR accepted');
check($model->checkBasketScheme(['1'], 'jet', 1200, 12, '0.125')['scheme']['downpayment'] === '0.13', 'existing PHP/browser downpayment tie preserved');
foreach ([3, 6, 9, 10, 12, 15, 18, 24, 30, 36] as $term) {
    check($model->checkBasketScheme(['1'], 'jet', 1200, $term)['valid'], 'supported term ' . $term);
}
foreach ([0, -3, 4, 48, 12.5] as $term) {
    check($model->checkBasketScheme(['1'], 'jet', 1200, $term)['constraint'] === 'term', 'unsupported exact term');
}
foreach ([0, 4, 12.5, null] as $initial) {
    check($model->resolveInitialBasketScheme(['1'], 'jet', 1200, $initial)['scheme']['term'] === 3, 'unsupported initial term searches from smallest');
}
// Valid rate equality alone is insufficient for choosing the initial scheme.
check($model->resolveInitialBasketScheme(['1'], 'jet', 75, 12)['scheme']['term'] === 3, 'initial monthly minimum failure falls back');
check($model->resolveInitialBasketScheme(['1'], 'jet', 400.9999, 15)['scheme']['term'] === 3, 'initial unrounded term threshold failure falls back');
check($model->resolveInitialBasketScheme(['1'], 'jet', 75, 12, 74)['reason'] === 'no_scheme', 'no term meets monthly minimum after downpayment');
check($model->resolveInitialBasketScheme(['1'], 'jet', 74.9999, 12)['reason'] === 'no_scheme', 'no scheme below minimum total');

foreach ([
    [[], 'jet', 1200, 12, 0], [['0'], 'jet', 1200, 12, 0],
    [['1'], 'unknown', 1200, 12, 0], [['1'], 'jet', INF, 12, 0],
    [['1'], 'jet', NAN, 12, 0], [['1'], 'jet', 0, 12, 0],
    [['1'], 'jet', [], 12, 0], [['1'], 'jet', 1200, [], 0],
    [['1'], 'jet', 1200, 12, INF], [['1'], 'jet', 1200, 12, '1e1'],
] as $args) {
    $result = $model->checkBasketScheme(...$args);
    check($result['reason'] === 'financial_constraint' && $result['constraint'] === 'invalid_input', 'invalid scheme inputs fail closed');
}
$r->get('config')->set('module_mt_jet_credit_purcent', INF);
check($model->checkBasketScheme(['1'], 'jet', 1200, 12)['constraint'] === 'percent', 'non-finite effective rate fails closed');

echo 'PASS ' . ($checks - $eligibilityStart) . " unified basket eligibility/search checks; existing native/SQL doubles, no real store or transport\n";
