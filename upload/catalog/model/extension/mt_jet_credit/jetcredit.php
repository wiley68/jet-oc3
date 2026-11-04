<?php
class ModelExtensionMtJetCreditJetcredit extends Model {
	private const JETCREDIT_FINANCIAL_MAX_ITERATIONS = 128;
	private const JETCREDIT_FINANCIAL_PRECISION = 1.0e-08;

	// Product quotes are short-lived session data, independent of Cart and Checkout orders.
	private const PRODUCT_QUOTE_TTL = 1800;
	private const PRODUCT_QUOTE_LIMIT = 64;
	private $cartFailureCode = '';


	// Merchant core/extensions are trusted application code, not sandboxed by JetCredit.
	// Shared across model instances in this request; recursion invalidates the outer pass too.
	private static $cartEvaluations = [];
	public function evaluateCartNativeTotals() {
		$key = spl_object_hash($this->registry);
		if (isset(self::$cartEvaluations[$key])) {
			self::$cartEvaluations[$key] = true;
			return ['success'=>false, 'code'=>'evaluation_failed'];
		}
		self::$cartEvaluations[$key] = false;
		try {
			if (!defined('VERSION') || !preg_match('/^3\./', VERSION)) {
				throw new JetCartTotalsEvaluationFailure('unsupported_runtime');
			}
			$result = $this->cartRunNativeTotals($this->registry);
			if (self::$cartEvaluations[$key]) throw new JetCartTotalsEvaluationFailure('evaluation_failed');
			return $result;
		} catch (JetCartTotalsEvaluationFailure $error) {
			return ['success'=>false, 'code'=>$error->getMessage()];
		} catch (\Throwable $error) {
			return ['success'=>false, 'code'=>'evaluation_failed'];
		} finally {
			unset(self::$cartEvaluations[$key]);
		}
	}

	// Snapshot data only. Never invoke object serialization hooks or retain live references.
	private function cartSnapshotValue($value, $depth = 0) {
		if ($depth > 32 || is_object($value) || is_resource($value)) throw new JetCartTotalsEvaluationFailure('unsupported_state');
		if (!is_array($value)) return $value;
		$result = [];
		foreach ($value as $key => $item) $result[$key] = $this->cartSnapshotValue($item, $depth + 1);
		return $result;
	}

