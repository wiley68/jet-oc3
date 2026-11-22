<?php
class ModelExtensionPaymentJetCard extends Model {

	public function getMethod($address, $total, $snapshot_eur = null) {
		if ($snapshot_eur !== null && ((!is_int($snapshot_eur) && !is_float($snapshot_eur)) || !is_finite($snapshot_eur) || $snapshot_eur <= 0)) return [];
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_jet_card_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
		if ($this->config->get('payment_jet_card_total') > 0 && $this->config->get('payment_jet_card_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('payment_jet_card_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}
		$jet_card_status = $this->config->get('module_mt_jet_credit_status');
		$jet_card_payment_status = $this->config->get('payment_jet_card_status');
		$jet_card_currency_code = $this->session->data['currency'];
		if ($jet_card_status == 1 & $jet_card_payment_status == 1 & ($jet_card_currency_code == 'EUR')) {
			$jet_card_minstojnost = (float)$this->config->get('module_mt_jet_credit_price');
			
			if (($snapshot_eur === null ? $this->currency->convert($total, $this->config->get('config_currency'), 'EUR') : $snapshot_eur) < $jet_card_minstojnost) {
				$status = false;
			}
		}else{
			$status = false;
		}
		if ($status && $snapshot_eur === null) {
			try {
				$amount = $this->currency->convert($total, $this->config->get('config_currency'), 'EUR');
				$this->load->model('extension/mt_jet_credit/jetcredit');
				$ids = array_column($this->cart->getProducts(), 'product_id');
				$status = !$this->model_extension_mt_jet_credit_jetcredit->checkoutInitialPromotionExcluded($ids, $amount, 'jet_card');
			} catch (\Throwable $error) {
				return [];
			}
		}
		if (!$status && $snapshot_eur === null && ($this->session->data['payment_method']['code'] ?? '') === 'jet_card') {
			unset($this->session->data['payment_method'], $this->session->data['payment_methods']['jet_card']);
		}
		$method_data = array();
		if ($status) {
			$method_data = array(
				'code'	   => 'jet_card',
				'title'	  => 'ПБ Лични Финанси на вноски с кредитна карта',
				'terms'	  => '',
				'sort_order' => $this->config->get('payment_jet_card_sort_order')
			);
		}
		return $method_data;
	}
}