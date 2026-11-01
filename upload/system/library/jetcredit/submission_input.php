<?php
require_once __DIR__ . '/financial.php';
class JetCreditSubmissionInput {
	public static function line($value, $max = 255, $required = true) {
		if (!is_string($value) || !preg_match('//u', $value)) { throw new JetCreditFinancialException('invalid_customer_input'); }
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (strlen($value) > $max || preg_match('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', $value) || preg_match('/&#(?:0*(?:[0-9]|[12][0-9]|3[01]|127|133|8232|8233)|x0*(?:[0-9a-f]|1[0-9a-f]|7f|85|2028|2029));/i', $value)) { throw new JetCreditFinancialException('invalid_customer_input'); }
		$value = trim($value);
		if ($required && $value === '') { throw new JetCreditFinancialException('invalid_customer_input'); }
		return $value;
	}
	public static function native($text) { return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'); }
	public static function nativeText($value, $characters, $required = false) {
		$encoded = self::native(self::line($value, $characters * 4, $required));
		if (preg_match_all('/./us', $encoded) > $characters) { throw new JetCreditFinancialException('invalid_customer_input'); }
		return $encoded;
	}
	public static function email($value) {
		$value = self::line($value, 254);
		if (!filter_var($value, FILTER_VALIDATE_EMAIL)) { throw new JetCreditFinancialException('invalid_customer_input'); }
		return $value;
	}
	public static function recipients($value) {
		// Only an explicitly empty string is manual; NULL and whitespace are invalid.
		if (!is_string($value) || strlen($value) > 4096) { throw new JetCreditFinancialException('invalid_recipient_configuration'); }
		if ($value === '') { return array('mode' => 'manual', 'addresses' => array()); }
		if (preg_match('/[\x00-\x1F\x7F]/', $value)) { throw new JetCreditFinancialException('invalid_recipient_configuration'); }
		$addresses = array();
		foreach (explode(',', $value) as $item) {
			$item = trim($item);
			if ($item === '' || strlen($item) > 254 || !filter_var($item, FILTER_VALIDATE_EMAIL)) { throw new JetCreditFinancialException('invalid_recipient_configuration'); }
			if (!in_array(strtolower($item), array_map('strtolower', $addresses), true)) { $addresses[] = $item; }
		}
		return array('mode' => 'automatic', 'addresses' => $addresses);
	}
	public static function validate($context, $scheme, $input, $order = null) {
		if (!is_array($input) || !in_array($context, array('product', 'cart', 'checkout'), true) || !in_array($scheme, array('jet', 'jet_card'), true)) { throw new JetCreditFinancialException('invalid_input'); }
		if (isset($input['jet_lname']) && (!is_string($input['jet_lname']) || $input['jet_lname'] !== '')) { throw new JetCreditFinancialException('invalid_customer_input'); }
		$checkout = $context === 'checkout';
		$prefix = $checkout && $scheme === 'jet_card' ? 'jet_card_' : 'jet_';
		$controls = array($prefix . 'uslovia', $prefix . 'uslovia1');
		if (!$checkout) { $controls[] = 'jet_uslovia2'; }
		foreach ($controls as $key) {
			if (!isset($input[$key]) || !in_array($input[$key], array(1, '1'), true)) { throw new JetCreditFinancialException('consent_required'); }
		}
		$egn = self::line($input[$prefix . 'egn'] ?? null, 10);
		// Match the existing UI policies: no new checksum/century/national-ID policy.
		$pattern = $checkout ? '/^[0-9]{1,10}$/D' : '/^[0-9]{2}((0[1-9]|1[0-2])|(4[1-9]|5[0-2]))(0[0-9]|1[0-9]|2[0-9]|3[0-1])[0-9]{4}$/D';
		if (!preg_match($pattern, $egn)) { throw new JetCreditFinancialException('invalid_customer_input'); }
		$person = $checkout ? $order : array('firstname' => $input['jet_name'] ?? null, 'lastname' => $input['jet_lastname'] ?? null, 'telephone' => $input['jet_phone'] ?? null, 'email' => $input['jet_email'] ?? null);
		if (!is_array($person)) { throw new JetCreditFinancialException('invalid_customer_input'); }
		$person = array('firstname' => self::line($person['firstname'] ?? null, 128), 'lastname' => self::line($person['lastname'] ?? null, 128), 'telephone' => self::line($person['telephone'] ?? null, 32), 'email' => self::email($person['email'] ?? null), 'egn' => $egn);
		if (preg_match_all('/./us', self::native($person['firstname'])) > 32 || preg_match_all('/./us', self::native($person['lastname'])) > 32 || strlen(self::native($person['telephone'])) > 32 || strlen(self::native($person['email'])) > 96) { throw new JetCreditFinancialException('invalid_customer_input'); }
		return array('person' => $person, 'consent' => array('flags' => $checkout ? 3 : 7, 'version' => self::consentVersion($context)));
	}
	public static function notices($context) {
		$terms = array('text' => 'Запознах се с условията за кандидатстване на ПБ Лични финанси', 'url' => 'https://www.postbank.bg/common-conditions-PFBG');
		$privacy = array('text' => '"GDPR" означава Регламент (ЕС) 2016/679 от 27 април 2016 г. за защита на физическите лица по отношение на обработката на лични данни и за свободното движение на такива данни и за отмяна на Директива 95/46 / ЕО', 'url' => 'https://www.postbank.bg/Personal-Data-PFBG-retailers');
		$result = array($terms, $privacy);
		if ($context !== 'checkout') { $result[] = $terms + array('information_text' => 'Продуктова Информация на ПБ Лични финанси', 'information_url' => 'https://www.postbank.bg/product-information-PBPG-retailers'); }
		return $result;
	}
	public static function consentVersion($context) {
		// Versions of the acknowledgements shown by this module, not remote legal documents.
		return hash('sha256', json_encode(self::notices($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