	private function cartRunNativeTotals($r) {
		// Prevent native warnings/deprecations from reaching the application's disk logger.
		set_error_handler(function ($severity, $message, $file, $line) {
			if ($severity === E_DEPRECATED) return true;
			throw new \ErrorException($message, 0, $severity, $file, $line);
		});
		try {
			$session_before = $r->get('session')->data;
			if (($session_before['currency'] ?? null) !== 'EUR') throw new JetCartTotalsEvaluationFailure('unsupported_currency');
			$cart = $r->get('cart');
			$products = $cart->getProducts();
			if (!$products || !empty($session_before['vouchers'])) throw new JetCartTotalsEvaluationFailure('unsupported_cart');
			$config = $r->get('config');
			if (!$r->get('customer')->isLogged() && $config->get('config_customer_price')) throw new JetCartTotalsEvaluationFailure('prices_hidden');
			$load = $r->get('load');
			$load->model('setting/extension');
			$results = $r->get('model_setting_extension')->getExtensions('total');
			$sort_order = [];

			foreach ($results as $key => $value) {
				if (!isset($value['code']) || !is_string($value['code']) || !preg_match('/^[a-zA-Z0-9_]+$/D', $value['code'])) throw new JetCartTotalsEvaluationFailure('unsupported_extension');
				$sort_order[$key] = $config->get('total_' . $value['code'] . '_sort_order');
			}
			if (!$results) throw new JetCartTotalsEvaluationFailure('missing_totals');
			array_multisort($sort_order, SORT_ASC, $results);
			$before = $this->cartAuthoritySnapshot($r, $products, $results);
			$totals = [];
			$taxes = $cart->getTaxes();
			$total = 0;
			$total_data = ['totals' => &$totals, 'taxes' => &$taxes, 'total' => &$total];
			foreach ($results as $result) {
				$code = $result['code'];
				if ($config->get('total_' . $code . '_status')) {
					$load->model('extension/total/' . $code);
					$r->get('model_extension_total_' . $code)->getTotal($total_data);
				}
			}
			if (!$totals) throw new JetCartTotalsEvaluationFailure('missing_totals');
			$sort_order = [];
			foreach ($totals as $key => $value) {
				if (!isset($value['value'], $value['sort_order']) || !is_numeric($value['value']) || !is_finite((float)$value['value'])) throw new JetCartTotalsEvaluationFailure('invalid_total');
				$sort_order[$key] = $value['sort_order'];
			}
			array_multisort($sort_order, SORT_ASC, $totals);
			$selected = end($totals); // Deliberately not code=total and not the running total.
			$base = $config->get('config_currency');
			$currency = $r->get('currency');
			foreach ([$base, 'EUR'] as $code) {
				$rate = $currency->getValue($code);
				if (!is_numeric($rate) || !is_finite((float)$rate) || $rate <= 0) throw new JetCartTotalsEvaluationFailure('invalid_currency');
			}
			$amount = $currency->convert($selected['value'], $base, 'EUR');
			if (!is_finite((float)$amount)) throw new JetCartTotalsEvaluationFailure('invalid_total');
			// One totals pass only. Re-read native products to detect a totals hook changing
			// the basket mid-pass; ordinary invalid-row cleanup has already run above.
			$after = $this->cartAuthoritySnapshot($r, $cart->getProducts(), $results);
			$comparison = $before;
			// Native voucher cleanup is accepted; no other relevant mid-pass changes are.
			if ($comparison['session']['voucher'] !== null && $after['session']['voucher'] === null) $comparison['session']['voucher'] = null;
			if ($comparison !== $after) throw new JetCartTotalsEvaluationFailure('evaluation_session_changed');
			return ['success' => true, 'code' => 'evaluated', 'base_currency' => $base,
				'base_amount' => $selected['value'], 'amount_eur' => $amount, 'totals' => $totals,
				'context' => $after];
		} finally {
			restore_error_handler();
		}
	}

	private function cartAuthoritySnapshot($r, $products, $extensions) {
		$config = $r->get('config'); $settings = [];
		foreach (['config_store_id','config_language_id','config_language','config_customer_group_id',
			'config_customer_price','config_currency','config_tax','config_country_id','config_zone_id','config_tax_default',
			'module_mt_jet_credit_status','module_mt_jet_credit_cart_show','module_mt_jet_credit_status_card',
			'module_mt_jet_credit_price','module_mt_jet_credit_vnoski','module_mt_jet_credit_purcent','module_mt_jet_credit_purcent_card'] as $key) $settings[$key] = $config->get($key);
		foreach ($extensions as $extension) {
			foreach (['status','sort_order'] as $suffix) {
				$key = 'total_' . $extension['code'] . '_' . $suffix;
				$settings[$key] = $config->get($key);
			}
		}
		$session = [];
		foreach (['currency','api_id','customer_id','guest','payment_address','shipping_address','shipping_method','coupon','voucher','reward','vouchers'] as $key) {
			$session[$key] = $r->get('session')->data[$key] ?? null;
		}
		$rows = [];
		foreach ($products as $product) {
			$row = [];
			foreach (['cart_id','product_id','quantity','price','total','tax_class_id','option','name','model','subtract','reward','points','stock','minimum','shipping'] as $key) $row[$key] = $product[$key] ?? null;
			$rows[] = $row;
		}
		$tax_rates = [];
		foreach ($products as $product) $tax_rates[] = $r->get('tax')->getRates($product['price'], $product['tax_class_id']);
		return $this->cartSnapshotValue(['products'=>$rows, 'settings'=>$settings, 'session'=>$session, 'session_id'=>$r->get('session')->getId(),
			'customer_id'=>$r->get('customer')->getId(), 'extensions'=>$extensions,
			'tax_rates'=>$tax_rates,
			'rates'=>[$r->get('currency')->getValue($config->get('config_currency')), $r->get('currency')->getValue('EUR')],
			]);
	}

