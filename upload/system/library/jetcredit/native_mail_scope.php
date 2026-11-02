<?php
require_once __DIR__ . '/financial.php';
/** Exact-order request-local guard; readiness requires the applied native OCMOD. */
class JetCreditNativeMailScope {
	private $order;
	private $scheme;
	public function available() {
		try {
			if (!defined('VERSION') || !preg_match('/^3\./D', VERSION) || !function_exists('modification')) { return false; }
			if (!class_exists('ControllerMailOrder', false)) { $path = modification(DIR_APPLICATION . 'controller/mail/order.php'); if (!is_file($path) || !is_readable($path)) { return false; } require_once $path; }
			if (!class_exists('ControllerCheckoutConfirm', false)) { $path = modification(DIR_APPLICATION . 'controller/checkout/confirm.php'); if (!is_file($path) || !is_readable($path)) { return false; } require_once $path; }
			$checkout = new ReflectionMethod('ControllerCheckoutConfirm', 'index');
			if (!defined('ControllerCheckoutConfirm::JETCREDIT_CHECKOUT_REVISION') || ControllerCheckoutConfirm::JETCREDIT_CHECKOUT_REVISION !== 1 || substr_count(file_get_contents($checkout->getFileName()), 'JETCREDIT:CHECKOUT-ORDER:v1') !== 1) { return false; }
			// Verify executable guard bodies in their actual methods, not marker constants alone.
			foreach (array('index', 'alert') as $name) {
				$body = $this->methodBody('ControllerMailOrder', $name);
				$expected = <<<'GUARD'
$jet_scope = $this->registry->get('jetcredit_native_mail_scope');
if ($jet_scope instanceof JetCreditNativeMailScope && $jet_scope->owns($args[0] ?? 0)) {
$jet_order = $this->model_checkout_order->getOrder($args[0]);
if ($jet_order && $jet_scope->suppresses($args[0], $jet_order['payment_code'])) { return; }
}
GUARD;
				if (strpos($this->compact(substr($body, strpos($body, '{') + 1)), $this->compact($expected)) !== 0) { return false; }
			}
			$checkoutBody = $this->methodBody('ControllerCheckoutConfirm', 'index');
			$checkoutGuard = <<<'CHECKOUT'
            if (in_array($order_data['payment_code'], array('jet','jet_card'), true)) {
                $jet_diagnostics = null;
                try {
                    require_once DIR_SYSTEM . 'library/jetcredit/diagnostics.php';
                    $jet_diagnostics = new JetCreditDiagnostics($this->config, $this->log, 'checkout_confirm');
                    $this->registry->set('jetcredit_diagnostics', $jet_diagnostics);
                    $jet_diagnostics->stage('model_loading');
                    $this->load->model('extension/mt_jet_credit/checkout');
                    $this->session->data['order_id'] = $this->model_extension_mt_jet_credit_checkout->orderForIntent($order_data);
                } catch (Throwable $e) {
                    if ($jet_diagnostics instanceof JetCreditDiagnostics) {
                        $jet_diagnostics->capture($e);
                        $jet_diagnostic_result = $jet_diagnostics->response(array());
                        if (isset($jet_diagnostic_result['correlation_id'])) {
                            $this->response->addHeader('X-Jet-Correlation-ID: ' . $jet_diagnostic_result['correlation_id']);
                        }
                    }
                    $this->response->addHeader('HTTP/1.1 503 Service Unavailable');
                    $this->response->setOutput('Финансирането временно не е достъпно.');
                    return;
                }
            } else {
                $this->session->data['order_id'] = $this->model_checkout_order->addOrder($order_data);
            }
CHECKOUT;
			if (strpos($this->compact($checkoutBody), $this->compact($checkoutGuard)) === false) { return false; }
			$method = new ReflectionMethod('ControllerMailOrder', 'index');
			$source = file_get_contents($method->getFileName());
			return defined('ControllerMailOrder::JETCREDIT_GUARD_REVISION') && ControllerMailOrder::JETCREDIT_GUARD_REVISION === 1
				&& substr_count($source, 'JETCREDIT:NATIVE-INDEX:v1') === 1 && substr_count($source, 'JETCREDIT:NATIVE-ALERT:v1') === 1;
		} catch (Throwable $e) { return false; }
	}
	private function compact($source) { return preg_replace('/\s+/', '', $source); }
	private function methodBody($class, $name) {
		$method = new ReflectionMethod($class, $name);
		$source = implode('', array_slice(file($method->getFileName()), $method->getStartLine()-1, $method->getEndLine()-$method->getStartLine()+1));
		$result = '';
		foreach (token_get_all('<?php ' . $source) as $token) {
			if (is_array($token)) { if (!in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG), true)) { $result .= $token[1]; } }
			else { $result .= $token; }
		}
		return $result;
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
