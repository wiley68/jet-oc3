<?php
class ControllerExtensionModuleMTJetCredit extends Controller {

	private $error = array();
	private const JET_VNOSKI_DEFAULT = 12;

	public function index() {
		$this->load->language('extension/module/mt_jet_credit');
		$this->document->setTitle('ПБ Лични Финанси');
		$this->load->model('setting/setting');
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$settings = $this->request->post;
			unset($settings['module_mt_jet_credit_eur'], $settings['module_mt_jet_credit_count']);
			$existing_settings = $this->model_setting_setting->getSetting('module_mt_jet_credit');
			// editSetting replaces the group: retain only the persistent initialization seed.
			if (array_key_exists('module_mt_jet_credit_count', $existing_settings)) {
				$settings['module_mt_jet_credit_count'] = $existing_settings['module_mt_jet_credit_count'];
			}
			if (array_key_exists('module_mt_jet_credit_eur', $existing_settings)) {
				$settings['module_mt_jet_credit_eur'] = $existing_settings['module_mt_jet_credit_eur'];
			}
			$this->model_setting_setting->editSetting('module_mt_jet_credit', $settings);
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
		foreach (array('createSchema', 'deleteSchema') as $schema_action) {
			$schema_url = $this->url->link('extension/module/mt_jet_credit/' . $schema_action, 'user_token=' . $this->session->data['user_token'], true);
			$data[$schema_action . '_url_json'] = json_encode(str_replace('&amp;', '&', $schema_url), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		}
		$data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = $this->session->data['jet_submission_install_error'] ?? '';
		}
		unset($this->session->data['jet_submission_install_error']);
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
		try {
			$data['module_mt_jet_credit_count'] = $this->submissionStore()->getNextReference();
		} catch (\Throwable $error) {
			$data['module_mt_jet_credit_count'] = '';
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

	public function createSchema() {
		if (!$this->validateSchemaRequest()) {
			return;
		}
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
		$this->load->model('extension/module/jetcredit');
		$result = (int) $this->model_extension_module_jetcredit->addKop( $mt_jet_categories_current );
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
		if (!$this->validateSchemaRequest()) {
			return;
		}
		$json = array();
		if (isset($this->request->post['mt_jet_credit_product_id'])) {
			$mt_jet_credit_product_id = $this->request->post['mt_jet_credit_product_id'];
		} else {
			$mt_jet_credit_product_id = '';
		}
		if (!is_string($mt_jet_credit_product_id) || $mt_jet_credit_product_id === '' || utf8_strlen($mt_jet_credit_product_id) > 20) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('success' => 'unsuccess')));
			return;
		}
		$this->load->model('extension/module/jetcredit');
		$result = (int) $this->model_extension_module_jetcredit->deleteKop( $mt_jet_credit_product_id );
		if ( $result > 0 ) {
			$json['success'] = 'success';
		} else {
			$json['success'] = 'unsuccess';
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install() {
		if (!$this->authorizedSubmissionInstall()) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(['success'=>'unsuccess']));
			return;
		}
		$stage = 'settings-read';
		try {
			$this->load->model('setting/setting');
			$existing = $this->model_setting_setting->getSetting('module_mt_jet_credit');
			$stage = 'storage-preparation';
			$this->prepareSubmissionStorage($existing);
			// Native uninstall may have removed settings. Never guess a seed from absence.
			if (!$existing) {
				$stage = 'settings-initialization';
				$settings = $this->request->post;
				unset($settings['module_mt_jet_credit_count']);
				$this->model_setting_setting->editSetting('module_mt_jet_credit', $settings);
			}
			$stage = 'promotion-preparation';
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
		} catch (\Throwable $error) {
			$this->logSubmissionPreparationFailure($stage, $error);
			$this->session->data['jet_submission_install_error'] = 'Инсталацията не е завършена. Подаването на заявления остава недостъпно при неподготвено хранилище. Проверете административния журнал; не повтаряйте деинсталация/инсталация.';
			unset($this->session->data['success']);
			// Native redirect terminates the request before core can report install success.
			$this->response->redirect($this->url->link('extension/module/mt_jet_credit', 'user_token=' . $this->session->data['user_token'], true));
			return;
		}
	}

	private function authorizedSubmissionInstall() {
		$token = $this->request->get['user_token'] ?? null;
		$expected = $this->session->data['user_token'] ?? null;
		if (!$this->user->isLogged() || !is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) return false;
		if ($this->user->hasPermission('modify', 'extension/module/mt_jet_credit')) return true;
		// Core grants module rights in DB after Cart\User loaded its permission snapshot.
		// This exception belongs exclusively to the authenticated native install route.
		return ($this->request->get['route'] ?? '') === 'extension/extension/module/install'
			&& ($this->request->get['extension'] ?? '') === 'mt_jet_credit'
			&& $this->user->hasPermission('modify', 'extension/extension/module');
	}

	private function logSubmissionPreparationFailure($stage, $error) {
		// Only fixed reason codes; never log SQL, exception arguments/messages or tokens.
		$reasons = [
			'Invalid reference'=>'reference-unavailable-or-invalid',
			'Submission storage connection failed'=>'connection-failed',
			'Submission storage operation failed'=>'operation-failed',
			'Transactional row-locking storage required'=>'transactional-storage-required',
			'Cannot verify database durability'=>'durability-unverified',
			'Autocommit required'=>'autocommit-required',
			'Durable commits required'=>'durable-commits-required',
			'Unknown binary log configuration'=>'binary-log-unknown',
			'Durable binary log required'=>'durable-binlog-required',
			'Partial submission schema; preparation refused'=>'partial-schema',
			'Invalid submission metadata'=>'invalid-metadata',
			'Counter/ledger inconsistency'=>'counter-ledger-inconsistent',
			'Submission column default mismatch'=>'column-default-mismatch',
			'Submission unique constraints mismatch'=>'unique-constraints-mismatch'
		];
		$file = basename($error->getFile());
		if (!in_array($file, ['mt_jet_credit.php','jet_submission_store.php'], true)) $file = 'dependency';
		$message = 'JET-SUBMISSION-PREPARE stage=' . $stage . ' reason=' . ($reasons[$error->getMessage()] ?? 'unexpected-failure')
			. ' source=' . $file . ':' . (int)$error->getLine();
		try { $this->log->write($message); } catch (\Throwable $ignored) { /* Reporting must not cause another install failure. */ }
	}

	public function uninstall() {
		if (!$this->user->hasPermission('modify', 'extension/module/mt_jet_credit')) {
			throw new \RuntimeException('Module modification permission required.');
		}
		// Native core may already have deleted module settings. This hook retains
		// promotion rules, ledger, key and reserved references.
		$this->load->controller('extension/modification/refresh');
	}

	// Explicit non-destructive upgrade for already installed v2.0.1 modules.
	// Invoke via authenticated admin POST with the normal user_token; no catalog DDL.
	public function prepareSubmissionSchema() {
		if (!$this->validateSchemaRequest()) return;
		$token = $this->request->get['user_token'] ?? null;
		$expected = $this->session->data['user_token'] ?? null;
		$json = ['success' => 'unsuccess'];
		if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
		} else {
			try {
				$this->load->model('setting/setting');
				$this->prepareSubmissionStorage($this->model_setting_setting->getSetting('module_mt_jet_credit'));
				$json['success'] = 'success';
			} catch (\Throwable $error) {
				$this->logSubmissionPreparationFailure('explicit-preparation', $error);
				// Do not expose SQL, schema details or connection credentials.
				$json['error'] = 'Хранилището не е подготвено или е несъвместимо. Подготовката е отказана; броячът не е нулиран.';
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	protected function submissionStore() {
		require_once DIR_SYSTEM . 'library/jet_submission_store.php';
		return JetSubmissionStore::connect();
	}

	private function prepareSubmissionStorage($settings) {
		// Existing storage is verified first by prepare(), even without a legacy seed.
		// Empty settings cannot prove a first install: core uninstall deletes them too.
		$this->submissionStore()->prepare($settings['module_mt_jet_credit_count'] ?? null);
	}

	private function validateSchemaRequest() {
		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
			$this->response->addHeader('Allow: POST');
		} elseif (!$this->user->hasPermission('modify', 'extension/module/mt_jet_credit')) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
		} else {
			return true;
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('success' => 'unsuccess')));
		return false;
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