	public function getCartContext() {
		$evaluation = $this->evaluateCartNativeTotals();
		$this->cartFailureCode = $evaluation['code'] ?? 'evaluation_failed';
		if (empty($evaluation['success']) || !isset($evaluation['context'])) return false;
		if (!$this->config->get('module_mt_jet_credit_status') || !$this->config->get('module_mt_jet_credit_cart_show')) { $this->cartFailureCode='configuration_changed'; return false; }
		$raw = (float)$evaluation['amount_eur'];
		if (!is_finite($raw) || $raw <= 0) { $this->cartFailureCode='invalid_total'; return false; }
		$ids = $quantities = $prices = $row_ids = $options = [];
		foreach ($evaluation['context']['products'] as $index => $product) {
			if (!$this->productId($product['cart_id']) || !$this->productId($product['product_id']) || !$this->productId($product['quantity'])) return false;
			$ids[] = (string)$product['product_id']; $quantities[] = (string)$product['quantity']; $row_ids[] = (string)$product['cart_id'];
			$price = $this->currency->convert($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->config->get('config_currency'), 'EUR');
			if (!is_finite((float)$price) || $price < 0) return false;
			$prices[] = (string)$price;
			foreach ($product['option'] as $key => $option) {
				foreach (['product_option_id','product_option_value_id','option_id','option_value_id','name','value','type'] as $field) {
					if (!array_key_exists($field,$option)) return false;
					$options[$index][$key][$field] = $option[$field];
				}
			}
		}
		// Preserve the legacy Cart order-option projection, including its exact cleaning layer.
		$encoded = json_encode($options);
		if ($encoded === false) return false;
		$order_options = json_decode(html_entity_decode(filter_var(htmlspecialchars($encoded, ENT_COMPAT, 'UTF-8'), FILTER_SANITIZE_STRING)), true);
		if (!is_array($order_options)) return false;
		return ['raw_amount'=>$raw, 'browser_raw'=>(float)(string)$raw, 'amount'=>$this->cartFixed((float)(string)$raw),
			'products_key'=>implode('_',$ids), 'products_qt'=>implode('_',$quantities), 'products_pr'=>implode('_',$prices),
			'cart_rows'=>implode('_',$row_ids), 'options'=>$options, 'order_options'=>$order_options, 'products'=>$evaluation['context']['products'],
			'fingerprint'=>hash('sha256',serialize([$evaluation,$prices]))];
	}

	public function getCartFailureCode() { return $this->cartFailureCode; }

	public function cartFixed($value) {
		if (abs($value) >= 1e21) return str_replace('.0e','e',strtolower(json_encode($value)));
		return $this->checkoutFixed($value);
	}

	public function cartTransportMatches($context, $post) {
		foreach (['products'=>'products_key','products_qt'=>'products_qt','cart_rows'=>'cart_rows'] as $field=>$key) {
			if (!isset($post['jet_'.$field]) || !is_string($post['jet_'.$field]) || $post['jet_'.$field] !== $context[$key]) return false;
		}
		if (!isset($post['jet_products_pr']) || !is_string($post['jet_products_pr'])) return false;
		$given = explode('_',$post['jet_products_pr']); $expected = explode('_',$context['products_pr']);
		if (count($given)!==count($expected)) return false;
		foreach ($given as $i=>$value) if ($this->productNumber($value) !== (float)$expected[$i]) return false;
		if (!isset($post['jet_products_vr']) || !is_string($post['jet_products_vr'])) return false;
		return json_decode(htmlspecialchars_decode($post['jet_products_vr'], ENT_COMPAT),true) === $context['options'] && json_last_error()===JSON_ERROR_NONE;
	}

