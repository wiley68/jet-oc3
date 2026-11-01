<?php
require_once __DIR__ . '/financial.php';
class JetCreditSubmissionHttp {
	public static function handle($controller, $context, $scheme = null) {
		$status = '200 OK';
		try {
			if (($controller->request->server['REQUEST_METHOD'] ?? '') !== 'POST') { throw new JetCreditFinancialException('method_not_allowed'); }
			if (empty($controller->request->server['HTTPS']) || $controller->request->server['HTTPS'] === 'off') { throw new JetCreditFinancialException('submission_unavailable'); }
			if ($scheme === null) {
				$card = $controller->request->post['jet_card'] ?? '0';
				if (!in_array($card, array(0,1,'0','1'), true)) { throw new JetCreditFinancialException('invalid_scheme'); }
				$scheme = (string)$card === '1' ? 'jet_card' : 'jet';
			}
			$controller->load->model('extension/mt_jet_credit/submission');
			$result = $controller->model_extension_mt_jet_credit_submission->submit($context, $scheme, $controller->request->post);
			if (($result['success'] ?? '') !== 'success') { $status = '409 Conflict'; $result = array('success' => 'unsuccess', 'code' => 'review_required'); }
		} catch (JetCreditFinancialException $e) {
			$code = $e->getMessage();
			$validation = array('invalid_scheme','invalid_context','invalid_token','invalid_application_context','invalid_order_context','consent_required','invalid_customer_input','invalid_recipient_configuration','invalid_configuration','invalid_offer','stale_offer','stale_order','invalid_term','invalid_down_payment','invalid_money','invalid_number','invalid_integer','invalid_options','required_options','invalid_quantity','invalid_product','invalid_cart','out_of_stock','principal_below_minimum','monthly_below_minimum','financing_unavailable','non_eur_currency');
			if ($code === 'method_not_allowed') { $status = '405 Method Not Allowed'; $controller->response->addHeader('Allow: POST'); }
			elseif ($code==='review_required') { $status='409 Conflict'; }
			elseif (in_array($code, $validation, true)) { $status = '400 Bad Request'; }
			else { $code = 'submission_unavailable'; $status = '503 Service Unavailable'; }
			$result = array('success' => 'unsuccess', 'code' => $code);
		} catch (Throwable $e) { $result = array('success' => 'unsuccess', 'code' => 'submission_unavailable'); $status = '503 Service Unavailable'; }
		$controller->response->addHeader('HTTP/1.1 ' . $status);
		$controller->response->addHeader('Content-Type: application/json');
		$controller->response->addHeader('Cache-Control: no-store');
		$controller->response->setOutput(json_encode($result));
	}
}
