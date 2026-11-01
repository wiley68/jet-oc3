<?php
require_once DIR_SYSTEM . 'library/jetcredit/submission_http.php';
require_once DIR_SYSTEM . 'library/jetcredit/version.php';
class ControllerExtensionPaymentJetCard extends Controller {
	public function index() {
		$language = $this->load->language('extension/payment/jet_card');
		$this->load->model('extension/mt_jet_credit/financial');
		$data = $this->model_extension_mt_jet_credit_financial->frontendData('checkout', 'jet_card');
		$data = array_merge($language, $data);
		$data['JET_VERSION'] = JetCreditVersion::VERSION;
		return $this->load->view('extension/payment/jet_card', $data);
	}
	public function confirm() { JetCreditSubmissionHttp::handle($this, 'checkout', 'jet_card'); }
}
