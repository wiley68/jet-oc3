<?php
require_once __DIR__ . '/financial.php';

/** Signed financial data, not a delivery lock. No persistence or mail. */
class JetCreditOffer {
	private $key;
	private $clock;
	public function __construct($secret, $clock = null) {
		if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/Di', $secret) || count(array_unique(str_split(strtolower($secret)))) < 4) { throw new JetCreditFinancialException('offer_secret_unavailable'); }
		$this->key = hex2bin($secret);
		$this->clock = $clock ?? function () { return time(); };
	}
	public static function configuredSecret($config) {
		return defined('JETCREDIT_OFFER_SECRET') ? JETCREDIT_OFFER_SECRET : $config->get('jetcredit_offer_secret');
	}
	public function digest($value) {
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		if ($json === false) { throw new JetCreditFinancialException('invalid_snapshot'); }
		return hash_hmac('sha256', $json, $this->key);
	}
	public function issue($binding, $source, $policy, $recipient, $calculation) {
		$now = call_user_func($this->clock);
		$payload = array('version' => 1, 'currency' => 'EUR', 'issued' => $now, 'expires' => $now + 1800, 'application_intent'=>$binding['application_intent'], 'generation'=>$binding['generation'], 'binding' => $this->digest($binding), 'source' => $this->digest($source), 'policy' => $this->digest($policy), 'recipient' => $this->digest($recipient), 'calculation' => $calculation);
		$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
		if ($json === false) { throw new JetCreditFinancialException('invalid_offer'); }
		$encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
		return $encoded . '.' . hash_hmac('sha256', $encoded, $this->key);
	}
	public function verify($token, $binding, $source, $policy, $recipient, $calculation) {
		$data = $this->payload($token);
		if ($data['application_intent']!==$binding['application_intent'] || $data['generation']!==$binding['generation']) { throw new JetCreditFinancialException('stale_offer'); }
		foreach (array('binding' => $binding, 'source' => $source, 'policy' => $policy, 'recipient' => $recipient, 'calculation' => $calculation) as $key => $current) {
			if ($key === 'calculation') {
				if ($data[$key] !== $current) { throw new JetCreditFinancialException('stale_offer'); }
			} elseif (!is_string($data[$key]) || !hash_equals($data[$key], $this->digest($current))) { throw new JetCreditFinancialException('stale_offer'); }
		}
		return $calculation;
	}
	public function identity($token) { $data=$this->payload($token); return array('application_intent'=>$data['application_intent'],'generation'=>$data['generation']); }
	private function payload($token) {
		if (!is_string($token) || strlen($token) > 8192 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts) || !hash_equals(hash_hmac('sha256', $parts[1], $this->key), $parts[2])) { throw new JetCreditFinancialException('invalid_offer'); }
		$decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$data = $decoded === false ? null : json_decode($decoded, true, 16);
		$now = call_user_func($this->clock);
		if (!is_array($data) || array_keys($data) !== array('version', 'currency', 'issued', 'expires', 'application_intent', 'generation', 'binding', 'source', 'policy', 'recipient', 'calculation') || $data['version'] !== 1 || $data['currency'] !== 'EUR' || !is_string($data['application_intent']) || !preg_match('/^[a-f0-9]{64}$/D',$data['application_intent']) || !is_int($data['generation']) || $data['generation']<1 || !is_int($data['issued']) || !is_int($data['expires']) || $data['issued'] > $now || $data['expires'] <= $now || $data['expires'] - $data['issued'] !== 1800) { throw new JetCreditFinancialException('stale_offer'); }
		return $data;
	}
}
