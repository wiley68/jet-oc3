<?php
require_once DIR_SYSTEM . 'library/jetcredit/submission_input.php';
require_once DIR_SYSTEM . 'library/jetcredit/native_mail_scope.php';
/** Native order API adapter. Never assumes addOrder() is transactional. */
class ModelExtensionMtJetCreditSubmissionOrder extends Model {
	public function ready($scheme) {
		$scope = $this->registry->get('jetcredit_native_mail_scope');
		if (!$scope instanceof JetCreditNativeMailScope || !$scope->available()) { throw new JetCreditFinancialException('native_mail_guard_unavailable'); }
		$status = JetCreditFinancial::positiveInteger($this->config->get('payment_' . $scheme . '_order_status_id'));
		$rows = $this->db->query('SELECT order_status_id FROM `' . DB_PREFIX . "order_status` WHERE order_status_id=" . $status . ' AND language_id=' . (int)$this->config->get('config_language_id'));
		if (!$rows->num_rows) { throw new JetCreditFinancialException('invalid_order_status'); }
		return $status;
	}
	public function prepare($context, $scheme, $source, $person, $intent) {
		$this->load->model('checkout/order');
		if ($context === 'checkout') { return array('source' => $source, 'order_id' => $source['order_id'], 'data' => null, 'person' => $person, 'cleanup' => $this->cleanupRows($source['cart_digest_source'])); }
		$this->load->model('catalog/product');
		$products = array();
		foreach ($source['lines'] as $line) {
			$product = $this->model_catalog_product->getProduct($line['product_id']);
			if (!$product) { throw new JetCreditFinancialException('invalid_product'); }
			$specs = $this->model_catalog_product->getProductOptions($line['product_id']); $names = array();
			foreach ($specs as $spec) { $names[(int)$spec['product_option_id']] = JetCreditSubmissionInput::nativeText($spec['name'] ?? '', 255); }
			$options = array();
			foreach ($line['options'] as $option) {
				$options[] = array('product_option_id' => $option['product_option_id'], 'product_option_value_id' => $option['product_option_value_id'], 'name' => $names[$option['product_option_id']] ?? '', 'value' => JetCreditSubmissionInput::native(JetCreditSubmissionInput::line($option['value'], 4096, false)), 'type' => $option['type']);
			}
			$products[] = array('product_id' => $line['product_id'], 'name' => JetCreditSubmissionInput::nativeText($product['name'], 255, true), 'model' => JetCreditSubmissionInput::nativeText($product['model'], 64), 'quantity' => $line['quantity'], 'price' => $line['net'], 'total' => $line['net_total'], 'tax' => $line['tax'], 'reward' => $line['reward'], 'option' => $options);
		}
		$totals = $source['totals'];
		foreach ($totals as &$row) { $row['title'] = JetCreditSubmissionInput::nativeText($row['title'], 255, true); } unset($row);
		if ($context === 'product') {
			$line = $source['lines'][0];
			$totals = array(array('code' => 'sub_total', 'title' => 'Междинна сума', 'value' => $line['net_total'], 'sort_order' => 1), array('code' => 'tax', 'title' => 'Данък', 'value' => (float)$line['tax'] * $line['quantity'], 'sort_order' => 2), array('code' => 'total', 'title' => 'Общо', 'value' => $source['amount'], 'sort_order' => 3));
		}
		$data = array('invoice_prefix' => (string)$this->config->get('config_invoice_prefix'), 'store_id' => 0, 'store_name' => JetCreditSubmissionInput::nativeText($this->config->get('config_name'), 64, true), 'store_url' => defined('HTTP_SERVER') ? HTTP_SERVER : (string)$this->config->get('config_url'), 'customer_id' => (int)$this->customer->getId(), 'customer_group_id' => (int)$this->config->get('config_customer_group_id'), 'firstname' => JetCreditSubmissionInput::native($person['firstname']), 'lastname' => JetCreditSubmissionInput::native($person['lastname']), 'email' => JetCreditSubmissionInput::native($person['email']), 'telephone' => JetCreditSubmissionInput::native($person['telephone']), 'custom_field' => array(), 'payment_method' => $scheme === 'jet_card' ? 'ПБ Лични Финанси на вноски с кредитна карта' : 'ПБ Лични Финанси', 'payment_code' => $scheme, 'shipping_method' => '', 'shipping_code' => '', 'comment' => 'Jet registration ' . $intent, 'total' => $source['amount'], 'affiliate_id' => 0, 'commission' => 0, 'marketing_id' => 0, 'tracking' => '', 'language_id' => (int)$this->config->get('config_language_id'), 'currency_id' => $this->currency->getId('EUR'), 'currency_code' => 'EUR', 'currency_value' => 1, 'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '', 'products' => $products, 'totals' => $totals, 'vouchers' => array());
		foreach (array('payment', 'shipping') as $kind) {
			$address = $this->session->data[$kind . '_address'] ?? array();
			foreach (array('firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'zone', 'address_format') as $key) {
				$value = $address[$key] ?? ($key === 'firstname' || $key === 'lastname' ? $person[$key] : '');
				// Address format is a server template and may contain line breaks; no bank output uses it.
				$data[$kind . '_' . $key] = $key === 'address_format' ? '' : JetCreditSubmissionInput::nativeText($value, in_array($key, array('firstname','lastname'), true) ? 32 : 128);
			}
			$data[$kind . '_country_id'] = (int)($address['country_id'] ?? 0); $data[$kind . '_zone_id'] = (int)($address['zone_id'] ?? 0); $data[$kind . '_custom_field'] = array();
		}
		if ($context === 'cart') {
			$data['shipping_method'] = JetCreditSubmissionInput::line($this->session->data['shipping_method']['title'] ?? '', 128, false);
			$data['shipping_code'] = JetCreditSubmissionInput::line($this->session->data['shipping_method']['code'] ?? '', 128, false);
			if (!empty($this->session->data['vouchers'])) { throw new JetCreditFinancialException('unsupported_pricing'); }
		}
		return array('source' => $source, 'order_id' => null, 'data' => $data, 'person' => $person, 'cleanup' => $context === 'cart' ? $this->cleanupRows($source) : array());
	}
	public function create($prepared) {
		return JetCreditFinancial::positiveInteger($this->model_checkout_order->addOrder($prepared['data']));
	}
	public function verify($id, $scheme, $prepared) {
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($id); $source = $prepared['source'];
		if (!$order || (int)$order['store_id'] !== 0 || (int)$order['customer_id'] !== (int)$this->customer->getId() || $order['payment_code'] !== $scheme || $order['currency_code'] !== 'EUR' || JetCreditFinancial::number($order['currency_value']) !== 1.0 || number_format(JetCreditFinancial::number($order['total']), 2, '.', '') !== $source['amount']) { throw new JetCreditFinancialException('invalid_persisted_order'); }
		foreach (array('firstname', 'lastname', 'email', 'telephone') as $field) { if (JetCreditSubmissionInput::line($order[$field] ?? null, 254) !== $prepared['person'][$field]) { throw new JetCreditFinancialException('invalid_persisted_order'); } }
		$actual = array();
		foreach ($this->model_checkout_order->getOrderProducts($id) as $product) {
			$actual[] = $this->comparisonLine($product['product_id'], $product['quantity'], $product['price'], $product['total'], $product['tax'], $this->model_checkout_order->getOrderOptions($id, $product['order_product_id']), $product['reward'] ?? 0);
		}
		$expected = array();
		foreach ($source['lines'] as $line) { $expected[] = $this->comparisonLine($line['product_id'], $line['quantity'], $line['net'], $line['net_total'], $line['tax'], $line['options'], $line['reward']); }
		if ($actual !== $expected) { throw new JetCreditFinancialException('invalid_persisted_order'); }
		$totals = $this->model_checkout_order->getOrderTotals($id);
		$expectedTotals = $prepared['data'] === null ? $source['totals'] : $prepared['data']['totals'];
		$normalize = function ($rows) {
			$result = array();
			foreach ($rows as $row) { $result[] = array($row['code'], number_format(JetCreditFinancial::number($row['value']), 4, '.', ''), (int)$row['sort_order']); }
			usort($result, function ($a, $b) { return $a[2] <=> $b[2]; }); return $result;
		};
		if ($normalize($totals) !== $normalize($expectedTotals)) { throw new JetCreditFinancialException('invalid_persisted_order'); }
		return $order;
	}
	private function comparisonLine($id, $quantity, $net, $total, $tax, $options, $reward) {
		$normalized = array();
		foreach ($options as $option) { $normalized[] = array((int)$option['product_option_id'], (int)$option['product_option_value_id'], (string)$option['type'], (string)$option['value']); }
		usort($normalized, function ($a, $b) { return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]); });
		return array(JetCreditFinancial::positiveInteger($id), JetCreditFinancial::positiveInteger($quantity), number_format(JetCreditFinancial::number($net), 4, '.', ''), number_format(JetCreditFinancial::number($total), 4, '.', ''), number_format(JetCreditFinancial::number($tax), 4, '.', ''), $normalized, (int)$reward);
	}
	public function bankProducts($id) {
		$this->load->model('catalog/product'); $this->load->model('catalog/category'); $products = array();
		foreach ($this->model_checkout_order->getOrderProducts($id) as $product) {
			$category = ' - ';
			foreach ($this->model_catalog_product->getCategories($product['product_id']) as $row) {
				$info = $this->model_catalog_category->getCategory($row['category_id']); if ($info) { $category = $info['name']; }
			}
			$options = array_column($this->model_checkout_order->getOrderOptions($id, $product['order_product_id']), 'value');
			$products[] = array('product_id' => $product['product_id'], 'name' => $product['name'], 'category' => $category, 'options' => $options, 'unit' => JetCreditFinancial::number($product['price']) + JetCreditFinancial::number($product['tax']), 'quantity' => $product['quantity']);
		}
		return $products;
	}
	public function finalize($id, $scheme, $status, $result) {
		$scope = $this->registry->get('jetcredit_native_mail_scope');
		$scope->run($id, $scheme, function () use ($id, $status, $result) {
			$this->model_checkout_order->addOrderHistory($id, $status, $result === 'manual_registered' ? 'Jet: manual registration; not sent to bank.' : 'Jet: mail transport accepted; no bank approval implied.', false);
		});
		$order = $this->model_checkout_order->getOrder($id);
		if (!$order || (int)$order['order_status_id'] !== $status) { throw new JetCreditFinancialException('order_finalization_failed'); }
	}
	private function cleanupRows($source) {
		$rows = $this->db->query('SELECT cart_id,product_id,quantity,`option`,recurring_id FROM `' . DB_PREFIX . "cart` WHERE api_id=0 AND customer_id=" . (int)$this->customer->getId() . " AND session_id='" . $this->db->escape($this->session->getId()) . "'")->rows;
		$expected = array();
		foreach ($source['lines'] as $line) { $expected[(int)($line['cart_id'] ?? 0)] = $line; }
		if (count($rows) !== count($expected) || isset($expected[0])) { throw new JetCreditFinancialException('unsupported_cart_cleanup'); }
		foreach ($rows as $row) {
			$line = $expected[(int)$row['cart_id']] ?? null;
			if (!$line || (int)$row['product_id'] !== $line['product_id'] || (int)$row['quantity'] !== $line['quantity'] || (int)$row['recurring_id'] !== 0) { throw new JetCreditFinancialException('stale_offer'); }
			$selected = json_decode($row['option'], true); $canonical = array();
			foreach ($line['options'] as $option) {
				$key = $option['product_option_id']; $value = in_array($option['type'], array('select', 'radio', 'checkbox'), true) ? (string)$option['product_option_value_id'] : $option['value'];
				if ($option['type'] === 'checkbox') { $canonical[$key][] = $value; } else { $canonical[$key] = $value; }
			}
			$normalize = function ($values) { if (!is_array($values)) { return null; } ksort($values); foreach ($values as &$value) { if (is_array($value)) { $value = array_map('strval', $value); sort($value); } else { $value = (string)$value; } } unset($value); return $values; };
			if ($normalize($selected) !== $normalize($canonical)) { throw new JetCreditFinancialException('stale_offer'); }
		}
		return $rows;
	}
	public function cleanup($id, $context, $prepared) {
		if ($context === 'product') { return 'not_applicable'; }
		if ($prepared['source']['pricing_context'] !== $this->model_extension_mt_jet_credit_financial->pricingContext() || !empty($this->session->data['vouchers'])) { return 'changed_cart_retained'; }
		$removed = 0;
		foreach ($prepared['cleanup'] as $row) {
			// Compare-and-delete each exact original row. Changed/new rows survive.
			$this->db->query('DELETE FROM `' . DB_PREFIX . 'cart` WHERE cart_id=' . (int)$row['cart_id'] . ' AND api_id=0 AND customer_id=' . (int)$this->customer->getId() . " AND session_id='" . $this->db->escape($this->session->getId()) . "' AND product_id=" . (int)$row['product_id'] . ' AND quantity=' . (int)$row['quantity'] . " AND recurring_id=0 AND BINARY `option`='" . $this->db->escape($row['option']) . "'");
			$removed += (int)$this->db->countAffected();
		}
		return $removed === count($prepared['cleanup']) ? 'cleaned' : 'changed_cart_retained';
	}
	public function disarmSuccess($id) {
		// Done before cleanup, including its failure path. Never unset another tab's order.
		if ((string)($this->session->data['order_id'] ?? '') === (string)$id) { unset($this->session->data['order_id']); }
	}
}
