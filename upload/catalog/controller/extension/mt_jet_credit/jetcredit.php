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

	public function createSchema() {
		$this->load->language('extension/mt_jet_credit/jetcredit');
		$json = array();
		if (isset($this->request->post['mt_jet_credit_product_id'])) {
			$mt_jet_credit_product_id = filter_var($this->request->post['mt_jet_credit_product_id'], FILTER_SANITIZE_STRING);
		} else {
			$mt_jet_credit_product_id = '';
		}
		if (isset($this->request->post['mt_jet_credit_product_percent'])) {
			$mt_jet_credit_product_percent = filter_var($this->request->post['mt_jet_credit_product_percent'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$mt_jet_credit_product_percent = -1;
		}
		if (isset($this->request->post['mt_jet_credit_product_meseci'])) {
			$mt_jet_credit_product_meseci = filter_var($this->request->post['mt_jet_credit_product_meseci'], FILTER_SANITIZE_STRING);
		} else {
			$mt_jet_credit_product_meseci = '';
		}
		if (isset($this->request->post['mt_jet_credit_product_price'])) {
			$mt_jet_credit_product_price = filter_var($this->request->post['mt_jet_credit_product_price'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$mt_jet_credit_product_price = 0;
		}
		if (isset($this->request->post['mt_jet_credit_product_start'])) {
			$mt_jet_credit_product_start = filter_var($this->request->post['mt_jet_credit_product_start'], FILTER_SANITIZE_STRING);
		} else {
			$mt_jet_credit_product_start = '';
		}
		if (isset($this->request->post['mt_jet_credit_product_end'])) {
			$mt_jet_credit_product_end = filter_var($this->request->post['mt_jet_credit_product_end'], FILTER_SANITIZE_STRING);
		} else {
			$mt_jet_credit_product_end = '';
		}
		$mt_jet_categories_current = array(
			'mt_jet_credit_product_id' => $mt_jet_credit_product_id,
			'mt_jet_credit_product_percent' => $mt_jet_credit_product_percent,
			'mt_jet_credit_product_meseci' => $mt_jet_credit_product_meseci,
			'mt_jet_credit_product_price' => $mt_jet_credit_product_price,
			'mt_jet_credit_product_start' => $mt_jet_credit_product_start,
			'mt_jet_credit_product_end' => $mt_jet_credit_product_end
		);
		$this->load->model('extension/mt_jet_credit/jetcredit');
		$result = (int) $this->model_extension_mt_jet_credit_jetcredit->addKop( $mt_jet_categories_current );
		if ( $result === (int) -1 ) {
			$json['success'] = 'success';
			$json['exist'] = 'Вече имате такъв филтър!';
		} else {
			if ( $result > 0 ) {
				$json['success'] = 'success';
				$json['exist'] = '';
			} else {
				$json['success'] = 'unsuccess';
				$json['exist'] = '';
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function deleteSchema() {
		$json = array();
		if (isset($this->request->post['mt_jet_credit_product_id'])) {
			$mt_jet_credit_product_id = $this->request->post['mt_jet_credit_product_id'];
		} else {
			$mt_jet_credit_product_id = '';
		}
		$this->load->model('extension/mt_jet_credit/jetcredit');
		$result = (int) $this->model_extension_mt_jet_credit_jetcredit->deleteKop( $mt_jet_credit_product_id );
		if ( $result > 0 ) {
			$json['success'] = 'success';
		} else {
			$json['success'] = 'unsuccess';
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function jet_check() {
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		$json = array();
		$this->load->model('catalog/product');
		$product_info = $this->model_catalog_product->getProduct($this->request->post['product_id']);
		if ($product_info) {
			if (isset($this->request->post['option'])) {
				$option = array_filter($this->request->post['option']);
			} else {
				$option = array();
			}
			$product_options = $this->model_catalog_product->getProductOptions($this->request->post['product_id']);
			$optionn = 0;
			foreach ($product_options as $product_option) {
				if ($product_option['required'] && empty($option[$product_option['product_option_id']])) {
					$json['error']['option'][$product_option['product_option_id']] = sprintf('%s задължително!', $product_option['name']);
				}else{
					$json['success'] = 1;
					foreach ($product_option['product_option_value'] as $key => $value) {
						$product_option['product_option_value'][$key]['price'] = $this->currency->convert($this->tax->calculate($product_option['product_option_value'][$key]['price'], $product_info['tax_class_id'], $this->config->get('config_tax')), $this->config->get('config_currency'), 'EUR');
					}
					$json['optionresult'][$optionn] = $product_option;
					$json['optionresult'][$optionn]['product_option_id_check'] = $option[$product_option['product_option_id']];
					$optionn++;
				}
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function jet_calculate() {
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		$json = [];
		if (isset($this->request->post['jet_priceall'])) {
			$jet_priceall = (float) filter_var($this->request->post['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = (float) 0.00;
		}
		if (isset($this->request->post['jet_parva'])) {
			$jet_parva = (float) filter_var($this->request->post['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = (float) 0.00;
		}
		if (isset($this->request->post['jet_vnoski'])) {
			$jet_vnoski = (int) filter_var($this->request->post['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int) $this->config->get('module_mt_jet_credit_vnoski');
		}
		if (isset($this->request->post['jet_product_id'])) {
			$jet_product_id = (int) filter_var($this->request->post['jet_product_id'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_product_id = (int) 0;
		}
		if (isset($this->request->post['jet_uslovia'])) {
			$jet_uslovia = (int) filter_var($this->request->post['jet_uslovia'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia = (int) 0;
		}
		if (isset($this->request->post['jet_uslovia1'])) {
			$jet_uslovia1 = (int) filter_var($this->request->post['jet_uslovia1'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia1 = (int) 0;
		}
		if (isset($this->request->post['jet_egn'])) {
			$jet_egn = filter_var($this->request->post['jet_egn'], FILTER_SANITIZE_STRING);
		} else {
			$jet_egn = "";
		}
		if (isset($this->request->post['jet_products'])) {
			$jet_products = filter_var($this->request->post['jet_products'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products = "";
		}
		if (isset($this->request->post['jet_products_qt'])) {
			$jet_products_qt = filter_var($this->request->post['jet_products_qt'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_qt = "";
		}
		if (isset($this->request->post['jet_products_pr'])) {
			$jet_products_pr = filter_var($this->request->post['jet_products_pr'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_pr = "";
		}
		if (isset($this->request->post['jet_products_vr'])) {
			$jet_products_vr = filter_var($this->request->post['jet_products_vr'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_vr = "";
		}

		$jet_min_250 = self::JET_MIN_250_EUR;
		

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
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function jet_send() {
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		if(!empty((string)$this->request->post['jet_lname'])) die();
		$json = [];
		if (isset($this->request->post['jet_priceall'])) {
			$jet_priceall = filter_var($this->request->post['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = 0.00;
		}
		if (isset($this->request->post['jet_vnoski'])) {
			$jet_vnoski = filter_var($this->request->post['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int)$this->config->get('module_mt_jet_credit_vnoski');
		}
		if (isset($this->request->post['jet_vnoska'])) {
			$jet_vnoska = filter_var($this->request->post['jet_vnoska'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_vnoska = 0.00;
		}
		if (isset($this->request->post['jet_parva'])) {
			$jet_parva = filter_var($this->request->post['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = 0.00;
		}
		if (isset($this->request->post['jet_total_credit_price'])) {
			$jet_total_credit_price = filter_var($this->request->post['jet_total_credit_price'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_total_credit_price = 0.00;
		}
		if (isset($this->request->post['jet_obshto'])) {
			$jet_obshto = filter_var($this->request->post['jet_obshto'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_obshto = 0.00;
		}
		if (isset($this->request->post['jet_gpr'])) {
			$jet_gpr = filter_var($this->request->post['jet_gpr'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_gpr = 0.00;
		}
		if (isset($this->request->post['jet_glp'])) {
			$jet_glp = filter_var($this->request->post['jet_glp'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_glp = 0.00;
		}
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
		if (isset($this->request->post['jet_card'])) {
			$jet_card = filter_var($this->request->post['jet_card'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_card = 0;
		}
		if (isset($this->request->post['jet_product_id'])) {
			$jet_product_id = filter_var($this->request->post['jet_product_id'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_product_id = 0;
		}
		if (isset($this->request->post['jet_variation_id'])) {
			$jet_variation_id = filter_var($this->request->post['jet_variation_id'], FILTER_SANITIZE_STRING);
		} else {
			$jet_variation_id = '';
		}
		if (isset($this->request->post['jet_quantity'])) {
			$jet_quantity = filter_var($this->request->post['jet_quantity'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_quantity = 1;
		}
		$jet_variations = json_decode(html_entity_decode($jet_variation_id), true);
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
		$product = $this->model_catalog_product->getProduct((int)$jet_product_id);
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
		$jet_count = (int)$this->config->get('module_mt_jet_credit_count');
		$this->editSettingValue('module_mt_jet_credit', 'module_mt_jet_credit_count', $jet_count + 1);
		$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
		$ccAddresses = explode(",", str_replace(' ', '', $toEmail_other));
		$mail_engine = $this->config->get('config_mail_engine');
		$mail_protocol = $this->config->get('config_mail_protocol');
		$mail = new PHPMailer(true);
		try {
			if ($mail_engine === 'smtp' || $mail_protocol === 'smtp') {
				$mail->isSMTP();
				$mail->Host = $this->config->get('config_mail_smtp_hostname');
				$mail->SMTPAuth = true;
				$mail->Username = $this->config->get('config_mail_smtp_username');
				$mail->Password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
				$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				$mail->Port = $this->config->get('config_mail_smtp_port');
				$mail->Subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
			} else {
				$mail->isMail();
				$mail->Subject = $subject;
			}
			$mail->CharSet = 'UTF-8';
			$mail->Encoding = 'base64';
			$mail->setFrom($toEmail_admin, $jet_id);
			$mail->addAddress($toEmail_admin);
			foreach ($ccAddresses as $cc) {
				$mail->addCC($cc);
			}
			$mail->addCC($jet_email);
			$mail->Body = $body;
			$mail->isHTML(false);
			if ($mail->send()) {
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
				$product_info = $this->model_catalog_product->getProduct($jet_product_id);
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
				$jet_order_id = $this->model_checkout_order->addOrder($order_data);
				$order_status_key = $jet_card == 1 ? 'payment_jet_card_order_status_id' : 'payment_jet_order_status_id';
				$this->model_checkout_order->addOrderHistory($jet_order_id, $this->config->get($order_status_key));
				$json['success'] = 'success';
			} else {
				$json['success'] = 'unsuccess';
			}
		} catch (Exception $e) {
			$json['success'] = 'unsuccess';
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function jet_calculate_cart() {
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		$json = [];
		if (isset($this->request->post['jet_priceall'])) {
			$jet_priceall = filter_var($this->request->post['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = 0.00;
		}
		if (isset($this->request->post['jet_parva'])) {
			$jet_parva = filter_var($this->request->post['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = 0.00;
		}
		if (isset($this->request->post['jet_vnoski'])) {
			$jet_vnoski = filter_var($this->request->post['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int)$this->config->get('module_mt_jet_credit_vnoski');
		}
		if (isset($this->request->post['jet_products'])) {
			$jet_products = filter_var($this->request->post['jet_products'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products = "";
		}
		if (isset($this->request->post['jet_products_qt'])) {
			$jet_products_qt = filter_var($this->request->post['jet_products_qt'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_qt = "";
		}
		if (isset($this->request->post['jet_products_pr'])) {
			$jet_products_pr = filter_var($this->request->post['jet_products_pr'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_pr = "";
		}
		if (isset($this->request->post['jet_products_vr'])) {
			$jet_products_vr = filter_var($this->request->post['jet_products_vr'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_vr = "";
		}
		if (isset($this->request->post['jet_uslovia'])) {
			$jet_uslovia = filter_var($this->request->post['jet_uslovia'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia = 0;
		}
		if (isset($this->request->post['jet_uslovia1'])) {
			$jet_uslovia1 = filter_var($this->request->post['jet_uslovia1'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_uslovia1 = 0;
		}
		if (isset($this->request->post['jet_egn'])) {
			$jet_egn = filter_var($this->request->post['jet_egn'], FILTER_SANITIZE_STRING);
		} else {
			$jet_egn = "";
		}

		$jet_min_250 = self::JET_MIN_250_EUR;

		$jet_total_credit_price = $jet_priceall - $jet_parva;
		$this->load->model('extension/mt_jet_credit/jetcredit');
		$jet_purcent = (float) $this->config->get('module_mt_jet_credit_purcent');
		$jet_purcent_card = (float)$this->config->get('module_mt_jet_credit_purcent_card');
		$jet_show_button = true;
		$jet_promo = $this->model_extension_mt_jet_credit_jetcredit->getPromo( $jet_products, $jet_vnoski, $jet_total_credit_price );
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
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function jet_send_cart() {
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		if(!empty((string)$this->request->post['jet_lname'])) die();
		$json = [];
		if (isset($this->request->post['jet_priceall'])) {
			$jet_priceall = filter_var($this->request->post['jet_priceall'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_priceall = 0.00;
		}
		if (isset($this->request->post['jet_vnoski'])) {
			$jet_vnoski = filter_var($this->request->post['jet_vnoski'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_vnoski = (int)$this->config->get('module_mt_jet_credit_vnoski');
		}
		if (isset($this->request->post['jet_vnoska'])) {
			$jet_vnoska = filter_var($this->request->post['jet_vnoska'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_vnoska = 0.00;
		}
		if (isset($this->request->post['jet_parva'])) {
			$jet_parva = filter_var($this->request->post['jet_parva'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_parva = 0.00;
		}
		if (isset($this->request->post['jet_total_credit_price'])) {
			$jet_total_credit_price = filter_var($this->request->post['jet_total_credit_price'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_total_credit_price = 0.00;
		}
		if (isset($this->request->post['jet_obshto'])) {
			$jet_obshto = filter_var($this->request->post['jet_obshto'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_obshto = 0.00;
		}
		if (isset($this->request->post['jet_gpr'])) {
			$jet_gpr = filter_var($this->request->post['jet_gpr'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_gpr = 0.00;
		}
		if (isset($this->request->post['jet_glp'])) {
			$jet_glp = filter_var($this->request->post['jet_glp'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		} else {
			$jet_glp = 0.00;
		}
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
		if (isset($this->request->post['jet_card'])) {
			$jet_card = filter_var($this->request->post['jet_card'], FILTER_SANITIZE_NUMBER_INT);
		} else {
			$jet_card = 0;
		}
		if (isset($this->request->post['jet_products'])) {
			$jet_products = filter_var($this->request->post['jet_products'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products = '';
		}
		if (isset($this->request->post['jet_products_qt'])) {
			$jet_products_qt = filter_var($this->request->post['jet_products_qt'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_qt = '';
		}
		if (isset($this->request->post['jet_products_pr'])) {
			$jet_products_pr = filter_var($this->request->post['jet_products_pr'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_pr = '';
		}
		if (isset($this->request->post['jet_products_vr'])) {
			$jet_products_vr = filter_var($this->request->post['jet_products_vr'], FILTER_SANITIZE_STRING);
		} else {
			$jet_products_vr = '';
		}
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
		$product_v = json_decode(html_entity_decode($jet_products_vr), true);
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
			$product = $this->model_catalog_product->getProduct((int)$jet_product_id);
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
			if (isset($product_p[$index]) && (float)$product_p[$index] != 0) {
				$product_p_txt = (float)$product_p[$index];
			}else{
				$product_p_txt = (float)$this->tax->calculate($product['price'], $product['tax_class_id'], true);
			}
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
		$jet_count = (int)$this->config->get('module_mt_jet_credit_count') + 1;
		$this->editSettingValue('module_mt_jet_credit', 'module_mt_jet_credit_count', $jet_count + 1);
		$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
		$ccAddresses = explode(",", str_replace(' ', '', $toEmail_other));
		$mail_engine = $this->config->get('config_mail_engine');
		$mail_protocol = $this->config->get('config_mail_protocol');
		$mail = new PHPMailer(true);
		try {
			if ($mail_engine === 'smtp' || $mail_protocol === 'smtp') {
				$mail->isSMTP();
				$mail->Host = $this->config->get('config_mail_smtp_hostname');
				$mail->SMTPAuth = true;
				$mail->Username = $this->config->get('config_mail_smtp_username');
				$mail->Password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
				$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				$mail->Port = $this->config->get('config_mail_smtp_port');
				$mail->Subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
			} else {
				$mail->isMail();
				$mail->Subject = $subject;
			}
			$mail->CharSet = 'UTF-8';
			$mail->Encoding = 'base64';
			$mail->setFrom($toEmail_admin, $jet_id);
			$mail->addAddress($toEmail_admin);
			foreach ($ccAddresses as $cc) {
				$mail->addCC($cc);
			}
			$mail->addCC($jet_email);
			$mail->Body = $body;
			$mail->isHTML(false);
			
			if ($mail->send()) {
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
					$product_info = $this->model_catalog_product->getProduct($jet_product_id);
					if (isset($product_p[$index]) && (float)$product_p[$index] != 0) {
						$jet_price = (float)$product_p[$index];
					}else{
						$jet_price = (float)$this->tax->calculate($product['price'], $product['tax_class_id'], true);
					}
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
						$jet_product_reward = $product_info['reward'];
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
				$jet_order_id = $this->model_checkout_order->addOrder($order_data);
				$order_status_key = $jet_card == 1 ? 'payment_jet_card_order_status_id' : 'payment_jet_order_status_id';
				$this->model_checkout_order->addOrderHistory($jet_order_id, $this->config->get($order_status_key));
				$this->cart->clear();
				$json['success'] = 'success';
			}else{
				$json['success'] = 'unsuccess';
			}
		} catch (Exception $e) {
			$json['success'] = 'unsuccess';
		}
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