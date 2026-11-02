<?php
require_once DIR_SYSTEM . 'library/jetcredit/submission.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_store.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_identity.php';
require_once DIR_SYSTEM . 'library/jetcredit/native_mail_scope.php';
require_once DIR_SYSTEM . 'library/jetcredit/receipt.php';
/** Shared public submission integration, conditional on installed native capabilities. */
class ModelExtensionMtJetCreditSubmission extends Model {
	public function submit($context, $scheme, $input) {
		try { return $this->process($context, $scheme, $input); }
		catch (JetCreditFinancialException $e) { throw $e; }
		catch (Throwable $e) { throw new JetCreditFinancialException('submission_unavailable'); }
	}
	private function process($context, $scheme, $input) {
		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') { throw new JetCreditFinancialException('method_not_allowed'); }
		if (!in_array($context, array('product', 'cart', 'checkout'), true) || !in_array($scheme, array('jet', 'jet_card'), true) || !is_array($input)) { throw new JetCreditFinancialException('invalid_context'); }
		$csrf = $this->session->data['jetcredit_csrf'] ?? null;
		if (!is_string($csrf) || !is_string($input['jet_csrf'] ?? null) || !hash_equals($csrf, $input['jet_csrf'])) { throw new JetCreditFinancialException('invalid_token'); }
		if (!in_array($this->config->get('config_store_id'), array(null, 0, '0'), true)) { throw new JetCreditFinancialException('unsupported_store'); }
		JetCreditRuntime::boot($this->registry);
		$signer = new JetCreditOffer(JetCreditOffer::configuredSecret($this->config));
		$submittedIntent = $input['jet_intent'] ?? null;
		if (!is_string($submittedIntent) || !preg_match('/^[a-f0-9]{64}$/D', $submittedIntent)) { throw new JetCreditFinancialException('invalid_application_context'); }
		$entity = $context === 'product' ? JetCreditFinancial::positiveInteger($input['jet_product_id'] ?? null) : null;
		$identity = $this->identity();
		$authorization = $identity->authorization(0,$this->customer->getId(),$context,$entity,$submittedIntent);
		$store = $this->submissionStore(); $store->ready();
		$historical = $store->find($submittedIntent); $draft = $context === 'checkout' ? $store->draft($submittedIntent) : null;
		if ($context === 'checkout') {
			if (!$historical && (!$draft || $draft['state']!=='draft_bound')) { throw new JetCreditFinancialException('review_required'); }
			$entity = JetCreditFinancial::positiveInteger($historical['order_id'] ?? $draft['order_id'] ?? null);
		}
		$intent = $submittedIntent;
		$scope = $this->registry->get('jetcredit_native_mail_scope');
		if (!$scope instanceof JetCreditNativeMailScope || !$scope->available()) { throw new JetCreditFinancialException('native_mail_guard_unavailable'); }
		$this->load->model('extension/mt_jet_credit/financial'); $this->load->model('extension/mt_jet_credit/submission_order');
		$store = $this->submissionStore();
		$service = new JetCreditSubmission($store, $this->model_extension_mt_jet_credit_submission_order, $this->mailer(), $signer, $this->config);
		$result = $service->submit($context, $scheme, $intent, $authorization, $context === 'checkout' ? $entity : null, function () use ($context, $scheme, $input, $intent, $entity) {
			$this->identity()->assertCurrent($context,$entity,$intent);
			$signed=(new JetCreditOffer(JetCreditOffer::configuredSecret($this->config)))->identity($input['jet_offer']??null);
			if (!hash_equals($signed['application_intent'],$intent) || $signed['generation']!==$this->identity()->generation($context,$entity,$intent)) { throw new JetCreditFinancialException('stale_offer'); }
			$verified = $this->model_extension_mt_jet_credit_financial->verifySubmission($context, $scheme, $input);
			if ($context === 'checkout' && $verified['source']['order_id'] !== $entity) { throw new JetCreditFinancialException('invalid_order_context'); }
			$order = null;
			if ($context === 'checkout') { $this->load->model('checkout/order'); $order = $this->model_checkout_order->getOrder($verified['source']['order_id']); }
			$customer = JetCreditSubmissionInput::validate($context, $scheme, $input, $order);
			$recipient = JetCreditSubmissionInput::recipients($this->config->get('module_mt_jet_credit_mail'));
			$status = $this->model_extension_mt_jet_credit_submission_order->ready($scheme);
			$prepared = $this->model_extension_mt_jet_credit_submission_order->prepare($context, $scheme, $verified['source'], $customer['person'], $intent);
			return array_merge($verified, $customer, array('recipient' => $recipient, 'status' => $status, 'prepared' => $prepared));
		});
		if ($result['success'] === 'success') {
			$receipt = (new JetCreditReceipt($signer, $this->session, $this->customer->getId()))->issue($context, $entity, $intent, $result);
			$result['redirect'] = html_entity_decode($this->url->link('extension/mt_jet_credit/success', 'receipt=' . rawurlencode($receipt), true), ENT_QUOTES, 'UTF-8');
		}
		return $result;
	}
	public function existingOutcome($context, $entity, $intent) {
		JetCreditRuntime::boot($this->registry);
		$signer = new JetCreditOffer(JetCreditOffer::configuredSecret($this->config));
		$identity = new JetCreditSubmissionIdentity($this->session, $signer, $this->submissionStore(), $this->customer->getId());
		$auth = $identity->authorization(0, $this->customer->getId(), $context, $entity, $intent);
		$store = $this->submissionStore(); $store->ready(); $row = $store->find($intent);
		if (!$row) { return null; }
		if (!is_string($row['authorization']) || !hash_equals($row['authorization'], $auth)) { throw new JetCreditFinancialException('invalid_application_context'); }
		if ($row['state'] !== 'complete' || !in_array($row['result'], array('manual_registered','transport_accepted'), true)) { return array('success'=>'unsuccess','code'=>'review_required','application_intent'=>$intent); }
		$result = array('success'=>'success','code'=>$row['result'],'order_id'=>(int)$row['order_id']);
		$receipt = (new JetCreditReceipt($signer, $this->session, $this->customer->getId()))->issue($context,$entity,$intent,$result);
		$result['redirect'] = html_entity_decode($this->url->link('extension/mt_jet_credit/success','receipt='.rawurlencode($receipt),true),ENT_QUOTES,'UTF-8');
		$result['existing_outcome'] = $row['result'];
		$result['application_intent'] = $intent;
		return $result;
	}
	public function receiptOutcome($token) {
		JetCreditRuntime::boot($this->registry);
		$signer = new JetCreditOffer(JetCreditOffer::configuredSecret($this->config));
		$data = (new JetCreditReceipt($signer, $this->session, $this->customer->getId()))->verify($token);
		$identity = new JetCreditSubmissionIdentity($this->session, $signer, $this->submissionStore(), $this->customer->getId());
		$auth = $identity->authorization(0, $this->customer->getId(), $data['context'], $data['entity'], $data['intent']);
		$store = $this->submissionStore(); $store->ready(); $row = $store->find($data['intent']);
		if (!$row || !hash_equals((string)$row['authorization'], $auth) || $row['state'] !== 'complete' || (int)$row['order_id'] !== $data['order'] || $row['result'] !== $data['result']) { throw new JetCreditFinancialException('invalid_receipt'); }
		return array('code' => $row['result'], 'order_id' => (int)$row['order_id'], 'context'=>$data['context'], 'entity'=>$data['entity'], 'intent'=>$data['intent']);
	}
	public function identity() {
		return new JetCreditSubmissionIdentity($this->session,new JetCreditOffer(JetCreditOffer::configuredSecret($this->config)),$this->submissionStore(),$this->customer->getId());
	}
	public function purchaseRepository() { $store=$this->submissionStore(); $store->ready(); return $store; }
	public function newPurchase($input) {
		if (($this->request->server['REQUEST_METHOD']??'')!=='POST') { throw new JetCreditFinancialException('method_not_allowed'); }
		$csrf=$this->session->data['jetcredit_csrf']??null;
		if (!is_string($csrf) || !is_string($input['jet_csrf']??null) || !hash_equals($csrf,$input['jet_csrf'])) { throw new JetCreditFinancialException('invalid_token'); }
		JetCreditRuntime::boot($this->registry);
		$this->load->model('extension/mt_jet_credit/financial');
		$this->model_extension_mt_jet_credit_financial->currencyContext();
		$context=$input['jet_context']??null; $entity=$context==='product'?JetCreditFinancial::positiveInteger($input['jet_product_id']??null):null;
		$this->purchaseRepository();
		$next=$this->identity()->advance($context,$entity,$input['jet_intent']??null,$input['jet_transition']??null);
		return array('success'=>'success','application_intent'=>$next,'previous_intent'=>$input['jet_intent']);
	}
	protected function mailer() { return new JetCreditSubmissionMail($this->config); }
	protected function submissionStore() {
		// Claims must commit independently of custom/native order transaction behavior.
		// No other model receives this connection; no global connection settings change.
		$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
		return new JetCreditSubmissionStore($db, DB_PREFIX, 0);
	}
}
