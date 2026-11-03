<?php
// Reuse the local mail/order doubles; never connect to a store, database or transport.
if (($argv[1] ?? '') === '--rounding') {
    class Model {}
    require 'upload/catalog/model/extension/mt_jet_credit/jetcredit.php';
    $model = new ModelExtensionMtJetCreditJetcredit();
    $values = [0, 0.125, 0.375, 0.625, 0.875, 1.005, 2.675, 500.005, 400.9999, 600.9999, 100000000000.125, 100000000000000.125, 100000000000000.375, 100000000000000.625, 100000000000000.875];
    for ($i = 1; $i <= 2500; $i++) {
        $values[] = $i / 200;
        $values[] = $i / 200 + 0.000000000001;
        $values[] = $i / 200 - 0.000000000001;
    }
    $rows = [];
    foreach ($values as $value) {
        $browser = (string)$value;
        $rows[] = ['browser' => $browser, 'actual' => $model->checkoutFixed((float)$browser)];
        $rows[] = ['browser' => json_encode($value), 'actual' => $model->checkoutFixed($value)];
    }
    echo json_encode($rows);
    exit;
}

$checkoutFinancialTests = function () {
    $checks = 0;
    $assert = function ($condition, $message) use (&$checks) {
        $checks++;
        if (!$condition) throw new RuntimeException($message);
    };
    $environment = function ($code, $base = 'EUR', $total = 600, $coefficient = 1, $down = '100', $term = 12, $serialized = null, $percent = null) {
        $c = setup($code === 'jet' ? 'NewJet' : 'NewCard', $base);
        $c->session->data['payment_method']['code'] = $code;
        $c->session->data['guest'] = ['email' => 'test@example.invalid'];
        $c->model_checkout_order->code = $code;
        $c->model_checkout_order->total = $total;
        $c->model_checkout_order->coefficient = $coefficient;
        $c->cart->price = $total / 2 / 1.2;
        $c->model_catalog_product->price = $total / 2 / 1.2;
        // Independent golden financial outputs come from the committed calculator.
        $old = setup('OldCalc', $base);
        if ($percent !== null) { $old->model_extension_mt_jet_credit_jetcredit->normal = $old->model_extension_mt_jet_credit_jetcredit->card = $percent; $c->model_extension_mt_jet_credit_jetcredit->normal = $c->model_extension_mt_jet_credit_jetcredit->card = $percent; }
        $old->request->post['jet_priceall'] = $serialized ?? number_format($total * $coefficient, 2, '.', '');
        $old->request->post['jet_parva'] = $down === '' || trim($down) === '' ? '0.00' : $c->model_extension_mt_jet_credit_jetcredit->checkoutFixed((float)$down);
        $old->request->post['jet_vnoski'] = $term;
        $old->jet_calculate_cart();
        $quote = json_decode($old->response->output, true);
        $post = [];
        foreach (['priceall', 'vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp'] as $field) {
            $suffix = $code === 'jet_card' && !in_array($field, ['priceall', 'total_credit_price']) ? '_card' : '';
            $post[$code . '_' . $field] = $quote['jet_' . $field . $suffix];
        }
        $post[$code . '_parva'] = $down;
        $post[$code . '_vnoski'] = (string)$term;
        $post[$code . '_egn'] = '1234567890';
        $post[$code . '_products'] = '1';
        $post[$code . '_products_qt'] = '2';
        $post[$code . '_products_pr'] = number_format(($total / 2 / 1.2 + ($total / 2 - $total / 2 / 1.2)) * $coefficient, 2, '.', '');
        $post[$code . '_products_vr'] = 'red';
        $c->request->post = $post;
        return [$c, $quote];
    };
    $reset = function () {
        SubmissionTestMailer::$messages = SubmissionTestMailer::$attempts = SubmissionTestMailer::$failures = [];
        Trace::$events = [];
    };
    $reject = function ($c, $label) use ($assert, $reset) {
        $reset(); $c->confirm();
        $result = json_decode($c->response->output, true);
        $assert(($result['success'] ?? null) === 'unsuccess', $label . ': unsuccessful result');
        $assert(!SubmissionTestMailer::$attempts, $label . ': no notification attempts');
        $assert(!$c->model_checkout_order->orders && !$c->model_checkout_order->histories, $label . ': no order/history mutations');
        $assert(!array_filter($c->db->queries, function ($sql) { return preg_match('/^\s*(UPDATE|INSERT|DELETE)/i', $sql); }), $label . ': no counter/database mutations');
    };
    foreach (['jet', 'jet_card'] as $code) {
        $cases = ['total' => function ($c) use ($code) { $c->request->post[$code . '_priceall'] = '1'; },
            'installment' => function ($c) use ($code) { $c->request->post[$code . '_vnoska'] = '1'; },
            'term 999' => function ($c) use ($code) { $c->request->post[$code . '_vnoski'] = '999'; },
            'zero term' => function ($c) use ($code) { $c->request->post[$code . '_vnoski'] = '0'; },
            'negative term' => function ($c) use ($code) { $c->request->post[$code . '_vnoski'] = '-12'; },
            'negative down' => function ($c) use ($code) { $c->request->post[$code . '_parva'] = '-1'; },
            'equal down' => function ($c) use ($code) { $c->request->post[$code . '_parva'] = '600'; },
            'excess down' => function ($c) use ($code) { $c->request->post[$code . '_parva'] = '601'; },
            'missing order' => function ($c) { $c->model_checkout_order->failure = 'missing'; },
            'missing session order' => function ($c) { unset($c->session->data['order_id']); },
            'array session order' => function ($c) { $c->session->data['order_id'] = [1]; },
            'wrong returned order' => function ($c) { $c->model_checkout_order->snapshot['order_id'] = 99; },
            'wrong session payment' => function ($c) { $c->session->data['payment_method']['code'] = 'cod'; },
            'wrong order payment' => function ($c) { $c->model_checkout_order->code = 'cod'; },
            'wrong store' => function ($c) { $c->model_checkout_order->snapshot['store_id'] = 3; },
            'wrong guest owner' => function ($c) { $c->model_checkout_order->snapshot['customer_id'] = 7; },
            'wrong guest session' => function ($c) { $c->session->data['guest']['email'] = 'other@example.invalid'; },
            'missing guest session' => function ($c) { unset($c->session->data['guest']); },
            'wrong customer' => function ($c) { $c->customer = new class { function isLogged() { return true; } function getId() { return 7; } }; },
            'session currency' => function ($c) { $c->session->data['currency'] = 'USD'; },
            'order currency' => function ($c) { $c->model_checkout_order->snapshot['currency_code'] = 'USD'; },
            'module disabled' => function ($c) { $c->config->values['module_mt_jet_credit_status'] = 0; },
            'payment disabled' => function ($c) use ($code) { $c->config->values['payment_' . $code . '_status'] = 0; },
            'payment minimum' => function ($c) use ($code) { $c->config->values['payment_' . $code . '_total'] = 601; },
            'geo zone' => function ($c) use ($code) { $c->config->values['payment_' . $code . '_geo_zone_id'] = 5; $c->db->geoMatches = 0; },
            'changed order line' => function ($c) { $c->model_checkout_order->rows = [['order_product_id'=>1,'product_id'=>1,'name'=>'Product','quantity'=>3,'price'=>250,'tax'=>50]]; },
            'changed order options' => function ($c) { $c->model_checkout_order->options = [['value'=>'blue']]; },
            'EUR minimum' => function ($c) { $c->config->values['module_mt_jet_credit_price'] = 601; },
            'changed order total' => function ($c) { $c->model_checkout_order->total = 650; },
            'changed snapshot rate' => function ($c) { $c->model_checkout_order->coefficient = 0.9; },
            'changed promotion' => function ($c) { $c->model_extension_mt_jet_credit_jetcredit->normal = 2; $c->model_extension_mt_jet_credit_jetcredit->card = 2; },
            'hidden promotion' => function ($c) { $c->model_extension_mt_jet_credit_jetcredit->show = false; },
            'tampered products' => function ($c) use ($code) { $c->request->post[$code . '_products'] = '99'; },
            'tampered quantity' => function ($c) use ($code) { $c->request->post[$code . '_products_qt'] = '99'; },
            'tampered unit price' => function ($c) use ($code) { $c->request->post[$code . '_products_pr'] = '1.00'; }];
        foreach ($cases as $label => $mutate) {
            [$c] = $environment($code); $mutate($c);
            $c->model_extension_mt_jet_credit_jetcredit->customer = $c->customer;
            if ($label === 'session currency') {
                $reset(); $c->confirm();
                $assert(!SubmissionTestMailer::$attempts && !$c->model_checkout_order->histories && !$c->db->queries, 'existing non-EUR guard has no side effects');
            } else { $reject($c, $code . ' ' . $label); }
        }
        foreach (['priceall', 'parva', 'vnoski', 'vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp'] as $field) {
            foreach ([[], ['1'], null, true, 'NaN', 'INF', INF, NAN, '1e999', '1x2'] as $bad) {
                [$c] = $environment($code); $c->request->post[$code . '_' . $field] = $bad;
                $reject($c, $code . ' invalid ' . $field);
            }
        }
        foreach ([0, -1, null, [], INF, NAN, 'bad'] as $bad) {
            [$c] = $environment($code); $c->model_checkout_order->snapshot['currency_value'] = $bad;
            $reject($c, $code . ' invalid saved coefficient');
        }
        foreach ([0, -1, null, [], INF, NAN, 'bad'] as $bad) {
            [$c] = $environment($code); $c->model_checkout_order->snapshot['total'] = $bad;
            $reject($c, $code . ' invalid native total');
        }
        // Complete golden bodies use the original Checkout controller with the same legitimate conditions.
        foreach (['EUR', 'USD'] as $base) foreach (['0', '100', '', '  ', '12.345'] as $down) foreach ([false, true] as $registered) {
            $total = $base === 'EUR' ? 1200 : 2400; $factor = $base === 'EUR' ? 1 : 0.5;
            [$c, $quote] = $environment($code, $base, $total, $factor, $down);
            if ($registered) {
                $c->customer = new class { function isLogged() { return true; } function getId() { return 7; } };
                $c->model_extension_mt_jet_credit_jetcredit->customer = $c->customer;
                $c->model_checkout_order->snapshot['customer_id'] = 7;
                unset($c->session->data['guest']);
            }
            $input = [];
            foreach ($c->request->post as $key => $value) $input['jet_' . substr($key, strlen($code) + 1)] = $value;
            $snapshot = $c->model_extension_mt_jet_credit_jetcredit->getCheckoutSnapshot($code);
            $assert($c->model_extension_mt_jet_credit_jetcredit->getCheckoutQuote($snapshot, $input) == $quote, 'complete financial response matches committed calculator golden');
            $old = setup($code === 'jet' ? 'OldJet' : 'OldCard', $base);
            $old->request->post = $c->request->post; $old->session->data['payment_method']['code'] = $code;
            if (trim($down) === '') $old->request->post[$code . '_parva'] = '0';
            $old->model_checkout_order->total = $total;
            $old->config->values['module_mt_jet_credit_mail'] = $c->config->values['module_mt_jet_credit_mail'] = 'bank@example.invalid';
            $reset(); $old->confirm(); $originalMessages = SubmissionTestMailer::$messages;
            $view = $c->index();
            $assert($view[$code . '_price'] === (float)($total * $factor), 'unrounded authoritative display');
            $reset(); $c->confirm();
            $assert((json_decode($c->response->output, true)['success'] ?? '') === 'success', 'positive golden submission');
            $assert(SubmissionTestMailer::$messages === $originalMessages, 'all three original message subjects/bodies/metadata exact');
            $assert(!$c->model_checkout_order->orders && count($c->model_checkout_order->histories) === 1, 'only existing successful status transition');
        }
        foreach (['A &amp; B', 'A &amp;amp; B', 'A &quot;B&quot;', 'A &apos;B&apos;'] as $option) {
            [$c] = $environment($code); $c->model_checkout_order->options = [['value'=>$option]];
            $c->request->post[$code.'_products_vr'] = html_entity_decode($option, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $old=setup($code==='jet'?'OldJet':'OldCard');$old->request->post=$c->request->post;$old->session->data['payment_method']['code']=$code;
            $reset();$old->confirm();$messages=SubmissionTestMailer::$messages;
            $reset();$c->confirm();
            $assert((json_decode($c->response->output,true)['success']??'')==='success' && SubmissionTestMailer::$messages===$messages, 'native option entities preserve browser round-trip and original email bytes');
        }
        foreach ([0.0, 0.5, 2.0] as $percent) {
            [$c] = $environment($code, 'EUR', 1200, 1, '100', 12, null, $percent);
            $reset(); $c->confirm();
            $assert((json_decode($c->response->output, true)['success'] ?? '') === 'success', 'promotional financial golden ' . $percent);
        }
        [$c] = $environment($code);
        $c->model_checkout_order->rows = [
            ['order_product_id'=>1,'product_id'=>1,'name'=>'Product','quantity'=>1,'price'=>100,'tax'=>20],
            ['order_product_id'=>2,'product_id'=>1,'name'=>'Product','quantity'=>2,'price'=>150,'tax'=>30]
        ];
        $c->model_checkout_order->options = [1=>[['value'=>'red']],2=>[['value'=>'blue']]];
        foreach (['products'=>'1_1','products_qt'=>'1_2','products_pr'=>'120.00_180.00','products_vr'=>'red_blue'] as $field=>$value) $c->request->post[$code.'_'.$field]=$value;
        $view=$c->index();
        $assert($view[$code.'_price']===600.0 && $view[$code.'_products_pr']==='120.00_180.00', 'native order total includes totals adjustments; variant lines kept separately');
        $reset();$c->confirm();
        $assert((json_decode($c->response->output,true)['success']??'')==='success', 'persisted line/option and total context supported');
        foreach ([3, 6, 9, 10, 12, 15, 18, 24, 30, 36] as $term) {
            [$c] = $environment($code, 'EUR', 3000, 1, '100', $term);
            $reset(); $c->confirm();
            $assert((json_decode($c->response->output, true)['success'] ?? '') === 'success', 'existing valid financing term ' . $term);
        }
        foreach ([[400.9999, '401.00', 15, false], [401, '401.00', 15, true], [600.9999, '601.00', 30, false], [601, '601.00', 30, true], [500.005, '500.00', 12, true]] as [$total, $serialized, $term, $valid]) {
            [$c] = $environment($code, 'EUR', $total, 1, '0', $term, $serialized);
            if (!$valid) { $reject($c, 'unrounded term boundary'); continue; }
            $reset(); $c->confirm();
            $assert((json_decode($c->response->output, true)['success'] ?? '') === 'success', 'threshold/half-cent positive golden');
        }
        foreach ([0.4, 0.5, 0.6] as $live) {
            [$c] = $environment($code, 'USD', 1000, 0.5, '0');
            $c->currency->rates['EUR'] = $live;
            $view = $c->index();
            $assert($view[$code . '_price'] === 500.0 && $view[$code . '_products_pr'] === '250.00', 'saved rate controls total and persisted product price');
            $reset(); $c->confirm();
            $assert((json_decode($c->response->output, true)['success'] ?? '') === 'success', 'live rate changes cannot reprice order');
            $assert(strpos(SubmissionTestMailer::$messages[0]['body'], 'Размер на кредита: 500.00;') !== false, 'bank amount follows saved snapshot');
        }
        [$c] = $environment($code, 'USD', 1000, 0.5, '0');
        $c->currency->rates['EUR'] = 0.4; $c->config->values['module_mt_jet_credit_price'] = 450;
        $model = $c->{'model_extension_payment_' . $code};
        $assert($model->getMethod(['country_id' => 1, 'zone_id' => 1], 1000) === [], 'two-argument native availability still uses live rate');
        $assert($model->getMethod(['country_id' => 1, 'zone_id' => 1], 1000, 500)['code'] === $code, 'snapshot-aware existing minimum');
        $reset(); $c->confirm(); $assert(count(SubmissionTestMailer::$attempts) === 3, 'snapshot eligibility used at confirm');
        foreach (['', 'bank@example.invalid'] as $bank) foreach (['false', 'throw'] as $failure) {
            [$c] = $environment($code); $c->config->values['module_mt_jet_credit_mail'] = $bank;
            $reset(); SubmissionTestMailer::$failures = $bank === '' ? ['admin' => 'throw', 'customer' => 'false'] : ['bank' => $failure];
            $c->confirm(); $result = json_decode($c->response->output, true);
            $assert($result['code'] === ($bank === '' ? 'manual_registered' : ($failure === 'throw' ? 'bank_uncertain' : 'bank_failed')), 'existing delivery outcomes');
            $assert(!$c->model_checkout_order->orders, 'never create replacement order');
        }
        // Shared endpoint context is a selector, never authority. Browser order_id is ignored.
        [$c] = $environment($code, 'USD', 1000, 0.5, '0');
        $calc = setup('NewCalc', 'USD');
        foreach (get_object_vars($c) as $property => $value) $calc->$property = $value;
        $post = [];
        foreach ($c->request->post as $key => $value) $post['jet_' . substr($key, strlen($code) + 1)] = $value;
        $post['jet_checkout_context'] = $code; $post['order_id'] = 999;
        $calc->request->post = $post; $reset(); $calc->jet_calculate_cart();
        $result = json_decode($calc->response->output, true);
        $assert(($result['jet_priceall'] ?? '') === '500.00', 'shared calculation uses own session snapshot');
        foreach (['cod', [], $code === 'jet' ? 'jet_card' : 'jet'] as $selector) {
            $calc->request->post['jet_checkout_context'] = $selector;
            $reset(); $calc->jet_calculate_cart();
            $assert(json_decode($calc->response->output, true) === ['success' => 'unsuccess'], 'selector rejection reveals no order data');
            $assert(!SubmissionTestMailer::$attempts && !$calc->model_checkout_order->histories, 'calculator rejection has no side effects');
        }
        unset($calc->request->post['jet_checkout_context']);
        $calc->request->post['jet_priceall'] = '600.00';
        $old = setup('OldCalc'); $old->request->post = $calc->request->post;
        $old->jet_calculate_cart(); $calc->jet_calculate_cart();
        $assert($calc->response->output === $old->response->output, 'ordinary Cart calculation unchanged despite existing session order');
    }
    echo "PASS $checks Checkout security/financial golden checks; original calculator and email golden references\n";
};
require __DIR__ . '/submission-emails.php';
