<?php
class ModelExtensionPaymentJetCard extends Model {

	public function getMethod($address, $total) {
		try {
			$this->load->model('extension/mt_jet_credit/financial');
			$this->model_extension_mt_jet_credit_financial->ready('jet_card');
			$total = JetCreditFinancial::money($total);
			$minimum = JetCreditFinancial::minimum($this->config->get('module_mt_jet_credit_price'));
			$payment_minimum = JetCreditFinancial::money($this->config->get('payment_jet_card_total') ?: 0);
		} catch (JetCreditFinancialException $e) { return array(); }
		catch (Throwable $e) { return array(); }
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_jet_card_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
		if ($payment_minimum > 0 && $payment_minimum > $total) {
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
		if ($jet_card_status == 1 & $jet_card_payment_status == 1) {
			$jet_card_minstojnost = JetCreditFinancial::minimum($this->config->get('module_mt_jet_credit_price'));
			
			if ($total < $jet_card_minstojnost) {
				$status = false;
			}
		}else{
			$status = false;
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