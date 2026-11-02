<?php
/** Request-local, opt-in diagnostics. Never retain input, exception text or trace. */
class JetCreditDiagnostics {
	private $log; private $enabled = false; private $id = ''; private $stage = 'entry'; private $operation;
	private $reservationReached = false; private $reservationConfirmed = false; private $nativeCreationStarted = false; private $bindingConfirmed = false;
	private $captured = false; private $claimReached = false; private $claimConfirmed = false; private $orderBegan = false;
	private const STAGES = array('entry','runtime','authorization','storage','financial_verification','customer_validation','recipient_validation','order_status_readiness','order_preparation','claim_acquisition','order_creation','order_verification','mail_preparation','reference_reservation','mail_transport','finalization','completion','receipt','model_loading','context','financing_readiness','claim_lookup','draft_reservation','draft_reuse','native_order_creation','persisted_verification','draft_binding','return');
	public function __construct($config, $log, $operation) {
		$this->operation = in_array($operation, array('submission_product','submission_cart','submission_checkout','quote_product','quote_cart','quote_checkout','checkout_confirm'), true) ? $operation : 'unknown';
		try {
			$setting = defined('JETCREDIT_DIAGNOSTICS') ? JETCREDIT_DIAGNOSTICS : $config->get('module_mt_jet_credit_diagnostics');
			if (!in_array($setting, array(true, 1, '1'), true)) { return; }
			$this->id = bin2hex(random_bytes(16)); $this->log = $log; $this->enabled = true;
		} catch (Throwable $ignored) { /* Diagnostics must not affect the operation. */ }
	}
	public function stage($stage) { $this->stage = in_array($stage, self::STAGES, true) ? $stage : 'entry'; }
	public function claimReached() { $this->claimReached = true; $this->stage('claim_acquisition'); }
	public function claimConfirmed() { $this->claimConfirmed = true; }
	public function orderBegan() { $this->orderBegan = true; $this->stage('order_creation'); }
	public function reservationReached() { $this->reservationReached = true; }
	public function reservationConfirmed() { $this->reservationConfirmed = true; }
	public function nativeCreationStarted() { $this->nativeCreationStarted = true; }
	public function bindingConfirmed() { $this->bindingConfirmed = true; }
	public function capture($exception) {
		if (!$this->enabled || $this->captured) { return; }
		$this->captured = true;
		try {
			$codes = array('invalid_integer','invalid_order_status','invalid_token','invalid_application_context','invalid_offer','stale_offer','stale_order','invalid_customer_input','consent_required','invalid_recipient_configuration','invalid_configuration','missing_financial_input','unsupported_pricing','unsupported_store','offer_secret_unavailable','invalid_snapshot','installation_unavailable','native_mail_guard_unavailable','missing_schema','incomplete_schema','incorrect_schema_revision','submission_storage_unavailable','submission_conflict','submission_unavailable','invalid_eur_currency','invalid_base_currency','invalid_currency_rate','invalid_product','invalid_quantity','invalid_options','required_options','invalid_order_context','invalid_cart','invalid_term','invalid_down_payment','invalid_money','invalid_number','invalid_percent','invalid_financial_result','invalid_product_price','out_of_stock','principal_below_minimum','monthly_below_minimum','financing_unavailable','non_eur_currency','method_not_allowed','invalid_scheme','review_required','invalid_persisted_order','order_finalization_failed','mail_preflight_failed');
			$code = $exception instanceof JetCreditFinancialException && in_array($exception->getMessage(), $codes, true) ? $exception->getMessage() : 'unexpected_exception';
			$file = str_replace('\\', '/', $exception->getFile()); $relative = 'external';
			foreach (array('DIR_SYSTEM' => 'system/', 'DIR_APPLICATION' => 'catalog/') as $constant => $prefix) {
				if (!defined($constant)) { continue; }
				$root = rtrim(str_replace('\\', '/', (realpath(constant($constant)) ?: constant($constant))), '/') . '/';
				if (strpos($file, $root) === 0) {
					$tail = substr($file, strlen($root));
					if (preg_match('~^[a-zA-Z0-9_./-]+\.php$~D', $tail) && strpos($tail, '..') === false) { $relative = $prefix . $tail; }
				}
			}
			// Bound class names to known PHP/module classes; custom names may contain data.
			$class = get_class($exception);
			if (!in_array($class, array('JetCreditFinancialException','JetCreditIntegerException','Exception','RuntimeException','LogicException','ErrorException','Error','TypeError','ValueError','ArgumentCountError','DivisionByZeroError'), true)) { $class = 'other_throwable'; }
			$record = array('correlation_id'=>$this->id, 'operation'=>$this->operation, 'stage'=>$this->stage, 'code'=>$code, 'exception_class'=>$class, 'file'=>$relative, 'line'=>(int)$exception->getLine(), 'claim_reached'=>$this->claimReached, 'claim_confirmed'=>$this->claimConfirmed, 'order_creation_began'=>$this->orderBegan);
			if ($this->operation === 'checkout_confirm') {
				$record['reservation_reached'] = $this->reservationReached; $record['reservation_confirmed'] = $this->reservationConfirmed;
				$record['native_order_creation_started'] = $this->nativeCreationStarted; $record['draft_binding_confirmed'] = $this->bindingConfirmed;
			}
			if ($exception instanceof JetCreditIntegerException) { $record['field'] = $exception->field(); $record['category'] = $exception->category(); }
			if (is_object($this->log)) { $this->log->write('JET_DIAG ' . json_encode($record, JSON_UNESCAPED_SLASHES)); }
		} catch (Throwable $ignored) { /* Logging failure never changes HTTP or business state. */ }
	}
	public function response($result) {
		if ($this->enabled && $this->captured) { $result['correlation_id'] = $this->id; }
		return $result;
	}
	public static function integer($value, $field) {
		try { return JetCreditFinancial::positiveInteger($value); }
		catch (JetCreditFinancialException $exception) { throw new JetCreditIntegerException($field, self::category($value)); }
	}
	private static function category($value) {
		if ($value === null) { return 'missing'; }
		if ($value === '') { return 'empty'; }
		if (!is_int($value) && !is_string($value)) { return 'wrong_type'; }
		if ($value === 0 || $value === '0') { return 'zero'; }
		if (!preg_match('/^[1-9][0-9]*$/D', (string)$value)) { return 'invalid_format'; }
		return 'out_of_range';
	}
}
