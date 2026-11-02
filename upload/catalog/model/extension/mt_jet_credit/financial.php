<?php
require_once DIR_SYSTEM . 'library/jetcredit/financial.php';
require_once DIR_SYSTEM . 'library/jetcredit/offer.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_identity.php';
require_once DIR_SYSTEM . 'library/jetcredit/runtime.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_input.php';

/** Read-only OC3 pricing adapter. Does not create orders or temporary cart rows. */
class ModelExtensionMtJetCreditFinancial extends Model {
	private const TOTALS = array('sub_total', 'shipping', 'tax', 'coupon', 'handling', 'low_order_fee', 'voucher', 'reward', 'credit', 'total');

	public function ready($scheme = 'jet') {
		JetCreditRuntime::boot($this->registry);
		JetCreditFinancial::requireEur($this->config, $this->session, $this->currency);
		$this->currencyContext();
		if (!in_array($this->config->get('config_store_id'), array(null, 0, '0'), true)) { throw new JetCreditFinancialException('unsupported_store'); }
		if (!in_array($scheme, array('jet', 'jet_card'), true) || !$this->enabled('module_mt_jet_credit_status') || ($scheme === 'jet_card' && !$this->enabled('module_mt_jet_credit_status_card'))) { throw new JetCreditFinancialException('financing_unavailable'); }
		if ($this->config->get('config_customer_price') && !$this->customer->isLogged()) { throw new JetCreditFinancialException('financing_unavailable'); }
		return new JetCreditOffer(JetCreditOffer::configuredSecret($this->config));
	}

	public function csrfToken() {
		if (!is_string($this->session->getId()) || $this->session->getId() === '') { throw new JetCreditFinancialException('missing_session'); }
		if (!isset($this->session->data['jetcredit_csrf'])) { $this->session->data['jetcredit_csrf'] = bin2hex(random_bytes(32)); }
		return $this->session->data['jetcredit_csrf'];
	}

