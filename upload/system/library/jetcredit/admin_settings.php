<?php
require_once __DIR__ . '/submission_input.php';
require_once __DIR__ . '/installation.php';
class JetCreditAdminSettingException extends JetCreditFinancialException {
	public $field;
	public function __construct($code, $field) { parent::__construct($code); $this->field = $field; }
}
class JetCreditAdminSettings {
	public static function module($input, $config) {
		if (!is_array($input)) { throw new JetCreditFinancialException('invalid_configuration'); }
		foreach (array_keys($input) as $key) { if (preg_match('/secret|marker|count|store_id|^jetcredit_/i', $key)) { throw new JetCreditFinancialException('protected_configuration'); } }
		if (!array_key_exists('module_mt_jet_credit_mail', $input)) { throw new JetCreditAdminSettingException('invalid_recipient_configuration', 'missing_mail'); }
		try { JetCreditSubmissionInput::recipients($input['module_mt_jet_credit_mail']); } catch (JetCreditFinancialException $e) { throw new JetCreditAdminSettingException('invalid_recipient_configuration', 'mail'); }
		$defaults = JetCreditInstallation::defaults()['module_mt_jet_credit']; $out = array();
		$types = array('status' => 'bool', 'status_card' => 'bool', 'cart_show' => 'bool', 'vnoska' => 'bool', 'btn_logo' => 'bool', 'id' => 'text', 'mail' => 'mail', 'price' => 'money', 'purcent' => 'percent', 'purcent_card' => 'percent', 'vnoski' => 'term', 'gap' => 'gap', 'button_type' => 'type', 'button_scheme' => 'scheme', 'btn_text' => 'text', 'btn_text_card' => 'text', 'btn_max_width' => 'width', 'btn_round' => 'radius', 'btn_font' => 'font');
		foreach ($types as $suffix => $type) {
			$key = 'module_mt_jet_credit_' . $suffix;
			$value = array_key_exists($key, $input) ? $input[$key] : ($config->get($key) ?? ($defaults[$key] ?? ''));
			try {
				// Empty optional controls select explicit safe defaults; recipient stays exact.
				if ($value === '' && $suffix !== 'mail' && $suffix !== 'id') { $value = $defaults[$key] ?? ''; }
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
				if ($suffix === 'id' && $value === '') { throw new JetCreditFinancialException('invalid_shop_identifier'); }
				$out[$key] = $value;
			} catch (JetCreditFinancialException $e) { throw new JetCreditAdminSettingException($e->getMessage(), $suffix); }
		}
		return $out;
	}
	public static function diagnostic($error) {
		if ($error instanceof JetCreditFinancialException && $error->getMessage() === 'protected_configuration') { return 'Формулярът съдържа защитени настройки и не може да бъде записан.'; }
		if (!$error instanceof JetCreditAdminSettingException) {
			$message = JetCreditInstallation::diagnostic($error);
			// Installation diagnostics retain their codes; the settings form shows prose.
			$separator = strpos($message, '] ');
			return $separator === false ? $message : substr($message, $separator + 2);
		}
		$labels = array('status'=>'Включи модула','status_card'=>'Метод с кредитна карта','cart_show'=>'Бутони в количката','vnoska'=>'Покажи вноска','btn_logo'=>'Лого','id'=>'Идентификатор на магазина','price'=>'Минимална сума','purcent'=>'Процент лихва','purcent_card'=>'Процент за карта','vnoski'=>'Брой вноски','gap'=>'Разстояние над бутона','button_type'=>'Вид на бутона','button_scheme'=>'Цветова схема','btn_text'=>'Текст на бутона','btn_text_card'=>'Текст за карта','btn_max_width'=>'Ширина на бутона','btn_round'=>'Радиус','btn_font'=>'Размер на шрифта');
		if ($error->field === 'missing_mail') { return 'Полето за банков email липсва в изпратената форма. Отворете отново настройките; празен email е валиден за ръчна регистрация.'; }
		if ($error->field === 'mail') { return 'Въведете валиден банков email/списък или оставете полето празно за ръчна регистрация.'; }
		if ($error->field === 'id') { return 'Попълнете валиден идентификатор на магазина.'; }
		return 'Проверете поле: ' . ($labels[$error->field] ?? 'настройка') . '. Празните незадължителни полета използват стойностите по подразбиране.';
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