	public function getCartPromotion($key, $term, $principal) {
		$result = $this->cartPromotionResult($key, $term, $principal);
		$promotion = $result['promotion'];
		$row = $result['evidence'];
		return ['promotion'=>$promotion, 'fingerprint'=>hash('sha256', serialize([$key,$term,$principal,$promotion,$row]))];
	}

	public function findCartQuote($reference) {
		if (!is_string($reference) || !preg_match('/^[a-f0-9]{48}$/D',$reference)) return false;
		$q = $this->session->data['jet_cart_quotes'][$reference] ?? null;
		return is_array($q) && isset($q['quote']) && ($q['expires'] ?? 0)>time() ? $q : false;
	}

	public function storeCartQuote($context, $quote, $promotion, $post) {
		$quotes = $this->session->data['jet_cart_quotes'] ?? [];
		if (!is_array($quotes)) return false;
		foreach ($quotes as $ref=>$q) if (!is_array($q) || ($q['expires'] ?? 0)<=time()) unset($quotes[$ref]);
		$previous = $post['jet_previous_quote'] ?? '';
		$lineage = $post['jet_cart_lineage'] ?? '';
		if (!is_string($previous) || !is_string($lineage)) return false;
		if ($previous !== '') {
			if (!isset($quotes[$previous]) || !hash_equals($quotes[$previous]['lineage'],$lineage)) return false;
			// Keep a bounded tombstone so concurrent calculations can reference their own predecessor.
			unset($quotes[$previous]['quote'], $quotes[$previous]['context'], $quotes[$previous]['promotion']);
		} elseif ($lineage !== '') return false;
		$active = array_filter($quotes,function($q){return isset($q['quote']);});
		if (count($active)>=64) return false;
		if ($previous === '') $lineage = bin2hex(random_bytes(24));
		while (count($quotes)>=128) {
			$removed=false;
			foreach ($quotes as $ref=>$q) if (!isset($q['quote'])) {unset($quotes[$ref]);$removed=true;break;}
			if (!$removed) return false;
		}
		$reference = bin2hex(random_bytes(24));
		$quotes[$reference] = ['expires'=>time()+1800,'lineage'=>$lineage,'context'=>$context,'quote'=>$quote,'promotion'=>$promotion];
		$this->session->data['jet_cart_quotes'] = $quotes;
		return ['jet_quote_ref'=>$reference,'jet_cart_lineage'=>$lineage];
	}