	public function frontendData($context, $scheme = 'jet', $product_id = null) {
		$data = array('is_active_jet' => false, 'jet_context' => $context, 'jet_scheme' => $scheme, 'jet_product_id' => $product_id ?? 0, 'jet_csrf' => '', 'jet_application_intent' => '', 'jet_sign' => 'евро', 'jet_vnoski' => 12, 'jet_status' => false, 'jet_column_left' => 'jet_column_left', 'jet_column_right' => 'jet_column_right', 'jet_diagnostic' => 'Кредитното финансиране временно не е достъпно.');
		try {
			$this->ready($scheme);
			if ($context === 'cart' && !$this->enabled('module_mt_jet_credit_cart_show')) { return $data; }
			$data['jet_csrf'] = $this->csrfToken();
			$this->load->model('extension/mt_jet_credit/submission');
			$data['jet_application_intent'] = $this->model_extension_mt_jet_credit_submission->identity()->intent($context,$product_id,true);
			$data['is_active_jet'] = true;
			$data['jet_card_in'] = $this->enabled('module_mt_jet_credit_status_card') ? 1 : 0;
			$data['jet_vnoska'] = $this->enabled('module_mt_jet_credit_vnoska') ? 1 : 0;
			$data['jet_gap'] = max(0, (int)$this->config->get('module_mt_jet_credit_gap'));
			$data['jet_button_type'] = $this->config->get('module_mt_jet_credit_button_type') === 'wide' ? 'wide' : 'standard';
			$this->load->model('extension/mt_jet_credit/jetcredit');
			$data['jet_button_scheme_style'] = $this->model_extension_mt_jet_credit_jetcredit->getJetWideButtonInlineStyle($this->config->get('module_mt_jet_credit_button_scheme'));
			foreach (array('btn_max_width' => array('max-width', 570, 30, 1200), 'btn_round' => array('radius', 16, 0, 25), 'btn_font' => array('font-size', 14, 6, 36)) as $key => $rule) {
				$value = $this->config->get('module_mt_jet_credit_' . $key);
				$data['jet_button_scheme_style'] .= '--jet-wide-' . $rule[0] . ':' . ($value === null || $value === '' ? $rule[1] : min($rule[3], max($rule[2], (int)$value))) . 'px;';
			}
			$data['jet_btn_text'] = $this->config->get('module_mt_jet_credit_btn_text') ?: 'Купи на изплащане с';
			$data['jet_btn_text_card'] = $this->config->get('module_mt_jet_credit_btn_text_card') ?: 'На вноски с твоята кредитна карта';
			$data['jet_btn_logo'] = $this->config->get('module_mt_jet_credit_btn_logo') === null ? 1 : (int)$this->config->get('module_mt_jet_credit_btn_logo');
			$data['image_jet'] = 'catalog/view/theme/default/image/jet.png';
			$data['image_jet_card'] = 'catalog/view/theme/default/image/jet_card.png';
			$base = 'catalog/view/theme/default/template/extension/mt_jet_credit/';
			foreach (array('jet_offer_js' => 'creditjet_offers.js', 'jet_product_js' => 'creditjet_products.js', 'jet_product_css' => 'creditjet_products.css') as $key => $file) {
				$data[$key] = $base . $file . '?ver=' . filemtime(DIR_APPLICATION . 'view/theme/default/template/extension/mt_jet_credit/' . $file);
			}
			// Profile data belongs only to the current customer's rendered popup, never quote DTOs.
			$data['jet_name'] = $data['jet_lastname'] = $data['jet_email'] = $data['jet_phone'] = '';
			if ($context !== 'checkout' && $this->customer->isLogged()) {
				$this->response->addHeader('Cache-Control: private, no-store');
				foreach (array('jet_name'=>'getFirstName','jet_lastname'=>'getLastName','jet_email'=>'getEmail','jet_phone'=>'getTelephone') as $key=>$method) {
					try { $data[$key] = JetCreditSubmissionInput::line($this->customer->$method(), 255, false); }
					catch (JetCreditFinancialException $e) { $data[$key] = ''; }
				}
			}
		} catch (JetCreditFinancialException $e) { $data['is_active_jet'] = false; }
		return $data;
	}

