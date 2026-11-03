<?php
class ModelExtensionMtJetCreditJetcredit extends Model {
	private const JETCREDIT_FINANCIAL_MAX_ITERATIONS = 128;
	private const JETCREDIT_FINANCIAL_PRECISION = 1.0e-08;

	public function getCheckoutSnapshot($code) {
		if (!in_array($code, ['jet', 'jet_card'], true) || ($this->session->data['currency'] ?? '') !== 'EUR'
			|| ($this->session->data['payment_method']['code'] ?? '') !== $code) {
			return false;
		}
		$order_id = $this->session->data['order_id'] ?? null;
		if ((!is_int($order_id) && !is_string($order_id)) || !ctype_digit((string)$order_id) || (int)$order_id <= 0) {
			return false;
		}
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder((int)$order_id);
		if (!$order || (int)($order['order_id'] ?? 0) !== (int)$order_id
			|| !isset($order['store_id'], $order['customer_id']) || (int)$order['store_id'] !== (int)$this->config->get('config_store_id')
			|| ($order['payment_code'] ?? '') !== $code || ($order['currency_code'] ?? '') !== 'EUR') {
			return false;
		}
		if ($this->customer->isLogged()) {
			if ((int)$order['customer_id'] !== (int)$this->customer->getId()) return false;
		} elseif ((int)$order['customer_id'] !== 0 || !isset($this->session->data['guest']['email'])
			|| $this->session->data['guest']['email'] !== ($order['email'] ?? null)) {
			return false;
		}
		$total = $this->checkoutNumber($order['total'] ?? null);
		$value = $this->checkoutNumber($order['currency_value'] ?? null);
		if ($total === null || $value === null || $total <= 0 || $value <= 0 || !is_finite($total * $value)) return false;
		$amount = $total * $value;
		$this->load->model('extension/payment/' . $code);
		$payment_model = 'model_extension_payment_' . $code;
		$address = ['country_id' => $order['payment_country_id'] ?? 0, 'zone_id' => $order['payment_zone_id'] ?? 0];
		if (!$this->{$payment_model}->getMethod($address, $total, $amount)) return false;
		$products = $this->model_checkout_order->getOrderProducts((int)$order_id);
		if (!$products) return false;
		$ids = $quantities = $prices = $variations = [];
		foreach ($products as &$product) {
			$price = $this->checkoutNumber($product['price'] ?? null);
			$tax = $this->checkoutNumber($product['tax'] ?? null);
			$quantity = $this->checkoutNumber($product['quantity'] ?? null);
			if ($price === null || $tax === null || $quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) return false;
			$options = $this->model_checkout_order->getOrderOptions((int)$order_id, $product['order_product_id']);
			$product['option'] = $options;
			$unit = ($price + ($this->config->get('config_tax') ? $tax : 0)) * $value;
			if (!is_finite($unit)) return false;
			$product['unit_eur'] = number_format($unit, 2, '.', '');
			$ids[] = $product['product_id'];
			$quantities[] = $product['quantity'];
			$prices[] = $product['unit_eur'];
			$variations[] = implode(', ', array_column($options, 'value'));
		}
		unset($product);
		return ['order' => $order, 'products' => $products, 'amount' => $amount,
			'products_key' => implode('_', $ids), 'products_qt' => implode('_', $quantities),
			'products_pr' => implode('_', $prices), 'products_vr' => implode('_', $variations)];
	}

	// Match the existing browser Number.toFixed(2), including exact binary ties.
	public function checkoutFixed($number) {
		if ($number == 0) return '0.00';
		$fixed = sprintf('%.2F', $number);
		// For exact .125/.625 ties printf ends in 2, while JS rounds up to 3.
		// Adjust the string so a cent is not lost by converting a large amount back to float.
		return fmod($number, 0.5) === 0.125 ? substr($fixed, 0, -1) . '3' : $fixed;
	}

