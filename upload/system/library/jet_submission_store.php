<?php
/** Module-owned storage only. Never share this connection with native order/hooks. */
class JetSubmissionStorageException extends RuntimeException {}
class JetSubmissionCommitUnknown extends JetSubmissionStorageException {}

class JetSubmissionStore {
    private $db;
    private $prefix;
    private $ready = false;
    private $poisoned = false;
    private $transaction = false;

    /** $db must be an exclusively owned OpenCart DB connection (or a test double). */
    public function __construct($db, $prefix) {
        if (!is_string($prefix) || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new InvalidArgumentException('Invalid table prefix');
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public static function connect() {
        try {
            return new self(new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, defined('DB_PORT') ? DB_PORT : null), DB_PREFIX);
        } catch (Throwable $error) {
            throw new JetSubmissionStorageException('Submission storage connection failed');
        }
    }

    private function query($sql) {
        if ($this->poisoned) throw new JetSubmissionStorageException('Storage connection unavailable');
        try {
            $result = $this->db->query($sql);
            if ($result === false || $result === null) throw new RuntimeException('Database operation failed');
            return $result;
        } catch (Throwable $error) {
            // Never propagate OpenCart SQL exceptions: they can contain SQL/data/credentials.
            throw new JetSubmissionStorageException('Submission storage operation failed');
        }
    }

    private function rows($sql) {
        $result = $this->query($sql);
        if (!is_object($result) || !isset($result->rows) || !is_array($result->rows)) throw new JetSubmissionStorageException('Invalid database result');
        return $result->rows;
    }

    private function table($suffix) { return '`' . $this->prefix . $suffix . '`'; }
    private function quote($value) { return "'" . $this->db->escape((string)$value) . "'"; }

    private function definitions() {
        return [
            'jet_submission_meta' => [
                'singleton' => 'tinyint unsigned NOT NULL',
                'schema_revision' => 'int unsigned NOT NULL',
                'next_reference' => 'bigint unsigned NOT NULL',
                'digest_key' => 'char(64) NOT NULL'
            ],
            'jet_submission' => [
                'intent_id' => 'char(48) NOT NULL',
                'store_id' => 'int unsigned NOT NULL',
                'owner_digest' => 'char(64) NOT NULL',
                'family' => 'varchar(8) NOT NULL',
                'scope_digest' => 'char(64) NOT NULL',
                'checkout_order_id' => 'int unsigned NULL',
                'native_order_id' => 'int unsigned NULL',
                'payload_digest' => 'char(64) NULL',
                'channel' => 'varchar(8) NULL',
                'state' => "varchar(12) NOT NULL DEFAULT 'draft'",
                'claim_digest' => 'char(64) NULL',
                'reference_no' => 'bigint unsigned NULL',
                'order_state' => "varchar(12) NOT NULL DEFAULT 'pending'",
                'bank_state' => "varchar(12) NOT NULL DEFAULT 'pending'",
                'admin_state' => "varchar(12) NOT NULL DEFAULT 'pending'",
                'customer_state' => "varchar(12) NOT NULL DEFAULT 'pending'",
                'history_state' => "varchar(12) NOT NULL DEFAULT 'pending'",
                'clear_state' => "varchar(12) NOT NULL DEFAULT 'pending'",
                'result_code' => 'varchar(24) NULL'
            ]
        ];
    }

    private function indexes() {
        return [
            'jet_submission_meta' => ['PRIMARY' => ['singleton']],
            'jet_submission' => ['PRIMARY' => ['intent_id'], 'checkout_intent' => ['store_id', 'checkout_order_id'], 'bank_reference' => ['reference_no']]
        ];
    }

    private function capabilities() {
        $engines = $this->rows('SHOW ENGINES');
        $found = false;
        foreach ($engines as $engine) {
            if (strcasecmp($engine['Engine'] ?? '', 'InnoDB') === 0 && in_array(strtoupper($engine['Support'] ?? ''), ['YES', 'DEFAULT'], true)
                && strtoupper($engine['Transactions'] ?? '') === 'YES') $found = true;
        }
        if (!$found) throw new JetSubmissionStorageException('Transactional row-locking storage required');
        $rows = $this->rows("SHOW VARIABLES LIKE 'autocommit'");
        if (count($rows) !== 1) throw new JetSubmissionStorageException('Autocommit required');
        $autocommit = strtoupper((string)$rows[0]['Value']);
        if (!in_array($autocommit, ['1','ON'], true)) {
            // Exclusively owned connection; never change server/global settings.
            if ($this->transaction || !in_array($autocommit, ['0','OFF'], true)
                || $this->query('SET SESSION autocommit=1') !== true) throw new JetSubmissionStorageException('Autocommit required');
            $rows = $this->rows("SHOW VARIABLES LIKE 'autocommit'");
            if (count($rows) !== 1 || !in_array(strtoupper((string)$rows[0]['Value']), ['1','ON'], true)) throw new JetSubmissionStorageException('Autocommit required');
        }
    }

    private function verifyTable($name, $columns) {
        // Equality instead of LIKE: underscores in DB_PREFIX are not wildcard characters.
        $status = $this->rows('SHOW TABLE STATUS WHERE Name = ' . $this->quote($this->prefix . $name));
        if (count($status) !== 1 || strcasecmp($status[0]['Engine'] ?? '', 'InnoDB') !== 0) throw new JetSubmissionStorageException('Submission table engine mismatch');
        $fields = $this->rows('SHOW FULL COLUMNS FROM ' . $this->table($name));
        if (count($fields) !== count($columns)) throw new JetSubmissionStorageException('Submission schema mismatch');
        foreach ($fields as $field) {
            $key = $field['Field'];
            if (!isset($columns[$key])) throw new JetSubmissionStorageException('Unknown submission column');
            preg_match('/^([a-z]+(?:\(\d+\))?(?: unsigned)?)/', $columns[$key], $match);
            // MySQL display widths are not a compatibility contract.
            $type = preg_replace('/\b(tinyint|int|bigint)\(\d+\)/', '$1', strtolower($field['Type']));
            $nullable = strpos($columns[$key], 'NOT NULL') === false ? 'YES' : 'NO';
            if ($type !== $match[1] || $field['Null'] !== $nullable || !empty($field['Extra'])) throw new JetSubmissionStorageException('Submission column mismatch');
            preg_match("/DEFAULT '([^']+)'/", $columns[$key], $default);
            if (($field['Default'] ?? null) !== ($default[1] ?? null)) throw new JetSubmissionStorageException('Submission column default mismatch');
            if (strpos($type, 'char') !== false && ($field['Collation'] ?? '') !== 'ascii_bin') throw new JetSubmissionStorageException('Binary identity collation required');
        }
        $actual = [];
        foreach ($this->rows('SHOW INDEX FROM ' . $this->table($name)) as $index) {
            if ((int)$index['Non_unique'] !== 0) continue;
            if (!empty($index['Sub_part'])) throw new JetSubmissionStorageException('Unexpected submission unique index');
            $actual[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name'];
        }
        foreach ($actual as &$parts) { ksort($parts); $parts = array_values($parts); }
        unset($parts);
        $expected = $this->indexes()[$name];
        // Names of secondary indexes are not part of the uniqueness contract.
        if (($actual['PRIMARY'] ?? null) !== $expected['PRIMARY']) throw new JetSubmissionStorageException('Submission primary key mismatch');
        unset($actual['PRIMARY'], $expected['PRIMARY']);
        $actual = array_values($actual); $expected = array_values($expected);
        sort($actual); sort($expected);
        if ($actual !== $expected) throw new JetSubmissionStorageException('Submission unique constraints mismatch');
    }

    /** Admin only. Never called by catalog reads/claims; no destructive migration. */
    public function prepare($initialReference) {
        $this->ready = false;
        $this->capabilities();
        $meta = $this->rows('SHOW TABLE STATUS WHERE Name = ' . $this->quote($this->prefix . 'jet_submission_meta'));
        $ledger = $this->rows('SHOW TABLE STATUS WHERE Name = ' . $this->quote($this->prefix . 'jet_submission'));
        if ($meta && $ledger) {
            $this->verify();
            return;
        }
        if ($meta || $ledger) throw new JetSubmissionStorageException('Partial submission schema; preparation refused');
        self::reference($initialReference);
        foreach ($this->definitions() as $name => $columns) {
            $parts = [];
            foreach ($columns as $key => $definition) $parts[] = '`' . $key . '` ' . $definition;
            foreach ($this->indexes()[$name] as $key => $fields) {
                $parts[] = ($key === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE KEY `' . $key . '`') . ' (`' . implode('`,`', $fields) . '`)';
            }
            $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table($name) . ' (' . implode(',', $parts) . ') ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin');
            $this->verifyTable($name, $columns);
        }
        if (!$this->rows('SELECT * FROM ' . $this->table('jet_submission_meta'))
            && $this->rows('SELECT intent_id FROM ' . $this->table('jet_submission') . ' LIMIT 1')) {
            throw new JetSubmissionStorageException('Missing existing counter; manual recovery required');
        }
        // The unique singleton makes repeated/concurrent preparation preserve seed and key.
        $this->query('INSERT INTO ' . $this->table('jet_submission_meta') . ' (singleton,schema_revision,next_reference,digest_key) VALUES (1,1,' . $this->quote($initialReference) . ',' . $this->quote(bin2hex(random_bytes(32))) . ') ON DUPLICATE KEY UPDATE singleton=singleton');
        $this->ready = false;
        $this->verify();
    }

    public function verify() {
        if ($this->ready) return;
        $this->capabilities();
        foreach ($this->definitions() as $name => $columns) $this->verifyTable($name, $columns);
        $meta = $this->metadata();
        if (count($meta) !== 1 || (string)$meta[0]['singleton'] !== '1' || (string)$meta[0]['schema_revision'] !== '1'
            || !preg_match('/^[a-f0-9]{64}$/D', $meta[0]['digest_key'])) throw new JetSubmissionStorageException('Invalid submission metadata');
        $this->checkReference($meta[0]);
        $this->ready = true;
    }

    private function metadata() {
        // One consistent statement avoids comparing an old counter with a newer ledger.
        return $this->rows('SELECT m.*, (SELECT MAX(reference_no) FROM ' . $this->table('jet_submission')
            . ') AS highest_reference FROM ' . $this->table('jet_submission_meta') . ' m');
    }

    private function checkReference($meta) {
        $next = self::reference($meta['next_reference']);
        if ($meta['highest_reference'] !== null) {
            $highest = self::reference($meta['highest_reference']);
            if (strlen($next) < strlen($highest) || (strlen($next) === strlen($highest) && strcmp($next, $highest) <= 0)) {
                throw new JetSubmissionStorageException('Counter/ledger inconsistency');
            }
        }
        return $next;
    }

    public function getNextReference() {
        $this->verify();
        $meta = $this->metadata();
        if (count($meta) !== 1) throw new JetSubmissionStorageException('Missing submission metadata');
        return $this->checkReference($meta[0]);
    }

    public function digest($domain, $value) {
        $this->verify();
        $meta = $this->rows('SELECT * FROM ' . $this->table('jet_submission_meta') . ' WHERE singleton=1');
        if (count($meta) !== 1) throw new JetSubmissionStorageException('Missing submission metadata');
        return hash_hmac('sha256', $domain . "\0" . $value, hex2bin($meta[0]['digest_key']));
    }

    public static function reference($value) {
        if (!is_int($value) && !is_string($value)) throw new InvalidArgumentException('Invalid reference');
        $value = (string)$value;
        if (!preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) throw new InvalidArgumentException('Invalid reference');
        return $value;
    }

    private static function nextReference($value) {
        $value = self::reference($value);
        if ($value === '18446744073709551615') throw new JetSubmissionStorageException('Reference capacity exhausted');
        for ($i = strlen($value) - 1; $i >= 0; --$i) {
            if ($value[$i] !== '9') { $value[$i] = (string)((int)$value[$i] + 1); return $value; }
            $value[$i] = '0';
        }
        return '1' . $value;
    }

    private function begin() {
        $this->verify();
        if ($this->transaction) throw new JetSubmissionStorageException('Nested storage transaction');
        if ($this->query('START TRANSACTION') !== true) {
            $this->poisoned = true;
            throw new JetSubmissionStorageException('Transaction acknowledgement unavailable');
        }
        $this->transaction = true;
    }

    private function commit() {
        try {
            if ($this->query('COMMIT') !== true) throw new JetSubmissionStorageException('Commit acknowledgement unavailable');
        }
        catch (Throwable $error) {
            // A missing acknowledgement is not proof of rollback. Never reuse this connection.
            $this->poisoned = true;
            $this->transaction = false;
            throw new JetSubmissionCommitUnknown('Submission commit outcome unknown');
        }
        $this->transaction = false;
    }

    private function rollback() {
        if ($this->transaction) {
            try {
                if ($this->query('ROLLBACK') !== true) $this->poisoned = true;
            } catch (Throwable $error) { $this->poisoned = true; }
            $this->transaction = false;
        }
    }

    private function load($id, $owner, $store, $lock = false) {
        $rows = $this->rows('SELECT * FROM ' . $this->table('jet_submission') . ' WHERE intent_id=' . $this->quote($id) . ($lock ? ' FOR UPDATE' : ''));
        if (count($rows) !== 1 || !hash_equals($rows[0]['owner_digest'], $owner) || (string)$rows[0]['store_id'] !== (string)$store) throw new JetSubmissionStorageException('Submission unavailable');
        return $rows[0];
    }

    private function update($id, $values) {
        $allowed = array_keys($this->definitions()['jet_submission']);
        $parts = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true) || $key === 'intent_id') throw new InvalidArgumentException('Invalid storage column');
            $parts[] = '`' . $key . '`=' . ($value === null ? 'NULL' : $this->quote($value));
        }
        $this->query('UPDATE ' . $this->table('jet_submission') . ' SET ' . implode(',', $parts) . ' WHERE intent_id=' . $this->quote($id));
    }

    /** Each call creates a separate Product/Cart form; only Checkout is deduplicated. */
    public function create($store, $owner, $family, $scope, $checkoutOrder) {
        $this->begin();
        try {
            if ($family === 'checkout') {
                // Also serializes initial Checkout creation across sessions/method changes.
                $this->rows('SELECT * FROM ' . $this->table('jet_submission_meta') . ' WHERE singleton=1 FOR UPDATE');
                $existing = $this->rows('SELECT * FROM ' . $this->table('jet_submission') . ' WHERE store_id=' . $this->quote($store) . ' AND checkout_order_id=' . $this->quote($checkoutOrder));
                if ($existing) {
                    $row = $this->load($existing[0]['intent_id'], $owner, $store);
                    $this->commit(); return $row;
                }
            }
            $id = bin2hex(random_bytes(24));
            $this->query('INSERT INTO ' . $this->table('jet_submission') . ' (intent_id,store_id,owner_digest,family,scope_digest,checkout_order_id,native_order_id) VALUES ('
                . implode(',', [$this->quote($id),$this->quote($store),$this->quote($owner),$this->quote($family),$this->quote($scope),$checkoutOrder === null ? 'NULL' : $this->quote($checkoutOrder),$checkoutOrder === null ? 'NULL' : $this->quote($checkoutOrder)]) . ')');
            $row = $this->load($id, $owner, $store);
            $this->commit(); return $row;
        } catch (Throwable $error) { $this->rollback(); throw $error; }
    }

    public function read($id, $owner, $store) { $this->verify(); return $this->load($id, $owner, $store); }

    public function findCheckout($store, $owner, $orderId) {
        $this->verify();
        $rows = $this->rows('SELECT * FROM ' . $this->table('jet_submission') . ' WHERE store_id=' . $this->quote($store) . ' AND checkout_order_id=' . $this->quote($orderId));
        if (!$rows) return null;
        if (count($rows) !== 1) throw new JetSubmissionStorageException('Checkout identity conflict');
        return $this->load($rows[0]['intent_id'], $owner, $store);
    }

    /** Locked callback cannot invoke external business actions; used only by the service. */
    public function change($id, $owner, $store, $callback) {
        $this->begin();
        try {
            $row = $this->load($id, $owner, $store, true);
            $values = $callback($row);
            if ($values) $this->update($id, $values);
            $row = array_merge($row, $values);
            $this->commit(); return $row;
        } catch (Throwable $error) { $this->rollback(); throw $error; }
    }

    public function claim($id, $owner, $store, $payload, $channel, $claimDigest) {
        $this->begin();
        try {
            $row = $this->load($id, $owner, $store, true);
            if ($row['state'] !== 'draft') {
                if (!hash_equals($row['payload_digest'], $payload) || $row['channel'] !== $channel) throw new JetSubmissionStorageException('Submission data conflict');
                $this->commit(); return ['acquired'=>false, 'row'=>$row];
            }
            $meta = $this->rows('SELECT * FROM ' . $this->table('jet_submission_meta') . ' WHERE singleton=1 FOR UPDATE');
            if (count($meta) !== 1) throw new JetSubmissionStorageException('Missing counter');
            $number = self::reference($meta[0]['next_reference']);
            $this->query('UPDATE ' . $this->table('jet_submission_meta') . ' SET next_reference=' . $this->quote(self::nextReference($number)) . ' WHERE singleton=1');
            $values = ['state'=>'processing','payload_digest'=>$payload,'channel'=>$channel,'claim_digest'=>$claimDigest,'reference_no'=>$number,
                'order_state'=>$row['family'] === 'checkout' ? 'done' : 'pending', 'clear_state'=>$row['family'] === 'cart' ? 'pending' : 'skipped'];
            $this->update($id, $values);
            $this->commit(); return ['acquired'=>true, 'row'=>array_merge($row,$values)];
        } catch (Throwable $error) { $this->rollback(); throw $error; }
    }
}
