<?php
require __DIR__ . '/submission-bootstrap.php';
$goldens = json_decode(file_get_contents(__DIR__ . '/fixtures/bank-oc3.json'), true);
// Original cart cases used a legacy underscore variation string, interpreted as
// no options by its JSON decoder. Keep those bytes immutable; valid JSON options
// are captured separately from the same original code.
foreach ($goldens['cases'] as $case) {
	if ($case['context'] === 'cart') { foreach ($case['products'] as &$product) { $product['options'] = array(); } unset($product); }
	fcheck(JetCreditSubmissionMail::bankBody($case['identity'], $case['products'], $case['calculation'], $case['scheme']) === $case['body'], 'unchanged OC3 bank body ' . $case['context'] . '/' . $case['scheme']);
	fcheck(JetCreditSubmissionMail::bankSubject($case['merchant'], $case['reference']) === $case['subject'], 'unchanged bank subject');
}
foreach (array(null, false, array(), ' ', 'a@example.test,', 'a@example.test,,b@example.test', 'not-email', "a@example.test\r\nBcc:other@example.test", 'Name <a@example.test>') as $bad) { freject(function () use ($bad) { JetCreditSubmissionInput::recipients($bad); }, 'invalid_recipient_configuration'); }
fcheck(JetCreditSubmissionInput::recipients('')['mode'] === 'manual', 'only explicit empty means manual');
fcheck(JetCreditSubmissionInput::recipients('bank@example.test, other@example.test, BANK@example.test')['addresses'] === array('bank@example.test', 'other@example.test'), 'all comma entries validated/deduplicated');
foreach (array("First\r\nInjected", 'First&#13;Injected', "First\u{2028}Injected", array(), str_repeat('a', 256)) as $bad) { freject(function () use ($bad) { JetCreditSubmissionInput::line($bad); }, 'invalid_customer_input'); }

foreach (array('product', 'cart', 'checkout') as $context) {
	foreach (array('jet', 'jet_card') as $scheme) {
		foreach (array('automatic', 'manual') as $mode) {
			$r = sfixture(); if ($mode === 'manual') { $r->get('config')->values['module_mt_jet_credit_mail'] = ''; }
			$input = sinput($r, $context, $scheme); $result = $r->get('submission')->submit($context, $scheme, $input);
			$expected = $mode === 'manual' ? 'manual_registered' : 'transport_accepted'; $claim = sclaim($r); $native = $r->get('model_checkout_order');
			fcheck($result['code'] === $expected && $result['success'] === 'success', $context . '/' . $scheme . '/' . $mode . ' complete');
			fcheck($claim['state'] === 'complete' && $claim['result'] === $expected, 'durable completion');
			fcheck($native->created === ($context === 'checkout' ? 0 : 1), 'order before bank / no second checkout order');
			$order = $native->getOrder($result['order_id']);
			fcheck($order['total'] === ($context === 'product' ? '240.00' : ($context === 'checkout' ? '235.0000' : '235.00')), 'authoritative order total');
			fcheck((int)$order['order_status_id'] === ($scheme === 'jet' ? 3 : 4), 'gateway configured order status');
			fcheck($native->nativeMails === 0 && $native->histories[0][3] === false, 'narrow native-mail scope / notify false');
			fcheck($r->get('db')->counter === ($mode === 'automatic' ? 17 : 16) && ($claim['bank_reference'] !== null) === ($mode === 'automatic'), 'manual has no reference; automatic one reference');
			fcheck(ssends($r) === ($mode === 'automatic' ? 3 : 2), 'independent bank/admin/customer messages');
			fcheck($claim['admin_state'] === 'accepted' && $claim['customer_state'] === 'accepted', 'independent notification states');
			fcheck(count($r->get('db')->cartRows) === ($context === 'product' ? 1 : 0), 'product leaves cart; cart/checkout safe cleanup');
			$before = ssends($r); $created = $native->created;
			// Recipients and active payment selection may change AFTER a completed manual claim.
			$r->get('config')->values['module_mt_jet_credit_mail'] = 'another-bank@example.test';
			$r->get('session')->data['payment_method']['code'] = $scheme === 'jet' ? 'jet_card' : 'jet';
			fcheck(soutcome($r->get('submission')->submit($context, $scheme === 'jet' ? 'jet_card' : 'jet', $input)) === soutcome($result), 'authorized replay returns original result despite recipient/scheme change');
			fcheck(ssends($r) === $before && $native->created === $created, 'replay never sends or creates another order');
			$serialized = json_encode($claim);
			foreach (array('9001010000', 'Иван', 'Петров', 'ivan@example.test', 'private@example.test', '+359888123456', 'Данни за потребителя') as $private) { fcheck(strpos($serialized, $private) === false, 'claim contains no complete identity/body'); }
			fcheck((int)$claim['consent_flags'] === ($context === 'checkout' ? 3 : 7) && strlen($claim['consent_version']) === 64, 'minimal consent evidence');
			foreach ($native->histories as $history) { fcheck(strpos($history[2], '9001010000') === false && strpos($history[2], 'Иван') === false, 'safe order history'); }
			$messages = $r->get('submission_mail_instances')->items;
			if ($mode === 'automatic') { fcheck($messages[0]->addresses === array('bank@example.test') && strpos($messages[0]->Body, '9001010000') !== false, 'full body only to bank'); }
			foreach (array_slice($messages, $mode === 'automatic' ? 1 : 0) as $message) {
				fcheck(strpos($message->Body, '9001010000') === false && strpos($message->Body, 'ЕГН:') === false && strpos($message->Body, 'Данни за потребителя') === false, 'safe secondary body');
				if ($mode === 'manual') { fcheck(strpos($message->Body, 'НЕ е изпратена') !== false, 'manual notification explicit'); }
			}
		}
	}
}