	public function quote($context, $scheme, $input) {
		JetCreditRuntime::boot($this->registry);
		$this->validateCsrf($input);
		$entity = $context === 'product' ? JetCreditFinancial::positiveInteger($input['jet_product_id'] ?? $input['product_id'] ?? null) : ($context === 'checkout' ? ($this->session->data['order_id'] ?? null) : null);
		$this->load->model('extension/mt_jet_credit/submission');
		$identity = $this->model_extension_mt_jet_credit_submission->identity();
		// Missing identity means generation ONE only, never the current generation.
		$intent = $input['jet_intent'] ?? $identity->first($context,$entity);
		$identity->authorization(0,$this->customer->getId(),$context,$entity,$intent);
		$existing = $this->model_extension_mt_jet_credit_submission->existingOutcome($context, $entity, $intent);
		if ($existing !== null) { return $existing; }
		$identity->assertCurrent($context,$entity,$intent);
		$offer = $this->ready($scheme);
		$source = $this->source($context, $scheme, $input);
		$term = isset($input['jet_vnoski']) && $input['jet_vnoski'] !== '' ? JetCreditFinancial::positiveInteger($input['jet_vnoski']) : JetCreditFinancial::initialTerm($source['amount'], $this->config->get('module_mt_jet_credit_vnoski'));
		$down = JetCreditFinancial::money($input['jet_parva'] ?? '0');
		$selected = $this->calculation($source, $down, $term, $scheme, false);
		$previews = array($scheme => $selected['calculation']);
		$other = $scheme === 'jet' ? 'jet_card' : 'jet';
		try {
			if ($other === 'jet' || $this->enabled('module_mt_jet_credit_status_card')) { $previews[$other] = $this->calculation($source, $down, $term, $other, false)['calculation']; }
		} catch (JetCreditFinancialException $e) { /* Only the selected scheme determines this quote. */ }
		$calculation = $selected['calculation'];
		$token = $calculation['eligible'] ? $offer->issue($this->binding($context, $scheme, $source, $intent), $source, $selected['policy'], $this->recipientConfiguration(), $calculation) : '';
		return array('success' => 'success', 'currency' => 'EUR', 'context' => $context, 'scheme' => $scheme, 'eligible' => $calculation['eligible'], 'allowed_terms' => JetCreditFinancial::terms($source['amount']), 'calculation' => $calculation, 'previews' => $previews, 'offer' => $token, 'application_intent' => $intent, 'expires_in' => $token === '' ? 0 : 1800, 'submission_enabled' => !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off');
	}

	/** Re-read and verify before acquiring a new claim or executing side effects. */
	public function verifySubmission($context, $scheme, $input) {
		$offer = $this->ready($scheme);
		$this->validateCsrf($input);
		$source = $this->source($context, $scheme, $input);
		if (!isset($input['jet_parva'], $input['jet_vnoski'])) { throw new JetCreditFinancialException('missing_financial_input'); }
		$this->load->model('extension/mt_jet_credit/submission');
		$identity = $this->model_extension_mt_jet_credit_submission->identity();
		$intent = $input['jet_intent'] ?? $identity->first($context,$context==='product'?$source['lines'][0]['product_id']:null);
		$identity->assertCurrent($context,$context==='product'?$source['lines'][0]['product_id']:null,$intent);
		$selected = $this->calculation($source, JetCreditFinancial::money($input['jet_parva']), JetCreditFinancial::positiveInteger($input['jet_vnoski']), $scheme, true);
		$calculation = $offer->verify($input['jet_offer'] ?? null, $this->binding($context, $scheme, $source, $intent), $source, $selected['policy'], $this->recipientConfiguration(), $selected['calculation']);
		// Redundant legacy display values may detect tampering, but never determine pricing.
		foreach (array('jet_priceall' => 'amount', 'jet_total_credit_price' => 'principal', 'jet_vnoska' => 'monthly', 'jet_obshto' => 'total', 'jet_glp' => 'glp', 'jet_gpr' => 'gpr') as $field => $key) {
			if (array_key_exists($field, $input) && number_format(JetCreditFinancial::money($input[$field]), 2, '.', '') !== $calculation[$key]) { throw new JetCreditFinancialException('stale_offer'); }
		}
		return array('source' => $source, 'calculation' => $calculation);
	}

	public function source($context, $scheme, $input) {
		if (!is_array($input)) { throw new JetCreditFinancialException('invalid_input'); }
		if ($context === 'product') { return $this->product($input['jet_product_id'] ?? $input['product_id'] ?? null, $input['jet_quantity'] ?? null, $input['option'] ?? array()); }
		if ($context === 'cart') { return $this->cartSnapshot(); }
		if ($context === 'checkout') { return $this->orderSnapshot($scheme); }
		throw new JetCreditFinancialException('invalid_context');
	}

	public function product($id, $quantity, $selection) {
		$id = JetCreditFinancial::positiveInteger($id);
		$quantity = JetCreditFinancial::positiveInteger($quantity);
		$this->load->model('catalog/product');
		$product = $this->model_catalog_product->getProduct($id);
		if (!$product || (int)$product['status'] !== 1) { throw new JetCreditFinancialException('invalid_product'); }
		if ($quantity < JetCreditFinancial::positiveInteger($product['minimum'])) { throw new JetCreditFinancialException('invalid_quantity'); }
		if ((int)$product['quantity'] < $quantity && !$this->config->get('config_stock_checkout')) { throw new JetCreditFinancialException('out_of_stock'); }
		if ($this->model_catalog_product->getProfiles($id)) { throw new JetCreditFinancialException('unsupported_pricing'); }
		$specs = $this->model_catalog_product->getProductOptions($id);
		$options = $this->validateOptions($specs, $selection, $quantity);
		$price = JetCreditFinancial::number($product['price']);
		$discounts = $this->model_catalog_product->getProductDiscounts($id);
		usort($discounts, function ($a, $b) {
			return ((int)$b['quantity'] <=> (int)$a['quantity']) ?: (((int)$a['priority'] <=> (int)$b['priority']) ?: (JetCreditFinancial::number($a['price']) <=> JetCreditFinancial::number($b['price'])));
		});
		foreach ($discounts as $discount) {
			if (JetCreditFinancial::positiveInteger($discount['quantity']) <= $quantity) { $price = JetCreditFinancial::number($discount['price']); break; }
		}
		if ($product['special'] !== null && $product['special'] !== '' && $product['special'] !== false) { $price = JetCreditFinancial::number($product['special']); }
		foreach ($options as $option) { $price += $option['modifier']; }
		if ($price < 0) { throw new JetCreditFinancialException('invalid_product_price'); }
		$tax = JetCreditFinancial::number($this->tax->getTax($price, $product['tax_class_id']));
		$reward = $product['reward'] ?? 0;
		$reward = $reward === null || $reward === 0 || $reward === '0' ? 0 : JetCreditFinancial::positiveInteger($reward);
		$line = $this->line($id, $quantity, $options, $price, $price * $quantity, $tax, $reward * $quantity);
		return array('base_amount' => number_format(($price + $tax) * $quantity, 2, '.', ''), 'amount' => $this->eurAmount(($price + $tax) * $quantity), 'lines' => array($line), 'totals' => array(), 'pricing_context' => $this->pricingContext());
	}

	private function validateOptions($specs, $selection, $quantity) {
		if (is_string($selection)) {
			if (strlen($selection) > 16384) { throw new JetCreditFinancialException('invalid_options'); }
			$selection = json_decode(html_entity_decode($selection, ENT_QUOTES, 'UTF-8'), true, 8);
		}
		if (!is_array($selection) || count($selection) > 100) { throw new JetCreditFinancialException('invalid_options'); }
		$known = array();
		$result = array();
		foreach ($specs as $spec) {
			$id = JetCreditFinancial::positiveInteger($spec['product_option_id']);
			$known[] = $id;
			$chosen = $selection[$id] ?? null;
			if ($chosen === null || $chosen === '' || $chosen === array()) {
				if ($spec['required']) { throw new JetCreditFinancialException('required_options'); }
				continue;
			}
			if (in_array($spec['type'], array('select', 'radio', 'checkbox'), true)) {
				if ($spec['type'] === 'checkbox') {
					if (!is_array($chosen) || count($chosen) > 100) { throw new JetCreditFinancialException('invalid_options'); }
				} else { $chosen = array($chosen); }
				$seen = array();
				foreach ($chosen as $value) {
					$value = JetCreditFinancial::positiveInteger($value);
					if (in_array($value, $seen, true)) { throw new JetCreditFinancialException('invalid_options'); }
					$seen[] = $value;
					$found = null;
					foreach ($spec['product_option_value'] as $candidate) { if ((int)$candidate['product_option_value_id'] === $value) { $found = $candidate; break; } }
					if (!$found || ($found['subtract'] && (int)$found['quantity'] < $quantity && !$this->config->get('config_stock_checkout'))) { throw new JetCreditFinancialException('invalid_options'); }
					if (!in_array($found['price_prefix'], array('+', '-'), true)) { throw new JetCreditFinancialException('unsupported_pricing'); }
					$modifier = JetCreditFinancial::number($found['price']);
					if ($modifier < 0) { throw new JetCreditFinancialException('unsupported_pricing'); }
					$result[] = array('product_option_id' => $id, 'product_option_value_id' => $value, 'value' => (string)$found['name'], 'type' => $spec['type'], 'modifier' => $found['price_prefix'] === '-' ? -$modifier : $modifier);
				}
			} elseif (in_array($spec['type'], array('text', 'textarea', 'date', 'time', 'datetime'), true)) {
				if (!is_string($chosen) || strlen($chosen) > 4096 || !preg_match('//u', $chosen)) { throw new JetCreditFinancialException('invalid_options'); }
				$result[] = array('product_option_id' => $id, 'product_option_value_id' => 0, 'value' => JetCreditSubmissionInput::native(JetCreditSubmissionInput::line($chosen, 4096, false)), 'type' => $spec['type'], 'modifier' => 0.0);
			} else { throw new JetCreditFinancialException('unsupported_pricing'); }
		}
		foreach (array_keys($selection) as $key) { if (!in_array(JetCreditFinancial::positiveInteger($key), $known, true)) { throw new JetCreditFinancialException('invalid_options'); } }
		usort($result, function ($a, $b) { return ($a['product_option_id'] <=> $b['product_option_id']) ?: ($a['product_option_value_id'] <=> $b['product_option_value_id']); });
		return $result;
	}

	public function cartSnapshot() {
		if (!empty($this->session->data['vouchers'])) { throw new JetCreditFinancialException('unsupported_pricing'); }
		$products = $this->cart->getProducts();
		if (!$products || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) { throw new JetCreditFinancialException('invalid_cart'); }
		$groups = array();
		foreach ($products as $product) {
			$id = JetCreditFinancial::positiveInteger($product['product_id']);
			$groups[$id] = ($groups[$id] ?? 0) + JetCreditFinancial::positiveInteger($product['quantity']);
		}
		$lines = array();
		$this->load->model('catalog/product');
		foreach ($products as $product) {
			if (!empty($product['recurring']) || $groups[$product['product_id']] < JetCreditFinancial::positiveInteger($product['minimum'])) { throw new JetCreditFinancialException('invalid_cart'); }
			$selection = array();
			foreach ($product['option'] as $option) {
				$id = JetCreditFinancial::positiveInteger($option['product_option_id']);
				if ($option['type'] === 'checkbox') { $selection[$id][] = $option['product_option_value_id']; }
				else { $selection[$id] = in_array($option['type'], array('select', 'radio'), true) ? $option['product_option_value_id'] : $option['value']; }
			}
			$options = $this->validateOptions($this->model_catalog_product->getProductOptions($product['product_id']), $selection, $product['quantity']);
			if (count($options) !== count($product['option'])) { throw new JetCreditFinancialException('invalid_options'); }
			$tax = $this->tax->getTax($product['price'], $product['tax_class_id']);
			$line = $this->line($product['product_id'], $product['quantity'], $options, $product['price'], $product['total'], $tax, $product['reward'] ?? 0);
			$line['cart_id'] = isset($product['cart_id']) ? JetCreditFinancial::positiveInteger($product['cart_id']) : null;
			$lines[] = $line;
		}
		$totals = array(); $taxes = $this->cart->getTaxes(); $total = 0;
		foreach ($taxes as $value) { JetCreditFinancial::number($value); }
		$total_data = array('totals' => &$totals, 'taxes' => &$taxes, 'total' => &$total);
		$this->load->model('setting/extension');
		$extensions = $this->model_setting_extension->getExtensions('total');
		usort($extensions, function ($a, $b) { return ((int)$this->config->get('total_' . $a['code'] . '_sort_order')) <=> ((int)$this->config->get('total_' . $b['code'] . '_sort_order')); });
		$seen = array();
		foreach ($extensions as $extension) {
			$code = $extension['code'];
			if (!is_string($code) || !preg_match('/^[a-z0-9_]+$/D', $code)) { throw new JetCreditFinancialException('unsupported_pricing'); }
			if (!$this->config->get('total_' . $code . '_status')) { continue; }
			if (!in_array($code, self::TOTALS, true) || in_array($code, $seen, true)) { throw new JetCreditFinancialException('unsupported_pricing'); }
			$seen[] = $code;
			$this->load->model('extension/total/' . $code);
			$this->{'model_extension_total_' . $code}->getTotal($total_data);
		}
		$totals = $this->normalizeTotals($totals, $total);
		return array('base_amount' => number_format(JetCreditFinancial::number($total), 4, '.', ''), 'amount' => $this->eurAmount($total), 'lines' => $lines, 'totals' => $totals, 'pricing_context' => $this->pricingContext());
	}

	public function orderSnapshot($scheme) {
		if (!$this->enabled('payment_' . $scheme . '_status') || ($this->session->data['payment_method']['code'] ?? null) !== $scheme) { throw new JetCreditFinancialException('invalid_order_context'); }
		$id = JetCreditFinancial::positiveInteger($this->session->data['order_id'] ?? null);
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($id);
		if ($this->model_checkout_order->getOrderVouchers($id)) { throw new JetCreditFinancialException('unsupported_pricing'); }
		if (!$order || (int)$order['store_id'] !== 0 || $order['payment_code'] !== $scheme || $order['currency_code'] !== 'EUR' || abs(JetCreditFinancial::number($order['currency_value']) - (float)$this->currencyContext()['eur_value']) > 1.0e-8) { throw new JetCreditFinancialException('invalid_order_context'); }
		if ($this->customer->isLogged()) {
			if ((int)$order['customer_id'] !== (int)$this->customer->getId()) { throw new JetCreditFinancialException('invalid_order_context'); }
		} elseif ((int)$order['customer_id'] !== 0 || !isset($this->session->data['guest']['email']) || strcasecmp($this->session->data['guest']['email'], $order['email']) !== 0) { throw new JetCreditFinancialException('invalid_order_context'); }
		if (in_array((int)$order['order_status_id'], array_map('intval', array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status'))), true)) { throw new JetCreditFinancialException('invalid_order_context'); }
		$lines = array();
		foreach ($this->model_checkout_order->getOrderProducts($id) as $product) {
			$options = array();
			foreach ($this->model_checkout_order->getOrderOptions($id, $product['order_product_id']) as $option) {
				$options[] = array('product_option_id' => JetCreditFinancial::positiveInteger($option['product_option_id']), 'product_option_value_id' => (int)$option['product_option_value_id'], 'value' => (string)$option['value'], 'type' => (string)$option['type'], 'modifier' => 0.0);
			}
			usort($options, function ($a, $b) { return ($a['product_option_id'] <=> $b['product_option_id']) ?: ($a['product_option_value_id'] <=> $b['product_option_value_id']); });
			$lines[] = $this->line($product['product_id'], $product['quantity'], $options, $product['price'], $product['total'], $product['tax'], $product['reward'] ?? 0);
		}
		if (!$lines) { throw new JetCreditFinancialException('invalid_order_context'); }
		$totals = $this->normalizeTotals($this->model_checkout_order->getOrderTotals($id), $order['total']);
		$cart = $this->cartSnapshot();
		if ($this->comparisonLines($lines) !== $this->comparisonLines($cart['lines']) || $totals !== $cart['totals'] || number_format(JetCreditFinancial::number($order['total']), 4, '.', '') !== $cart['base_amount']) { throw new JetCreditFinancialException('stale_order'); }
		return array('order_id' => $id, 'base_amount' => number_format(JetCreditFinancial::number($order['total']), 4, '.', ''), 'amount' => $this->eurAmount($order['total']), 'lines' => $lines, 'totals' => $totals, 'cart_digest_source' => $cart, 'pricing_context' => $this->pricingContext());
	}

	private function line($id, $quantity, $options, $net, $net_total, $tax, $reward = 0) {
		$id = JetCreditFinancial::positiveInteger($id); $quantity = JetCreditFinancial::positiveInteger($quantity);
		$net = JetCreditFinancial::number($net); $net_total = JetCreditFinancial::number($net_total); $tax = JetCreditFinancial::number($tax);
		if ($net < 0 || $tax < 0 || abs($net * $quantity - $net_total) > 0.0001) { throw new JetCreditFinancialException('unsupported_pricing'); }
		$reward = $reward === 0 || $reward === '0' ? 0 : JetCreditFinancial::positiveInteger($reward);
		return array('product_id' => $id, 'quantity' => $quantity, 'options' => $options, 'net' => number_format($net, 8, '.', ''), 'net_total' => number_format($net_total, 8, '.', ''), 'tax' => number_format($tax, 8, '.', ''), 'gross_total' => number_format($net_total + $tax * $quantity, 8, '.', ''), 'reward' => $reward);
	}

	private function normalizeTotals($rows, $amount) {
		$normalized = array(); $finals = 0;
		foreach ($rows as $row) {
			if (!isset($row['code'], $row['value'], $row['sort_order']) || !in_array($row['code'], self::TOTALS, true)) { throw new JetCreditFinancialException('unsupported_pricing'); }
			$value = JetCreditFinancial::number($row['value']);
			if ($row['code'] === 'total') {
				++$finals;
				if (abs($value - JetCreditFinancial::number($amount)) > 0.0001) { throw new JetCreditFinancialException('invalid_total'); }
			}
			$normalized[] = array('code' => $row['code'], 'title' => (string)($row['title'] ?? $row['code']), 'value' => number_format($value, 4, '.', ''), 'sort_order' => (int)$row['sort_order']);
		}
		if ($finals !== 1 || JetCreditFinancial::number($amount) <= 0) { throw new JetCreditFinancialException('invalid_total'); }
		usort($normalized, function ($a, $b) { return $a['sort_order'] <=> $b['sort_order']; });
		return $normalized;
	}

	private function comparisonLines($lines) {
		return array_map(function ($line) {
			unset($line['cart_id']);
			foreach (array('net', 'net_total', 'tax', 'gross_total') as $field) { $line[$field] = number_format((float)$line[$field], 4, '.', ''); }
			foreach ($line['options'] as &$option) { unset($option['modifier']); }
			unset($option);
			return $line;
		}, $lines);
	}

	private function calculation($source, $down, $term, $scheme, $requireMonthly) {
		$this->load->model('extension/mt_jet_credit/jetcredit');
		$selector = implode('_', array_column($source['lines'], 'product_id'));
		$promo = $this->model_extension_mt_jet_credit_jetcredit->getPromo($selector, $term, (float)$source['amount'] - $down);
		if (!isset($promo['jet_show_button']) || $promo['jet_show_button'] !== true) { throw new JetCreditFinancialException('financing_unavailable'); }
		$regular = JetCreditFinancial::percent($promo['jet_purcent']);
		$card = JetCreditFinancial::percent($promo['jet_purcent_card']);
		$minimum = JetCreditFinancial::minimum($this->config->get('module_mt_jet_credit_price'));
		$result = JetCreditFinancial::calculate($source['amount'], $down, $term, $scheme === 'jet_card' ? $card : $regular, $minimum, $requireMonthly);
		$policy = array('version' => 1, 'rules' => JetCreditFinancial::rules(), 'minimum' => $minimum, 'regular' => $regular, 'card' => $card, 'default_term' => $this->config->get('module_mt_jet_credit_vnoski'), 'promo' => $promo, 'enabled' => array($this->config->get('module_mt_jet_credit_status'), $this->config->get('module_mt_jet_credit_status_card'), $this->config->get('payment_' . $scheme . '_status')));
		return array('calculation' => $result, 'policy' => $policy);
	}

	private function binding($context, $scheme, $source, $intent) {
		return array('application_intent'=>$intent,'generation'=>$this->model_extension_mt_jet_credit_submission->identity()->generation($context,$context==='product'?$source['lines'][0]['product_id']:null,$intent),'store' => 0, 'session' => $this->session->getId(), 'customer' => (int)$this->customer->getId(), 'group' => $this->config->get('config_customer_group_id'), 'context' => $context, 'scheme' => $scheme, 'order' => $source['order_id'] ?? null);
	}
	public function pricingContext() {
		return array('conversion' => $this->currencyContext(), 'base' => $this->config->get('config_currency'), 'session_currency' => $this->session->data['currency'] ?? null, 'group' => $this->config->get('config_customer_group_id'), 'tax' => $this->config->get('config_tax'), 'stock_checkout' => $this->config->get('config_stock_checkout'), 'payment_address' => $this->session->data['payment_address'] ?? null, 'shipping_address' => $this->session->data['shipping_address'] ?? null, 'shipping_method' => $this->session->data['shipping_method'] ?? null, 'coupon' => $this->session->data['coupon'] ?? null, 'voucher' => $this->session->data['voucher'] ?? null, 'reward' => $this->session->data['reward'] ?? null);
	}
	/** Native amounts stay in base units; only financing DTOs cross this boundary. */
	public function currencyContext() {
		JetCreditFinancial::requireEur($this->config, $this->session, $this->currency);
		$base = $this->config->get('config_currency');
		if (!is_string($base) || !preg_match('/^[A-Z]{3}$/D', $base) || !$this->currency->has($base)) { throw new JetCreditFinancialException('invalid_base_currency'); }
		$baseValue = JetCreditFinancial::number($this->currency->getValue($base));
		$eurValue = JetCreditFinancial::number($this->currency->getValue('EUR'));
		if ($baseValue <= 0 || $eurValue <= 0) { throw new JetCreditFinancialException('invalid_currency_rate'); }
		$eur = $this->db->query("SELECT currency_id, status, value FROM " . DB_PREFIX . "currency WHERE code = 'EUR'");
		if ($eur->num_rows !== 1 || (int)$eur->row['status'] !== 1 || (int)$eur->row['currency_id'] !== (int)$this->currency->getId('EUR') || abs(JetCreditFinancial::number($eur->row['value']) - $eurValue) > 1.0e-8) { throw new JetCreditFinancialException('invalid_eur_currency'); }
		if ($base !== 'EUR') {
			$record = $this->db->query("SELECT currency_id, status, value FROM " . DB_PREFIX . "currency WHERE code = '" . $this->db->escape($base) . "'");
			if ($record->num_rows !== 1 || (int)$record->row['status'] !== 1 || abs(JetCreditFinancial::number($record->row['value']) - $baseValue) > 1.0e-8) { throw new JetCreditFinancialException('invalid_base_currency'); }
		}
		$rate = JetCreditFinancial::number($this->currency->convert(1, $base, 'EUR'));
		if ($rate <= 0) { throw new JetCreditFinancialException('invalid_currency_rate'); }
		return array('base'=>$base, 'session'=>'EUR', 'financing'=>'EUR', 'base_value'=>number_format($baseValue, 8, '.', ''), 'eur_value'=>number_format($eurValue, 8, '.', ''), 'rate'=>number_format($rate, 12, '.', ''), 'eur_id'=>(int)$eur->row['currency_id'], 'eur_status'=>1);
	}
	public function eurAmount($amount) { return number_format($this->toEur($amount), 2, '.', ''); }
	public function toEur($amount) {
		$context = $this->currencyContext();
		return JetCreditFinancial::number($this->currency->convert(JetCreditFinancial::number($amount), $context['base'], 'EUR'));
	}
	private function recipientConfiguration() {
		$value = $this->config->get('module_mt_jet_credit_mail');
		if (!is_string($value)) { throw new JetCreditFinancialException('invalid_configuration'); }
		return array('bank_recipients' => $value, 'merchant' => $this->config->get('module_mt_jet_credit_id'), 'sender' => $this->config->get('config_email'));
	}
	private function validateCsrf($input) {
		$expected = $this->session->data['jetcredit_csrf'] ?? null;
		if (!is_array($input) || !is_string($expected) || !is_string($input['jet_csrf'] ?? null) || !hash_equals($expected, $input['jet_csrf'])) { throw new JetCreditFinancialException('invalid_token'); }
	}
	private function enabled($key) { return in_array($this->config->get($key), array(1, '1'), true); }
}
