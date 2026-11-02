<?php
require_once __DIR__ . '/submission_schema.php';
require_once __DIR__ . '/submission_store.php';
require_once __DIR__ . '/offer.php';
/** Clean installs only. One protected counter row owns secret and installation marker. */
class JetCreditInstallation {
	public const MARKER = 'jet-oc3-clean-v1';
	private $db; private $prefix;
	public function __construct($db, $prefix) { JetCreditSubmissionSchema::createSql($prefix); $this->db = $db; $this->prefix = $prefix; }
	private function q($value) { return "'" . $this->db->escape($value) . "'"; }
	private function exists($name) { return $this->db->query('SHOW TABLE STATUS WHERE Name=' . $this->q($this->prefix . $name))->num_rows > 0; }
	public function load($config) {
		try {
			if (!$this->exists('jet_submission')) { throw new JetCreditFinancialException('missing_schema'); }
			(new JetCreditSubmissionStore($this->db, $this->prefix))->ready(true);
			$this->promotionReady();
			$rows = $this->db->query('SELECT install_marker,offer_secret,state FROM `' . $this->prefix . "jet_submission` WHERE store_id=0 AND record_type='counter' AND intent=" . $this->q(hash('sha256', 'jet-bank-reference-v1')));
			if ($rows->num_rows !== 1 || $rows->row['install_marker'] !== self::MARKER || $rows->row['state'] !== 'counter') { throw new JetCreditFinancialException('incomplete_schema'); }
			$secret = $rows->row['offer_secret']; new JetCreditOffer($secret);
			if (defined('JETCREDIT_OFFER_SECRET') && !hash_equals($secret, JETCREDIT_OFFER_SECRET)) { throw new JetCreditFinancialException('incomplete_schema'); }
			(new JetCreditSubmissionStore($this->db, $this->prefix))->ready();
			$this->db->query('SELECT id,jet_product_id,jet_product_percent,jet_product_meseci,jet_product_price,jet_product_start,jet_product_end FROM `' . $this->prefix . 'jet_kop` LIMIT 0');
			$config->set('jetcredit_offer_secret', $secret);
			$config->set('jetcredit_install_marker', self::MARKER);
		} catch (JetCreditFinancialException $e) { throw $e; }
		catch (Throwable $e) { throw new JetCreditFinancialException('installation_unavailable'); }
	}
	private function promotionReady() {
		if (!$this->exists('jet_kop')) { throw new JetCreditFinancialException('incomplete_schema'); }
		$table = $this->db->query('SHOW TABLE STATUS WHERE Name=' . $this->q($this->prefix . 'jet_kop'));
		if ($table->num_rows !== 1 || strcasecmp($table->row['Engine'] ?? '', 'InnoDB') !== 0) { throw new JetCreditFinancialException('incomplete_schema'); }
		$rows = $this->db->query('SHOW COLUMNS FROM `' . $this->prefix . 'jet_kop`');
		if (array_diff(array('id','jet_product_id','jet_product_percent','jet_product_meseci','jet_product_price','jet_product_start','jet_product_end'), array_column($rows->rows, 'Field'))) { throw new JetCreditFinancialException('incomplete_schema'); }
		$indexes = $this->db->query('SHOW INDEX FROM `' . $this->prefix . 'jet_kop`');
		$primary = array_filter($indexes->rows, function ($row) { return $row['Key_name'] === 'PRIMARY' && (int)$row['Non_unique'] === 0 && (int)$row['Seq_in_index'] === 1 && $row['Column_name'] === 'id'; });
		if (count($primary) !== 1) { throw new JetCreditFinancialException('incomplete_schema'); }
	}
	public function install($config, $saveDefaults) {
		if (!in_array($config->get('config_store_id'), array(null, 0, '0'), true)) { throw new JetCreditFinancialException('unsupported_store'); }
		$lock = 'jet-install-' . substr(hash('sha256', $this->prefix . (defined('DB_DATABASE') ? DB_DATABASE : 'default')), 0, 40);
		$locked = $this->db->query('SELECT GET_LOCK(' . $this->q($lock) . ', 0) AS acquired');
		if ((int)($locked->row['acquired'] ?? 0) !== 1) { throw new JetCreditFinancialException('installation_busy'); }
		try {
			if ($this->exists('jet_submission')) { $this->load($config); return 'reactivated'; }
			$legacy = $this->db->query('SELECT code,`key`,value,serialized FROM `' . $this->prefix . "setting` WHERE store_id=0 AND (code='module_mt_jet_credit' OR code IN ('payment_jet','payment_jet_card'))");
			$orders = $this->db->query('SELECT order_id FROM `' . $this->prefix . "order` WHERE payment_code IN ('jet','jet_card') LIMIT 1");
			if ($this->exists('jet_kop')) { throw new JetCreditFinancialException('legacy_promotions_owner_review'); }
			foreach ($legacy->rows as $setting) {
				// A disabled-status-only residue carries no legacy financial values.
				if (!isset($setting['code'],$setting['key'],$setting['value'],$setting['serialized']) || $setting['key'] !== $setting['code'] . '_status' || (string)$setting['value'] !== '0' || (int)$setting['serialized'] !== 0) { throw new JetCreditFinancialException('legacy_conflict_owner_review'); }
			}
			$mods = $this->db->query('SELECT version,xml FROM `' . $this->prefix . "modification` WHERE code='cc_jet'");
			foreach ($mods->rows as $mod) {
				if ($mod['version'] !== '2.0.0 for OpenCart 3.x' || strpos($mod['xml'], 'JETCREDIT:NATIVE-INDEX:v1') === false || strpos($mod['xml'], 'JETCREDIT:CHECKOUT-ORDER:v1') === false) { throw new JetCreditFinancialException('legacy_ocmod_owner_review'); }
			}
			try { $eur = $this->db->query('SELECT currency_id,status,value FROM `' . $this->prefix . "currency` WHERE code='EUR'");
			if ($eur->num_rows !== 1 || (int)$eur->row['status'] !== 1 || JetCreditFinancial::number($eur->row['value']) <= 0) { throw new JetCreditFinancialException('invalid_eur_currency'); }
			} catch (Throwable $e) { throw new JetCreditFinancialException('invalid_eur_currency'); }
			try { $secret = defined('JETCREDIT_OFFER_SECRET') ? JETCREDIT_OFFER_SECRET : bin2hex(random_bytes(32)); new JetCreditOffer($secret); } catch (Throwable $e) { throw new JetCreditFinancialException('secret_initialization_failed'); }
			try { $this->db->query(JetCreditSubmissionSchema::createSql($this->prefix));
			$this->db->query('CREATE TABLE `' . $this->prefix . "jet_kop` (id INT NOT NULL AUTO_INCREMENT,jet_product_id VARCHAR(20) NOT NULL,jet_product_percent DECIMAL(5,2) NOT NULL,jet_product_meseci VARCHAR(50) NOT NULL,jet_product_price DECIMAL(10,2) UNSIGNED NOT NULL,jet_product_start DATE NOT NULL,jet_product_end DATE NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
			} catch (Throwable $e) { throw new JetCreditFinancialException('schema_creation_failed'); }
			// Single insert initializes counter/secret/marker atomically. A partial install
			// remains visibly incomplete, never silently reset or regenerated on retry.
			$this->db->query('INSERT INTO `' . $this->prefix . "jet_submission` (record_type,store_id,intent,counter_value,state,install_marker,offer_secret,created_at,updated_at) VALUES ('counter',0," . $this->q(hash('sha256', 'jet-bank-reference-v1')) . ",0,'initializing'," . $this->q(self::MARKER) . ',' . $this->q($secret) . ',UTC_TIMESTAMP(),UTC_TIMESTAMP())');
			call_user_func($saveDefaults);
			$this->db->query('UPDATE `' . $this->prefix . "jet_submission` SET state='counter',updated_at=UTC_TIMESTAMP() WHERE store_id=0 AND record_type='counter' AND state='initializing' AND install_marker=" . $this->q(self::MARKER));
			if ((int)$this->db->countAffected() !== 1) { throw new RuntimeException('initialization'); }
			$this->load($config); return $orders->num_rows ? 'installed_historical_only' : 'installed';
		} catch (JetCreditFinancialException $e) { throw $e; }
		catch (Throwable $e) { throw new JetCreditFinancialException('installation_unavailable'); }
		finally { $this->db->query('SELECT RELEASE_LOCK(' . $this->q($lock) . ')'); }
	}
	public static function diagnostic($error) {
		$messages = array(
			'unsupported_store'=>'Разрешен е само default store.',
			'installation_busy'=>'Друга инсталация държи lock; опитайте след приключването ѝ.',
			'legacy_conflict_owner_review'=>'Намерени са стари финансови настройки; необходим е owner review, без автоматично изтриване или миграция.',
			'legacy_promotions_owner_review'=>'Намерена е съществуваща jet_kop; прегледайте структурата и валутата на промоциите.',
			'legacy_ocmod_owner_review'=>'Намерена е стара или неразпозната Jet OCMOD модификация; необходим е owner review.',
			'missing_schema'=>'Новата submission таблица липсва; модулът още не е инициализиран.',
			'incomplete_schema'=>'Схемата или seed записът е непълен; не нулирайте secret/counter.',
			'incorrect_schema_revision'=>'Несъвместима schema revision; няма автоматична миграция.',
			'invalid_eur_currency'=>'Необходима е инсталирана активна EUR с валиден положителен курс.',
			'offer_secret_unavailable'=>'Съществуващият secret е невалиден; необходим е owner review без reset.',
			'secret_initialization_failed'=>'Secret не може да бъде инициализиран безопасно.',
			'schema_creation_failed'=>'Създаването на схемата е неуспешно; проверете DB правата и частичното състояние.',
			'installation_unavailable'=>'Инсталацията е недостъпна; необходим е owner review.',
			'submission_storage_unavailable'=>'Схемата/връзката не удовлетворява изискванията за безопасно съхранение.',
			'authorization'=>'Неуспешна проверка на права, token или store scope.'
		);
		$code = $error instanceof JetCreditFinancialException ? $error->getMessage() : 'installation_unavailable';
		if (!isset($messages[$code])) { $code = 'installation_unavailable'; }
		return '[' . $code . '] ' . $messages[$code];
	}
	public static function defaults($status = 0) {
		return array('module_mt_jet_credit' => array('module_mt_jet_credit_status' => '0', 'module_mt_jet_credit_status_card' => '0', 'module_mt_jet_credit_cart_show' => '1', 'module_mt_jet_credit_mail' => '', 'module_mt_jet_credit_id' => '', 'module_mt_jet_credit_price' => '75.00', 'module_mt_jet_credit_vnoski' => '12', 'module_mt_jet_credit_purcent' => '1.40', 'module_mt_jet_credit_purcent_card' => '1.00', 'module_mt_jet_credit_gap' => '0', 'module_mt_jet_credit_vnoska' => '1', 'module_mt_jet_credit_button_type' => 'standard', 'module_mt_jet_credit_button_scheme' => '0', 'module_mt_jet_credit_btn_logo' => '1'), 'payment_jet' => array('payment_jet_status' => '0', 'payment_jet_order_status_id' => $status, 'payment_jet_total' => '0', 'payment_jet_geo_zone_id' => '0', 'payment_jet_sort_order' => '0'), 'payment_jet_card' => array('payment_jet_card_status' => '0', 'payment_jet_card_order_status_id' => $status, 'payment_jet_card_total' => '0', 'payment_jet_card_geo_zone_id' => '0', 'payment_jet_card_sort_order' => '0'));
	}
}
