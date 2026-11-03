<?php
class ModelExtensionMtJetCreditJetcredit extends Model {
	public function getAllKops() {
		$query_check = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "jet_kop'");
		if ($query_check->num_rows) {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "jet_kop");
			return $query->rows;
		} else {
			return [];
		}
	}

	public function getPromo($jet_product_id, $jet_vnoski, $jet_total_credit_price) {
		$jet_purcent      = (float)$this->config->get('module_mt_jet_credit_purcent');
		$jet_purcent_card = (float)$this->config->get('module_mt_jet_credit_purcent_card');
		$jet_show_button  = true;

		$product_id = $this->db->escape((string)$jet_product_id);
		$vnoski     = (int)$jet_vnoski;
		$total      = (float)$jet_total_credit_price;

		$sql = "
			SELECT jet_product_percent, jet_product_id, id
			FROM " . DB_PREFIX . "jet_kop
			WHERE (jet_product_id = '*' OR jet_product_id = '{$product_id}')
			  AND FIND_IN_SET({$vnoski}, REPLACE(jet_product_meseci, '_', ','))
			  AND {$total} >= jet_product_price
			  AND jet_product_start <= CURDATE()
			  AND jet_product_end >= CURDATE()
			ORDER BY
				CASE WHEN jet_product_id = '{$product_id}' THEN 0 ELSE 1 END,
				id ASC
			LIMIT 1
		";

		$query = $this->db->query($sql);

		$row = [];
		if ($query->num_rows > 0) {
			$row = $query->row;

			if ((float)$row['jet_product_percent'] === -1.00) {
				$jet_show_button = false;
			} else {
				$jet_purcent = (float)$row['jet_product_percent'];
				$jet_purcent_card = (float)$row['jet_product_percent'];
			}
		}

		return [
			'jet_show_button' => $jet_show_button,
			'jet_purcent' => $jet_purcent,
			'jet_purcent_card' => $jet_purcent_card
		];
	}

	/**
	 * @param mixed $raw_index
	 * @return string style атрибут за .jet_wide_button_wrap
	 */
	public function getJetWideButtonInlineStyle($raw_index) {
		if (!class_exists('JetButtonScheme', false)) {
			require_once DIR_SYSTEM . 'library/jet_button_scheme.php';
		}
		return JetButtonScheme::wrapInlineStyle($raw_index);
	}
}