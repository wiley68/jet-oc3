<?php
require_once __DIR__ . '/financial.php';
/** Exact-order request-local guard; readiness requires the applied native OCMOD. */
class JetCreditNativeMailScope {
	private $order;
	private $scheme;
	public function available() {
		try {
			if (!defined('VERSION') || !in_array(VERSION, array('3.0.3.8', '3.0.5.1'), true) || !function_exists('modification')) { return false; }
			if (!class_exists('ControllerMailOrder', false)) { require_once modification(DIR_APPLICATION . 'controller/mail/order.php'); }
			if (!class_exists('ControllerCheckoutConfirm', false)) { require_once modification(DIR_APPLICATION . 'controller/checkout/confirm.php'); }
			$checkout = new ReflectionMethod('ControllerCheckoutConfirm', 'index');
			if (!defined('ControllerCheckoutConfirm::JETCREDIT_CHECKOUT_REVISION') || ControllerCheckoutConfirm::JETCREDIT_CHECKOUT_REVISION !== 1 || substr_count(file_get_contents($checkout->getFileName()), 'JETCREDIT:CHECKOUT-ORDER:v1') !== 1) { return false; }
			$method = new ReflectionMethod('ControllerMailOrder', 'index');
			$source = file_get_contents($method->getFileName());
			return defined('ControllerMailOrder::JETCREDIT_GUARD_REVISION') && ControllerMailOrder::JETCREDIT_GUARD_REVISION === 1
				&& substr_count($source, 'JETCREDIT:NATIVE-INDEX:v1') === 1 && substr_count($source, 'JETCREDIT:NATIVE-ALERT:v1') === 1;
		} catch (Throwable $e) { return false; }
	}
	public function run($order, $scheme, $callback) {
		if (!$this->available() || $this->order !== null || !in_array($scheme, array('jet', 'jet_card'), true)) { throw new JetCreditFinancialException('native_mail_guard_unavailable'); }
		$this->order = JetCreditFinancial::positiveInteger($order); $this->scheme = $scheme;
		try { return call_user_func($callback); }
		finally { $this->order = null; $this->scheme = null; }
	}
	public function owns($order) { return $this->order !== null && (string)$this->order === (string)$order; }
	public function suppresses($order, $paymentCode) {
		return $this->order !== null && (string)$this->order === (string)$order && $this->scheme === $paymentCode;
	}
}
