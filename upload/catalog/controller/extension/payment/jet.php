<?php
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/PHPMailer.php';
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/SMTP.php';
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class ControllerExtensionPaymentJet extends Controller
{
	private const JETCREDIT_FINANCIAL_MAX_ITERATIONS = 128;
	private const JETCREDIT_FINANCIAL_PRECISION = 1.0e-08;

	public function index()
	{
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			return '';
		}
		$this->load->language('extension/payment/jet');
		$data['JET_VERSION'] = '2.0.1';
		$this->load->model('extension/mt_jet_credit/jetcredit');
		try {
			$snapshot = $this->model_extension_mt_jet_credit_jetcredit->getCheckoutSnapshot('jet');
		} catch (\Throwable $error) {
			return '';
		}
		if (!$snapshot) return '';
		$jet_price = $snapshot['amount'];
		$jet_minstojnost = (float) $this->config->get('module_mt_jet_credit_price');
		$data['jet_status'] = false;
		$data['jet_price'] = $jet_price;
		$data['jet_minstojnost'] = $jet_minstojnost;
		$data['jet_vnoski'] = (int) $this->config->get('module_mt_jet_credit_vnoski');

		$jet_sign = 'евро';
		$jet_sign_second = '';
		$jet_min_250 = 125.00;
		$jet_vnoski_default = (int)$this->config->get('module_mt_jet_credit_vnoski');
		if ($jet_price < $jet_min_250) {
			$jet_vnoski = '9';
		} else {
			$jet_vnoski = $jet_vnoski_default;
		}
		$data['jet_products'] = $snapshot['products_key'];
		$data['jet_products_qt'] = $snapshot['products_qt'];
		$data['jet_products_pr'] = $snapshot['products_pr'];
		$data['jet_products_vr'] = $snapshot['products_vr'];
		$data['jet_sign'] = $jet_sign;
		$data['jet_sign_second'] = $jet_sign_second;

		$useragent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$jet_is_mobile = preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($useragent, 0, 4));
		if ($jet_is_mobile) {
			$data['jet_column_left'] = "jetm_column_left";
			$data['jet_column_right'] = "jetm_column_right";
		} else {
			$data['jet_column_left'] = "jet_column_left";
			$data['jet_column_right'] = "jet_column_right";
		}

		return $this->load->view('extension/payment/jet', $data);
	}

	public function confirm()
	{
		if (($this->session->data['currency'] ?? '') !== 'EUR') {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success' => false]));
			return;
		}
		$json = [];
		$json['success'] = 'unsuccess';
		$json['redirect'] = $this->url->link('checkout/success');
		$this->load->model('extension/mt_jet_credit/jetcredit');
		try {
			$verified = $this->model_extension_mt_jet_credit_jetcredit->validateCheckoutSubmission('jet', $this->request->post);
		} catch (\Throwable $error) {
			$verified = false;
		}
		if ($verified) {
			$snapshot = $verified['snapshot'];
			$order = $snapshot['order'];
			$jet_priceall = $verified['quote']['jet_priceall'];
			$jet_vnoski = $verified['term'];
			$jet_vnoska = $verified['quote']['jet_vnoska'];
			$jet_parva = $verified['downpayment'];
			$jet_egn = isset($this->request->post['jet_egn']) ? filter_var($this->request->post['jet_egn'], FILTER_SANITIZE_STRING) : ' - ';
			$jet_products = filter_var($snapshot['products_key'], FILTER_SANITIZE_STRING);
			$jet_products_qt = filter_var($snapshot['products_qt'], FILTER_SANITIZE_STRING);
			$jet_products_pr = filter_var($snapshot['products_pr'], FILTER_SANITIZE_STRING);
			$jet_products_vr = filter_var(html_entity_decode($snapshot['products_vr'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), FILTER_SANITIZE_STRING);
			$jet_name = isset($order['firstname']) ? $order['firstname'] : '';
			$jet_lastname = isset($order['lastname']) ? $order['lastname'] : '';
			$jet_phone = isset($order['telephone']) ? $order['telephone'] : '';
			$jet_email = isset($order['email']) ? $order['email'] : '';
			$toEmail_admin = (string) $this->config->get('config_email');
			$toEmail_other = (string) $this->config->get('module_mt_jet_credit_mail');
			$jet_id = (string) $this->config->get('module_mt_jet_credit_id');

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
			$product_v = explode('_', $jet_products_vr);

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
				$product = $snapshot['products'][$index];
				$jet_variations = $product_v[$index];
				$product_m_txt = html_entity_decode($product['name']);
				if ($jet_variations != '') {
					$product_m_txt .= ' - ';
					$product_m_txt .= html_entity_decode($jet_variations);
				}
				if ($product_m_txt == "") {
					$product_m_txt = " - ";
				}
				$product_p_txt = (float)$product_p[$index];
				if (isset($product_q[$index]) && (int)$product_q[$index] != 0) {
					$product_q_txt = (int)$product_q[$index];
				} else {
					$product_q_txt = 1;
				}
				$body .= "Тип стока: " . $product_c_txt . ";\r\n";
				$body .= "Марка: " . "(" . $_product[$index] . ") " . $product_m_txt . ";\r\n";
				$body .= "Единична цена с ДДС: " . number_format($product_p_txt, 2, ".", "") . ";\r\n";
				$body .= "Брой стоки: " . $product_q_txt . ";\r\n";
				$body .= "Обща сума с ДДС: " . number_format((float)$product_q_txt * (float)$product_p_txt, 2, ".", "") . ";\r\n\r\n";
			}

			$body .= "Данни за кредита:\r\n";
			$body .= "Размер на кредита: " . number_format($jet_priceall - $jet_parva, 2, '.', '') . ";\r\n";
			$body .= "Срок на изплащане в месеца: $jet_vnoski;\r\n";
			$body .= "Месечна вноска: $jet_vnoska;\r\n";
			$body .= "Първоначална вноска: " . number_format(floatval($jet_parva), 2, ".", "") . ";\r\n";
			$jet_count = (int) $this->config->get('module_mt_jet_credit_count');
			$this->editSettingValue('module_mt_jet_credit', 'module_mt_jet_credit_count', $jet_count + 1);
			$subject = $jet_id . ", онлайн заявка по поръчка $jet_count";
			$ccAddresses = trim($toEmail_other) === '' ? [] : explode(",", str_replace(' ', '', $toEmail_other));
			try {
				if (!$order) throw new \RuntimeException('Order persistence failed.');
				$json['order_id'] = $this->session->data['order_id'];
				require_once DIR_SYSTEM . 'library/jet_submission_mail.php';
				$delivery = JetSubmissionMail::deliver($this->config, $ccAddresses, $toEmail_admin, $jet_email, $jet_id, $subject, $body, $jet_egn);
				$json['code'] = $delivery['code'];
				$json['notifications'] = $delivery['notifications'];
				$json['success'] = in_array($delivery['code'], ['bank_sent', 'manual_registered'], true) ? 'success' : 'unsuccess';
				if ($json['success'] === 'success') {
					try {
					$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_jet_order_status_id'));
					} catch (\Throwable $error) {
						$json['order_status_updated'] = false;
					}
				}
			} catch (\Throwable $error) {
				$json['success'] = 'unsuccess';
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function editSettingValue($code = '', $key = '', $value = '', $store_id = 0)
	{
		if (!is_array($value)) {
			$this->db->query("UPDATE " . DB_PREFIX . "setting SET `value` = '" . $this->db->escape($value) . "', serialized = '0'  WHERE `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "' AND store_id = '" . (int)$store_id . "'");
		} else {
			$this->db->query("UPDATE " . DB_PREFIX . "setting SET `value` = '" . $this->db->escape(json_encode($value)) . "', serialized = '1' WHERE `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "' AND store_id = '" . (int)$store_id . "'");
		}
	}
}
