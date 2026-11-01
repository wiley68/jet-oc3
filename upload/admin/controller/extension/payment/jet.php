<?php
class ControllerExtensionPaymentJet extends Controller {

	private $error = array();

	public function index() {
		$this->load->language('extension/payment/jet');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_jet', $this->request->post);
			$this->session->data['success'] = 'Успех: Успешно направихте промените!';
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}
		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}
		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => 'Начало',
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => 'Разплащане',
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/jet', 'user_token=' . $this->session->data['user_token'], true)
		);
		
		$data['action'] = $this->url->link('extension/payment/jet', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
		
		if (isset($this->request->post['payment_jet_order_status_id'])) {
			$data['payment_jet_order_status_id'] = $this->request->post['payment_jet_order_status_id'];
		} else {
			$data['payment_jet_order_status_id'] = $this->config->get('payment_jet_order_status_id');
		}
		if (isset($this->request->post['payment_jet_total'])) {
			$data['payment_jet_total'] = $this->request->post['payment_jet_total'];
		} else {
			$data['payment_jet_total'] = $this->config->get('payment_jet_total');
		}
		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		if (isset($this->request->post['payment_jet_geo_zone_id'])) {
			$data['payment_jet_geo_zone_id'] = $this->request->post['payment_jet_geo_zone_id'];
		} else {
			$data['payment_jet_geo_zone_id'] = $this->config->get('payment_jet_geo_zone_id');
		}
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
		if (isset($this->request->post['payment_jet_status'])) {
			$data['payment_jet_status'] = $this->request->post['payment_jet_status'];
		} else {
			$data['payment_jet_status'] = $this->config->get('payment_jet_status');
		}
		if (isset($this->request->post['payment_jet_sort_order'])) {
			$data['payment_jet_sort_order'] = $this->request->post['payment_jet_sort_order'];
		} else {
			$data['payment_jet_sort_order'] = $this->config->get('payment_jet_sort_order');
		}
		
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/payment/jet', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/jet')) {
			$this->error['warning'] = 'Нямате права за тази операция!';
		}
		return !$this->error;
	}
}