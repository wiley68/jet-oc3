<?php
require_once DIR_SYSTEM . 'library/jetcredit/admin_settings.php';
class ControllerExtensionPaymentJetCard extends Controller {

	private $error = array();

	public function index() {
		$this->load->language('extension/payment/jet_card');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_jet_card', JetCreditAdminSettings::payment($this->request->post, $this->config, $this->db, 'jet_card'), 0);
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
			'href' => $this->url->link('extension/payment/jet_card', 'user_token=' . $this->session->data['user_token'], true)
		);
		
		$data['action'] = $this->url->link('extension/payment/jet_card', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
		
		if (isset($this->request->post['payment_jet_card_order_status_id'])) {
			$data['payment_jet_card_order_status_id'] = $this->request->post['payment_jet_card_order_status_id'];
		} else {
			$data['payment_jet_card_order_status_id'] = $this->config->get('payment_jet_card_order_status_id');
		}
		if (isset($this->request->post['payment_jet_card_total'])) {
			$data['payment_jet_card_total'] = $this->request->post['payment_jet_card_total'];
		} else {
			$data['payment_jet_card_total'] = $this->config->get('payment_jet_card_total');
		}
		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		if (isset($this->request->post['payment_jet_card_geo_zone_id'])) {
			$data['payment_jet_card_geo_zone_id'] = $this->request->post['payment_jet_card_geo_zone_id'];
		} else {
			$data['payment_jet_card_geo_zone_id'] = $this->config->get('payment_jet_card_geo_zone_id');
		}
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
		if (isset($this->request->post['payment_jet_card_status'])) {
			$data['payment_jet_card_status'] = $this->request->post['payment_jet_card_status'];
		} else {
			$data['payment_jet_card_status'] = $this->config->get('payment_jet_card_status');
		}
		if (isset($this->request->post['payment_jet_card_sort_order'])) {
			$data['payment_jet_card_sort_order'] = $this->request->post['payment_jet_card_sort_order'];
		} else {
			$data['payment_jet_card_sort_order'] = $this->config->get('payment_jet_card_sort_order');
		}
		
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/payment/jet_card', $data));
	}

	protected function validate() {
		try {
			$token = $this->request->get['user_token'] ?? null; $expected = $this->session->data['user_token'] ?? null;
			if (!$this->user->isLogged() || !$this->user->hasPermission('modify', 'extension/payment/jet_card') || !is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token) || isset($this->request->post['store_id']) || isset($this->request->get['store_id']) || !in_array($this->config->get('config_store_id'), array(null,0,'0'), true)) { throw new RuntimeException('authorization'); }
			(new JetCreditInstallation($this->db, DB_PREFIX))->load($this->config);
			JetCreditAdminSettings::payment($this->request->post, $this->config, $this->db, 'jet_card');
			return true;
		} catch (Throwable $e) { $this->error['warning'] = 'Проверете правата, clean инсталацията и статуса на поръчката.'; return false; }
	}
}
