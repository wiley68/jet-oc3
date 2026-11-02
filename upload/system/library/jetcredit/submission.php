<?php
require_once __DIR__ . '/submission_mail.php';
/** One owner run, no lease takeover and no automatic retry of any side effect. */
class JetCreditSubmission {
	private $store; private $orders; private $mail; private $signer; private $config;
	public function __construct($store, $orders, $mail, $signer, $config) {
		$this->store = $store; $this->orders = $orders; $this->mail = $mail; $this->signer = $signer; $this->config = $config;
	}
	private function replay($row, $authorization) {
		if (!is_string($row['authorization']) || !hash_equals($row['authorization'], $authorization)) { throw new JetCreditFinancialException('invalid_application_context'); }
		if ($row['state'] === 'complete' && in_array($row['result'], array('manual_registered', 'transport_accepted'), true)) {
			return array('success' => 'success', 'code' => $row['result'], 'order_id' => (int)$row['order_id']);
		}
		return array('success' => 'unsuccess', 'code' => 'review_required');
	}
	public function submit($context, $scheme, $intent, $authorization, $checkoutOrder, $validate) {
		$this->store->ready();
		$existing = $this->store->find($intent, $checkoutOrder);
		// Read-only authorized replay precedes current recipient/offer checks: a completed
		// manual registration must not be reprocessed after settings or cart changes.
		if ($existing) { return $this->replay($existing, $authorization); }
		$verified = call_user_func($validate);
		$run = bin2hex(random_bytes(32));
		$claim = $this->store->acquire(array('intent' => $intent, 'authorization' => $authorization, 'owner_run' => $run, 'request_digest' => $this->signer->digest(array('source' => $verified['source'], 'calculation' => $verified['calculation'], 'person' => $verified['person'], 'recipient' => $verified['recipient'], 'status' => $verified['status'])), 'context' => $context, 'scheme' => $scheme, 'order_id' => $checkoutOrder, 'recipient_mode' => $verified['recipient']['mode'], 'consent_flags' => $verified['consent']['flags'], 'consent_version' => $verified['consent']['version']));
		if (!$claim['acquired']) { return $this->replay($claim['row'], $authorization); }
		$id = $claim['row']['submission_id'];
		$state = 'claimed'; $order = $checkoutOrder;
		try {
			if ($context !== 'checkout') {
				$this->store->change($id, $run, $state, 'order_creating'); $state = 'order_creating';
				// MyISAM may retain a partial order if addOrder() throws or the process dies.
				$order = $this->orders->create($verified['prepared']);
			}
			$this->store->change($id, $run, $state, 'order_verifying', array('order_id' => $order)); $state = 'order_verifying';
			$this->orders->verify($order, $scheme, $verified['prepared']);
			$this->store->change($id, $run, $state, 'order_ready'); $state = 'order_ready';
		} catch (Throwable $e) {
			$this->bestChange($id, $run, $state, 'order_uncertain');
			return array('success' => 'unsuccess', 'code' => 'review_required');
		}
		$result = 'manual_registered';
		if ($verified['recipient']['mode'] === 'automatic') {
			try {
				$body = JetCreditSubmissionMail::bankBody($verified['person'], $this->orders->bankProducts($order, $verified['source']), $verified['calculation'], $scheme);
				$merchant = $this->config->get('module_mt_jet_credit_id');
				$preparedMail = $this->mail->prepare($verified['recipient']['addresses'], JetCreditSubmissionMail::bankSubject($merchant, 0), $body);
			} catch (Throwable $e) {
				$this->bestChange($id, $run, 'order_ready', 'before_transport_failed', array('bank_state' => 'before_transport_failed'));
				return array('success' => 'unsuccess', 'code' => 'review_required');
			}
			try { $reference = $this->store->reserve($id, $run); }
			catch (Throwable $e) { return array('success' => 'unsuccess', 'code' => 'review_required'); }
			$outcome = $this->mail->transport($preparedMail, JetCreditSubmissionMail::bankSubject($merchant, $reference));
			if (!in_array($outcome, array('accepted', 'before_transport_failed', 'unknown'), true)) { $outcome = 'unknown'; }
			try {
				$this->store->change($id, $run, 'bank_attempting', $outcome === 'accepted' ? 'registered' : ($outcome === 'unknown' ? 'bank_unknown' : 'before_transport_failed'), array('bank_state' => $outcome, 'result' => $outcome === 'accepted' ? 'transport_accepted' : ''));
			} catch (Throwable $e) {
				// The durable attempting marker blocks retries even if acceptance cannot be saved.
				return array('success' => 'unsuccess', 'code' => 'review_required');
			}
			if ($outcome !== 'accepted') { return array('success' => 'unsuccess', 'code' => 'review_required'); }
			$result = 'transport_accepted';
		} else {
			$this->store->change($id, $run, 'order_ready', 'registered', array('result' => $result));
		}
		try {
			$this->store->change($id, $run, 'registered', 'finalizing');
			$this->orders->finalize($order, $scheme, $verified['status'], $result);
			$this->store->change($id, $run, 'finalizing', 'complete');
		} catch (Throwable $e) {
			$this->bestChange($id, $run, 'finalizing', 'finalization_failed');
			return array('success' => 'unsuccess', 'code' => 'review_required');
		}
		// Local registration and banking outcome are durable before these independent actions.
		$this->orders->disarmSuccess($order);
		try {
			$this->store->change($id, $run, 'complete', 'complete', array('cleanup_state' => 'attempting'));
			$cleanup = $this->orders->cleanup($order, $context, $verified['prepared']);
			$this->store->change($id, $run, 'complete', 'complete', array('cleanup_state' => $cleanup));
		} catch (Throwable $e) { $this->bestChange($id, $run, 'complete', 'complete', array('cleanup_state' => 'failed')); }
		foreach (array('admin', 'customer') as $category) {
			try {
				$this->store->notification($id, $run, $category, 'pending', 'attempting');
			} catch (Throwable $e) { continue; }
			$outcome = 'before_transport_failed';
			try {
				$address = $category === 'admin' ? $this->config->get('config_email') : $verified['person']['email'];
				$body = JetCreditSubmissionMail::safeBody($category, $verified['person'], $order, $result, $verified['calculation']['amount']);
				$preparedMail = $this->mail->prepare(array($address), 'Jet: регистрация на заявка ' . $order, $body);
				$outcome = $this->mail->transport($preparedMail);
			} catch (Throwable $e) { /* Fixed state only; never persist/log exception or body. */ }
			try { $this->store->notification($id, $run, $category, 'attempting', $outcome); } catch (Throwable $e) {}
		}
		return array('success' => 'success', 'code' => $result, 'order_id' => (int)$order);
	}
	private function bestChange($id, $run, $from, $to, $values = array()) {
		try { $this->store->change($id, $run, $from, $to, $values); } catch (Throwable $e) {}
	}
}
