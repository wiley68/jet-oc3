<?php
require __DIR__ . '/submission-bootstrap.php';
require DIR_SYSTEM . 'library/jetcredit/admin_settings.php';
require __DIR__ . '/../upload/admin/controller/extension/module/mt_jet_credit.php';
/** Definition/SQL and lifecycle double: deliberately no database connection. */
class InstallDb extends SDb {
	public $tables = array(); public $settings = array(); public $legacyOrders = false; public $secret; public $marker; public $installState; public $locked = false; public $seeds = 0; public $createCount = 0; public $failAfterCreate = false;
	private function rows($rows = array()) { return (object)array('num_rows'=>count($rows),'row'=>$rows[0]??array(),'rows'=>$rows); }
	public function query($sql) {
		if (strpos($sql,'GET_LOCK(')!==false) { if ($this->locked) { return $this->rows(array(array('acquired'=>0))); } $this->locked=true; return $this->rows(array(array('acquired'=>1))); }
		if (strpos($sql,'RELEASE_LOCK(')!==false) { $this->locked=false; return $this->rows(array(array('released'=>1))); }
		if (strpos($sql,'SHOW TABLE STATUS')===0) { $name=strpos($sql,'jet_submission')!==false?'jet_submission':'jet_kop'; return $this->rows(isset($this->tables[$name])?array(array('Engine'=>$this->engine)):array()); }
		if (strpos($sql,'SELECT setting_id')===0) { return $this->rows($this->settings?array(array('setting_id'=>1)):array()); }
		if (strpos($sql,'SELECT order_id FROM')===0) { return $this->rows($this->legacyOrders?array(array('order_id'=>11)):array()); }
		if (strpos($sql,"currency` WHERE code='EUR'")!==false) { return $this->rows(array($this->currency)); }
		if (strpos($sql,'CREATE TABLE')===0) {
			$this->queries[]=$sql; $name=strpos($sql,'jet_submission')!==false?'jet_submission':'jet_kop';
			if (isset($this->tables[$name])) { throw new RuntimeException('existing table'); } $this->tables[$name]=true; ++$this->createCount;
			if ($this->failAfterCreate) { throw new RuntimeException('synthetic interrupted DDL'); } return true;
		}
		if (strpos($sql,'INSERT INTO')===0 && strpos($sql,"'counter',0")!==false) {
			preg_match("/0,'initializing','([^']+)','([^']+)'/",$sql,$matches);
			$this->marker=$matches[1];$this->secret=$matches[2];$this->counterExists=true;$this->counter=0;$this->installState='initializing';++$this->seeds;$this->queries[]=$sql;return true;
		}
		if (strpos($sql,"SET state='counter'")!==false) { $this->installState='counter';$this->affected=1;$this->queries[]=$sql;return true; }
		if (strpos($sql,'SELECT install_marker,offer_secret,state')===0) { return $this->rows(isset($this->tables['jet_submission'])&&$this->secret?array(array('install_marker'=>$this->marker,'offer_secret'=>$this->secret,'state'=>$this->installState)):array()); }
		if (strpos($sql,'SELECT id,jet_product_id')===0) { if (!isset($this->tables['jet_kop'])) { throw new RuntimeException('missing promo table'); }return $this->rows(); }
		return parent::query($sql);
	}
}
$db=new InstallDb();$db->counterExists=false;$config=new FConfig();unset($config->values['jetcredit_install_marker'],$config->values['jetcredit_offer_secret']);
$install=new JetCreditInstallation($db,DB_PREFIX);
freject(function () use ($install,$config) { $install->load($config); },'installation_unavailable');
$result=$install->install($config,function () use ($db) { $db->settings=JetCreditInstallation::defaults(3); });
fcheck($result==='installed'&&$db->createCount===2&&$db->seeds===1&&!$db->locked,'fresh schema + atomic counter/secret init + lock cleanup');
fcheck(is_string($db->secret)&&preg_match('/^[a-f0-9]{64}$/D',$db->secret)&&$config->get('jetcredit_offer_secret')===$db->secret,'secret generated once and loaded server-side');
fcheck($db->settings['module_mt_jet_credit']['module_mt_jet_credit_status']==='0'&&$db->settings['module_mt_jet_credit']['module_mt_jet_credit_mail']===''&&$db->counter===0,'explicit safe disabled/manual defaults; no banking reference consumed');
$secret=$db->secret;$db->counter=23;$db->claims[99]=array('intent'=>'historical');$db->promos=array(array('id'=>7));$settings=$db->settings;
fcheck($install->install($config,function () { throw new RuntimeException('must not reset'); })==='reactivated','reactivation loads existing installation');
fcheck($db->secret===$secret&&$db->counter===23&&isset($db->claims[99])&&$db->promos===array(array('id'=>7))&&$db->settings===$settings&&$db->seeds===1,'reactivation preserves claims/counter/promotions/secret/settings');
foreach (array('promo','settings','orders','partial','marker','currency') as $legacy) {
 $db2=new InstallDb();$db2->counterExists=false;$cfg=new FConfig();$instance=new JetCreditInstallation($db2,DB_PREFIX);
 if ($legacy==='promo') { $db2->tables['jet_kop']=true; }
 if ($legacy==='settings') { $db2->settings=array('legacy'=>true); }
 if ($legacy==='orders') { $db2->legacyOrders=true; }
 if ($legacy==='partial') { $db2->tables['jet_submission']=true; }
 if ($legacy==='marker') { $db2->tables['jet_submission']=true;$db2->secret=hash('sha256','old');$db2->marker='legacy';$db2->installState='counter'; }
 if ($legacy==='currency') { $cfg->values['config_currency']='USD'; }
 freject(function () use ($instance,$cfg) { $instance->install($cfg,function () {}); });
 fcheck($db2->seeds===0&&$db2->createCount===0&&!$db2->locked,'legacy/missing marker/non-EUR refuses all initialization writes: '.$legacy);
}
$db2=new InstallDb();$db2->failAfterCreate=true;$instance=new JetCreditInstallation($db2,DB_PREFIX);
freject(function () use ($instance,$config) { $instance->install($config,function () {}); },'installation_unavailable');
$db2->failAfterCreate=false;
freject(function () use ($instance,$config) { $instance->install($config,function () {}); },'installation_unavailable');
fcheck($db2->createCount===1&&$db2->seeds===0,'interrupted DDL never auto-recreates or erases evidence');
foreach (array('secret','marker','counter') as $bad) {
 $copy=clone $db;$cfg=new FConfig();
 if ($bad==='secret') { $copy->secret='broken'; }
 if ($bad==='marker') { $copy->marker='incompatible'; }
 if ($bad==='counter') { $copy->counterExists=false; }
 freject(function () use ($copy,$cfg) { (new JetCreditInstallation($copy,DB_PREFIX))->load($cfg); },'installation_unavailable');
}
// Ordinary settings can never provision/rotate secret, marker or bank number.
$cfg=new FConfig();$valid=JetCreditInstallation::defaults()['module_mt_jet_credit'];$valid['module_mt_jet_credit_id']='MERCHANT';
fcheck(JetCreditAdminSettings::module($valid,$cfg)['module_mt_jet_credit_mail']==='','explicit saved empty recipient is manual');
foreach (array('jetcredit_offer_secret','module_mt_jet_credit_count','jetcredit_install_marker','store_id') as $key) {
 $post=$valid;$post[$key]='attacker';freject(function () use ($post,$cfg) { JetCreditAdminSettings::module($post,$cfg); },'protected_configuration');
}
foreach (array(null,'bad,',' ',array()) as $bad) { $post=$valid;$post['module_mt_jet_credit_mail']=$bad;freject(function () use ($post,$cfg) { JetCreditAdminSettings::module($post,$cfg); }); }
$post=$valid;unset($post['module_mt_jet_credit_mail']);freject(function () use ($post,$cfg) { JetCreditAdminSettings::module($post,$cfg); },'invalid_recipient_configuration');
// Actual native-facing uninstall preserves the business tables/settings except disable.
$r=sfixture();$r->set('db',$db);$r->get('session')->data['user_token']='token';$r->get('request')->get=array('user_token'=>'token');
$r->set('user',new class { public function isLogged(){return true;}public function hasPermission($type,$route){return true;} });
$r->set('model_setting_setting',new class($db) { private $db;public function __construct($db){$this->db=$db;}public function getSetting($code,$store=0){return $this->db->settings[$code]??array();}public function editSetting($code,$values,$store=0){$this->db->settings[$code]=$values;} });
(new ControllerExtensionModuleMTJetCredit($r))->uninstall();
fcheck($db->counter===23&&$db->secret===$secret&&isset($db->claims[99])&&isset($db->tables['jet_kop'])&&$db->settings['module_mt_jet_credit']['module_mt_jet_credit_status']==='0','actual uninstall disables without deleting historical data/secret/counter');
fcheck(!array_filter($db->queries,function ($sql){return strpos($sql,'DROP ')===0;}),'no destructive uninstall SQL');
foreach (array('disabled','rate','store') as $failure) {
 $probe=new InstallDb();$cfg=new FConfig();
 if ($failure==='disabled') { $probe->currency['status']=0; }
 if ($failure==='rate') { $probe->currency['value']='2'; }
 if ($failure==='store') { $cfg->values['config_store_id']=1; }
 freject(function () use ($probe,$cfg) { (new JetCreditInstallation($probe,DB_PREFIX))->install($cfg,function () {}); });
 fcheck($probe->createCount===0&&$probe->seeds===0,'invalid active EUR/store scope cannot initialize');
}
$probe=new InstallDb();$probe->locked=true;
freject(function () use ($probe,$config) { (new JetCreditInstallation($probe,DB_PREFIX))->install($config,function () {}); },'installation_busy');
fcheck($probe->locked&&$probe->createCount===0,'competing install does not release another connection lock or write');
class InvalidStatusDb extends SDb { public function query($sql) { if (strpos($sql,'order_status_id=0')!==false || strpos($sql,'order_status_id=999')!==false) { return (object)array('num_rows'=>0,'row'=>array(),'rows'=>array()); } return parent::query($sql); } }
$statusDb=new InvalidStatusDb();
foreach (array(0,999) as $badStatus) {
 freject(function () use ($cfg,$statusDb,$badStatus) { JetCreditAdminSettings::payment(array('payment_jet_order_status_id'=>$badStatus),$cfg,$statusDb,'jet'); },'invalid_order_status');
}
define('JETCREDIT_OFFER_SECRET',hash('sha256','synthetic pre-provisioned installation key'));
$preconfigured=new InstallDb();$cfg=new FConfig();
(new JetCreditInstallation($preconfigured,DB_PREFIX))->install($cfg,function () {});
fcheck($preconfigured->secret===JETCREDIT_OFFER_SECRET&&$preconfigured->seeds===1,'fresh installation preserves valid pre-provisioned module secret');
echo "T-INSTALL PASS: $fchecks checks; actual definitions/lifecycle with SQL doubles, no real DDL/DB.\n";
