<?php

/** Independent messages for an already persisted JetCredit application. */
class JetSubmissionMail {
	public static function deliver($config, $bank, $admin, $customer, $sender_name, $subject, $body, $egn, $lifecycle = null) {
		$result = ['code' => $bank ? 'bank_failed' : 'manual_registered', 'notifications' => ['admin' => false, 'customer' => false]];
		if ($bank) {
			if ($lifecycle) $lifecycle('startAction', 'bank');
			$attempted = false;
			try {
				if (self::send($config, $bank, $admin, $sender_name, $subject, $body, $attempted)) $result['code'] = 'bank_sent';
			} catch (\Throwable $error) {
				$result['code'] = $attempted ? 'bank_uncertain' : 'bank_failed';
			}
			if ($lifecycle) $lifecycle('finishAction', 'bank', $result['code'] === 'bank_sent' ? 'done' : ($result['code'] === 'bank_uncertain' ? 'uncertain' : 'failed'));
		} elseif ($lifecycle) {
			$lifecycle('skipAction', 'bank');
		}

		$admin_body = str_replace('ЕГН: ' . $egn . ";\r\n", "ЕГН: **********;\r\n", $body);
		$customer_body = 'Вашата заявка за кредитно финансиране чрез ПБ Лични Финанси беше подадена успешно за обработка.' . "\r\n\r\n" . $admin_body;
		foreach (['admin' => [[$admin], $admin_body], 'customer' => [[$customer], $customer_body]] as $kind => $message) {
			if ($lifecycle) $lifecycle('startAction', $kind);
			$attempted = false;
			$outcome = 'failed';
			try {
				$result['notifications'][$kind] = self::send($config, $message[0], $admin, $sender_name, $subject, $message[1], $attempted);
				$outcome = $result['notifications'][$kind] ? 'done' : 'failed';
			} catch (\Throwable $error) {
				// Secondary notifications never retry or change the completed primary result.
				$outcome = $attempted ? 'uncertain' : 'failed';
			}
			if ($lifecycle) $lifecycle('finishAction', $kind, $outcome);
		}
		return $result;
	}

	private static function send($config, $recipients, $sender, $sender_name, $subject, $body, &$attempted) {
		$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
		if ($config->get('config_mail_engine') === 'smtp' || $config->get('config_mail_protocol') === 'smtp') {
			$mail->isSMTP();
			$mail->Host = $config->get('config_mail_smtp_hostname');
			$mail->SMTPAuth = true;
			$mail->Username = $config->get('config_mail_smtp_username');
			$mail->Password = html_entity_decode($config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
			$mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
			$mail->Port = $config->get('config_mail_smtp_port');
			$mail->Subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
		} else {
			$mail->isMail();
			$mail->Subject = $subject;
		}
		$mail->CharSet = 'UTF-8';
		$mail->Encoding = 'base64';
		$mail->setFrom($sender, $sender_name);
		foreach ($recipients as $recipient) $mail->addAddress($recipient);
		$mail->Body = $body;
		$mail->isHTML(false);
		$attempted = true;
		return $mail->send();
	}
}