	private function checkoutNumber($value, $empty_zero = false) {
		if ($empty_zero && is_string($value) && trim($value) === '') return 0.0;
		if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
		if (is_string($value) && !preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', trim($value))) return null;
		return is_finite((float)$value) ? (float)$value : null;
	}

	public function getCheckoutQuote($snapshot, $post) {
		$submitted = $this->checkoutNumber($post['jet_priceall'] ?? null);
		$down = $this->checkoutNumber($post['jet_parva'] ?? '', true);
		$term = $this->checkoutNumber($post['jet_vnoski'] ?? null);
		// Twig prints floats as PHP strings; retain that same browser input precision.
		$browser_amount = (float)(string)$snapshot['amount'];
		$amount = (float)$this->checkoutFixed($browser_amount);
		if ($submitted === null || $submitted !== $amount || $down === null || $down < 0 || $down >= $browser_amount
			|| !in_array($term, [3.0, 6.0, 9.0, 10.0, 12.0, 15.0, 18.0, 24.0, 30.0, 36.0], true)
			|| ($browser_amount < 401 && in_array($term, [15.0, 18.0, 24.0], true))
			|| ($browser_amount < 601 && in_array($term, [30.0, 36.0], true))) return false;
		foreach (['products' => 'products_key', 'products_qt' => 'products_qt', 'products_pr' => 'products_pr', 'products_vr' => 'products_vr'] as $field => $key) {
			$expected = $field === 'products_vr' ? html_entity_decode($snapshot[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $snapshot[$key];
			if (array_key_exists('jet_' . $field, $post) && (!is_string($post['jet_' . $field]) || $post['jet_' . $field] !== $expected)) return false;
		}
		$principal = $amount - (float)$this->checkoutFixed($down);
		if ($principal <= 0 || $amount - $down <= 0) return false;
		$promo = $this->model_extension_mt_jet_credit_jetcredit->getPromo($snapshot['products_key'], (int)$term, $principal);
		if (!$promo['jet_show_button']) return false;
		$json = ['success' => 'success', 'jet_priceall' => number_format($amount, 2, '.', ''),
			'jet_priceall_second' => number_format($amount, 2, '.', ''),
			'jet_total_credit_price' => number_format($principal, 2, '.', ''),
			'jet_total_credit_price_second' => number_format($principal, 2, '.', '')];
		foreach (['' => 'jet_purcent', '_card' => 'jet_purcent_card'] as $suffix => $key) {
			$percent = (float)$promo[$key];
			$monthly = (($principal / $term) * (1 + ($term * $percent) / 100));
			$rate = $this->checkoutRate($term, $monthly, -1 * $principal) * 12;
			$glp = ($this->checkoutRate($term, $monthly, -1 * $principal) * 12) * 100;
			$gpr = (pow((1 + $rate / 12), 12) - 1) * 100;
			$overall = $monthly * $term;
			foreach (['vnoska' => $monthly, 'glp' => $glp, 'gpr' => $gpr, 'obshto' => $overall] as $field => $number) {
				if (!is_finite($number)) return false;
				$json['jet_' . $field . $suffix] = number_format($number, 2, '.', '');
			}
			$json['jet_vnoska' . $suffix . '_second'] = '0.00';
			$json['jet_obshto' . $suffix . '_second'] = number_format($overall, 2, '.', '');
		}
		return $json;
	}

	public function validateCheckoutSubmission($code, $post) {
		$snapshot = $this->getCheckoutSnapshot($code);
		if (!$snapshot) return false;
		$input = [];
		foreach (['priceall', 'parva', 'vnoski', 'vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp', 'products', 'products_qt', 'products_pr', 'products_vr'] as $field) {
			if (array_key_exists($code . '_' . $field, $post)) $input['jet_' . $field] = $post[$code . '_' . $field];
		}
		foreach (['products', 'products_qt', 'products_pr', 'products_vr'] as $field) {
			if (!isset($input['jet_' . $field])) return false;
		}
		$quote = $this->getCheckoutQuote($snapshot, $input);
		if (!$quote) return false;
		$suffix = $code === 'jet_card' ? '_card' : '';
		foreach (['vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp'] as $field) {
			$value = $this->checkoutNumber($input['jet_' . $field] ?? null);
			$expected = $quote['jet_' . $field . ($field === 'total_credit_price' ? '' : $suffix)];
			if ($value === null || $value !== (float)$expected) return false;
		}
		if ((float)$quote['jet_vnoska' . $suffix] < 20) return false;
		return ['snapshot' => $snapshot, 'quote' => $quote, 'term' => (int)$input['jet_vnoski'],
			'downpayment' => $this->checkoutNumber($input['jet_parva'] ?? '', true)];
	}

	private function checkoutRate($nper, $pmt, $pv, $fv = 0.0, $type = 0, $guess = 0.1) {
		$rate = $guess;
		if (abs($rate) < self::JETCREDIT_FINANCIAL_PRECISION) {
			$y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
		} else {
			$f = exp($nper * log(1 + $rate));
			$y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
		}
		$y0 = $pv + $pmt * $nper + $fv;
		$y1 = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
		$i  = $x0 = 0.0;
		$x1 = $rate;
		while ((abs($y0 - $y1) > self::JETCREDIT_FINANCIAL_PRECISION) && ($i < self::JETCREDIT_FINANCIAL_MAX_ITERATIONS)) {
			$rate = ($y1 * $x0 - $y0 * $x1) / ($y1 - $y0);
			$x0 = $x1;
			$x1 = $rate;
			if (abs($rate) < self::JETCREDIT_FINANCIAL_PRECISION) {
				$y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
			} else {
				$f = exp($nper * log(1 + $rate));
				$y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
			}
			$y0 = $y1;
			$y1 = $y;
			++$i;
		}
		return $rate;
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