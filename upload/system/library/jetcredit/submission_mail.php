<?php
require_once __DIR__ . '/submission_input.php';
/** Pure formatter plus isolated PHPMailer transports; never logs message/error contents. */
class JetCreditSubmissionMail {
	private $config;
	private $factory;
	public function __construct($config, $factory = null) {
		$this->config = $config;
		$this->factory = $factory ?? function () {
			$base = DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/';
			require_once $base . 'Exception.php'; require_once $base . 'SMTP.php'; require_once $base . 'PHPMailer.php';
			return new \PHPMailer\PHPMailer\PHPMailer(true);
		};
	}
	public static function bankBody($person, $products, $calculation, $scheme) {
		foreach ($person as $value) { JetCreditSubmissionInput::line($value, 254); }
		$body = "Данни за потребителя:\r\n";
		$body .= 'Собствено име: ' . $person['firstname'] . ";\r\n";
		$body .= 'Фамилия: ' . $person['lastname'] . ";\r\n";
		$body .= 'ЕГН: ' . $person['egn'] . ";\r\n";
		$body .= 'Телефон за връзка: ' . $person['telephone'] . ";\r\n";
		$body .= 'Имейл адрес: ' . $person['email'] . ";\r\n\r\n";
		$body .= "Данни за стоката:\r\n";
		foreach ($products as $product) {
			$name = JetCreditSubmissionInput::line($product['name'], 4096);
			$options = array();
			foreach ($product['options'] as $value) { $options[] = JetCreditSubmissionInput::line($value, 4096, false); }
			if ($options) { $name .= ' - ' . implode(', ', $options); }
			$category = JetCreditSubmissionInput::line($product['category'], 255, false);
			if ($category === '-') { $category = ' - '; }
			$quantity = JetCreditFinancial::positiveInteger($product['quantity']);
			$unit = JetCreditFinancial::number($product['unit']);
			$body .= 'Тип стока: ' . $category . ";\r\n";
			$body .= 'Марка: (' . JetCreditFinancial::positiveInteger($product['product_id']) . ') ' . $name . ";\r\n";
			$body .= 'Единична цена с ДДС: ' . number_format($unit, 2, '.', '') . ";\r\n";
			$body .= 'Брой стоки: ' . $quantity . ";\r\n";
			$body .= 'Обща сума с ДДС: ' . number_format($unit * $quantity, 2, '.', '') . ";\r\n\r\n";
		}
		if ($scheme === 'jet_card') {
			$body .= "Тип стока: Кредитна Карта;\r\nМарка: -;\r\nЕдинична цена с ДДС: 0.00;\r\nБрой стоки: 1;\r\nОбща сума с ДДС: 0.00;\r\n\r\n";
		} elseif ($scheme !== 'jet') { throw new JetCreditFinancialException('invalid_scheme'); }
		$body .= "Данни за кредита:\r\n";
		$body .= 'Размер на кредита: ' . number_format(JetCreditFinancial::money($calculation['principal']), 2, '.', '') . ";\r\n";
		$body .= 'Срок на изплащане в месеца: ' . JetCreditFinancial::positiveInteger($calculation['term']) . ";\r\n";
		$body .= 'Месечна вноска: ' . number_format(JetCreditFinancial::money($calculation['monthly']), 2, '.', '') . ";\r\n";
		$body .= 'Първоначална вноска: ' . number_format(JetCreditFinancial::money($calculation['down']), 2, '.', '') . ";\r\n";
		return $body;
	}
	public static function bankSubject($merchant, $reference) {
		$merchant = JetCreditSubmissionInput::line($merchant, 128);
		if (!is_int($reference) || $reference < 0) { throw new JetCreditFinancialException('invalid_reference'); }
		return $merchant . ', онлайн заявка по поръчка ' . $reference;
	}
	public static function safeBody($category, $person, $order, $result, $amount) {
		if (!in_array($category, array('admin', 'customer'), true) || !in_array($result, array('manual_registered', 'transport_accepted'), true)) { throw new LogicException('invalid_notification'); }
		$body = 'Заявката е регистрирана в магазина. Поръчка: ' . JetCreditFinancial::positiveInteger($order) . ".\r\n";
		$body .= 'Сума: ' . number_format(JetCreditFinancial::money($amount), 2, '.', '') . " EUR.\r\n";
		$body .= $result === 'manual_registered' ? "Заявката НЕ е изпратена до банката. Магазинът ще я обработи ръчно.\r\n" : "Пощенският транспорт прие заявката. Това не потвърждава банково одобрение или крайна доставка.\r\n";
		if ($category === 'admin') {
			preg_match('/^./us', $person['firstname'], $first); preg_match('/^./us', $person['lastname'], $last);
			$body .= 'Клиент: ' . ($first[0] ?? '') . '*** ' . ($last[0] ?? '') . "***.\r\n";
			$body .= 'Телефон: ***' . substr($person['telephone'], -2) . ".\r\n";
		}
		return $body;
	}
	public function prepare($addresses, $subject, $body) {
		if (!$addresses) { throw new JetCreditFinancialException('mail_preflight_failed'); }
		try {
			$mail = call_user_func($this->factory);
			$mail->SMTPDebug = 0;
			$mail->Debugoutput = function ($message, $level) {};
			$mail->CharSet = 'UTF-8'; $mail->Encoding = 'base64';
			$mail->setFrom(JetCreditSubmissionInput::email($this->config->get('config_email')), JetCreditSubmissionInput::line($this->config->get('module_mt_jet_credit_id'), 128));
			foreach ($addresses as $address) { $mail->addAddress(JetCreditSubmissionInput::email($address)); }
			// No CC/BCC/reply-to populated from customer data; a fresh instance per category.
			$engine = $this->config->get('config_mail_engine');
			if ($engine === 'smtp' || $this->config->get('config_mail_protocol') === 'smtp') {
				$mail->isSMTP(); $mail->Host = JetCreditSubmissionInput::line($this->config->get('config_mail_smtp_hostname'), 255);
				$mail->SMTPAuth = true; $mail->Username = (string)$this->config->get('config_mail_smtp_username');
				$mail->Password = html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
				$mail->SMTPSecure = 'tls'; $mail->Port = JetCreditFinancial::positiveInteger($this->config->get('config_mail_smtp_port'));
				if ($mail->Port > 65535) { throw new RuntimeException('port'); }
				$mail->Timeout = 30; $mail->SMTPKeepAlive = false;
			} elseif ($engine === 'mail') { $mail->isMail(); }
			else { throw new RuntimeException('transport'); }
			$mail->Subject = JetCreditSubmissionInput::line($subject, 255); $mail->Body = $body; $mail->isHTML(false);
			if (!$mail->preSend()) { throw new RuntimeException('preflight'); }
			return $mail;
		} catch (Throwable $e) { throw new JetCreditFinancialException('mail_preflight_failed'); }
	}
	public function transport($mail, $subject = null) {
		if ($subject !== null) {
			try {
				$mail->Subject = JetCreditSubmissionInput::line($subject, 255);
				if (!$mail->preSend()) { return 'before_transport_failed'; }
			} catch (Throwable $e) { return 'before_transport_failed'; }
		}
		try {
			// postSend can partially accept recipients or time out after DATA. Never infer
			// safe-to-retry from its false return or exception text.
			return $mail->postSend() ? 'accepted' : 'unknown';
		} catch (Throwable $e) { return 'unknown'; }
	}
}