// Additional valid legacy cart-option JSON, independently captured from Git.
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/bank-oc3-cart-options.json'), true)['cases'] as $case) {
 fcheck(JetCreditSubmissionMail::bankBody($case['identity'], $case['products'], $case['calculation'], $case['scheme']) === $case['body'], 'valid cart option JSON golden');
}
// Every validation failure precedes claims, order creation and transport.
foreach (array('consent0', 'consent1', 'consent2', 'consent_type', 'recipient_missing', 'recipient_invalid', 'recipient_whitespace', 'tamper', 'expired', 'stale', 'forged', 'customer_input', 'csrf', 'method', 'scheme', 'secret', 'storage_engine', 'storage_index', 'storage_seed') as $change) {
 $r = sfixture(); $input = sinput($r); $scheme = 'jet';
 switch ($change) {
  case 'consent0': $input['jet_uslovia'] = '0'; break;
  case 'consent1': unset($input['jet_uslovia1']); break;
  case 'consent2': $input['jet_uslovia2'] = false; break;
  case 'consent_type': $input['jet_uslovia'] = array('1'); break;
  case 'recipient_missing': unset($r->get('config')->values['module_mt_jet_credit_mail']); break;
  case 'recipient_invalid': $r->get('config')->values['module_mt_jet_credit_mail'] = 'bank@example.test,'; break;
  case 'recipient_whitespace': $r->get('config')->values['module_mt_jet_credit_mail'] = ' '; break;
  case 'tamper': $input['jet_offer'][5] = $input['jet_offer'][5] === 'A' ? 'B' : 'A'; break;
  case 'expired':
   list($payload, $signature) = explode('.', $input['jet_offer']); $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
   $decoded['issued'] = time()-1801; $decoded['expires'] = $decoded['issued']+1800;
   $payload = rtrim(strtr(base64_encode(json_encode($decoded, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
   $input['jet_offer'] = $payload . '.' . hash_hmac('sha256', $payload, hex2bin($r->get('config')->get('jetcredit_offer_secret'))); break;
  case 'stale': $r->get('model_catalog_product')->products[42]['price'] = '101'; break;
  case 'forged': $input['jet_vnoska'] = '0.01'; break;
  case 'customer_input': $input['jet_name'] = "Name\r\nExtra: injected"; break;
  case 'csrf': $input['jet_csrf'] = 'wrong'; break;
  case 'method': $r->get('request')->server['REQUEST_METHOD'] = 'GET'; break;
  case 'scheme': $scheme = 'other'; break;
  case 'secret': unset($r->get('config')->values['jetcredit_offer_secret']); break;
  case 'storage_engine': $r->get('db')->engine = 'MyISAM'; break;
  case 'storage_index': $r->get('db')->indexes = false; break;
  case 'storage_seed': $r->get('db')->counterExists = false; break;
 }
 freject(function () use ($r, $scheme, $input) { $r->get('submission')->submit('product', $scheme, $input); });
 fcheck(!$r->get('db')->claims && $r->get('model_checkout_order')->created === 0 && ssends($r) === 0, 'validation precedes every side effect: ' . $change);
}
foreach (array('checkout' => array('jet_uslovia', 'jet_uslovia1'), 'checkout_card' => array('jet_card_uslovia', 'jet_card_uslovia1')) as $path => $controls) {
 foreach ($controls as $key) {
  $r = sfixture(); $scheme = $path === 'checkout_card' ? 'jet_card' : 'jet'; $input = sinput($r, 'checkout', $scheme); unset($input[$key]);
  freject(function () use ($r, $scheme, $input) { $r->get('submission')->submit('checkout', $scheme, $input); }, 'consent_required');
  fcheck(!$r->get('db')->claims, 'checkout consent before claim');
 }
}
// Existing checkout UI accepts 1..10 digits; no new EGN checksum policy.
$r = sfixture(); $input = sinput($r, 'checkout', 'jet', array('jet_egn' => '7'));
fcheck($r->get('submission')->submit('checkout', 'jet', $input)['code'] === 'transport_accepted', 'legacy checkout ID policy preserved');

foreach (array('before', 'partial', 'corrupt') as $failure) {
 $r = sfixture(); $input = sinput($r);
 if ($failure === 'corrupt') { $r->get('model_checkout_order')->corrupt = true; } else { $r->get('model_checkout_order')->failCreate = $failure; }
 $result = $r->get('submission')->submit('product', 'jet', $input);
 fcheck($result['code'] === 'review_required' && sclaim($r)['state'] === 'order_uncertain', 'durable uncertain MyISAM order: ' . $failure);
 fcheck(ssends($r) === 0 && $r->get('db')->counter === 16, 'no reference/bank before verified persisted order');
 $r->get('model_checkout_order')->failCreate = null;
 $r->get('submission')->submit('product', 'jet_card', $input);
 fcheck($r->get('model_checkout_order')->created === 1, 'no second order on retry or scheme switch');
}
foreach (array('preflight', 'second_preflight', 'smtp_false', 'smtp_exception') as $failure) {
 $r = sfixture(); $input = sinput($r);
 $r->get('submission_mail_instances')->settings[0] = $failure === 'preflight' ? array('preflight' => false) : ($failure === 'second_preflight' ? array('failSecondPreflight' => true) : array('outcome' => $failure === 'smtp_false' ? 'unknown' : 'throw'));
 $result = $r->get('submission')->submit('product', 'jet', $input); $row = sclaim($r);
 fcheck($result['code'] === 'review_required' && $row['state'] === (strpos($failure, 'smtp') === 0 ? 'bank_unknown' : 'before_transport_failed'), 'explicit pretransport vs unknown SMTP: ' . $failure);
 fcheck($r->get('db')->counter === ($failure === 'preflight' ? 16 : 17), 'reference consumed only after preflight; never reused');
 $sent = ssends($r); $r->get('submission')->submit('product', 'jet', $input);
 fcheck(ssends($r) === $sent && $r->get('model_checkout_order')->created === 1, 'no automatic retry after any terminal failure');
}
foreach (array('before', 'after') as $failure) {
 $r = sfixture(); $input = sinput($r); $r->get('model_checkout_order')->failHistory = $failure;
 fcheck($r->get('submission')->submit('product', 'jet', $input)['code'] === 'review_required', 'history failure requires review');
 fcheck(sclaim($r)['bank_state'] === 'accepted' && sclaim($r)['state'] === 'finalization_failed', 'acceptance persists before history');
 $r->get('model_checkout_order')->failHistory = null; $r->get('submission')->submit('product', 'jet', $input);
 fcheck(ssends($r) === 1 && count($r->get('model_checkout_order')->histories) === 1, 'history retry never repeats bank or uncertain finalization');
}
foreach (array(1, 2) as $category) {
 $r = sfixture(); $input = sinput($r); $r->get('submission_mail_instances')->settings[$category] = array('outcome' => 'throw');
 fcheck($r->get('submission')->submit('product', 'jet', $input)['code'] === 'transport_accepted', 'secondary failure does not fail bank registration');
 fcheck(sclaim($r)[$category === 1 ? 'admin_state' : 'customer_state'] === 'unknown', 'independent notification uncertainty');
 $sent = ssends($r); $r->get('submission')->submit('product', 'jet', $input); fcheck(ssends($r) === $sent, 'secondary failure/replay never resends');
}
// Session/customer/intent/order replay authorization never discloses an outcome.
foreach (array('session', 'customer', 'intent') as $change) {
 $r = sfixture(); $input = sinput($r, $change === 'order' ? 'checkout' : 'product');
 $context = $change === 'order' ? 'checkout' : 'product'; $r->get('submission')->submit($context, 'jet', $input); $sent = ssends($r);
 if ($change === 'session') { $r->get('session')->id = 'another-session'; }
 if ($change === 'customer') { $r->get('customer')->id = 8; }
 if ($change === 'intent') { $input['jet_intent'] = hash('sha256', 'foreign-intent'); }
 if ($change === 'order') { $r->get('session')->data['jetcredit_intent_orders'][$input['jet_intent']] = 12; }
 freject(function () use ($r, $context, $input) { $r->get('submission')->submit($context, 'jet', $input); });
 fcheck(ssends($r) === $sent, 'unauthorized replay no sends');
}
// Recalculation keeps intent; simultaneous owner runs linearize at the unique insert.
$r = sfixture(); $first = sinput($r); $second = sinput($r, 'product', 'jet_card');
fcheck($first['jet_intent'] === $second['jet_intent'], 'recalculation/scheme switch shares application identity');
$r->get('db')->onInsert = function () use ($r, $second) { fcheck($r->get('submission')->submit('product', 'jet_card', $second)['success'] === 'success', 'competing request wins atomic insert'); };
$result = $r->get('submission')->submit('product', 'jet', $first);
fcheck($result['success'] === 'success' && count($r->get('db')->claims) === 1 && $r->get('model_checkout_order')->created === 1 && ssends($r) === 3, 'interleaved claim loser only replays winner');

function srecord($intent, $order, $run) { return array('intent' => hash('sha256', $intent), 'authorization' => hash('sha256', 'owner'), 'owner_run' => hash('sha256', $run), 'request_digest' => hash('sha256', 'request'), 'context' => 'checkout', 'scheme' => 'jet', 'order_id' => $order, 'recipient_mode' => 'automatic', 'consent_flags' => 3, 'consent_version' => hash('sha256', 'consent')); }
$db = new SDb(); $store = new JetCreditSubmissionStore($db, DB_PREFIX); $store->ready();
$a = srecord('A', 101, 'runA'); $b = srecord('B', 102, 'runB');
$claimA = $store->acquire($a); $claimB = $store->acquire($b);
$duplicateOrder = $store->acquire(srecord('other-intent', 101, 'other-run'));
fcheck(!$duplicateOrder['acquired'] && count($db->claims) === 2, 'unique order binding blocks another identity');
foreach (array(array($claimA, $a, 17), array($claimB, $b, 18)) as $case) {
 $store->change($case[0]['row']['submission_id'], $case[1]['owner_run'], 'claimed', 'order_ready');
 fcheck($store->reserve($case[0]['row']['submission_id'], $case[1]['owner_run']) === $case[2], 'distinct atomic references across claims');
 freject(function () use ($store, $case) { $store->reserve($case[0]['row']['submission_id'], $case[1]['owner_run']); });
}
fcheck($db->counter === 18 && !$db->transaction, 'counter/claim committed and no lock retained');
$db = new SDb(); $store = new JetCreditSubmissionStore($db, DB_PREFIX); $a = srecord('rollback', 101, 'run'); $claim = $store->acquire($a); $store->change($claim['row']['submission_id'], $a['owner_run'], 'claimed', 'order_ready');
$db->failure = array('contains' => "state='bank_attempting'");
freject(function () use ($store, $claim, $a) { $store->reserve($claim['row']['submission_id'], $a['owner_run']); }, 'submission_storage_unavailable');
fcheck($db->counter === 16 && array_values($db->claims)[0]['state'] === 'order_ready', 'reference and attempt roll back together');

foreach (array(false, true) as $afterCommit) {
 $r = sfixture(); $input = sinput($r); $r->get('db')->failure = array('contains' => 'COMMIT', 'after' => $afterCommit);
 fcheck($r->get('submission')->submit('product', 'jet', $input)['code'] === 'review_required' && ssends($r) === 0, 'uncertain commit never starts mail');
 $r->get('submission')->submit('product', 'jet', $input); fcheck(ssends($r) === 0 && $r->get('model_checkout_order')->created === 1, 'uncertain commit cannot auto-resume');
}
$r = sfixture(); $input = sinput($r); $r->get('db')->failure = array('contains' => "SET state='registered'", 'after' => false);
fcheck($r->get('submission')->submit('product', 'jet', $input)['code'] === 'review_required' && sclaim($r)['bank_state'] === 'attempting', 'lost acceptance persistence stays blocked');
$r->get('submission')->submit('product', 'jet', $input); fcheck(ssends($r) === 1, 'no resend after accepted transport / failed DB');

foreach (array('changed', 'failed') as $cleanup) {
 $r = sfixture(); $input = sinput($r, 'cart');
 if ($cleanup === 'changed') { $r->get('db')->beforeDelete = function ($db) { $db->cartRows[1]['quantity'] = '3'; $db->cartRows[2] = $db->cartRows[1] + array(); $db->cartRows[2]['cart_id'] = '2'; }; }
 else { $r->get('db')->failure = array('contains' => 'DELETE FROM `oc_cart`'); }
 fcheck($r->get('submission')->submit('cart', 'jet', $input)['success'] === 'success', 'cleanup failure/change does not resend or undo registration');
 fcheck(count($r->get('db')->cartRows) === ($cleanup === 'changed' ? 2 : 1), 'changed/new cart rows survive cleanup');
 fcheck(sclaim($r)['cleanup_state'] === ($cleanup === 'changed' ? 'changed_cart_retained' : 'failed'), 'durable cleanup outcome');
 $r->get('submission')->submit('cart', 'jet', $input); fcheck(ssends($r) === 3 && $r->get('model_checkout_order')->created === 1, 'cleanup retry does not resend or create order');
}
// Native OC3's actual callback sends its initial email even with notify=false.
$r = sfixture(); $native = new SNativeMail(); $native->model_checkout_order = $r->get('model_checkout_order'); $route = ''; $args = array(11, 3, '', false); $native->index($route, $args);
fcheck($native->calls === 1, 'stock native initial email ignores notify=false');
$scope = new SScope(); fcheck(!$scope->suppresses(11, 'jet'), 'no global suppression');
$scope->run(11, 'jet', function () use ($scope) { fcheck($scope->suppresses(11, 'jet') && !$scope->suppresses(12, 'jet') && !$scope->suppresses(11, 'other'), 'only exact Jet-owned order is suppressed'); });
fcheck(!$scope->suppresses(11, 'jet'), 'suppression removed after finalization');
$r = sfixture(); $input = sinput($r); $r->set('jetcredit_native_mail_scope', new JetCreditNativeMailScope());
freject(function () use ($r, $input) { $r->get('submission')->submit('product', 'jet', $input); }, 'native_mail_guard_unavailable');
fcheck(!$r->get('db')->claims && ssends($r) === 0 && $r->get('model_checkout_order')->created === 0, 'stock native-mail dependency fails closed before mutations');
$schema = JetCreditSubmissionSchema::createSql(DB_PREFIX);
fcheck(strpos($schema, 'ENGINE=InnoDB') !== false && substr_count($schema, 'UNIQUE KEY') === 5 && strpos($schema, 'egn') === false && strpos($schema, 'body') === false, 'minimal InnoDB schema, unique claims/orders/references, no EGN/body');
fcheck(strpos(JetCreditSubmissionSchema::counterSql(DB_PREFIX, 0, 100), ',100,') !== false, 'explicit owner-supplied counter seed definition only');
// Independently loaded session snapshots derive the same first intent even without
// a backend session write lock. Recalculation cannot manufacture another intent.
$r1 = sfixture(); $r2 = sfixture();
fcheck(sinput($r1)['jet_intent'] === sinput($r2)['jet_intent'], 'simultaneous first quotes share deterministic server identity');
foreach (array('product' => 'install.xml', 'checkout' => 'upload/catalog/view/theme/default/template/extension/payment/jet.twig') as $context => $file) {
 $markup = file_get_contents(__DIR__ . '/../' . $file);
 foreach (JetCreditSubmissionInput::notices($context) as $notice) {
  fcheck(strpos($markup, $notice['text']) !== false && strpos($markup, $notice['url']) !== false, 'consent version describes actual local acknowledgement and URL');
 }
}
// Bank-specific options/modifiers and discounts are persisted through native APIs.
$r = sfixture();
$r->get('model_catalog_product')->options[42] = array(array('product_option_id' => 10, 'name' => 'Цвят', 'required' => true, 'type' => 'select', 'product_option_value' => array(array('product_option_value_id' => 100, 'name' => 'Червен', 'quantity' => 100, 'subtract' => 1, 'price' => '10', 'price_prefix' => '+'))));
$r->get('model_catalog_product')->discounts[42] = array(array('quantity' => 3, 'priority' => 1, 'price' => '80'));
$input = sinput($r, 'product', 'jet', array('jet_quantity' => '3', 'option' => array(10 => 100)));
$result = $r->get('submission')->submit('product', 'jet', $input);
fcheck($result['success'] === 'success' && $r->get('model_checkout_order')->getOrder($result['order_id'])['total'] === '324.00', 'product discounted/option/tax order authoritative');
fcheck(strpos($r->get('submission_mail_instances')->items[0]->Body, 'Уред - Червен') !== false && strpos($r->get('submission_mail_instances')->items[0]->Body, '108.00') !== false, 'bank receives persisted option and tax-inclusive unit price');
// Sender/category isolation uses real bundled PHPMailer preSend(), never postSend().
$cfg = new FConfig(); $cfg->values += array('config_email' => 'admin@example.test', 'module_mt_jet_credit_id' => 'TEST-MERCHANT', 'config_mail_engine' => 'mail');
$mail = new JetCreditSubmissionMail($cfg);
$bank = $mail->prepare(array('bank@example.test', 'second@example.test'), 'Synthetic test', 'No real send');
$admin = $mail->prepare(array('admin@example.test'), 'Synthetic test', 'No real send');
$customer = $mail->prepare(array('ivan@example.test'), 'Synthetic test', 'No real send');
fcheck($bank !== $admin && $admin !== $customer && count($bank->getToAddresses()) === 2, 'separate actual PHPMailer instances / validated recipient list');
fcheck(!$bank->getCcAddresses() && !$bank->getBccAddresses() && !$admin->getCcAddresses() && !$customer->getBccAddresses(), 'actual PHPMailer has no cross-category CC/BCC');
fcheck($bank->SMTPDebug === 0 && $bank->CharSet === 'UTF-8' && $bank->Encoding === 'base64', 'actual PHPMailer privacy/encoding configuration');
// Scope cleanup must also happen after a native finalization exception.
$scope = new SScope();
try { $scope->run(11, 'jet_card', function () { throw new RuntimeException('synthetic'); }); } catch (RuntimeException $ignored) {}
fcheck(!$scope->suppresses(11, 'jet_card'), 'request-local guard finally clears on exception');
// Never disarm another tab's order ID; success-route wiring must respect this.
$r = sfixture(); $r->get('session')->data['order_id'] = 99;
$r->get('model_extension_mt_jet_credit_submission_order')->disarmSuccess(11);
fcheck($r->get('session')->data['order_id'] === 99, 'unrelated current checkout context preserved');
foreach (array(false, true) as $afterInsert) {
 $r = sfixture(); $input = sinput($r); $r->get('db')->failure = array('contains' => 'INSERT INTO `oc_jet_submission`', 'after' => $afterInsert);
 freject(function () use ($r, $input) { $r->get('submission')->submit('product', 'jet', $input); }, 'submission_unavailable');
 fcheck($r->get('model_checkout_order')->created === 0 && ssends($r) === 0, 'claim DB failure precedes order/mail');
 if ($afterInsert) { fcheck($r->get('submission')->submit('product', 'jet', $input)['code'] === 'review_required', 'uncertain acquired claim is never retried'); }
}
$r = sfixture(); $input = sinput($r); $r->get('db')->autocommit = false;
freject(function () use ($r, $input) { $r->get('submission')->submit('product', 'jet', $input); }, 'submission_storage_unavailable');
fcheck(!$r->get('db')->claims && $r->get('model_checkout_order')->created === 0, 'claim connection must commit independently');
$r = sfixture(); $r->get('config')->values['module_mt_jet_credit_mail'] = ''; $input = sinput($r);
$original = $r->get('submission')->submit('product', 'jet', $input);
foreach (array(null, 'invalid', 'bank@example.test') as $changedRecipient) {
 $r->get('config')->values['module_mt_jet_credit_mail'] = $changedRecipient;
 fcheck(soutcome($r->get('submission')->submit('product', 'jet', $input)) === soutcome($original) && $r->get('db')->counter === 16, 'completed manual claim ignores all later recipient modes');
}
$r = sfixture(); $r->get('model_catalog_product')->products[42]['reward'] = '5'; $input = sinput($r);
$result = $r->get('submission')->submit('product', 'jet', $input);
fcheck($r->get('model_checkout_order')->getOrderProducts($result['order_id'])[0]['reward'] === 10, 'native order reward points preserved without altering finance formula');
$r = sfixture(); $source = $r->get('model_extension_mt_jet_credit_financial')->cartSnapshot();
$prepared = array('source' => $source, 'cleanup' => array_values($r->get('db')->cartRows));
$r->get('session')->data['coupon'] = 'changed';
fcheck($r->get('model_extension_mt_jet_credit_submission_order')->cleanup(11, 'cart', $prepared) === 'changed_cart_retained' && count($r->get('db')->cartRows) === 1, 'changed pricing context retains cart');
foreach (array('module_mt_jet_credit_id', 'config_email') as $key) {
 $r = sfixture(); $input = sinput($r); $r->get('config')->values[$key] = $key === 'config_email' ? 'different@example.test' : 'OTHER-MERCHANT';
 freject(function () use ($r, $input) { $r->get('submission')->submit('product', 'jet', $input); }, 'stale_offer');
 fcheck(!$r->get('db')->claims && ssends($r) === 0, 'merchant/sender routing configuration bound to offer');
}
echo "IMP-03 SUBMISSION PASS: $fchecks assertions; isolated SQL/OC3/PHPMailer doubles, no network/real DB.\n";
