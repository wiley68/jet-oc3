<?php
// Real Cart endpoints/model/native totals; existing isolated SQL/mail/order doubles.
require __DIR__ . '/cart-financial.php';
$start = $checks;
$allTerms = '3_6_9_10_12_15_18_24_30_36';

function discoverCart($rules = [], $configure = null) {
    [$c, $r] = cartEnvironment(basketCart(['1', '2'], $rules));
    if ($configure) $configure($r);
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    $context = $model->getCartContext();
    check((bool)$context, 'discovery fixture has a native Cart context');
    cartWire($r, cartPayload($model, $context, '0') + ['jet_cart_discovery'=>'1']);
    $before = serialize([$r->get('session')->data, $r->get('test_submission_memory'), $r->get('model_checkout_order'), $r->get('db')->writes]);
    $passes = nativePasses($r);
    $c->jet_calculate_cart();
    check(nativePasses($r) === $passes + 1, 'one native totals evaluation for both channels and all candidate terms');
    check($before === serialize([$r->get('session')->data, $r->get('test_submission_memory'), $r->get('model_checkout_order'), $r->get('db')->writes]), 'discovery has no quote/intent/order/session writes');
    $out = json_decode($r->get('response')->output, true);
    check($out['success'] === 'success' && isset($out['jet_cart_schemes']), 'discovery returns per-channel results');
    foreach ($out['jet_cart_schemes'] as $quote) if ($quote) {
        check(!array_intersect(['jet_quote_ref', 'jet_intent_id', 'jet_cart_lineage'], array_keys($quote)), 'preview is not an authenticated quote');
    }
    return [$out['jet_cart_schemes'], $c, $r, $context];
}

foreach ([
    [[], [12, 12]],
    [[basketRule('1', '-1', 1, '12'), basketRule('1', '1.20', 2, '3')], [3, 6]],
    [[basketRule('1', '1.20', 1, $allTerms)], [12, null]],
    [[basketRule('1', '0.80', 1, $allTerms)], [null, 12]],
    [[basketRule('1', '0.00', 1, $allTerms)], [null, null]],
    [[basketRule('1', '-1', 1, '12')], [3, 3]],
    [[basketRule('1', '0.50', 1, '12')], [3, 3]],
    [[basketRule('*', '-1', 1, $allTerms)], [null, null]],
] as [$rules, $terms]) {
    [$schemes, $c, $r, $context] = discoverCart($rules);
    foreach (['jet', 'jet_card'] as $card=>$channel) {
        check(($schemes[$channel] ? $schemes[$channel]['jet_vnoski'] : null) === $terms[$card], 'independent initial scheme ' . json_encode([$rules, $channel, $terms]));
        if ($schemes[$channel]) {
            check($schemes[$channel]['jet_card'] === $card && $schemes[$channel]['jet_priceall'] === $context['amount'], 'preview channel and amount bound to native context');
            check(!isset($schemes[$channel]['jet_vnoska'.($card?'':'_card')]), 'preview contains no other channel financial results');
        }
    }
}
[$schemes] = discoverCart();
check($schemes['jet']['jet_vnoska'] === '114.40' && $schemes['jet_card']['jet_vnoska_card'] === '109.60', 'each preview uses its own standard rate and unchanged formula');
[$schemes] = discoverCart([], function ($r) { $r->get('config')->set('module_mt_jet_credit_vnoski', 24); });
check($schemes['jet']['jet_vnoski'] === 24 && $schemes['jet_card']['jet_vnoski'] === 24, 'valid configured initial term retained');
[$schemes, $c, $r] = discoverCart([], function ($r) { $r->get('db')->price = 100 / 4 / 1.2; });
$queries = array_values(array_filter($r->get('db')->queries, function ($sql) { return strpos($sql, 'FROM oc_jet_kop') !== false; }));
check(strpos($queries[0], 'FIND_IN_SET(9,') !== false && $schemes['jet']['jet_vnoski'] === 3, 'below 125 starts at 9, then finds the smallest financially valid term');
[$schemes] = discoverCart([], function ($r) { $r->get('config')->set('module_mt_jet_credit_status_card', 0); });
check($schemes['jet'] && $schemes['jet_card'] === false, 'inactive Card is not discovered');

// Discovery does not trust rounded browser amounts for the 401/601 restrictions.
[$schemes] = discoverCart([], function ($r) {
    $r->get('db')->price = 400.9999 / 4 / 1.2;
    $r->get('config')->set('module_mt_jet_credit_vnoski', 15);
});
check($schemes['jet']['jet_priceall'] === '401.00' && $schemes['jet']['jet_vnoski'] === 3, 'unrounded authoritative amount controls discovery');

// Equal rates from different sources are valid for only the matching channel.
[$c, $r, $wire, $quote] = quoteCart(basketCart(['1', '2'], [basketRule('1', '1.20')]), '100', 0);
check($quote['jet_vnoska'] === '104.87', 'mixed-source Jet quote financial golden');
$c->jet_send_cart();
$accepted = $r->get('response')->output;
check(json_decode($accepted, true)['success'] === 'success', 'equal mixed-source scheme submits');
$state = serialize([$r->get('test_submission_memory'), $r->get('model_checkout_order'), CartFinancialMailer::$messages]);
$passes = nativePasses($r);
$c->jet_send_cart();
check($r->get('response')->output === $accepted && nativePasses($r) === $passes, 'accepted replay skips recalculation and resending');
check($state === serialize([$r->get('test_submission_memory'), $r->get('model_checkout_order'), CartFinancialMailer::$messages]), 'replay has no business effects');

// A previously acceptable max/fallback quote must not bypass the new final rule.
foreach ([
    [[basketRule('1', '0.50'), basketRule('2', '0.99', 2)], 0],
    [[basketRule('1', '1.20')], 1],
] as [$rules, $card]) {
    [, $r] = quoteCart(basketCart(['1', '2'], $rules), '100', $card, 12, true);
    rejectCart(new CartFinancialController($r), $r, true);
}

// Filter change after popup opening is re-evaluated before any order/bank action.
[$c, $r] = quoteCart(basketCart(['1', '2'], [basketRule('1', '1.20')]));
$r->get('db')->promotionRules[0]['jet_product_percent'] = '0.50';
rejectCart($c, $r, true);

// Exact recalculation rejects a threshold crossing and recovers without a term search.
[$c, $r] = cartEnvironment(basketCart(['1', '2'], [basketRule('1', '0.50', 1, '12', 1100), basketRule('2', '0.50', 2)]));
$model = $r->get('model_extension_mt_jet_credit_jetcredit');
$context = $model->getCartContext();
$previous = [];
foreach (['100'=>true, '101'=>false, '100.00'=>true] as $down=>$valid) {
    cartWire($r, cartPayload($model, $context, (string)$down) + $previous);
    $c->jet_calculate_cart(); $out = json_decode($r->get('response')->output, true);
    check(($out['success'] === 'success') === $valid, 'exact downpayment eligibility');
    if ($valid) {
        check($out['jet_vnoski'] === 12, 'exact term retained on recovery');
        $previous = ['jet_previous_quote'=>$out['jet_quote_ref'], 'jet_cart_lineage'=>$out['jet_cart_lineage'], 'jet_intent_id'=>$out['jet_intent_id']];
    } else check($out['jet_scheme_invalid'] && !isset($out['jet_quote_ref']), 'invalid scheme has no authenticated offer');
}

echo 'PASS ' . ($checks - $start) . " Cart discovery/eligibility/submission checks; isolated native/SQL/mail/order doubles\n";
