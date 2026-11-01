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
			$rows = $this->db->query('SELECT install_marker,offer_secret,state FROM `' . $this->prefix . "jet_submission` WHERE store_id=0 AND record_type='counter' AND intent=" . $this->q(hash('sha256', 'jet-bank-reference-v1')));
			if ($rows->num_rows !== 1 || $rows->row['install_marker'] !== self::MARKER || $rows->row['state'] !== 'counter') { throw new RuntimeException('marker'); }
			$secret = $rows->row['offer_secret']; new JetCreditOffer($secret);
			if (defined('JETCREDIT_OFFER_SECRET') && !hash_equals($secret, JETCREDIT_OFFER_SECRET)) { throw new RuntimeException('secret mismatch'); }
			(new JetCreditSubmissionStore($this->db, $this->prefix))->ready();
			$this->db->query('SELECT id,jet_product_id,jet_product_percent,jet_product_meseci,jet_product_price,jet_product_start,jet_product_end FROM `' . $this->prefix . 'jet_kop` LIMIT 0');
			$config->set('jetcredit_offer_secret', $secret);
			$config->set('jetcredit_install_marker', self::MARKER);
		} catch (Throwable $e) { throw new JetCreditFinancialException('installation_unavailable'); }
	}
	public function install($config, $saveDefaults) {
		if (!in_array($config->get('config_store_id'), array(null, 0, '0'), true)) { throw new JetCreditFinancialException('unsupported_store'); }
		$lock = 'jet-install-' . substr(hash('sha256', $this->prefix . (defined('DB_DATABASE') ? DB_DATABASE : 'default')), 0, 40);
		$locked = $this->db->query('SELECT GET_LOCK(' . $this->q($lock) . ', 0) AS acquired');
		if ((int)($locked->row['acquired'] ?? 0) !== 1) { throw new JetCreditFinancialException('installation_busy'); }
		try {
			if ($this->exists('jet_submission')) { $this->load($config); return 'reactivated'; }
			$legacy = $this->db->query('SELECT setting_id FROM `' . $this->prefix . "setting` WHERE store_id=0 AND (code='module_mt_jet_credit' OR code IN ('payment_jet','payment_jet_card')) LIMIT 1");
			$orders = $this->db->query('SELECT order_id FROM `' . $this->prefix . "order` WHERE payment_code IN ('jet','jet_card') LIMIT 1");
			if ($this->exists('jet_kop') || $legacy->num_rows || $orders->num_rows) { throw new JetCreditFinancialException('legacy_installation'); }
			if ($config->get('config_currency') !== 'EUR') { throw new JetCreditFinancialException('non_eur_currency'); }
			$eur = $this->db->query('SELECT currency_id,status,value FROM `' . $this->prefix . "currency` WHERE code='EUR'");
			if ($eur->num_rows !== 1 || (int)$eur->row['status'] !== 1 || JetCreditFinancial::number($eur->row['value']) !== 1.0) { throw new JetCreditFinancialException('invalid_eur_base'); }
			$secret = defined('JETCREDIT_OFFER_SECRET') ? JETCREDIT_OFFER_SECRET : bin2hex(random_bytes(32));
			new JetCreditOffer($secret);
			$this->db->query(JetCreditSubmissionSchema::createSql($this->prefix));
			$this->db->query('CREATE TABLE `' . $this->prefix . "jet_kop` (id INT NOT NULL AUTO_INCREMENT,jet_product_id VARCHAR(20) NOT NULL,jet_product_percent DECIMAL(5,2) NOT NULL,jet_product_meseci VARCHAR(50) NOT NULL,jet_product_price DECIMAL(10,2) UNSIGNED NOT NULL,jet_product_start DATE NOT NULL,jet_product_end DATE NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
			// Single insert initializes counter/secret/marker atomically. A partial install
			// remains visibly incomplete, never silently reset or regenerated on retry.
			$this->db->query('INSERT INTO `' . $this->prefix . "jet_submission` (record_type,store_id,intent,counter_value,state,install_marker,offer_secret,created_at,updated_at) VALUES ('counter',0," . $this->q(hash('sha256', 'jet-bank-reference-v1')) . ",0,'initializing'," . $this->q(self::MARKER) . ',' . $this->q($secret) . ',UTC_TIMESTAMP(),UTC_TIMESTAMP())');
			call_user_func($saveDefaults);
			$this->db->query('UPDATE `' . $this->prefix . "jet_submission` SET state='counter',updated_at=UTC_TIMESTAMP() WHERE store_id=0 AND record_type='counter' AND state='initializing' AND install_marker=" . $this->q(self::MARKER));
			if ((int)$this->db->countAffected() !== 1) { throw new RuntimeException('initialization'); }
			$this->load($config); return 'installed';
		} catch (JetCreditFinancialException $e) { throw $e; }
		catch (Throwable $e) { throw new JetCreditFinancialException('installation_unavailable'); }
		finally { $this->db->query('SELECT RELEASE_LOCK(' . $this->q($lock) . ')'); }
	}
	public static function defaults($status = 0) {
		return array('module_mt_jet_credit' => array('module_mt_jet_credit_status' => '0', 'module_mt_jet_credit_status_card' => '0', 'module_mt_jet_credit_cart_show' => '1', 'module_mt_jet_credit_mail' => '', 'module_mt_jet_credit_id' => '', 'module_mt_jet_credit_price' => '75.00', 'module_mt_jet_credit_vnoski' => '12', 'module_mt_jet_credit_purcent' => '1.40', 'module_mt_jet_credit_purcent_card' => '1.00', 'module_mt_jet_credit_gap' => '0', 'module_mt_jet_credit_vnoska' => '1', 'module_mt_jet_credit_button_type' => 'standard', 'module_mt_jet_credit_button_scheme' => '0', 'module_mt_jet_credit_btn_logo' => '1'), 'payment_jet' => array('payment_jet_status' => '0', 'payment_jet_order_status_id' => $status, 'payment_jet_total' => '0', 'payment_jet_geo_zone_id' => '0', 'payment_jet_sort_order' => '0'), 'payment_jet_card' => array('payment_jet_card_status' => '0', 'payment_jet_card_order_status_id' => $status, 'payment_jet_card_total' => '0', 'payment_jet_card_geo_zone_id' => '0', 'payment_jet_card_sort_order' => '0'));
	}
}
