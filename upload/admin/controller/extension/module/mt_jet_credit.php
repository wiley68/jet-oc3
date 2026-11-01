<?php
class ControllerExtensionModuleMTJetCredit extends Controller {

	private $error = array();
	private const JET_VNOSKI_DEFAULT = 12;

	public function index() {
		$this->load->language('extension/module/mt_jet_credit');
		$this->document->setTitle('ПБ Лични Финанси');
		$this->load->model('setting/setting');
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_mt_jet_credit', $this->request->post);
			$this->session->data['success'] = 'Успешно записахте направените промени.';
			$this->cache->delete('module_mt_jet_credit');
			$this->response->redirect($this->url->link('extension/module/mt_jet_credit', 'user_token=' . $this->session->data['user_token'], true));
		}
		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => 'Начало',
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => 'Модули',
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => 'ПБ Лични Финанси',
			'href' => $this->url->link('extension/module/mt_jet_credit', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true);
		$data['form_action'] = $this->url->link('extension/module/mt_jet_credit', 'user_token=' . $this->session->data['user_token'], true);
		$data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}
		if (isset($this->request->post['module_mt_jet_credit_status'])) {
			$data['module_mt_jet_credit_status'] = $this->request->post['module_mt_jet_credit_status'];
		} else {
			$data['module_mt_jet_credit_status'] = $this->config->get('module_mt_jet_credit_status');
		}
		if (isset($this->request->post['module_mt_jet_credit_mail'])) {
			$data['module_mt_jet_credit_mail'] = $this->request->post['module_mt_jet_credit_mail'];
		} else {
			$data['module_mt_jet_credit_mail'] = $this->config->get('module_mt_jet_credit_mail');
		}
		if (isset($this->request->post['module_mt_jet_credit_cart_show'])) {
			$data['module_mt_jet_credit_cart_show'] = $this->request->post['module_mt_jet_credit_cart_show'];
		} else {
			$data['module_mt_jet_credit_cart_show'] = $this->config->get('module_mt_jet_credit_cart_show') == '' ? 1 : $this->config->get('module_mt_jet_credit_cart_show');
		}
		if (isset($this->request->post['module_mt_jet_credit_id'])) {
			$data['module_mt_jet_credit_id'] = $this->request->post['module_mt_jet_credit_id'];
		} else {
			$data['module_mt_jet_credit_id'] = $this->config->get('module_mt_jet_credit_id');
		}
		if (isset($this->request->post['module_mt_jet_credit_status_card'])) {
			$data['module_mt_jet_credit_status_card'] = $this->request->post['module_mt_jet_credit_status_card'];
		} else {
			$data['module_mt_jet_credit_status_card'] = $this->config->get('module_mt_jet_credit_status_card') == '' ? 1 : $this->config->get('module_mt_jet_credit_status_card');
		}
		if (isset($this->request->post['module_mt_jet_credit_vnoski'])) {
			$data['module_mt_jet_credit_vnoski'] = $this->request->post['module_mt_jet_credit_vnoski'];
		} else {
			$data['module_mt_jet_credit_vnoski'] = $this->config->get('module_mt_jet_credit_vnoski') == '' ? self::JET_VNOSKI_DEFAULT : $this->config->get('module_mt_jet_credit_vnoski');
		}
		if (isset($this->request->post['module_mt_jet_credit_gap'])) {
			$data['module_mt_jet_credit_gap'] = $this->request->post['module_mt_jet_credit_gap'];
		} else {
			$data['module_mt_jet_credit_gap'] = $this->config->get('module_mt_jet_credit_gap') == '' ? 0 : $this->config->get('module_mt_jet_credit_gap');
		}
		if (isset($this->request->post['module_mt_jet_credit_button_type'])) {
			$data['module_mt_jet_credit_button_type'] = $this->request->post['module_mt_jet_credit_button_type'];
		} else {
			$data['module_mt_jet_credit_button_type'] = $this->config->get('module_mt_jet_credit_button_type') == '' ? 'standard' : $this->config->get('module_mt_jet_credit_button_type');
		}
		if (isset($this->request->post['module_mt_jet_credit_button_scheme'])) {
			$_js = (int) $this->request->post['module_mt_jet_credit_button_scheme'];
			$data['module_mt_jet_credit_button_scheme'] = min(6, max(0, $_js));
		} else {
			$_jsc = $this->config->get('module_mt_jet_credit_button_scheme');
			$data['module_mt_jet_credit_button_scheme'] = $_jsc === null || $_jsc === '' ? 0 : min(6, max(0, (int) $_jsc));
		}
		$default_jet_btn_text = 'Купи на изплащане с';
		if (isset($this->request->post['module_mt_jet_credit_btn_text'])) {
			$_t = trim((string) $this->request->post['module_mt_jet_credit_btn_text']);
			$data['module_mt_jet_credit_btn_text'] = $_t === '' ? $default_jet_btn_text : $_t;
		} else {
			$_bt = $this->config->get('module_mt_jet_credit_btn_text');
			$data['module_mt_jet_credit_btn_text'] = $_bt === null || $_bt === '' ? $default_jet_btn_text : $_bt;
		}
		$default_jet_btn_text_card = 'На вноски с твоята кредитна карта';
		if (isset($this->request->post['module_mt_jet_credit_btn_text_card'])) {
			$_tc = trim((string) $this->request->post['module_mt_jet_credit_btn_text_card']);
			$data['module_mt_jet_credit_btn_text_card'] = $_tc === '' ? $default_jet_btn_text_card : $_tc;
		} else {
			$_btc = $this->config->get('module_mt_jet_credit_btn_text_card');
			$data['module_mt_jet_credit_btn_text_card'] = $_btc === null || $_btc === '' ? $default_jet_btn_text_card : $_btc;
		}
		if (isset($this->request->post['module_mt_jet_credit_btn_logo'])) {
			$data['module_mt_jet_credit_btn_logo'] = (int) $this->request->post['module_mt_jet_credit_btn_logo'] ? 1 : 0;
		} else {
			$_jbl = $this->config->get('module_mt_jet_credit_btn_logo');
			$data['module_mt_jet_credit_btn_logo'] = $_jbl === null || $_jbl === '' ? 1 : ((int) $_jbl ? 1 : 0);
		}
		if (isset($this->request->post['module_mt_jet_credit_btn_max_width'])) {
			$data['module_mt_jet_credit_btn_max_width'] = $this->normalizeBtnMaxWidth($this->request->post['module_mt_jet_credit_btn_max_width']);
		} else {
			$data['module_mt_jet_credit_btn_max_width'] = $this->normalizeBtnMaxWidth($this->config->get('module_mt_jet_credit_btn_max_width'));
		}
		if (isset($this->request->post['module_mt_jet_credit_btn_round'])) {
			$data['module_mt_jet_credit_btn_round'] = $this->normalizeBtnRound($this->request->post['module_mt_jet_credit_btn_round']);
		} else {
			$data['module_mt_jet_credit_btn_round'] = $this->normalizeBtnRound($this->config->get('module_mt_jet_credit_btn_round'));
		}
		if (isset($this->request->post['module_mt_jet_credit_btn_font'])) {
			$data['module_mt_jet_credit_btn_font'] = $this->normalizeBtnFont($this->request->post['module_mt_jet_credit_btn_font']);
		} else {
			$data['module_mt_jet_credit_btn_font'] = $this->normalizeBtnFont($this->config->get('module_mt_jet_credit_btn_font'));
		}
		if (! class_exists('JetButtonScheme', false)) {
			require_once DIR_SYSTEM . 'library/jet_button_scheme.php';
		}
		$data['mt_jet_button_schemes'] = JetButtonScheme::getSchemes();
		$data['mt_jet_button_scheme_label'] = '';
		$_sc = JetButtonScheme::getScheme($data['module_mt_jet_credit_button_scheme']);
		if (is_array($_sc) && isset($_sc['label'])) {
			$data['mt_jet_button_scheme_label'] = $_sc['label'];
		}
		$data['mt_jet_button_scheme_style'] = JetButtonScheme::wrapInlineStyle($data['module_mt_jet_credit_button_scheme']);
		$data['mt_jet_wide_button_wrap_style'] = $data['mt_jet_button_scheme_style'] . '--jet-wide-max-width:' . (int) $data['module_mt_jet_credit_btn_max_width'] . 'px;--jet-wide-radius:' . (int) $data['module_mt_jet_credit_btn_round'] . 'px;--jet-wide-font-size:' . (int) $data['module_mt_jet_credit_btn_font'] . 'px;';
		$data['mt_jet_button_scheme_style_json'] = array();
		foreach (JetButtonScheme::getSchemes() as $k => $_s) {
			$data['mt_jet_button_scheme_style_json'][(string) $k] = JetButtonScheme::wrapInlineStyle($k);
		}
		$data['mt_jet_button_scheme_labels_json'] = json_encode(
			array_map(function ($s) { return $s['label'] ?? ''; }, $data['mt_jet_button_schemes']),
			JSON_UNESCAPED_UNICODE
		);
		$data['mt_jet_button_scheme_styles_for_js'] = json_encode(
			$data['mt_jet_button_scheme_style_json'],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if (isset($this->request->post['module_mt_jet_credit_purcent'])) {
			$data['module_mt_jet_credit_purcent'] = $this->request->post['module_mt_jet_credit_purcent'];
		} else {
			$data['module_mt_jet_credit_purcent'] = $this->config->get('module_mt_jet_credit_purcent') == '' ? 1.40 : $this->config->get('module_mt_jet_credit_purcent');
		}
		if (isset($this->request->post['module_mt_jet_credit_purcent_card'])) {
			$data['module_mt_jet_credit_purcent_card'] = $this->request->post['module_mt_jet_credit_purcent_card'];
		} else {
			$data['module_mt_jet_credit_purcent_card'] = $this->config->get('module_mt_jet_credit_purcent_card') == '' ? 1.00 : $this->config->get('module_mt_jet_credit_purcent_card');
		}
		if (isset($this->request->post['module_mt_jet_credit_vnoska'])) {
			$data['module_mt_jet_credit_vnoska'] = $this->request->post['module_mt_jet_credit_vnoska'];
		} else {
			$data['module_mt_jet_credit_vnoska'] = $this->config->get('module_mt_jet_credit_vnoska') == '' ? 1 : $this->config->get('module_mt_jet_credit_vnoska');
		}
		if (isset($this->request->post['module_mt_jet_credit_count'])) {
			$data['module_mt_jet_credit_count'] = $this->request->post['module_mt_jet_credit_count'];
		} else {
			$data['module_mt_jet_credit_count'] = $this->config->get('module_mt_jet_credit_count') == "" ? 1 : $this->config->get('module_mt_jet_credit_count');
		}
		if (isset($this->request->post['module_mt_jet_credit_eur'])) {
			$data['module_mt_jet_credit_eur'] = $this->request->post['module_mt_jet_credit_eur'];
		} else {
			$data['module_mt_jet_credit_eur'] = $this->config->get('module_mt_jet_credit_eur') == "" ? 0 : $this->config->get('module_mt_jet_credit_eur');
		}
		if (isset($this->request->post['module_mt_jet_credit_price'])) {
			$data['module_mt_jet_credit_price'] = $this->request->post['module_mt_jet_credit_price'];
		} else {
			$data['module_mt_jet_credit_price'] = $this->config->get('module_mt_jet_credit_price') == "" ? 75 : $this->config->get('module_mt_jet_credit_price');
		}
		$this->load->model('extension/module/jetcredit');
		$mt_jet_schemes = $this->model_extension_module_jetcredit->getAllKops();
		$data['module_mt_jet_schemes'] = $mt_jet_schemes;
		$store_base = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off' ? HTTPS_CATALOG : HTTP_CATALOG;
		$data['mt_jet_preview_jet'] = $store_base . 'catalog/view/theme/default/image/jet.png';
		$data['mt_jet_preview_jet_logo'] = $store_base . 'catalog/view/theme/default/image/jet_logo.png';
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/module/mt_jet_credit', $data));
		unset($this->session->data['success']);
	}

	public function install() {
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_mt_jet_credit', $this->request->post);
		$table_kop_name = DB_PREFIX . 'jet_kop';
		$this->db->query("CREATE TABLE IF NOT EXISTS `$table_kop_name` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`jet_product_id` varchar(20) NOT NULL,
			`jet_product_percent` DECIMAL(5,2) NOT NULL,
			`jet_product_meseci` varchar(50) NOT NULL,
			`jet_product_price` DECIMAL(10,2) UNSIGNED NOT NULL,
			`jet_product_start` DATE NOT NULL,
			`jet_product_end` DATE NOT NULL,
			PRIMARY KEY (`id`),
			FULLTEXT idx (`jet_product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
	}
	
	public function uninstall() {
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('module_mt_jet_credit');
		$table_kop_name = DB_PREFIX . 'jet_kop';
		$this->db->query("DROP TABLE IF EXISTS `$table_kop_name`;");
		$this->load->controller('extension/modification/refresh');
	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/mt_jet_credit')) {
			$this->error['warning'] = 'Нямате права за тази операция!';
		}
	
		if (isset($this->request->post['module_mt_jet_credit_gap'])) {
			$cr_gap = $this->request->post['module_mt_jet_credit_gap'];
		} else {
			$cr_gap = $this->config->get('module_mt_jet_credit_gap');
		}
		if (!is_numeric($cr_gap)) {
			$this->error['warning'] = 'Моля въведете числова стойност в полето "Празно място над бутона"!';
		}
	
		if (isset($this->request->post['module_mt_jet_credit_id'])) {
			$cr_id = $this->request->post['module_mt_jet_credit_id'];
		} else {
			$cr_id = $this->config->get('module_mt_jet_credit_id');
		}
		if (trim($cr_id) == '') {
			$this->error['warning'] = 'Необходимо е да въведете стойност в полето "Избери идентификатор на магазина за изпращане"!';
		}
	
		if (isset($this->request->post['module_mt_jet_credit_price'])) {
			$cr_price = $this->request->post['module_mt_jet_credit_price'];
		} else {
			$cr_price = $this->config->get('module_mt_jet_credit_price');
		}
		if (!is_numeric($cr_price)) {
			$this->error['warning'] = 'Моля въведете числова стойност в полето "Минимална сума"!';
		}

		if (isset($this->request->post['module_mt_jet_credit_btn_max_width'])) {
			$raw_mw = $this->request->post['module_mt_jet_credit_btn_max_width'];
			if ($raw_mw !== '' && $raw_mw !== null) {
				if (!is_numeric($raw_mw) || (int) $raw_mw < 30 || (int) $raw_mw > 1200) {
					$this->error['warning'] = 'Поле "Максимална ширина на бутона" трябва да е цяло число (px) между 30 и 1200.';
				}
			}
		}
		if (isset($this->request->post['module_mt_jet_credit_btn_round'])) {
			$raw_r = $this->request->post['module_mt_jet_credit_btn_round'];
			if ($raw_r !== '' && $raw_r !== null) {
				if (!is_numeric($raw_r) || (int) $raw_r < 0 || (int) $raw_r > 25) {
					$this->error['warning'] = 'Поле "Радиус на закръгление" трябва да е цяло число (px) между 0 и 25.';
				}
			}
		}
		if (isset($this->request->post['module_mt_jet_credit_btn_font'])) {
			$raw_f = $this->request->post['module_mt_jet_credit_btn_font'];
			if ($raw_f !== '' && $raw_f !== null) {
				if (!is_numeric($raw_f) || (int) $raw_f < 6 || (int) $raw_f > 36) {
					$this->error['warning'] = 'Поле "Размер на шрифт в бутона" трябва да е цяло число (px) между 6 и 36.';
				}
			}
		}
	
		if ($this->error) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * @param mixed $v
	 * @return int
	 */
	private function normalizeBtnMaxWidth($v) {
		if ($v === null || $v === '') {
			return 570;
		}
		$n = (int) $v;
		if ($n < 30) {
			return 30;
		}
		if ($n > 1200) {
			return 1200;
		}
		return $n;
	}

	/**
	 * @param mixed $v
	 * @return int
	 */
	private function normalizeBtnRound($v) {
		if ($v === null || $v === '') {
			return 16;
		}
		$n = (int) $v;
		if ($n < 0) {
			return 0;
		}
		if ($n > 25) {
			return 25;
		}
		return $n;
	}

	/**
	 * @param mixed $v
	 * @return int
	 */
	private function normalizeBtnFont($v) {
		if ($v === null || $v === '') {
			return 14;
		}
		$n = (int) $v;
		if ($n < 6) {
			return 6;
		}
		if ($n > 36) {
			return 36;
		}
		return $n;
	}
}