	public function productNumber($value, $blank_zero = false) {
		if ($blank_zero && is_string($value) && trim($value) === '') return 0.0;
		if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
		if (is_string($value) && !preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', trim($value))) return null;
		return is_finite((float)$value) ? (float)$value : null;
	}

	private function productId($value) {
		if ((!is_int($value) && !is_string($value)) || !ctype_digit((string)$value)) return false;
		$number = (float)$value;
		return $number > 0 && $number <= 2147483647 ? (int)$value : false;
	}

	public function productSelections($encoded) {
		if (!is_string($encoded)) return false;
		// Undo exactly the single ENT_COMPAT layer added by native Request::clean().
		$entries = json_decode(htmlspecialchars_decode($encoded, ENT_COMPAT), true);
		if (!is_array($entries) || ($entries && array_keys($entries) !== range(0, count($entries) - 1))) return false;
		$options = [];
		foreach ($entries as $entry) {
			if (!is_array($entry) || !is_string($entry['name'] ?? null) || !is_string($entry['value'] ?? null)
				|| !preg_match('/^option\[([1-9]\d*)\](\[\])?$/D', $entry['name'], $match)) return false;
			$id = $this->productId($match[1]);
			if (!$id) return false;
			if (!empty($match[2])) {
				if (isset($options[$id]) && !is_array($options[$id])) return false;
				$options[$id][] = $entry['value'];
			} else {
				if (array_key_exists($id, $options)) return false;
				$options[$id] = $entry['value'];
			}
		}
		return $options;
	}

	public function getProductContext($product_id, $quantity, $selections) {
		$id = $this->productId($product_id);
		$quantity = $this->productId($quantity);
		if (!$id || !$quantity || !is_array($selections) || !$this->config->get('module_mt_jet_credit_status')
			|| ($this->session->data['currency'] ?? '') !== 'EUR'
			|| ($this->config->get('config_customer_price') && !$this->customer->isLogged())) return false;
		$this->load->model('catalog/product');
		$product = $this->model_catalog_product->getProduct($id);
		if (!$product || $product['quantity'] <= 0) return false;
		$base_currency = $this->config->get('config_currency');
		foreach ([$base_currency, 'EUR'] as $code) {
			$rate = $this->productNumber($this->currency->getValue($code));
			if ($rate === null || $rate <= 0) return false;
		}
		$eur = function ($price) use ($product, $base_currency) {
			$number = $this->productNumber($price);
			if ($number === null) throw new \RuntimeException('Invalid Product price');
			$number = $this->currency->convert($this->tax->calculate($number, $product['tax_class_id'], $this->config->get('config_tax')), $base_currency, 'EUR');
			if (!is_finite($number)) throw new \RuntimeException('Invalid Product conversion');
			return $number;
		};
		// The base enters JS through Twig/PHP string conversion; option components enter through JSON.
		$base = $eur((float)$product['special'] ? $product['special'] : $product['price']);
		$browser_base = (float)(string)$base;
		$definitions = $this->model_catalog_product->getProductOptions($id);
		$known = [];
		foreach ($definitions as $definition) $known[(string)$definition['product_option_id']] = true;
		foreach ($selections as $key => $value) if (!isset($known[(string)$key])) return false;
		$options = $errors = $order_options = [];
		$adjustment = 0.0;
		foreach ($definitions as $definition) {
			$option_id = $definition['product_option_id'];
			$type = $definition['type'];
			$value = $selections[$option_id] ?? null;
			if (!in_array($type, ['select', 'radio', 'checkbox', 'text', 'textarea', 'file', 'date', 'datetime', 'time'], true)) return false;
			if ($value !== null && (($type === 'checkbox' && !is_array($value)) || ($type !== 'checkbox' && !is_string($value)))) return false;
			if ($definition['required'] && empty($value)) $errors[$option_id] = sprintf('%s задължително!', $definition['name']);
			$converted = $definition;
			foreach ($converted['product_option_value'] as &$component) $component['price'] = $eur($component['price']);
			unset($component);
			$converted['product_option_id_check'] = empty($value) ? null : $value;
			$options[] = $converted;
			// Native array_filter/empty semantics, including optional "0", are retained.
			if (empty($value)) continue;
			if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
				$chosen = $type === 'checkbox' ? $value : [$value];
				$seen = [];
				foreach ($chosen as $index => $choice) {
					$choice_id = $this->productId($choice);
					if (!$choice_id || isset($seen[$choice_id])) return false;
					$seen[$choice_id] = true;
					$found = false;
					foreach ($converted['product_option_value'] as $component) {
						if ((int)$component['product_option_value_id'] !== $choice_id) continue;
						if (!empty($component['subtract']) && $component['quantity'] <= 0) return false;
						$found = true;
						// Match the JSON number consumed by the existing browser option calculation.
						$browser_component = (float)json_decode(json_encode($component['price']));
						$adjustment += (($component['price_prefix'] ?? '') === '-' ? -1 : 1) * $browser_component;
						// Preserve the existing priced-option output shape; free option output repair is excluded.
						$order_options[] = ['product_option_id' => $option_id, 'product_option_value_id' => $component['product_option_value_id'],
							'option_id' => $type === 'checkbox' ? $index : 0, 'option_value_id' => $component['option_value_id'],
							'name' => $definition['name'], 'value' => $component['name'], 'type' => $type];
						break;
					}
					if (!$found) return false;
				}
			} elseif ($type === 'file') {
				$this->load->model('tool/upload');
				if (!$this->model_tool_upload->getUploadByCode($value)) return false;
			}
		}
		if ($errors) return ['error' => ['option' => $errors]];
		$raw_amount = ($browser_base + $adjustment) * $quantity;
		if (!is_finite($raw_amount) || $raw_amount <= 0 || $raw_amount >= 1e21) return false;
		$amount = $this->checkoutFixed($raw_amount);
		$customer = $this->customer->isLogged() ? (int)$this->customer->getId() : 0;
		$settings = [];
		foreach (['config_store_id', 'config_language_id', 'config_customer_group_id', 'config_customer_price', 'config_currency', 'config_tax',
			'config_country_id', 'config_zone_id', 'module_mt_jet_credit_status', 'module_mt_jet_credit_status_card', 'module_mt_jet_credit_price'] as $key) $settings[$key] = $this->config->get($key);
		$addresses = [];
		foreach (['payment_address', 'shipping_address'] as $key) {
			foreach (['country_id', 'zone_id', 'postcode'] as $field) $addresses[$key][$field] = $this->session->data[$key][$field] ?? null;
		}
		$source_product = [];
		foreach (['price', 'special', 'quantity', 'tax_class_id', 'name', 'model', 'subtract', 'reward'] as $key) $source_product[$key] = $product[$key] ?? null;
		$fingerprint = hash('sha256', serialize([$id, $quantity, $selections, $source_product, $definitions, $options, $base, $settings, $addresses, $customer,
			$this->currency->getValue($base_currency), $this->currency->getValue('EUR')]));
		$order_options = json_decode(html_entity_decode(filter_var(json_encode($order_options), FILTER_SANITIZE_STRING)), true);
		if (!is_array($order_options)) return false;
		return ['product_id' => $id, 'quantity' => $quantity, 'product' => $product, 'selections' => $selections,
			'optionresult' => $options, 'order_options' => $order_options, 'raw_amount' => $raw_amount, 'amount' => $amount, 'fingerprint' => $fingerprint];
	}

