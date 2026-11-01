<?php
require_once __DIR__ . '/offer.php';
class JetCreditReceipt {
	private $signer; private $binding;
	public function __construct($signer, $session, $customer) { $this->signer = $signer; $this->binding = $signer->digest(array('purpose' => 'receipt-owner-v1', 'session' => $session->getId(), 'customer' => (int)$customer, 'store' => 0)); }
	public function issue($context, $entity, $intent, $result) {
		$data = array('purpose' => 'jet-receipt-v1', 'expires' => time() + 1800, 'owner' => $this->binding, 'context' => $context, 'entity' => $context === 'product' ? (int)$entity : null, 'intent' => $intent, 'order' => (int)$result['order_id'], 'result' => $result['code']);
		$encoded = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
		return $encoded . '.' . $this->signer->digest(array('receipt-v1', $encoded));
	}
	public function verify($token) {
		if (!is_string($token) || strlen($token) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $m) || !hash_equals($this->signer->digest(array('receipt-v1', $m[1])), $m[2])) { throw new JetCreditFinancialException('invalid_receipt'); }
		$data = json_decode(base64_decode(strtr($m[1], '-_', '+/'), true), true, 8);
		if (!is_array($data) || array_keys($data) !== array('purpose','expires','owner','context','entity','intent','order','result') || $data['purpose'] !== 'jet-receipt-v1' || !is_int($data['expires']) || $data['expires'] <= time() || $data['expires'] > time() + 1800 || !is_string($data['owner']) || !hash_equals($this->binding, $data['owner']) || !in_array($data['context'], array('product','cart','checkout'), true) || !in_array($data['result'], array('manual_registered','transport_accepted'), true)) { throw new JetCreditFinancialException('invalid_receipt'); }
		return $data;
	}
}
