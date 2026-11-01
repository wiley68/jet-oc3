<?php
class ModelExtensionModuleJetcredit extends Model {
	private const TERMS = array('3', '6', '9', '10', '12', '15', '18', '24', '30', '36');

	public function addKop($data) {
		$data = $this->validateKop($data);
		// The legacy table is global. Resolve product selectors only in the default store.
		if ($data['mt_jet_credit_product_id'] !== '*') {
			foreach (explode('_', $data['mt_jet_credit_product_id']) as $product_id) {
				$product = $this->db->query("SELECT p.product_id FROM " . DB_PREFIX . "product p INNER JOIN " . DB_PREFIX . "product_to_store p2s ON p2s.product_id = p.product_id WHERE p.product_id = '" . (int)$product_id . "' AND p2s.store_id = '0' AND p.status = '1' AND p.date_available <= NOW()");
				if (!$product->num_rows) {
					throw new InvalidArgumentException('invalid_product');
				}
			}
		}
		$selector = $this->db->escape($data['mt_jet_credit_product_id']);
		$existing = $this->db->query("SELECT id FROM " . DB_PREFIX . "jet_kop WHERE jet_product_id = '" . $selector . "'");
		if ($existing->num_rows) {
			return -1;
		}
		// Application-level duplicate check only; no schema/concurrency change in IMP-01.
		$assignments = array();
		foreach (array('id', 'percent', 'meseci', 'price', 'start', 'end') as $field) {
			$assignments[] = 'jet_product_' . $field . " = '" . $this->db->escape($data['mt_jet_credit_product_' . $field]) . "'";
		}
		$this->db->query("INSERT INTO " . DB_PREFIX . "jet_kop SET " . implode(', ', $assignments));
		return (int)$this->db->countAffected();
	}

	public function deleteKop($id) {
		$id = $this->positiveId($id);
		$this->db->query("DELETE FROM " . DB_PREFIX . "jet_kop WHERE id = '" . $id . "'");
		return (int)$this->db->countAffected();
	}

	private function positiveId($value) {
		if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) {
			throw new InvalidArgumentException('invalid_id');
		}
		return (int)$value;
	}

	private function validateKop($data) {
		if (!is_array($data)) {
			throw new InvalidArgumentException('invalid_promotion');
		}
		$validated = array();
		foreach (array('id', 'percent', 'meseci', 'price', 'start', 'end') as $field) {
			$key = 'mt_jet_credit_product_' . $field;
			if (!isset($data[$key]) || !is_string($data[$key])) {
				throw new InvalidArgumentException('invalid_promotion');
			}
			$validated[$key] = trim($data[$key]);
		}
		$selector = $validated['mt_jet_credit_product_id'];
		if (strlen($selector) > 20 || ($selector !== '*' && !preg_match('/^[1-9][0-9]*(?:_[1-9][0-9]*)*$/D', $selector))) {
			throw new InvalidArgumentException('invalid_selector');
		}
		if ($selector !== '*') {
			foreach (explode('_', $selector) as $id) {
				$this->positiveId($id);
			}
		}
		$percent = $validated['mt_jet_credit_product_percent'];
		if (!preg_match('/^(?:-1(?:\.0{1,2})?|[0-9]{1,3}(?:\.[0-9]{1,2})?)$/D', $percent)) {
			throw new InvalidArgumentException('invalid_percent');
		}
		$terms = $validated['mt_jet_credit_product_meseci'];
		if (strlen($terms) > 50 || !preg_match('/^[0-9]+(?:_[0-9]+)*$/D', $terms)) {
			throw new InvalidArgumentException('invalid_terms');
		}
		foreach (explode('_', $terms) as $term) {
			if (!in_array($term, self::TERMS, true)) {
				throw new InvalidArgumentException('invalid_terms');
			}
		}
		if (!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D', $validated['mt_jet_credit_product_price'])) {
			throw new InvalidArgumentException('invalid_price');
		}
		foreach (array('start', 'end') as $field) {
			$date = $validated['mt_jet_credit_product_' . $field];
			if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
				throw new InvalidArgumentException('invalid_dates');
			}
		}
		if ($validated['mt_jet_credit_product_start'] > $validated['mt_jet_credit_product_end']) {
			throw new InvalidArgumentException('invalid_dates');
		}
		return $validated;
	}

	public function getAllKops() {
		$query_check = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "jet_kop'");
		if ($query_check->num_rows) {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "jet_kop");
			return $query->rows;
		} else {
			return [];
		}
	}
}
