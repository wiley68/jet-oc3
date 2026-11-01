<?php
require_once __DIR__ . '/submission_input.php';
require_once __DIR__ . '/installation.php';
class JetCreditAdminSettings {
	public static function module($input, $config) {
		if (!is_array($input)) { throw new JetCreditFinancialException('invalid_configuration'); }
		foreach (array_keys($input) as $key) { if (preg_match('/secret|marker|count|store_id|^jetcredit_/i', $key)) { throw new JetCreditFinancialException('protected_configuration'); } }
		if (!array_key_exists('module_mt_jet_credit_mail', $input)) { throw new JetCreditFinancialException('invalid_recipient_configuration'); }
		JetCreditSubmissionInput::recipients($input['module_mt_jet_credit_mail']);
		$defaults = JetCreditInstallation::defaults()['module_mt_jet_credit']; $out = array();
		$types = array('status' => 'bool', 'status_card' => 'bool', 'cart_show' => 'bool', 'vnoska' => 'bool', 'btn_logo' => 'bool', 'id' => 'text', 'mail' => 'mail', 'price' => 'money', 'purcent' => 'percent', 'purcent_card' => 'percent', 'vnoski' => 'term', 'gap' => 'gap', 'button_type' => 'type', 'button_scheme' => 'scheme', 'btn_text' => 'text', 'btn_text_card' => 'text', 'btn_max_width' => 'width', 'btn_round' => 'radius', 'btn_font' => 'font');
		foreach ($types as $suffix => $type) {
			$key = 'module_mt_jet_credit_' . $suffix;
			$value = array_key_exists($key, $input) ? $input[$key] : ($config->get($key) ?? ($defaults[$key] ?? ''));
			if (!is_string($value) && !is_int($value) && !is_float($value)) { throw new JetCreditFinancialException('invalid_configuration'); }
			if ($type === 'bool') { if (!in_array($value, array(0,1,'0','1'), true)) { throw new JetCreditFinancialException('invalid_configuration'); } $value = (string)$value; }
			elseif ($type === 'text') { $value = JetCreditSubmissionInput::line((string)$value, $suffix === 'id' ? 128 : 255, false); }
			elseif ($type === 'money') { $value = number_format(JetCreditFinancial::minimum($value), 2, '.', ''); }
			elseif ($type === 'percent') { $value = number_format(JetCreditFinancial::percent($value), 2, '.', ''); }
			elseif ($type === 'term') { $value = JetCreditFinancial::positiveInteger($value); if (!in_array($value, JetCreditFinancial::TERMS, true)) { throw new JetCreditFinancialException('invalid_configuration'); } }
			elseif ($type === 'type') { if (!in_array($value, array('standard','wide'), true)) { throw new JetCreditFinancialException('invalid_configuration'); } }
			elseif ($type !== 'mail') {
				$ranges = array('gap' => array(0,2147483647), 'scheme' => array(0,6), 'width' => array(30,1200), 'radius' => array(0,25), 'font' => array(6,36));
				if ($value === '' && in_array($type, array('width','radius','font'), true)) { $value = array('width'=>570,'radius'=>16,'font'=>14)[$type]; }
				if (!preg_match('/^[0-9]+$/D', (string)$value) || (float)$value < $ranges[$type][0] || (float)$value > $ranges[$type][1]) { throw new JetCreditFinancialException('invalid_configuration'); } $value = (int)$value;
			}
			$out[$key] = $value;
		}
		if ($out['module_mt_jet_credit_status'] === '1' && $out['module_mt_jet_credit_id'] === '') { throw new JetCreditFinancialException('invalid_configuration'); }
		return $out;
	}
	public static function payment($input, $config, $db, $scheme) {
		$out = array();
		foreach (array('status','total','geo_zone_id','sort_order','order_status_id') as $field) {
			$key = 'payment_' . $scheme . '_' . $field; $value = $input[$key] ?? $config->get($key) ?? 0;
			if ($field === 'total') { $value = JetCreditFinancial::money($value); if ($value < 0) { throw new JetCreditFinancialException('invalid_configuration'); } }
			elseif ($field === 'status') { if (!in_array($value, array(0,1,'0','1'), true)) { throw new JetCreditFinancialException('invalid_configuration'); } }
			elseif ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value) || (float)$value > 2147483647) { throw new JetCreditFinancialException('invalid_configuration'); }
			$out[$key] = $value;
		}
		$status = (int)$out['payment_' . $scheme . '_order_status_id'];
		if (!$db->query('SELECT order_status_id FROM `' . DB_PREFIX . "order_status` WHERE order_status_id=" . $status . ' AND language_id=' . (int)$config->get('config_language_id'))->num_rows) { throw new JetCreditFinancialException('invalid_order_status'); }
		return $out;
	}
}
