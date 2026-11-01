<?php
require __DIR__ . '/financial-bootstrap.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_schema.php';
require DIR_APPLICATION . 'model/extension/mt_jet_credit/submission_order.php';
require DIR_APPLICATION . 'model/extension/mt_jet_credit/submission.php';
require __DIR__ . '/fixtures/oc3-native-mail-index.php';
// Statement-level in-memory SQL double. It proves contracts/interleavings, NOT InnoDB.
class SDb extends FDb {
	public $claims = array(); public $counter = 16; public $counterExists = true; public $engine = 'InnoDB'; public $indexes = true;
	public $transaction = false; public $autocommit = true; public $snapshot; public $affected = 0; public $nextId = 2;
	public $failure; public $onCommit; public $onInsert; public $beforeDelete; public $cartRows;
	public function __construct() { $this->cartRows = array(1 => array('cart_id' => '1', 'product_id' => '42', 'quantity' => '2', 'option' => '{}', 'recurring_id' => '0', 'customer_id' => 7, 'session_id' => 'session-one')); }
	public function countAffected() { return $this->purchaseAffected ?: $this->affected; }
	private function result($rows = array()) { return (object)array('num_rows' => count($rows), 'rows' => array_values($rows), 'row' => $rows ? array_values($rows)[0] : array()); }
	private function value($sql, $column) {
		if (preg_match('/(?<![a-zA-Z0-9_])(?:`?' . preg_quote($column, '/') . '`?)=(NULL|\'[a-zA-Z0-9_]*\'|[0-9]+)/', $sql, $m)) { return $m[1] === 'NULL' ? null : trim($m[1], "'"); }
		return null;
	}
	public function query($sql) {
		if (strpos($sql, 'jet_submission') === false && !preg_match('/^(START TRANSACTION|COMMIT|ROLLBACK)$/', $sql) && strpos($sql, '`oc_cart`') === false && strpos($sql, 'oc_order_status') === false && strpos($sql, '@@session.autocommit') === false) { return parent::query($sql); }
		$this->queries[] = $sql; $this->affected = 0; $this->purchaseAffected=0;
		if (strpos($sql,"record_type='generation'")!==false || strpos($sql,"record_type='draft'")!==false || strpos($sql,"VALUES ('generation'")!==false || strpos($sql,"VALUES ('draft'")!==false) { return $this->purchaseQuery($sql); }
		if ($this->failure && strpos($sql, $this->failure['contains']) !== false && empty($this->failure['after'])) { $this->failure = null; throw new RuntimeException('synthetic DB failure PRIVATE'); }
		$result = $this->perform($sql);
		if ($this->failure && strpos($sql, $this->failure['contains']) !== false && !empty($this->failure['after'])) { $this->failure = null; throw new RuntimeException('synthetic uncertain DB failure PRIVATE'); }
		return $result;
	}
	private function perform($sql) {
		if (strpos($sql, '@@session.autocommit') !== false) { return $this->result(array(array('autocommit' => $this->autocommit ? 1 : 0, 'active_transaction' => $this->transaction ? 1 : 0))); }
		if ($sql === 'START TRANSACTION') { fcheck(!$this->transaction, 'no nested transaction'); $this->snapshot = array($this->claims, $this->counter, $this->purchaseRows); $this->transaction = true; return $this->result(); }
		if ($sql === 'COMMIT') { $this->transaction = false; $this->snapshot = null; if($this->onCommit){$hook=$this->onCommit;$this->onCommit=null;$hook();} return $this->result(); }
		if ($sql === 'ROLLBACK') { if ($this->transaction) { list($this->claims, $this->counter, $this->purchaseRows) = $this->snapshot; } $this->transaction = false; return $this->result(); }
		if (strpos($sql, 'SHOW TABLE STATUS') === 0) { return $this->result(array(array('Engine' => $this->engine))); }
		if (strpos($sql, 'SHOW INDEX') === 0) {
			$rows = array();
			foreach (array('PRIMARY' => array('submission_id'), 'jet_intent' => array('store_id', 'record_type', 'intent'), 'jet_order' => array('store_id', 'record_type', 'order_id'), 'jet_reference' => array('store_id', 'bank_reference'), 'jet_successor'=>array('store_id','record_type','previous_intent'), 'jet_generation'=>array('store_id','record_type','purchase_scope','purchase_generation')) as $name => $columns) {
				if (!$this->indexes && $name === 'jet_order') { continue; }
				foreach ($columns as $i => $column) { $rows[] = array('Non_unique' => 0, 'Key_name' => $name, 'Seq_in_index' => $i + 1, 'Column_name' => $column); }
			}
			return $this->result($rows);
		}
		if (strpos($sql, 'SELECT schema_version') === 0) { return $this->result($this->counterExists ? array(array('schema_version' => 2)) : array()); }
		if (strpos($sql, 'LIMIT 0') !== false) { return $this->result(); }
		if (strpos($sql, 'oc_order_status') !== false) { return $this->result(array(array('order_status_id' => 3, 'name' => 'Pending'))); }
		if (strpos($sql, 'SELECT cart_id,') === 0) { return $this->result($this->cartRows); }
		if (strpos($sql, 'DELETE FROM `oc_cart`') === 0) {
			if ($this->beforeDelete) { $callback = $this->beforeDelete; $this->beforeDelete = null; $callback($this); }
			$id = (int)$this->value($sql, 'cart_id'); $row = $this->cartRows[$id] ?? null;
			preg_match("/BINARY `option`='(.*)'$/", $sql, $option);
			if ($row && (int)$row['quantity'] === (int)$this->value($sql, 'quantity') && $row['option'] === stripslashes($option[1]) && (int)$row['customer_id'] === (int)$this->value($sql, 'customer_id') && $row['session_id'] === str_replace('_', '-', $this->value(str_replace('-', '_', $sql), 'session_id'))) { unset($this->cartRows[$id]); $this->affected = 1; }
			return $this->result();
		}
		if (strpos($sql, 'SELECT submission_id,counter_value') === 0) { return $this->result($this->counterExists ? array(array('submission_id' => 1, 'counter_value' => (string)$this->counter)) : array()); }
		if (strpos($sql, 'SELECT *') === 0) {
			$id = $this->value($sql, 'submission_id'); $intent = $this->value($sql, 'intent'); $order = $this->value($sql, 'order_id'); $rows = array();
			foreach ($this->claims as $row) { if ($id !== null ? (int)$row['submission_id'] === (int)$id : ($row['intent'] === $intent || ($order !== null && (int)$row['order_id'] === (int)$order))) { $rows[] = $row; } }
			return $this->result($rows);
		}
		if (strpos($sql, 'INSERT INTO') === 0) {
			if ($this->onInsert) { $callback = $this->onInsert; $this->onInsert = null; $callback(); }
			preg_match('/\((record_type.*?)\) VALUES \((.*?)\) ON DUPLICATE/s', $sql, $parts);
			$columns = explode(',', $parts[1]); $values = str_getcsv($parts[2], ',', "'", '\\'); $data = array_combine($columns, $values);
			foreach ($data as &$value) { if ($value === 'NULL') { $value = null; } } unset($value);
			foreach ($this->claims as $row) { if ($row['intent'] === $data['intent'] || ($data['order_id'] !== null && (int)$row['order_id'] === (int)$data['order_id'])) { return $this->result(); } }
			$id = $this->nextId++; $data = array_merge(array('state' => 'claimed', 'bank_state' => 'not_attempted', 'bank_reference' => null, 'admin_state' => 'pending', 'customer_state' => 'pending', 'cleanup_state' => 'pending', 'result' => '', 'schema_version' => 2), $data, array('submission_id' => $id));
			$this->claims[$id] = $data; $this->affected = 1; return $this->result();
		}
		if (strpos($sql, 'UPDATE') === 0 && strpos($sql, 'SET counter_value=') !== false) { $this->counter = (int)$this->value($sql, 'counter_value'); $this->affected = 1; return $this->result(); }
		if (strpos($sql, 'UPDATE') === 0) {
			list($set, $where) = explode(' WHERE ', explode(' SET ', $sql, 2)[1], 2);
			$id = (int)$this->value($where, 'submission_id'); $row = $this->claims[$id] ?? null;
			if (!$row || $row['owner_run'] !== $this->value($where, 'owner_run')) { return $this->result(); }
			foreach (array('state', 'admin_state', 'customer_state') as $column) { $expected = $this->value($where, $column); if ($expected !== null && $row[$column] !== $expected) { return $this->result(); } }
			foreach (explode(',', $set) as $assignment) {
				if (strpos($assignment, 'updated_at=') === 0) { continue; }
				list($key, $value) = explode('=', $assignment, 2); $key = trim($key, '`');
				$row[$key] = $value === 'NULL' ? null : trim($value, "'");
			}
			$this->claims[$id] = $row; $this->affected = 1; return $this->result();
		}
		throw new RuntimeException('Unsupported synthetic SQL: ' . $sql);
	}
}
class SScope extends JetCreditNativeMailScope { public function available() { return true; } }
class SNativeMail {
	use StockOc3MailIndex;
	public $model_checkout_order; public $calls = 0;
	public function add($order, $status, $comment, $notify) { ++$this->calls; }
	public function edit($order, $status, $comment) { ++$this->calls; }
}
class SOrder extends FOrder {
	private $r; public $created = 0; public $histories = array(); public $nextId = 20; public $orders = array(); public $productRows = array(); public $optionRows = array(); public $totalRows = array(); public $failCreate; public $failHistory; public $corrupt; public $nativeMails = 0;
	public function __construct($r) { $this->r = $r; }
	public function getOrder($id) { return (int)$id === 11 ? $this->order : ($this->orders[$id] ?? false); }
	public function getOrderProducts($id) { return (int)$id === 11 ? $this->products : ($this->productRows[$id] ?? array()); }
	public function getOrderOptions($id, $line) { return (int)$id === 11 ? $this->options : ($this->optionRows[$id][$line] ?? array()); }
	public function getOrderTotals($id) { return (int)$id === 11 ? $this->totals : ($this->totalRows[$id] ?? array()); }
	public function addOrder($data = null) {
		++$this->created;
		if ($this->failCreate === 'before') { throw new RuntimeException('synthetic order SQL PRIVATE EGN'); }
		$id = $this->nextId++; $this->orders[$id] = $data + array('order_id' => $id, 'order_status_id' => 0);
		if ($this->failCreate === 'partial') { throw new RuntimeException('synthetic MyISAM partial order PRIVATE'); }
		foreach ($data['products'] as $i => $product) { $this->productRows[$id][] = $product + array('order_product_id' => $i + 1); $this->optionRows[$id][$i + 1] = $product['option']; }
		$this->totalRows[$id] = $data['totals'];
		if ($this->corrupt) { $this->orders[$id]['total'] = '1'; }
		return $id;
	}
	public function addOrderHistory($id, $status, $comment, $notify) {
		$this->histories[] = array($id, $status, $comment, $notify);
		if (!$this->r->get('jetcredit_native_mail_scope')->suppresses($id, $this->getOrder($id)['payment_code'])) { ++$this->nativeMails; }
		if ($this->failHistory === 'before') { throw new RuntimeException('synthetic history PRIVATE'); }
		if ($id === 11) { $this->order['order_status_id'] = $status; } else { $this->orders[$id]['order_status_id'] = $status; }
		if ($this->failHistory === 'after') { throw new RuntimeException('synthetic history uncertain'); }
	}
}
class SMailer {
	public $SMTPDebug, $Debugoutput, $CharSet, $Encoding, $Subject, $Body, $Host, $SMTPAuth, $Username, $Password, $SMTPSecure, $Port, $Timeout, $SMTPKeepAlive;
	public $addresses = array(); public $from; public $preflight = true; public $outcome = 'accepted'; public $sent = 0; public $preflights = 0; public $isHtml; public $failSecondPreflight = false;
	private $db;
	public function __construct($db) { $this->db = $db; }
	public function setFrom($address, $name) { $this->from = array($address, $name); }
	public function addAddress($address) { $this->addresses[] = $address; }
	public function isSMTP() {} public function isMail() {} public function isHTML($value) { $this->isHtml = $value; }
	public function preSend() { ++$this->preflights; return $this->preflight && !($this->failSecondPreflight && $this->preflights === 2); }
	public function postSend() {
		fcheck(!$this->db->transaction, 'no SQL locks during mail transport');
		if (strpos($this->Body, 'Данни за потребителя:') === 0) {
			$attempts = array_filter($this->db->claims, function ($claim) { return $claim['state'] === 'bank_attempting' && $claim['bank_state'] === 'attempting' && $claim['order_id'] && $claim['bank_reference']; });
			fcheck(count($attempts) === 1, 'durable order binding / unique reference / attempt before SMTP');
		}
		++$this->sent;
		if ($this->outcome === 'throw') { throw new RuntimeException('PRIVATE EGN partial recipient accepted'); }
		return $this->outcome === 'accepted';
	}
}
class SSubmission extends ModelExtensionMtJetCreditSubmission {
	protected function mailer() { return $this->registry->get('submission_test_mail'); }
	protected function submissionStore() { return new JetCreditSubmissionStore($this->db, DB_PREFIX, 0); }
}
function sfixture() {
	$r = ffixture(); $r->set('db', new SDb()); $r->set('jetcredit_native_mail_scope', new SScope());
	$r->get('config')->values += array('payment_jet_order_status_id' => '3', 'payment_jet_card_order_status_id' => '4', 'config_language_id' => 1, 'config_name' => 'Fixture Shop', 'config_url' => 'https://example.test/', 'config_email' => 'admin@example.test', 'config_mail_engine' => 'mail', 'module_mt_jet_credit_id' => 'TEST-MERCHANT');
	$r->get('cart')->products[0]['cart_id'] = 1;
	$r->get('model_catalog_product')->products[42] += array('name' => 'Уред', 'model' => 'MODEL');
	$r->set('model_checkout_order', new SOrder($r)); $r->get('model_checkout_order')->order += array('firstname' => 'Иван', 'lastname' => 'Петров', 'telephone' => '+359888123456');
	$r->get('model_checkout_order')->products[0] += array('name' => 'Уред', 'model' => 'MODEL');
	$r->get('model_checkout_order')->totals = $r->get('model_extension_mt_jet_credit_financial')->cartSnapshot()['totals'];
	$r->set('model_catalog_category', new class { public function getCategory($id) { return array('name' => 'Техника'); } });
	$product = $r->get('model_catalog_product');
	// Use an actual subclass for the metadata-only native category API.
	$r->set('model_catalog_product', new class($product) extends FProduct {
		public function __construct($source) { foreach (get_object_vars($source) as $key => $value) { $this->$key = $value; } }
		public function getCategories($id) { return array(array('category_id' => $id)); }
	});
	$r->set('model_extension_mt_jet_credit_submission_order', new ModelExtensionMtJetCreditSubmissionOrder($r));
	$r->set('submission_mail_instances', (object)array('items' => array(), 'settings' => array()));
	$r->set('submission_test_mail', new JetCreditSubmissionMail($r->get('config'), function () use ($r) {
		$mail = new SMailer($r->get('db')); $state = $r->get('submission_mail_instances'); $index = count($state->items);
		foreach ($state->settings[$index] ?? array() as $key => $value) { $mail->$key = $value; }
		$state->items[] = $mail; return $mail;
	}));
	$r->set('submission', new SSubmission($r)); $r->set('model_extension_mt_jet_credit_submission', $r->get('submission')); return $r;
}
function sinput($r, $context = 'product', $scheme = 'jet', $extra = array()) {
	if ($context === 'checkout') { $r->get('session')->data['payment_method']['code'] = $scheme; $r->get('model_checkout_order')->order['payment_code'] = $scheme; }
	if ($context==='checkout') {
	 $store=$r->get('submission')->purchaseRepository();$identity=$r->get('submission')->identity();$id=$identity->intent('checkout',null,true);
	 if (!$store->draft($id)) {$run=hash('sha256','fixture draft owner');$store->reserveDraft($id,$identity->authorization(0,$r->get('customer')->getId(),'checkout',null,$id),hash('sha256','fixture snapshot'),$scheme,$run);$store->bindDraft($id,$run,(int)$r->get('session')->data['order_id'],hash('sha256','fixture persisted snapshot'));}
	}
	$input = finput(array('jet_name' => 'Иван', 'jet_lastname' => 'Петров', 'jet_phone' => '+359888123456', 'jet_email' => 'ivan@example.test', 'jet_egn' => '9001010000', 'jet_uslovia' => '1', 'jet_uslovia1' => '1', 'jet_uslovia2' => '1', 'jet_card_egn' => '9001010000', 'jet_card_uslovia' => '1', 'jet_card_uslovia1' => '1'));
	$input = array_merge($input, $extra); $q = $r->get('model_extension_mt_jet_credit_financial')->quote($context, $scheme, $input);
	return array_merge($input, array('jet_offer' => $q['offer'], 'jet_intent' => $q['application_intent']));
}
function sclaim($r) { return array_values($r->get('db')->claims)[0]; }
function ssends($r) { return array_sum(array_map(function ($mail) { return $mail->sent; }, $r->get('submission_mail_instances')->items)); }

function soutcome($result) { return array_intersect_key($result,array('success'=>true,'code'=>true,'order_id'=>true)); }
