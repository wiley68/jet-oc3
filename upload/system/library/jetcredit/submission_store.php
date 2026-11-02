<?php
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/submission_schema.php';
/** Requires a dedicated OC3 DB connection owned only by this repository.
 * InnoDB claim-only transactions. Native order tables are NEVER part of rollback. */
class JetCreditSubmissionStore {
	private $db;
	private $table;
	private $store;
	private $ownsTransaction = false;
	public function __construct($db, $prefix, $store = 0) {
		if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) || !is_int($store) || $store < 0) { throw new JetCreditFinancialException('submission_storage_unavailable'); }
		$this->db = $db; $this->table = $prefix . 'jet_submission'; $this->store = $store;
	}
	private function q($value) { return "'" . $this->db->escape((string)$value) . "'"; }
	private function digest($value) {
		if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new JetCreditFinancialException('invalid_application_context'); }
		return $this->q($value);
	}
	public function ready($structureOnly = false) {
		try {
			$this->connectionReady();
			$table = $this->db->query('SHOW TABLE STATUS WHERE Name = ' . $this->q($this->table));
			if ($table->num_rows !== 1 || strcasecmp($table->row['Engine'] ?? '', 'InnoDB') !== 0) { throw new RuntimeException('engine'); }
			$columns = $this->db->query('SHOW COLUMNS FROM `' . $this->table . '`');
			$names = array_column($columns->rows, 'Field');
			preg_match_all('/^ ([a-z_]+) [A-Z]/m', JetCreditSubmissionSchema::createSql(''), $required);
			if (array_diff($required[1], $names)) { throw new JetCreditFinancialException('incomplete_schema'); }
			$indexes = $this->db->query('SHOW INDEX FROM `' . $this->table . '`'); $found = array();
			foreach ($indexes->rows as $index) {
				if ((int)$index['Non_unique'] === 0) { $found[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name']; }
			}
			foreach (array('PRIMARY' => array('submission_id'), 'jet_intent' => array('store_id', 'record_type', 'intent'), 'jet_order' => array('store_id', 'record_type', 'order_id'), 'jet_reference' => array('store_id', 'bank_reference'), 'jet_successor' => array('store_id','record_type','previous_intent'), 'jet_generation' => array('store_id','record_type','purchase_scope','purchase_generation')) as $name => $columns) {
				$actual = $found[$name] ?? array(); ksort($actual);
				if (array_values($actual) !== $columns) { throw new RuntimeException('unique'); }
			}
			if ($structureOnly) { return; }
			$row = $this->db->query("SELECT schema_version FROM `" . $this->table . "` WHERE record_type='counter' AND store_id=" . $this->store . ' AND intent=' . $this->q(hash('sha256', 'jet-bank-reference-v1')));
			if ($row->num_rows !== 1) { throw new JetCreditFinancialException('incomplete_schema'); }
			if ((int)$row->row['schema_version'] !== 2) { throw new JetCreditFinancialException('incorrect_schema_revision'); }
			// Probe every required column without modifying the database.
			$this->db->query('SELECT submission_id,authorization,request_digest,owner_run,purchase_scope,purchase_generation,previous_intent,order_digest,context,scheme,order_id,state,recipient_mode,bank_state,bank_reference,counter_value,admin_state,customer_state,cleanup_state,result,consent_flags,consent_version,consent_at,created_at,updated_at FROM `' . $this->table . '` LIMIT 0');
		} catch (JetCreditFinancialException $e) { throw $e; }
		catch (Throwable $e) { throw new JetCreditFinancialException('submission_storage_unavailable'); }
	}
	private function connectionReady() {
		try {
			$row = $this->db->query('SELECT @@session.autocommit AS autocommit');
			if ($row->num_rows !== 1 || (int)$row->row['autocommit'] !== 1) { throw new RuntimeException('connection'); }
		} catch (Throwable $e) { throw new JetCreditFinancialException('submission_storage_unavailable'); }
	}
	public function find($intent, $order = null) {
		$identity = 'intent=' . $this->digest($intent);
		if ($order !== null) { $identity = '(' . $identity . ' OR order_id=' . JetCreditFinancial::positiveInteger($order) . ')'; }
		$rows = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE record_type='claim' AND store_id=" . $this->store . ' AND ' . $identity);
		if ($rows->num_rows > 1) { throw new JetCreditFinancialException('submission_conflict'); }
		return $rows->num_rows ? $rows->row : null;
	}
	/** Read-only metadata, deliberately separate from executable submission claims. */
	public function latestGeneration($scope) {
		$rows=$this->db->query("SELECT * FROM `".$this->table."` WHERE record_type='generation' AND store_id=".$this->store.' AND purchase_scope='.$this->digest($scope).' ORDER BY purchase_generation DESC LIMIT 1');
		return $rows->num_rows ? $rows->row : null;
	}
	public function generation($scope,$intent) {
		$rows=$this->db->query("SELECT * FROM `".$this->table."` WHERE record_type='generation' AND store_id=".$this->store.' AND purchase_scope='.$this->digest($scope).' AND intent='.$this->digest($intent));
		return $rows->num_rows===1 ? $rows->row : null;
	}
	public function advanceGeneration($scope,$prior,$authorization,$next) {
		$this->connectionReady(); $this->db->query('START TRANSACTION');
		try {
			$rows=$this->db->query("SELECT * FROM `".$this->table."` WHERE record_type='claim' AND store_id=".$this->store.' AND intent='.$this->digest($prior).' FOR UPDATE');
			$row=$rows->row;
			// Authorize BEFORE exposing any historical outcome or successor.
			if ($rows->num_rows!==1 || !hash_equals((string)$row['authorization'],$authorization)) { throw new JetCreditFinancialException('invalid_application_context'); }
			if ($row['state']!=='complete' || !in_array($row['result'],array('manual_registered','transport_accepted'),true)) { throw new JetCreditFinancialException('review_required'); }
			$successors=$this->db->query("SELECT * FROM `".$this->table."` WHERE record_type='generation' AND store_id=".$this->store.' AND previous_intent='.$this->digest($prior).' FOR UPDATE');
			if ($successors->num_rows) {
				$child=$successors->row;
				if (!hash_equals((string)$child['purchase_scope'],$scope) || !hash_equals((string)$child['authorization'],$next['authorization']) || $child['intent']!==$next['intent']) { throw new JetCreditFinancialException('invalid_application_context'); }
				$this->db->query('COMMIT'); return $child['intent'];
			}
			$latest=$this->latestGeneration($scope);
			if (($latest && $latest['intent']!==$prior) || (!$latest && $next['generation']!==2)) { throw new JetCreditFinancialException('invalid_application_context'); }
			$this->db->query('INSERT INTO `'.$this->table."` (record_type,store_id,intent,authorization,purchase_scope,purchase_generation,previous_intent,owner_run,state,created_at,updated_at) VALUES ('generation',".$this->store.','.$this->digest($next['intent']).','.$this->digest($next['authorization']).','.$this->digest($scope).','.JetCreditFinancial::positiveInteger($next['generation']).','.$this->digest($prior).','.$this->digest($next['request']).",'generation',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
			$this->db->query('COMMIT'); return $next['intent'];
		} catch (Throwable $e) {
			try { $this->db->query('ROLLBACK'); } catch (Throwable $ignored) {}
			if ($e instanceof JetCreditFinancialException) { throw $e; }
			throw new JetCreditFinancialException('submission_storage_unavailable');
		}
	}
	public function draft($intent) {
		$rows=$this->db->query("SELECT * FROM `".$this->table."` WHERE record_type='draft' AND store_id=".$this->store.' AND intent='.$this->digest($intent));
		return $rows->num_rows===1 ? $rows->row : null;
	}
	/** Autocommitted reservation BEFORE nontransactional native order creation. No takeover. */
	public function reserveDraft($intent,$authorization,$digest,$scheme,$run) {
		$this->connectionReady();
		$this->db->query('INSERT INTO `'.$this->table."` (record_type,store_id,intent,authorization,request_digest,scheme,owner_run,state,created_at,updated_at) VALUES ('draft',".$this->store.','.$this->digest($intent).','.$this->digest($authorization).','.$this->digest($digest).','.$this->q($scheme).','.$this->digest($run).",'draft_creating',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE submission_id=submission_id");
		$row=$this->draft($intent);
		if (!$row || !hash_equals((string)$row['authorization'],$authorization)) { throw new JetCreditFinancialException('invalid_application_context'); }
		return array('row'=>$row,'acquired'=>hash_equals((string)$row['owner_run'],$run));
	}
	public function bindDraft($intent,$run,$id,$digest) {
		$this->connectionReady();
		$this->db->query('UPDATE `'.$this->table."` SET state='draft_bound',order_id=".JetCreditFinancial::positiveInteger($id).',order_digest='.$this->digest($digest).",updated_at=UTC_TIMESTAMP() WHERE record_type='draft' AND store_id=".$this->store.' AND intent='.$this->digest($intent).' AND owner_run='.$this->digest($run)." AND state='draft_creating'");
		if ((int)$this->db->countAffected()!==1) { throw new JetCreditFinancialException('review_required'); }
	}
	public function acquire($data) {
		$this->connectionReady();
		$order = $data['order_id'] === null ? 'NULL' : JetCreditFinancial::positiveInteger($data['order_id']);
		$this->db->query("INSERT INTO `" . $this->table . "` (record_type,store_id,intent,authorization,request_digest,owner_run,context,scheme,order_id,recipient_mode,consent_flags,consent_version,consent_at,created_at,updated_at) VALUES ('claim'," . $this->store . ',' . $this->digest($data['intent']) . ',' . $this->digest($data['authorization']) . ',' . $this->digest($data['request_digest']) . ',' . $this->digest($data['owner_run']) . ',' . $this->q($data['context']) . ',' . $this->q($data['scheme']) . ',' . $order . ',' . $this->q($data['recipient_mode']) . ',' . (int)$data['consent_flags'] . ',' . $this->digest($data['consent_version']) . ",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE submission_id=submission_id");
		$row = $this->find($data['intent'], $data['order_id']);
		if (!$row) { throw new JetCreditFinancialException('submission_storage_unavailable'); }
		return array('row' => $row, 'acquired' => is_string($row['owner_run']) && hash_equals($data['owner_run'], $row['owner_run']));
	}
	public function change($id, $run, $from, $state, $values = array()) {
		if (!$this->ownsTransaction) { $this->connectionReady(); }
		$allowed = array('order_id', 'bank_state', 'bank_reference', 'result', 'admin_state', 'customer_state', 'cleanup_state');
		$sql = 'state=' . $this->q($state) . ',updated_at=UTC_TIMESTAMP()';
		foreach ($values as $key => $value) {
			if (!in_array($key, $allowed, true)) { throw new LogicException('unsupported_claim_field'); }
			$sql .= ',`' . $key . '`=' . ($value === null ? 'NULL' : $this->q($value));
		}
		$this->db->query('UPDATE `' . $this->table . '` SET ' . $sql . " WHERE record_type='claim' AND store_id=" . $this->store . ' AND submission_id=' . JetCreditFinancial::positiveInteger($id) . ' AND owner_run=' . $this->digest($run) . ' AND state=' . $this->q($from));
		if ((int)$this->db->countAffected() !== 1) { throw new JetCreditFinancialException('submission_conflict'); }
	}
	/** The reference and durable attempt marker commit together, before any transport. */
	public function reserve($id, $run) {
		$this->connectionReady();
		$this->db->query('START TRANSACTION');
		$this->ownsTransaction = true;
		try {
			$claim = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE record_type='claim' AND store_id=" . $this->store . ' AND submission_id=' . JetCreditFinancial::positiveInteger($id) . ' FOR UPDATE');
			$row = $claim->row;
			if ($claim->num_rows !== 1 || !hash_equals((string)$row['owner_run'], $run) || $row['state'] !== 'order_ready' || $row['bank_state'] !== 'not_attempted' || $row['recipient_mode'] !== 'automatic' || !$row['order_id'] || $row['bank_reference'] !== null) { throw new JetCreditFinancialException('submission_conflict'); }
			$counter = $this->db->query("SELECT submission_id,counter_value FROM `" . $this->table . "` WHERE record_type='counter' AND store_id=" . $this->store . ' AND intent=' . $this->q(hash('sha256', 'jet-bank-reference-v1')) . ' FOR UPDATE');
			$value = $counter->row['counter_value'] ?? null;
			if ($counter->num_rows !== 1 || (!is_int($value) && !is_string($value)) || !ctype_digit((string)$value) || strlen((string)$value) > 19 || (float)$value >= PHP_INT_MAX - 1 || (int)$value < 0) { throw new JetCreditFinancialException('submission_storage_unavailable'); }
			$reference = (int)$value + 1;
			$this->db->query('UPDATE `' . $this->table . '` SET counter_value=' . $reference . ',updated_at=UTC_TIMESTAMP() WHERE submission_id=' . JetCreditFinancial::positiveInteger($counter->row['submission_id']) . " AND record_type='counter' AND store_id=" . $this->store);
			if ((int)$this->db->countAffected() !== 1) { throw new JetCreditFinancialException('submission_conflict'); }
			$this->change($id, $run, 'order_ready', 'bank_attempting', array('bank_state' => 'attempting', 'bank_reference' => $reference));
			$this->db->query('COMMIT');
			$this->ownsTransaction = false;
			return $reference;
		} catch (Throwable $e) {
			try { $this->db->query('ROLLBACK'); } catch (Throwable $ignored) {}
			$this->ownsTransaction = false;
			throw new JetCreditFinancialException('submission_storage_unavailable');
		}
	}
	public function notification($id, $run, $category, $from, $to) {
		$this->connectionReady();
		if (!in_array($category, array('admin', 'customer'), true)) { throw new LogicException('invalid_category'); }
		$this->db->query('UPDATE `' . $this->table . '` SET ' . $category . '_state=' . $this->q($to) . ",updated_at=UTC_TIMESTAMP() WHERE record_type='claim' AND store_id=" . $this->store . ' AND submission_id=' . JetCreditFinancial::positiveInteger($id) . ' AND owner_run=' . $this->digest($run) . ' AND ' . $category . '_state=' . $this->q($from));
		if ((int)$this->db->countAffected() !== 1) { throw new JetCreditFinancialException('submission_conflict'); }
	}
}