	public function normalizeProductDownpayment($raw) {
		$number = $this->productNumber($raw, true);
		if ($number === null || $number < 0 || $number >= 1e21) return false;
		return $this->checkoutFixed($number);
	}

	private function pruneProductQuotes() {
		$quotes = $this->session->data['jet_product_quotes'] ?? [];
		if (!is_array($quotes)) $quotes = [];
		foreach ($quotes as $reference => $quote) if (!is_array($quote) || ($quote['expires'] ?? 0) <= time()) unset($quotes[$reference]);
		$this->session->data['jet_product_quotes'] = $quotes;
	}

	public function storeProductQuote($context, $quote, $previous = null) {
		$this->pruneProductQuotes();
		$quotes =& $this->session->data['jet_product_quotes'];
		$replace = is_string($previous) && isset($quotes[$previous]);
		if (count($quotes) - ($replace ? 1 : 0) >= self::PRODUCT_QUOTE_LIMIT) return false;
		$reference = bin2hex(random_bytes(24));
		$quotes[$reference] = ['expires' => time() + self::PRODUCT_QUOTE_TTL, 'fingerprint' => $context['fingerprint'], 'quote' => $quote];
		if ($replace) unset($quotes[$previous]);
		return $reference;
	}

	public function findProductQuote($reference) {
		$this->pruneProductQuotes();
		if (!is_string($reference) || !preg_match('/^[a-f0-9]{48}$/D', $reference)) return false;
		return $this->session->data['jet_product_quotes'][$reference] ?? false;
	}

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
		$result = $this->cartPromotionResult($jet_product_id, $jet_vnoski, $jet_total_credit_price);
		return $result['promotion'];
	}

	// Explicit per-call evidence for Cart; the public promotion API stays unchanged.
	private function cartPromotionResult($jet_product_id, $jet_vnoski, $jet_total_credit_price) {
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

		return ['evidence' => $row, 'promotion' => [
			'jet_show_button' => $jet_show_button,
			'jet_purcent' => $jet_purcent,
			'jet_purcent_card' => $jet_purcent_card
		]];
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

// Internal financial validation failure, not an executable-code sandbox.
final class JetCartTotalsEvaluationFailure extends \RuntimeException {}
