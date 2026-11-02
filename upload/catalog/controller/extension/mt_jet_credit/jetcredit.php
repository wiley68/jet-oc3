<?php
require_once DIR_SYSTEM . 'library/jetcredit/financial.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_http.php';

class ControllerExtensionMtJetCreditJetCredit extends Controller {
	public function jet_check() { $this->calculate('product'); }
	public function jet_calculate() { $this->calculate('product'); }
	public function jet_calculate_cart() {
		$context = $this->request->post['jet_context'] ?? 'cart';
		if (!in_array($context, array('cart', 'checkout'), true)) { $this->output(array('success' => 'unsuccess', 'code' => 'invalid_context'), '400 Bad Request'); return; }
		$this->calculate($context);
	}
	public function jet_send() { JetCreditSubmissionHttp::handle($this, 'product'); }
	public function jet_send_cart() { JetCreditSubmissionHttp::handle($this, 'cart'); }

	public function new_purchase() {
		try {
			if (($this->request->server['REQUEST_METHOD']??'')!=='POST') { throw new JetCreditFinancialException('method_not_allowed'); }
			if (empty($this->request->server['HTTPS']) || $this->request->server['HTTPS']==='off') { throw new JetCreditFinancialException('submission_unavailable'); }
			$this->load->model('extension/mt_jet_credit/submission');
			$this->output($this->model_extension_mt_jet_credit_submission->newPurchase($this->request->post));
		} catch (JetCreditFinancialException $e) {
			$code=$e->getMessage();
			$status=$code==='method_not_allowed'?'405 Method Not Allowed':($code==='review_required'?'409 Conflict':(in_array($code,array('invalid_token','invalid_application_context','invalid_integer'),true)?'400 Bad Request':'503 Service Unavailable'));
			$this->output(array('success'=>'unsuccess','code'=>in_array($status,array('400 Bad Request','409 Conflict','405 Method Not Allowed'),true)?$code:'submission_unavailable'),$status);
		} catch (Throwable $e) { $this->output(array('success'=>'unsuccess','code'=>'submission_unavailable'),'503 Service Unavailable'); }
	}

	private function calculate($context) {
		$diagnostics = new JetCreditDiagnostics($this->config, $this->log, 'quote_' . $context);
		$this->registry->set('jetcredit_diagnostics', $diagnostics);
		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') { $this->output(array('success' => 'unsuccess', 'code' => 'method_not_allowed'), '405 Method Not Allowed'); return; }
		try {
			$card = $this->request->post['jet_card'] ?? '0';
			if (!in_array($card, array('0', '1', 0, 1), true)) { throw new JetCreditFinancialException('invalid_scheme'); }
			$this->load->model('extension/mt_jet_credit/financial');
			$result = $this->model_extension_mt_jet_credit_financial->quote($context, (string)$card === '1' ? 'jet_card' : 'jet', $this->request->post);
			$this->output($result);
		} catch (JetCreditFinancialException $e) {
			$diagnostics->capture($e);
			$this->output($diagnostics->response(array('success' => 'unsuccess', 'code' => $e->getMessage())), '400 Bad Request');
		} catch (Throwable $e) {
			$diagnostics->capture($e);
			$this->output($diagnostics->response(array('success' => 'unsuccess', 'code' => 'calculation_unavailable')), '500 Internal Server Error');
		}
	}
	private function output($json, $status = '200 OK') {
		$this->response->addHeader('HTTP/1.1 ' . $status);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($json));
	}
}
