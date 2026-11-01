<?php
require_once __DIR__ . '/installation.php';
require_once __DIR__ . '/native_mail_scope.php';
class JetCreditRuntime {
	public static function boot($registry) {
		$config = $registry->get('config');
		if (!in_array($config->get('config_store_id'), array(null, 0, '0'), true)) { throw new JetCreditFinancialException('unsupported_store'); }
		if (!$registry->get('jetcredit_runtime_loaded')) { (new JetCreditInstallation($registry->get('db'), DB_PREFIX))->load($config); $registry->set('jetcredit_runtime_loaded', true); }
		$scope = $registry->get('jetcredit_native_mail_scope');
		if (!$scope instanceof JetCreditNativeMailScope) { $scope = new JetCreditNativeMailScope(); $registry->set('jetcredit_native_mail_scope', $scope); }
		if (!$scope->available()) { throw new JetCreditFinancialException('native_mail_guard_unavailable'); }
	}
}
