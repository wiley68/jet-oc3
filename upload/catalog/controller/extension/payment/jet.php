<?php
require_once DIR_SYSTEM . 'library/jetcredit/submission_http.php';
require_once DIR_SYSTEM . 'library/jetcredit/version.php';
class ControllerExtensionPaymentJet extends Controller {
	public function index() {
		$language = $this->load->language('extension/payment/jet');
		$this->load->model('extension/mt_jet_credit/financial');
		$data = $this->model_extension_mt_jet_credit_financial->frontendData('checkout', 'jet');
		$data = array_merge($language, $data);
		$data['JET_VERSION'] = JetCreditVersion::VERSION;
		return $this->load->view('extension/payment/jet', $data);
	}
	public function confirm() { JetCreditSubmissionHttp::handle($this, 'checkout', 'jet'); }
}
