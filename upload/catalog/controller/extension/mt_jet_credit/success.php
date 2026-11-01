<?php
class ControllerExtensionMtJetCreditSuccess extends Controller {
	public function index() {
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->addHeader('Referrer-Policy: no-referrer');
		try {
			$this->load->model('extension/mt_jet_credit/submission');
			$result = $this->model_extension_mt_jet_credit_submission->receiptOutcome($this->request->get['receipt'] ?? null);
		} catch (Throwable $e) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('Невалидно или незавършено потвърждение.'); return;
		}
		$data = $this->load->language('extension/mt_jet_credit/success');
		$data['text_message'] = $data[$result['code'] === 'manual_registered' ? 'text_manual' : 'text_accepted'];
		$data['order_id'] = $result['order_id'];
			$data['purchase_return'] = html_entity_decode($this->url->link($result['context']==='product'?'product/product':($result['context']==='cart'?'checkout/cart':'checkout/checkout'),$result['context']==='product'?'product_id='.(int)$result['entity']:'',true),ENT_QUOTES,'UTF-8');
		$data['continue'] = html_entity_decode($this->url->link('common/home'), ENT_QUOTES, 'UTF-8');
		$this->document->setTitle($data['heading_title']);
		foreach (array('column_left','column_right','content_top','content_bottom','footer','header') as $part) { $data[$part] = $this->load->controller('common/' . $part); }
		// Render a normal success layout without calling checkout/success or mutating
		// another tab's order/session/cart. Receipt authorization is read-only.
		$this->response->setOutput($this->load->view('extension/mt_jet_credit/success', $data));
	}
}
