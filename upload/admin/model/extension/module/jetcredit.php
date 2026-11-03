<?php
class ModelExtensionModuleJetcredit extends Model {
	public function addKop( $data ) {
		$query_check = $this->db->query("
			SELECT * FROM `" . DB_PREFIX . "jet_kop`
			WHERE jet_product_id = '" . $this->db->escape($data['mt_jet_credit_product_id']) . "'"
		);
		if ($query_check->num_rows == 0) {
			$this->db->query("
				INSERT INTO `" . DB_PREFIX . "jet_kop`
				SET
				jet_product_id = '" . $this->db->escape($data['mt_jet_credit_product_id']) . "',
				jet_product_percent = '" . $this->db->escape($data['mt_jet_credit_product_percent']) . "',
				jet_product_meseci = '" . $this->db->escape($data['mt_jet_credit_product_meseci']) . "',
				jet_product_price = '" . $this->db->escape($data['mt_jet_credit_product_price']) . "',
				jet_product_start = '" . $this->db->escape($data['mt_jet_credit_product_start']) . "',
				jet_product_end = '" . $this->db->escape($data['mt_jet_credit_product_end']) . "'"
			);
			return (int) $this->db->countAffected();
		} else {
			return (int) -1;
		}
	}

	public function deleteKop( $jet_product_id ) {
		if (!is_string($jet_product_id) || $jet_product_id === '' || utf8_strlen($jet_product_id) > 20) {
			return 0;
		}
		$this->db->query("DELETE FROM " . DB_PREFIX . "jet_kop WHERE jet_product_id = '" . $this->db->escape($jet_product_id) . "'");
		return $this->db->countAffected();
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