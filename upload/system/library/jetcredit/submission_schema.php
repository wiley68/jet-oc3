<?php
/** Definition only. No constructor, install hook, runtime DDL or automatic seeding. */
class JetCreditSubmissionSchema {
	public static function createSql($prefix) {
		if (!is_string($prefix) || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) { throw new InvalidArgumentException('invalid_prefix'); }
		return "CREATE TABLE `" . $prefix . "jet_submission` (
 submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 install_marker VARCHAR(32) CHARACTER SET ascii DEFAULT NULL,
 offer_secret CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
 record_type ENUM('claim','counter','generation','draft') NOT NULL,
 store_id INT UNSIGNED NOT NULL,
 intent CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 authorization CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 owner_run CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 purchase_scope CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 purchase_generation BIGINT UNSIGNED DEFAULT NULL,
 previous_intent CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 order_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 context VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 scheme VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 order_id INT UNSIGNED DEFAULT NULL,
 state VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'claimed',
 recipient_mode VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 bank_state VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'not_attempted',
 bank_reference BIGINT UNSIGNED DEFAULT NULL,
 counter_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
 admin_state VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
 customer_state VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
 cleanup_state VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
 result VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
 consent_flags TINYINT UNSIGNED NOT NULL DEFAULT 0,
 consent_version CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 consent_at DATETIME DEFAULT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (submission_id),
 UNIQUE KEY jet_intent (store_id, record_type, intent),
 UNIQUE KEY jet_order (store_id, record_type, order_id),
 UNIQUE KEY jet_reference (store_id, bank_reference),
 UNIQUE KEY jet_successor (store_id, record_type, previous_intent),
 UNIQUE KEY jet_generation (store_id, record_type, purchase_scope, purchase_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}
	/** Owner-reviewed last reserved value; never read the legacy settings counter at runtime. */
	public static function counterSql($prefix, $store, $lastReserved) {
		self::createSql($prefix);
		if (!is_int($store) || $store < 0 || !is_int($lastReserved) || $lastReserved < 0 || $lastReserved >= PHP_INT_MAX) { throw new InvalidArgumentException('invalid_counter_seed'); }
		return "INSERT INTO `" . $prefix . "jet_submission` (record_type,store_id,intent,counter_value,state,created_at,updated_at) VALUES ('counter'," . $store . ",'" . hash('sha256', 'jet-bank-reference-v1') . "'," . $lastReserved . ",'counter',UTC_TIMESTAMP(),UTC_TIMESTAMP())";
	}
}
