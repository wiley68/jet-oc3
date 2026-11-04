<?php
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/PHPMailer.php';
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/SMTP.php';
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class ControllerExtensionMtJetCreditJetCredit extends Controller {
	private const JETCREDIT_FINANCIAL_MAX_ITERATIONS = 128;
	private const JETCREDIT_FINANCIAL_PRECISION = 1.0e-08;
	private const JET_MIN_250_EUR = 125;

	public function jet_check() {
		$json = ['success' => 'unsuccess'];
		try {
			$this->load->model('extension/mt_jet_credit/jetcredit');
			$context = $this->model_extension_mt_jet_credit_jetcredit->getProductContext($this->request->post['product_id'] ?? null,
				$this->request->post['quantity'] ?? 1, $this->request->post['option'] ?? []);
			if ($context) $json = isset($context['error']) ? $context : ['success' => 1, 'optionresult' => $context['optionresult']];
		} catch (\Throwable $error) {}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function jet_calculate() {
		$json = ['success' => 'unsuccess'];
		try {
			$this->load->model('extension/mt_jet_credit/jetcredit');
			$context = $this->productRequestContext($this->request->post);
			$quote = $context ? $this->productCalculate($context, $this->request->post) : false;
			if ($quote) {
				$json = $quote;
				if ($quote['jet_show_button']) {
					$intent = $this->submissionContext('product', true);
					if ($intent['row']['state'] !== 'draft') { $this->submissionReview(); return; }
					$reference = $this->model_extension_mt_jet_credit_jetcredit->storeProductQuote($context, $quote, $this->request->post['jet_previous_quote'] ?? null, $intent['id']);
					$json = $reference ? $quote + ['jet_quote_ref' => $reference, 'jet_intent_id' => $intent['id']] : ['success' => 'unsuccess'];
				}
			}
		} catch (\Throwable $error) { $json = ['success'=>'unsuccess']; }
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	protected function submissionService() {
		require_once DIR_SYSTEM . 'library/jet_submission.php';
		return new JetSubmission(JetSubmissionStore::connect());
	}

	protected function submissionOwner() {
		$id = $this->session->getId();
		if (!is_string($id) || $id === '') throw new \RuntimeException('Session identity unavailable.');
		return $id . ':' . ($this->customer->isLogged() ? (int)$this->customer->getId() : 0);
	}

	private function submissionOutput($json) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function submissionReview() {
		$this->submissionOutput(['success'=>'unsuccess','jet_submission_review'=>true]);
	}

	private function submissionContext($family, $create = false) {
		$service = $this->submissionService();
		$store = (string)(int)$this->config->get('config_store_id');
		$owner = $this->submissionOwner();
		$id = $this->request->post['jet_intent_id'] ?? '';
		if ($create && $id === '') {
			// No content-based deduplication: each independent form gets its own intent.
			$row = $service->createIntent($store, $owner, $family, $family);
			$id = $row['intent_id'];
		} else {
			$row = $service->resolve($id, $store, $owner);
		}
		if ($row['family'] !== $family) throw new \RuntimeException('Intent family mismatch.');
		return ['service'=>$service,'id'=>$id,'store'=>$store,'owner'=>$owner,'row'=>$row,'family'=>$family];
	}

	private function submissionChannel() {
		$card = $this->request->post['jet_card'] ?? null;
		if (!in_array($card, [0,1,'0','1'], true)) throw new \RuntimeException('Invalid channel.');
		return (int)$card === 1 ? 'jet_card' : 'jet';
	}

	private function submissionReplay($execution) {
		$row = $execution['service']->replay($execution['id'], $execution['store'], $execution['owner'], $this->submissionChannel(), $this->request->post);
		if ($row['state'] !== 'completed') { $this->submissionReview(); return; }
		$json = [];
		if (!empty($row['native_order_id'])) $json['order_id'] = (int)$row['native_order_id'];
		$json['code'] = $row['result_code'];
		$json['notifications'] = ['admin'=>$row['admin_state']==='done','customer'=>$row['customer_state']==='done'];
		$json['success'] = in_array($row['result_code'], ['bank_sent','manual_registered'], true) ? 'success' : 'unsuccess';
		if (in_array($row['history_state'], ['failed','uncertain'], true)) $json['order_status_updated'] = false;
		if (in_array($row['clear_state'], ['failed','uncertain'], true)) $json['cart_cleared'] = false;
		$this->submissionOutput($json);
	}

	private function submissionClaim(&$execution) {
		$claim = $execution['service']->claim($execution['id'], $execution['store'], $execution['owner'], $this->submissionChannel(), $this->request->post);
		if (!$claim['acquired']) { $this->submissionReplay($execution); return false; }
		$execution['token'] = $claim['execution_token'];
		$execution['reference'] = $claim['row']['reference_no'];
		return true;
	}

	private function submissionAction($execution, $method, ...$args) {
		return $execution['service']->$method($execution['id'], $execution['store'], $execution['owner'], $execution['token'], ...$args);
	}

	private function submissionFinish($execution, $code) {
		if ($code === 'bank_uncertain') {
			$this->submissionAction($execution, 'uncertain');
			return;
		}
		if ($code === 'bank_failed') {
			$this->submissionAction($execution, 'skipAction', 'history');
			if ($execution['family'] === 'cart') $this->submissionAction($execution, 'skipAction', 'clear');
		}
		$this->submissionAction($execution, 'complete', $code);
	}

	private function submissionFailed($execution, &$json) {
		$json['success'] = 'unsuccess';
		$json['jet_submission_review'] = true;
		try { $this->submissionAction($execution, 'uncertain'); } catch (\Throwable $ignored) {}
	}

	private function productRequestContext($post) {
		$model = $this->model_extension_mt_jet_credit_jetcredit;
		$options = $model->productSelections($post['jet_options'] ?? null);
		if ($options === false) return false;
		$context = $model->getProductContext($post['jet_product_id'] ?? null, $post['jet_quantity'] ?? null, $options);
		return $context && !isset($context['error']) ? $context : false;
	}

	private function productCalculate($context, $post) {
		$model = $this->model_extension_mt_jet_credit_jetcredit;
		$submitted = $model->productNumber($post['jet_priceall'] ?? null);
		$down = $model->normalizeProductDownpayment(array_key_exists('jet_downpayment_raw', $post) ? $post['jet_downpayment_raw'] : ($post['jet_parva'] ?? null));
		$term = $model->productNumber($post['jet_vnoski'] ?? null);
		$displayed_down = $model->productNumber($post['jet_parva'] ?? null, true);
		if ($submitted === null || $submitted !== (float)$context['amount'] || $down === false || $displayed_down !== (float)$down
			|| !in_array($term, [3.0, 6.0, 9.0, 10.0, 12.0, 15.0, 18.0, 24.0, 30.0, 36.0], true)
			|| ($context['raw_amount'] < 401 && in_array($term, [15.0, 18.0, 24.0], true))
			|| ($context['raw_amount'] < 601 && in_array($term, [30.0, 36.0], true))
			|| (float)$down >= (float)$context['amount']) return false;
		$jet_priceall = (float)$context['amount'];
		$jet_parva = (float)$down;
		$jet_vnoski = (int)$term;
		$jet_product_id = $context['product_id'];
		$json = [];
		$_minprice = (float)$this->config->get('module_mt_jet_credit_price');
		$jet_total_credit_price = $jet_priceall - $jet_parva;
		$this->load->model('extension/mt_jet_credit/jetcredit');
		$jet_purcent = (float) $this->config->get('module_mt_jet_credit_purcent');
		$jet_purcent_card = (float)$this->config->get('module_mt_jet_credit_purcent_card');
		$jet_show_button = true;
		$jet_promo = $this->model_extension_mt_jet_credit_jetcredit->getPromo( $jet_product_id, $jet_vnoski, $jet_total_credit_price );
		$jet_show_button = (bool) $jet_promo['jet_show_button'];
		if ($_minprice > $jet_total_credit_price) {
			$jet_show_button = false;
		}
		$jet_purcent = (float) $jet_promo['jet_purcent'];
		$jet_purcent_card = (float) $jet_promo['jet_purcent_card'];
		$jet_vnoska = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent) / 100));
		$jet_vnoska_card = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent_card) / 100));
		$jet_gprm = $this->RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12;
		$jet_glp = ($this->RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12) * 100;
		$jet_gpr = (pow((1 + $jet_gprm / 12), 12) - 1) * 100;
		$jet_obshto = $jet_vnoska * $jet_vnoski;
		$jet_gprm_card = $this->RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12;
		$jet_glp_card = ($this->RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12) * 100;
		$jet_gpr_card = (pow((1 + $jet_gprm_card / 12), 12) - 1) * 100;
		$jet_obshto_card = $jet_vnoska_card * $jet_vnoski;
		$jet_vnoska_second = 0;
		$jet_vnoska_card_second = 0;
		$jet_priceall_second = $jet_priceall;
		$jet_total_credit_price_second = $jet_total_credit_price;
		$jet_obshto_second = $jet_obshto;
		$jet_obshto_card_second = $jet_obshto_card;

		foreach ([$jet_vnoska, $jet_vnoska_card, $jet_gpr, $jet_glp, $jet_obshto, $jet_gpr_card, $jet_glp_card, $jet_obshto_card] as $number) if (!is_finite($number)) return false;
		$json['success'] = 'success';
		$json['jet_show_button'] = $jet_show_button;
		$json['jet_vnoska'] = number_format($jet_vnoska, 2, ".", "");
		$json['jet_vnoska_second'] = number_format($jet_vnoska_second, 2, ".", "");
		$json['jet_priceall'] = number_format($jet_priceall, 2, ".", "");
		$json['jet_priceall_second'] = number_format($jet_priceall_second, 2, ".", "");
		$json['jet_total_credit_price'] = number_format($jet_total_credit_price, 2, ".", "");
		$json['jet_total_credit_price_second'] = number_format($jet_total_credit_price_second, 2, ".", "");
		$json['jet_gpr'] = number_format($jet_gpr, 2, ".", "");
		$json['jet_glp'] = number_format($jet_glp, 2, ".", "");
		$json['jet_obshto'] = number_format($jet_obshto, 2, ".", "");
		$json['jet_obshto_second'] = number_format($jet_obshto_second, 2, ".", "");
		$json['jet_vnoska_card'] = number_format($jet_vnoska_card, 2, ".", "");
		$json['jet_vnoska_card_second'] = number_format($jet_vnoska_card_second, 2, ".", "");
		$json['jet_gpr_card'] = number_format($jet_gpr_card, 2, ".", "");
		$json['jet_glp_card'] = number_format($jet_glp_card, 2, ".", "");
		$json['jet_obshto_card'] = number_format($jet_obshto_card, 2, ".", "");
		$json['jet_obshto_card_second'] = number_format($jet_obshto_card_second, 2, ".", "");
		$json['jet_parva'] = $down;
		$json['jet_vnoski'] = $jet_vnoski;
		return $json;
	}

	private function validateProductSubmission($post) {
		$context = $this->productRequestContext($post);
		if (!$context) return false;
		$model = $this->model_extension_mt_jet_credit_jetcredit;
		$saved = $model->findProductQuote($post['jet_quote_ref'] ?? null);
		if (!$saved || ($saved['intent_id'] ?? null) !== ($post['jet_intent_id'] ?? null) || !hash_equals($saved['fingerprint'], $context['fingerprint'])) return false;
		$quote = $this->productCalculate($context, $post);
		if (!$quote || !$quote['jet_show_button'] || $quote !== $saved['quote']) return false;
		$card = $post['jet_card'] ?? null;
		if (!in_array($card, [0, 1, '0', '1'], true) || ((int)$card === 1 && !$this->config->get('module_mt_jet_credit_status_card'))) return false;
		$suffix = (int)$card === 1 ? '_card' : '';
		foreach (['vnoska', 'total_credit_price', 'obshto', 'gpr', 'glp'] as $field) {
			$value = $model->productNumber($post['jet_' . $field] ?? null);
			if ($value === null || $value !== (float)$quote['jet_' . $field . ($field === 'total_credit_price' ? '' : $suffix)]) return false;
		}
		if ((float)$quote['jet_vnoska' . $suffix] < 20) return false;
		return ['context' => $context, 'quote' => $quote, 'card' => (int)$card, 'suffix' => $suffix];
	}

	public function jet_send() {
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		if(!empty((string)($this->request->post['jet_lname'] ?? ''))) die();
		$this->load->model('extension/mt_jet_credit/jetcredit');
		try {
			$execution = $this->submissionContext('product');
			if ($execution['row']['state'] !== 'draft') { $this->submissionReplay($execution); return; }
		} catch (\Throwable $error) { $this->submissionReview(); return; }
		try { $verified = $this->validateProductSubmission($this->request->post); }
		catch (\Throwable $error) { $verified = false; }
		if (!$verified) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => 'unsuccess', 'jet_quote_invalid' => true]));
			return;
		}

		try { if (!$this->submissionClaim($execution)) return; }
		catch (\Throwable $error) { $this->submissionReview(); return; }
		$json = [];
		try {
		$quote = $verified['quote'];
		$suffix = $verified['suffix'];
		$jet_priceall = $quote['jet_priceall'];
		$jet_vnoski = $quote['jet_vnoski'];
		$jet_vnoska = $quote['jet_vnoska' . $suffix];
		$jet_parva = $quote['jet_parva'];
		$jet_total_credit_price = $quote['jet_total_credit_price'];
		$jet_obshto = $quote['jet_obshto' . $suffix];
		$jet_gpr = $quote['jet_gpr' . $suffix];
		$jet_glp = $quote['jet_glp' . $suffix];
		if (isset($this->request->post['jet_name'])) {
			$jet_name = filter_var($this->request->post['jet_name'], FILTER_SANITIZE_STRING);
		} else {
			$jet_name = " - ";
		}
		if (isset($this->request->post['jet_lastname'])) {
			$jet_lastname = filter_var($this->request->post['jet_lastname'], FILTER_SANITIZE_STRING);
		} else {
			$jet_lastname = " - ";
		}
		if (isset($this->request->post['jet_egn'])) {
			$jet_egn = filter_var($this->request->post['jet_egn'], FILTER_SANITIZE_STRING);
		} else {
			$jet_egn = " - ";
		}
		if (isset($this->request->post['jet_email'])) {
			$jet_email = filter_var($this->request->post['jet_email'], FILTER_SANITIZE_STRING);
		} else {
			$jet_email = " - ";
		}
		if (isset($this->request->post['jet_phone'])) {
			$jet_phone = filter_var($this->request->post['jet_phone'], FILTER_SANITIZE_STRING);
		} else {
			$jet_phone = " - ";
		}
		$jet_card = $verified['card'];
		$jet_product_id = (string)$verified['context']['product_id'];
		$jet_quantity = (string)$verified['context']['quantity'];
		$jet_variations = $verified['context']['order_options'];
		$toEmail_admin = (string)$this->config->get('config_email');
		$toEmail_other = (string)$this->config->get('module_mt_jet_credit_mail');
		$jet_id = (string)$this->config->get('module_mt_jet_credit_id');
		if ($jet_card == 1) {
			$payment_method = 'ПБ Лични Финанси на вноски с кредитна карта';
			$payment_code = 'jet_card';
		} else {
			$payment_method = 'ПБ Лични Финанси';
			$payment_code = 'jet';
		}
		$this->load->model('catalog/category');
		$this->load->model('catalog/product');
		$product = $verified['context']['product'];
		$categories = $this->model_catalog_product->getCategories($jet_product_id);
		$product_c_txt = " - ";
		foreach ($categories as $category) {
			$category_info = $this->model_catalog_category->getCategory($category['category_id']);
			if ($category_info) {
				$product_c_txt = $category_info['name'];
			}
		}
		$product_m_txt = $product['name'];
		if (count($jet_variations) > 0) {
			$product_m_txt .= ' - ';
			foreach ($jet_variations as $jet_variation) {
				$product_m_txt .= $jet_variation['value'] . ', ';
			}
			$product_m_txt = substr($product_m_txt, 0, -2);
		}
		if ($product_m_txt == "") {
			$product_m_txt = " - ";
		}
		$product_p_txt = $jet_priceall / $jet_quantity;

		$jet_sign = 'евро';
		$body = "Данни за потребителя:\r\n";
		$body .= "Собствено име: $jet_name;\r\n";
		$body .= "Фамилия: $jet_lastname;\r\n";
		$body .= "ЕГН: $jet_egn;\r\n";
		$body .= "Телефон за връзка: $jet_phone;\r\n";
		$body .= "Имейл адрес: $jet_email;\r\n\r\n";
        
		$body .= "Данни за стоката:\r\n";
		$body .= "Тип стока: " . $product_c_txt . ";\r\n";
		$body .= "Марка: " . "(" . $jet_product_id . ") " . $product_m_txt . ";\r\n";
		$body .= "Единична цена с ДДС: " . number_format($product_p_txt, 2, ".", "") . ";\r\n";
		$body .= "Брой стоки: " . $jet_quantity . ";\r\n";
		$body .= "Обща сума с ДДС: " . number_format($jet_priceall, 2, ".", "") . ";\r\n\r\n";
        
		if ($jet_card == 1) {
			$body .= "Тип стока: Кредитна Карта;\r\n";
			$body .= "Марка: -;\r\n";
			$body .= "Единична цена с ДДС: 0.00;\r\n";
			$body .= "Брой стоки: 1;\r\n";
			$body .= "Обща сума с ДДС: 0.00;\r\n\r\n";
		}
        
		$body .= "Данни за кредита:\r\n";
		$body .= "Размер на кредита: " . number_format($jet_priceall - $jet_parva, 2, '.', '') . ";\r\n";
		$body .= "Срок на изплащане в месеца: $jet_vnoski;\r\n";
		$body .= "Месечна вноска: $jet_vnoska;\r\n";
		$body .= "Първоначална вноска: " . number_format(floatval($jet_parva), 2, ".", "") . ";\r\n";
		$jet_count = $execution['reference'];
		$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
		$ccAddresses = trim($toEmail_other) === '' ? [] : explode(",", str_replace(' ', '', $toEmail_other));
		try {
				$order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
				$order_data['store_id'] = $this->config->get('config_store_id');
				$order_data['store_name'] = $this->config->get('config_name');
				if ($order_data['store_id']) {
					$order_data['store_url'] = $this->config->get('config_url');
				} else {
					if ($this->request->server['HTTPS']) {
						$order_data['store_url'] = HTTPS_SERVER;
					} else {
						$order_data['store_url'] = HTTP_SERVER;
					}
				}
				if ($this->customer->isLogged()) {
					$this->load->model('account/customer');
					$customer_info = $this->model_account_customer->getCustomer($this->customer->getId());
					$order_data['customer_id'] = $this->customer->getId();
					$order_data['customer_group_id'] = $customer_info['customer_group_id'];
					$order_data['firstname'] = $customer_info['firstname'];
					$order_data['lastname'] = $customer_info['lastname'];
					$order_data['email'] = $customer_info['email'];
					$order_data['telephone'] = $customer_info['telephone'];
					$order_data['fax'] = $customer_info['fax'];
					$order_data['custom_field'] = json_decode($customer_info['custom_field'], true);
				} elseif (isset($this->session->data['guest'])) {
					$order_data['customer_id'] = 0;
					$order_data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
					$order_data['firstname'] = $this->session->data['guest']['firstname'];
					$order_data['lastname'] = $this->session->data['guest']['lastname'];
					$order_data['email'] = $this->session->data['guest']['email'];
					$order_data['telephone'] = $this->session->data['guest']['telephone'];
					$order_data['fax'] = $this->session->data['guest']['fax'];
					$order_data['custom_field'] = $this->session->data['guest']['custom_field'];
				} else {
					$order_data['customer_id'] = 0;
					$order_data['customer_group_id'] = 0;
					$order_data['firstname'] = $jet_name;
					$order_data['lastname'] = $jet_lastname;
					$order_data['email'] = $jet_email;
					$order_data['telephone'] = $jet_phone;
					$order_data['fax'] = "";
					$order_data['custom_field'] = "";
				}
				$order_data['payment_firstname'] = $jet_name;
				$order_data['payment_lastname'] = $jet_lastname;
				$order_data['payment_company'] = "";
				$order_data['payment_address_1'] = '';
				$order_data['payment_address_2'] = '';
				$order_data['payment_city'] = '';
				$order_data['payment_postcode'] = "";
				$order_data['payment_zone'] = "";
				$order_data['payment_zone_id'] = "";
				$order_data['payment_country'] = "";
				$order_data['payment_country_id'] = $this->config->get('config_country_id');
				$order_data['payment_address_format'] = "";
				$order_data['payment_custom_field'] = [];
				$order_data['payment_method'] = $payment_method;
				$order_data['payment_code'] = $payment_code;
				$order_data['shipping_firstname'] = $jet_name;
				$order_data['shipping_lastname'] = $jet_lastname;
				$order_data['shipping_company'] = '';
				$order_data['shipping_address_1'] = '';
				$order_data['shipping_address_2'] = '';
				$order_data['shipping_city'] = '';
				$order_data['shipping_postcode'] = '';
				$order_data['shipping_zone'] = '';
				$order_data['shipping_zone_id'] = '';
				$order_data['shipping_country'] = "";
				$order_data['shipping_country_id'] = $this->config->get('config_country_id');
				$order_data['shipping_address_format'] = '';
				$order_data['shipping_custom_field'] = [];
				$order_data['shipping_method'] = '';
				$order_data['shipping_code'] = '';
				$order_data['comment'] = '';
				$order_data['total'] = $this->currency->convert($jet_priceall, 'EUR', $this->config->get('config_currency'));
				if (isset($this->request->cookie['tracking'])) {
					$order_data['tracking'] = $this->request->cookie['tracking'];
					$this->load->model('affiliate/affiliate');
					$affiliate_info = $this->model_affiliate_affiliate->getAffiliateByCode($this->request->cookie['tracking']);
					if ($affiliate_info) {
						$order_data['affiliate_id'] = $affiliate_info['affiliate_id'];
						$order_data['commission'] = ($subtotal / 100) * $affiliate_info['commission'];
					} else {
						$order_data['affiliate_id'] = 0;
						$order_data['commission'] = 0;
					}
					$this->load->model('checkout/marketing');
					$marketing_info = $this->model_checkout_marketing->getMarketingByCode($this->request->cookie['tracking']);
					if ($marketing_info) {
						$order_data['marketing_id'] = $marketing_info['marketing_id'];
					} else {
						$order_data['marketing_id'] = 0;
					}
				}else{
					$order_data['affiliate_id'] = 0;
					$order_data['commission'] = 0;
					$order_data['marketing_id'] = 0;
					$order_data['tracking'] = '';
				}
				$order_data['language_id'] = $this->config->get('config_language_id');
				$order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
				$order_data['currency_code'] = $this->session->data['currency'];
				$order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
				$order_data['ip'] = $this->request->server['REMOTE_ADDR'];
				if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
					$order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
				} elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
					$order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
				} else {
					$order_data['forwarded_ip'] = '';
				}
				if (isset($this->request->server['HTTP_USER_AGENT'])) {
					$order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
				} else {
					$order_data['user_agent'] = '';
				}
				if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
					$order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
				} else {
					$order_data['accept_language'] = '';
				}
				$order_data['products'] = [];
				$this->load->model('catalog/product');
				$product_info = $verified['context']['product'];
				if ($product_info) {
					$jet_product_name = $product_info['name'];
					$jet_product_model = $product_info['model'];
					$jet_product_subtract = $product_info['subtract'];
					$jet_product_tax_class_id = $product_info['tax_class_id'];
					$jet_product_reward = $product_info['reward'];
					$jet_product_price_with_vat = $this->currency->convert($jet_priceall / $jet_quantity, 'EUR', $this->config->get('config_currency'));
					$jet_product_tax = $this->tax->getTax($jet_product_price_with_vat, $jet_product_tax_class_id);
					$jet_product_price = $jet_product_price_with_vat - $jet_product_tax;
				} else {
					$jet_product_name = "";
					$jet_product_model = "";
					$jet_product_subtract = "";
					$jet_product_tax_class_id = "";
					$jet_product_reward = "";
					$jet_product_tax = "";
					$jet_product_price = "";
				}
				$order_data['products'][] = [
					'product_id' => $jet_product_id,
					'name'       => str_replace('"', '', str_replace("'", "", $jet_product_name)),
					'model'      => $jet_product_model,
					'option'     => $jet_variations,
					'download'   => "",
					'quantity'   => $jet_quantity,
					'subtract'   => $jet_product_subtract,
					'price'      => $jet_product_price,
					'total'      => $jet_product_price * $jet_quantity,
					'tax'        => $jet_product_tax,
					'reward'     => $jet_product_reward * $jet_quantity
				];
				$order_data['vouchers'] = [];
				$totals = [];
				$order_data['totals'] = $totals;
				$this->load->model('checkout/order');
				$this->submissionAction($execution, 'startAction', 'order');
				$jet_order_id = $this->model_checkout_order->addOrder($order_data);
				if (!$jet_order_id || !$this->model_checkout_order->getOrder($jet_order_id)) {
					throw new \RuntimeException('Order persistence failed.');
				}
				$this->submissionAction($execution, 'recordOrder', $jet_order_id);
				$json['order_id'] = $jet_order_id;
				$order_status_key = $jet_card == 1 ? 'payment_jet_card_order_status_id' : 'payment_jet_order_status_id';
			require_once DIR_SYSTEM . 'library/jet_submission_mail.php';
			$delivery = JetSubmissionMail::deliver($this->config, $ccAddresses, $toEmail_admin, $jet_email, $jet_id, $subject, $body, $jet_egn, function ($method, ...$args) use ($execution) { $this->submissionAction($execution, $method, ...$args); });
			$json['code'] = $delivery['code'];
			$json['notifications'] = $delivery['notifications'];
			$json['success'] = in_array($delivery['code'], ['bank_sent', 'manual_registered'], true) ? 'success' : 'unsuccess';
			if ($json['success'] === 'success') {
				$this->submissionAction($execution, 'startAction', 'history');
				$history = 'done';
				try {
					$this->model_checkout_order->addOrderHistory($jet_order_id, $this->config->get($order_status_key));
				} catch (\Throwable $error) {
					$json['order_status_updated'] = false;
					$history = 'uncertain';
				}
				$this->submissionAction($execution, 'finishAction', 'history', $history);
			}
			$this->submissionFinish($execution, $json['code']);
		} catch (\Throwable $error) {
			$this->submissionFailed($execution, $json);
		}
		} catch (\Throwable $error) { $this->submissionFailed($execution, $json); }
		if (!empty($json['order_id'])) unset($this->session->data['jet_product_quotes'][$this->request->post['jet_quote_ref']]);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	// Resolve through the merchant's native loader; its extensions are trusted.
	protected function cartModel() {
		$this->load->model('extension/mt_jet_credit/jetcredit');
		return $this->registry->get('model_extension_mt_jet_credit_jetcredit');
	}

	private function cartFailure($stale = false) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(['success'=>'unsuccess','jet_quote_invalid'=>true,'jet_cart_stale'=>$stale]));
	}

	private function cartKnownStaleFailure($model, $reference) {
		return $model && in_array($model->getCartFailureCode(), ['evaluation_session_changed','unsupported_cart','unsupported_currency','invalid_currency','invalid_total','configuration_changed'],true)
			&& (bool)$model->findCartQuote($reference);
	}

	private function cartCalculate($model, $context, $post) {
		if (!$model->cartTransportMatches($context,$post)) { return false; }
		$submitted = $model->productNumber($post['jet_priceall'] ?? null);
		$raw_down = $model->productNumber($post['jet_downpayment_raw'] ?? ($post['jet_parva'] ?? null),true);
		$displayed_down = $model->productNumber($post['jet_parva'] ?? null,true);
		$term = $model->productNumber($post['jet_vnoski'] ?? null);
		$card = $model->productNumber($post['jet_card'] ?? null);
		if ($submitted === null || $submitted !== (float)$context['amount'] || $raw_down === null || $raw_down < 0
			|| !in_array($term,[3.0,6.0,9.0,10.0,12.0,15.0,18.0,24.0,30.0,36.0],true)
			|| ($context['browser_raw'] < 401 && in_array($term,[15.0,18.0,24.0],true))
			|| ($context['browser_raw'] < 601 && in_array($term,[30.0,36.0],true))
			|| !in_array($card,[0.0,1.0],true) || ($card===1.0 && !$this->config->get('module_mt_jet_credit_status_card'))
			|| $context['raw_amount'] < (float)$this->config->get('module_mt_jet_credit_price')) { return false; }
		$canonical = $model->cartFixed($raw_down);
		if ($displayed_down === null || $displayed_down !== (float)$canonical || (float)$canonical >= (float)$context['amount']) { return false; }
		$jet_priceall = (float)$context['amount']; $jet_parva = (float)$canonical; $jet_vnoski = (int)$term;
		$json = [];
		$jet_total_credit_price = $jet_priceall - $jet_parva;
		$jet_purcent = (float) $this->config->get('module_mt_jet_credit_purcent');
		$jet_purcent_card = (float)$this->config->get('module_mt_jet_credit_purcent_card');
		$jet_show_button = true;
		$promotion = $model->getCartPromotion($context['products_key'], $jet_vnoski, $jet_total_credit_price);
		$jet_promo = $promotion['promotion'];
		if (!$jet_promo['jet_show_button']) { return ['quote'=>null,'promotion'=>$promotion['fingerprint'],'eligible'=>false]; }
		$jet_show_button = (bool) $jet_promo['jet_show_button'];
		$jet_purcent = (float) $jet_promo['jet_purcent'];
		$jet_purcent_card = (float) $jet_promo['jet_purcent_card'];
		$jet_vnoska = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent) / 100));
		$jet_vnoska_card = (($jet_total_credit_price / $jet_vnoski) * (1 + ($jet_vnoski * $jet_purcent_card) / 100));
		$jet_gprm = $this->RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12;
		$jet_glp = ($this->RATE($jet_vnoski, $jet_vnoska, -1 * $jet_total_credit_price) * 12) * 100;
		$jet_gpr = (pow((1 + $jet_gprm / 12), 12) - 1) * 100;
		$jet_obshto = $jet_vnoska * $jet_vnoski;
		$jet_gprm_card = $this->RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12;
		$jet_glp_card = ($this->RATE($jet_vnoski, $jet_vnoska_card, -1 * $jet_total_credit_price) * 12) * 100;
		$jet_gpr_card = (pow((1 + $jet_gprm_card / 12), 12) - 1) * 100;
		$jet_obshto_card = $jet_vnoska_card * $jet_vnoski;
		$jet_vnoska_second = 0;
		$jet_vnoska_card_second = 0;
		$jet_priceall_second = $jet_priceall;
		$jet_total_credit_price_second = $jet_total_credit_price;
		$jet_obshto_second = $jet_obshto;
		$jet_obshto_card_second = $jet_obshto_card;

		foreach ([$jet_vnoska,$jet_vnoska_card,$jet_gpr,$jet_glp,$jet_obshto,$jet_gpr_card,$jet_glp_card,$jet_obshto_card] as $number) if (!is_finite($number)) { return false; }
		$json['success'] = 'success';
		$json['jet_vnoska'] = number_format($jet_vnoska, 2, ".", "");
		$json['jet_vnoska_second'] = number_format($jet_vnoska_second, 2, ".", "");
		$json['jet_priceall'] = number_format($jet_priceall, 2, ".", "");
		$json['jet_priceall_second'] = number_format($jet_priceall_second, 2, ".", "");
		$json['jet_total_credit_price'] = number_format($jet_total_credit_price, 2, ".", "");
		$json['jet_total_credit_price_second'] = number_format($jet_total_credit_price_second, 2, ".", "");
		$json['jet_gpr'] = number_format($jet_gpr, 2, ".", "");
		$json['jet_glp'] = number_format($jet_glp, 2, ".", "");
		$json['jet_obshto'] = number_format($jet_obshto, 2, ".", "");
		$json['jet_obshto_second'] = number_format($jet_obshto_second, 2, ".", "");
		$json['jet_vnoska_card'] = number_format($jet_vnoska_card, 2, ".", "");
		$json['jet_vnoska_card_second'] = number_format($jet_vnoska_card_second, 2, ".", "");
		$json['jet_gpr_card'] = number_format($jet_gpr_card, 2, ".", "");
		$json['jet_glp_card'] = number_format($jet_glp_card, 2, ".", "");
		$json['jet_obshto_card'] = number_format($jet_obshto_card, 2, ".", "");
		$json['jet_obshto_card_second'] = number_format($jet_obshto_card_second, 2, ".", "");
		$json['jet_parva'] = $canonical;
		$json['jet_vnoski'] = $jet_vnoski;
		$json['jet_card'] = (int)$card;
		$eligible = (float)$json['jet_vnoska'.($card===1.0?'_card':'')] >= 20;
		$json['jet_show_button'] = $eligible;
		return ['quote'=>$json,'promotion'=>$promotion['fingerprint'],'eligible'=>$eligible];
	}

	public function jet_calculate_cart() {
		if (array_key_exists('jet_checkout_context', $this->request->post)) {
			$json = false;
			try {
				$this->load->model('extension/mt_jet_credit/jetcredit');
				$snapshot = $this->model_extension_mt_jet_credit_jetcredit->getCheckoutSnapshot($this->request->post['jet_checkout_context']);
				if ($snapshot) $json = $this->model_extension_mt_jet_credit_jetcredit->getCheckoutQuote($snapshot, $this->request->post);
			} catch (\Throwable $error) {
				$json = false;
			}
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json ?: ['success' => 'unsuccess']));
			return;
		}
		try {
			$model = $this->cartModel();
			$context = $model ? $model->getCartContext() : false;
			if (!$context) {
				$this->cartFailure($this->cartKnownStaleFailure($model,$this->request->post['jet_previous_quote'] ?? null)); return; }
			$previous = $model->findCartQuote($this->request->post['jet_previous_quote'] ?? null);
			if ($previous && $previous['context']['fingerprint'] !== $context['fingerprint']) { $this->cartFailure(true); return; }
			$result = $this->cartCalculate($model,$context,$this->request->post);
			if (!$result || !$result['quote']) { $this->cartFailure(); return; }
			$json = $result['quote'];
			if ($result['eligible']) {
				$intent = $this->submissionContext('cart', true);
				if ($intent['row']['state'] !== 'draft') { $this->submissionReview(); return; }
				$reference = $model->storeCartQuote($context,$json,$result['promotion'],$this->request->post,$intent['id']);
				if (!$reference) { $this->cartFailure(); return; }
				$json += $reference + ['jet_intent_id'=>$intent['id']];
			}
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
		} catch (\Throwable $error) { $this->cartFailure(); }
	}

	public function jet_send_cart() {
		if(!empty((string)$this->request->post['jet_lname'])) die();
		try {
			$execution = $this->submissionContext('cart');
			if ($execution['row']['state'] !== 'draft') { $this->submissionReplay($execution); return; }
		} catch (\Throwable $error) { $this->submissionReview(); return; }
		try {
			$model = $this->cartModel(); $context = $model ? $model->getCartContext() : false;
			$saved = $context ? $model->findCartQuote($this->request->post['jet_quote_ref'] ?? null) : false;
			if ($saved && ($saved['intent_id'] ?? null) !== $execution['id']) { $this->cartFailure(); return; }
			if (!$context || !$saved) { $this->cartFailure(!$context && $this->cartKnownStaleFailure($model,$this->request->post['jet_quote_ref'] ?? null)); return; }
			if ($context['fingerprint'] !== $saved['context']['fingerprint']) { $this->cartFailure(true); return; }
			foreach (['priceall','parva','vnoski','card'] as $field) {
				if ($model->productNumber($this->request->post['jet_'.$field] ?? null) !== (float)$saved['quote']['jet_'.$field]) { $this->cartFailure(); return; }
			}
			$result = $this->cartCalculate($model,$context,$this->request->post);
			if ($result && $result['promotion'] !== $saved['promotion']) { $this->cartFailure(true); return; }
			if (!$result || !$result['eligible'] || $result['quote'] !== $saved['quote']) { $this->cartFailure(); return; }
			$quote = $result['quote']; $suffix = $quote['jet_card'] ? '_card' : '';
			foreach (['vnoska','total_credit_price','obshto','gpr','glp'] as $field) {
				if ($model->productNumber($this->request->post['jet_'.$field] ?? null) !== (float)$quote['jet_'.$field.($field==='total_credit_price'?'':$suffix)]) { $this->cartFailure(); return; }
			}
		} catch (\Throwable $error) { $this->cartFailure(); return; }
		try { if (!$this->submissionClaim($execution)) return; }
		catch (\Throwable $error) { $this->submissionReview(); return; }
		$json = [];
		try {
		$jet_priceall = $quote['jet_priceall']; $jet_parva = $quote['jet_parva']; $jet_vnoski = $quote['jet_vnoski'];
		$jet_vnoska = $quote['jet_vnoska'.$suffix]; $jet_total_credit_price = $quote['jet_total_credit_price'];
		$jet_obshto = $quote['jet_obshto'.$suffix]; $jet_gpr = $quote['jet_gpr'.$suffix]; $jet_glp = $quote['jet_glp'.$suffix];
		if (isset($this->request->post['jet_name'])) {
			$jet_name = filter_var($this->request->post['jet_name'], FILTER_SANITIZE_STRING);
		} else {
			$jet_name = " - ";
		}
		if (isset($this->request->post['jet_lastname'])) {
			$jet_lastname = filter_var($this->request->post['jet_lastname'], FILTER_SANITIZE_STRING);
		} else {
			$jet_lastname = " - ";
		}
		if (isset($this->request->post['jet_egn'])) {
			$jet_egn = filter_var($this->request->post['jet_egn'], FILTER_SANITIZE_STRING);
		} else {
			$jet_egn = " - ";
		}
		if (isset($this->request->post['jet_email'])) {
			$jet_email = filter_var($this->request->post['jet_email'], FILTER_SANITIZE_STRING);
		} else {
			$jet_email = " - ";
		}
		if (isset($this->request->post['jet_phone'])) {
			$jet_phone = filter_var($this->request->post['jet_phone'], FILTER_SANITIZE_STRING);
		} else {
			$jet_phone = " - ";
		}
		$jet_card = $quote['jet_card'];
		$jet_products = $context['products_key'];
		$jet_products_qt = $context['products_qt'];
		$jet_products_pr = $context['products_pr'];
		$toEmail_admin = (string)$this->config->get('config_email');
		$toEmail_other = (string)$this->config->get('module_mt_jet_credit_mail');
		$jet_id = (string)$this->config->get('module_mt_jet_credit_id');
		if ($jet_card == 1) {
			$payment_method = 'ПБ Лични Финанси на вноски с кредитна карта';
			$payment_code = 'jet_card';
		} else {
			$payment_method = 'ПБ Лични Финанси';
			$payment_code = 'jet';
		}

		$jet_sign = 'евро';
		$body = "Данни за потребителя:\r\n";
		$body .= "Собствено име: $jet_name;\r\n";
		$body .= "Фамилия: $jet_lastname;\r\n";
		$body .= "ЕГН: $jet_egn;\r\n";
		$body .= "Телефон за връзка: $jet_phone;\r\n";
		$body .= "Имейл адрес: $jet_email;\r\n\r\n";
        
		$body .= "Данни за стоката:\r\n";
		$_product = explode('_', $jet_products);
		$product_q = explode('_', $jet_products_qt);
		$product_p = explode('_', $jet_products_pr);
		$product_v = $context['order_options'];
		for ($index = 0; $index < sizeof($_product); $index++) {
			$jet_product_id = $_product[$index];
			$this->load->model('catalog/product');
			$this->load->model('catalog/category');
			$categories = $this->model_catalog_product->getCategories($jet_product_id);
			$product_c_txt = " - ";
			foreach ($categories as $category) {
				$category_info = $this->model_catalog_category->getCategory($category['category_id']);
				if ($category_info) {
					$product_c_txt = $category_info['name'];
				}
			}
			$product = $context['products'][$index];
			$jet_variations = isset($product_v[$index]) ? $product_v[$index] : [];
			$product_m_txt = html_entity_decode($product['name']);
			if (count($jet_variations) > 0) {
				$product_m_txt .= ' - ';
				foreach ($jet_variations as $jet_variation) {
					$product_m_txt .= html_entity_decode($jet_variation['value']) . ', ';
				}
				$product_m_txt = substr($product_m_txt, 0, -2);
			}
			if ($product_m_txt == "") {
				$product_m_txt = " - ";
			}
			$product_p_txt = (float)$product_p[$index];
			if (isset($product_q[$index]) && (int)$product_q[$index] != 0) {
				$product_q_txt = (int)$product_q[$index];
			}else{
				$product_q_txt = 1;
			}
			$body .= "Тип стока: " . $product_c_txt . ";\r\n";
			$body .= "Марка: " . "(" . $_product[$index] . ") " . $product_m_txt . ";\r\n";
			$body .= "Единична цена с ДДС: " . number_format($product_p_txt, 2, ".", "") . ";\r\n";
			$body .= "Брой стоки: " . $product_q_txt . ";\r\n";
			$body .= "Обща сума с ДДС: " . number_format((float)$product_q_txt * (float)$product_p_txt, 2, ".", "") . ";\r\n\r\n";
		}
        
		if ($jet_card == 1) {
			$body .= "Тип стока: Кредитна Карта;\r\n";
			$body .= "Марка: -;\r\n";
			$body .= "Единична цена с ДДС: 0.00;\r\n";
			$body .= "Брой стоки: 1;\r\n";
			$body .= "Обща сума с ДДС: 0.00;\r\n\r\n";
		}
        
		$body .= "Данни за кредита:\r\n";
		$body .= "Размер на кредита: " . number_format($jet_priceall - $jet_parva, 2, '.', '') . ";\r\n";
		$body .= "Срок на изплащане в месеца: $jet_vnoski;\r\n";
		$body .= "Месечна вноска: $jet_vnoska;\r\n";
		$body .= "Първоначална вноска: " . number_format(floatval($jet_parva), 2, ".", "") . ";\r\n";
		$jet_count = $execution['reference'];
		$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
		$ccAddresses = trim($toEmail_other) === '' ? [] : explode(",", str_replace(' ', '', $toEmail_other));
		try {
				$order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
				$order_data['store_id'] = $this->config->get('config_store_id');
				$order_data['store_name'] = $this->config->get('config_name');
				if ($order_data['store_id']) {
					$order_data['store_url'] = $this->config->get('config_url');
				} else {
					if ($this->request->server['HTTPS']) {
						$order_data['store_url'] = HTTPS_SERVER;
					} else {
						$order_data['store_url'] = HTTP_SERVER;
					}
				}
				if ($this->customer->isLogged()) {
					$this->load->model('account/customer');
					$customer_info = $this->model_account_customer->getCustomer($this->customer->getId());
					$order_data['customer_id'] = $this->customer->getId();
					$order_data['customer_group_id'] = $customer_info['customer_group_id'];
					$order_data['firstname'] = $customer_info['firstname'];
					$order_data['lastname'] = $customer_info['lastname'];
					$order_data['email'] = $customer_info['email'];
					$order_data['telephone'] = $customer_info['telephone'];
					$order_data['fax'] = $customer_info['fax'];
					$order_data['custom_field'] = json_decode($customer_info['custom_field'], true);
				} elseif (isset($this->session->data['guest'])) {
					$order_data['customer_id'] = 0;
					$order_data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
					$order_data['firstname'] = $this->session->data['guest']['firstname'];
					$order_data['lastname'] = $this->session->data['guest']['lastname'];
					$order_data['email'] = $this->session->data['guest']['email'];
					$order_data['telephone'] = $this->session->data['guest']['telephone'];
					$order_data['fax'] = $this->session->data['guest']['fax'];
					$order_data['custom_field'] = $this->session->data['guest']['custom_field'];
				} else {
					$order_data['customer_id'] = 0;
					$order_data['customer_group_id'] = 0;
					$order_data['firstname'] = $jet_name;
					$order_data['lastname'] = $jet_lastname;
					$order_data['email'] = $jet_email;
					$order_data['telephone'] = $jet_phone;
					$order_data['fax'] = "";
					$order_data['custom_field'] = "";
				}
				$order_data['payment_firstname'] = $jet_name;
				$order_data['payment_lastname'] = $jet_lastname;
				$order_data['payment_company'] = "";
				$order_data['payment_address_1'] = '';
				$order_data['payment_address_2'] = '';
				$order_data['payment_city'] = '';
				$order_data['payment_postcode'] = "";
				$order_data['payment_zone'] = "";
				$order_data['payment_zone_id'] = "";
				$order_data['payment_country'] = "";
				$order_data['payment_country_id'] = $this->config->get('config_country_id');
				$order_data['payment_address_format'] = "";
				$order_data['payment_custom_field'] = [];
				$order_data['payment_method'] = $payment_method;
				$order_data['payment_code'] = $payment_code;
				$order_data['shipping_firstname'] = $jet_name;
				$order_data['shipping_lastname'] = $jet_lastname;
				$order_data['shipping_company'] = '';
				$order_data['shipping_address_1'] = '';
				$order_data['shipping_address_2'] = '';
				$order_data['shipping_city'] = '';
				$order_data['shipping_postcode'] = '';
				$order_data['shipping_zone'] = '';
				$order_data['shipping_zone_id'] = '';
				$order_data['shipping_country'] = "";
				$order_data['shipping_country_id'] = $this->config->get('config_country_id');
				$order_data['shipping_address_format'] = '';
				$order_data['shipping_custom_field'] = [];
				$order_data['shipping_method'] = '';
				$order_data['shipping_code'] = '';
				$order_data['comment'] = '';
				$order_data['total'] = $this->currency->convert($jet_priceall, 'EUR', $this->config->get('config_currency'));
				if (isset($this->request->cookie['tracking'])) {
					$order_data['tracking'] = $this->request->cookie['tracking'];
					$this->load->model('affiliate/affiliate');
					$affiliate_info = $this->model_affiliate_affiliate->getAffiliateByCode($this->request->cookie['tracking']);
					if ($affiliate_info) {
						$order_data['affiliate_id'] = $affiliate_info['affiliate_id'];
						$order_data['commission'] = ($subtotal / 100) * $affiliate_info['commission'];
					} else {
						$order_data['affiliate_id'] = 0;
						$order_data['commission'] = 0;
					}
					$this->load->model('checkout/marketing');
					$marketing_info = $this->model_checkout_marketing->getMarketingByCode($this->request->cookie['tracking']);
					if ($marketing_info) {
						$order_data['marketing_id'] = $marketing_info['marketing_id'];
					} else {
						$order_data['marketing_id'] = 0;
					}
				}else{
					$order_data['affiliate_id'] = 0;
					$order_data['commission'] = 0;
					$order_data['marketing_id'] = 0;
					$order_data['tracking'] = '';
				}
				$order_data['language_id'] = $this->config->get('config_language_id');
				$order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
				$order_data['currency_code'] = $this->session->data['currency'];
				$order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
				$order_data['ip'] = $this->request->server['REMOTE_ADDR'];
				if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
					$order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
				} elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
					$order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
				} else {
					$order_data['forwarded_ip'] = '';
				}
				if (isset($this->request->server['HTTP_USER_AGENT'])) {
					$order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
				} else {
					$order_data['user_agent'] = '';
				}
				if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
					$order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
				} else {
					$order_data['accept_language'] = '';
				}
				$order_data['products'] = [];
				$this->load->model('catalog/product');
				for ($index = 0; $index < sizeof($_product); $index++) {
					$jet_product_id = $_product[$index];
					$product_info = $context['products'][$index];
					$jet_price = (float)$product_p[$index];
					if (isset($product_q[$index]) && (int)$product_q[$index] != 0) {
						$jet_quantity = (int)$product_q[$index];
					}else{
						$jet_quantity = 1;
					}
					if ($product_info) {
						$jet_product_name = $product_info['name'];
						$jet_product_model = $product_info['model'];
						$jet_product_subtract = $product_info['subtract'];
						$jet_product_tax_class_id = $product_info['tax_class_id'];
						$jet_product_reward = $product_info['reward'] / $jet_quantity;
						$jet_product_price_with_vat = $this->currency->convert($jet_price, 'EUR', $this->config->get('config_currency'));
						$jet_product_tax = $this->tax->getTax($jet_product_price_with_vat, $jet_product_tax_class_id);
						$jet_product_price = $jet_product_price_with_vat - $jet_product_tax;
					} else {
						$jet_product_name = "";
						$jet_product_model = "";
						$jet_product_subtract = "";
						$jet_product_tax_class_id = "";
						$jet_product_reward = "";
						$jet_product_tax = "";
						$jet_product_price = "";
					}
					$order_data['products'][] = [
						'product_id' => $jet_product_id,
						'name'       => str_replace('"', '', str_replace("'", "", $jet_product_name)),
						'model'      => $jet_product_model,
						'option'     => isset($product_v[$index]) ? $product_v[$index] : [],
						'download'   => "",
						'quantity'   => $jet_quantity,
						'subtract'   => $jet_product_subtract,
						'price'      => $jet_product_price,
						'total'      => $jet_product_price * $jet_quantity,
						'tax'        => $jet_product_tax,
						'reward'     => $jet_product_reward * $jet_quantity
					];
				}
				$order_data['vouchers'] = [];
				$totals = [];
				$order_data['totals'] = $totals;
				$this->load->model('checkout/order');
				$this->submissionAction($execution, 'startAction', 'order');
				$jet_order_id = $this->model_checkout_order->addOrder($order_data);
				if (!$jet_order_id || !$this->model_checkout_order->getOrder($jet_order_id)) {
					throw new \RuntimeException('Order persistence failed.');
				}
				$this->submissionAction($execution, 'recordOrder', $jet_order_id);
				$json['order_id'] = $jet_order_id;
				$order_status_key = $jet_card == 1 ? 'payment_jet_card_order_status_id' : 'payment_jet_order_status_id';
			require_once DIR_SYSTEM . 'library/jet_submission_mail.php';
			$delivery = JetSubmissionMail::deliver($this->config, $ccAddresses, $toEmail_admin, $jet_email, $jet_id, $subject, $body, $jet_egn, function ($method, ...$args) use ($execution) { $this->submissionAction($execution, $method, ...$args); });
			$json['code'] = $delivery['code'];
			$json['notifications'] = $delivery['notifications'];
			$json['success'] = in_array($delivery['code'], ['bank_sent', 'manual_registered'], true) ? 'success' : 'unsuccess';
			if ($json['success'] === 'success') {
				$this->submissionAction($execution, 'startAction', 'history');
				$history = 'done';
				try {
					$this->model_checkout_order->addOrderHistory($jet_order_id, $this->config->get($order_status_key));
				} catch (\Throwable $error) {
					$json['order_status_updated'] = false;
					$history = 'uncertain';
				}
				$this->submissionAction($execution, 'finishAction', 'history', $history);
			}
			if ($json['success'] === 'success') {
				$this->submissionAction($execution, 'startAction', 'clear');
				$clear = 'done';
				try {
					$this->cart->clear();
				} catch (\Throwable $error) {
					$json['cart_cleared'] = false;
					$clear = 'uncertain';
				}
				$this->submissionAction($execution, 'finishAction', 'clear', $clear);
			}
			$this->submissionFinish($execution, $json['code']);
		} catch (\Throwable $error) {
			$this->submissionFailed($execution, $json);
		}
		} catch (\Throwable $error) { $this->submissionFailed($execution, $json); }
		if (!empty($json['order_id'])) unset($this->session->data['jet_cart_quotes'][$this->request->post['jet_quote_ref']]['quote']);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function editSettingValue($code = '', $key = '', $value = '', $store_id = 0) {
		if (!is_array($value)) {
			$this->db->query("UPDATE " . DB_PREFIX . "setting SET `value` = '" . $this->db->escape($value) . "', serialized = '0'  WHERE `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "' AND store_id = '" . (int)$store_id . "'");
		} else {
			$this->db->query("UPDATE " . DB_PREFIX . "setting SET `value` = '" . $this->db->escape(json_encode($value)) . "', serialized = '1' WHERE `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "' AND store_id = '" . (int)$store_id . "'");
		}
	}

	public function RATE($nper, $pmt, $pv, $fv = 0.0, $type = 0, $guess = 0.1) {
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
}
