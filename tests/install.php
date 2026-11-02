<?php
require __DIR__ . '/submission-bootstrap.php';
require DIR_SYSTEM . 'library/jetcredit/admin_settings.php';
require __DIR__ . '/../upload/admin/controller/extension/module/mt_jet_credit.php';
/** Definition/SQL and lifecycle double: deliberately no database connection. */
class InstallDb extends SDb {
	public $seen = array(); public $mods = array(); public $tables = array(); public $settings = array(); public $legacyOrders = false; public $secret; public $marker; public $installState; public $locked = false; public $seeds = 0; public $createCount = 0; public $failAfterCreate = false;
	private function rows($rows = array()) { return (object)array('num_rows'=>count($rows),'row'=>$rows[0]??array(),'rows'=>$rows); }
	public function query($sql) {
		$this->seen[] = $sql;
		if (strpos($sql,'GET_LOCK(')!==false) { if ($this->locked) { return $this->rows(array(array('acquired'=>0))); } $this->locked=true; return $this->rows(array(array('acquired'=>1))); }
		if (strpos($sql,'RELEASE_LOCK(')!==false) { $this->locked=false; return $this->rows(array(array('released'=>1))); }
		if (strpos($sql,'SHOW TABLE STATUS')===0) { $name=strpos($sql,'jet_submission')!==false?'jet_submission':'jet_kop'; return $this->rows(isset($this->tables[$name])?array(array('Engine'=>$this->engine)):array()); }
		if (strpos($sql,'SHOW COLUMNS')===0 && strpos($sql,'jet_kop')!==false) { return $this->rows(array_map(function ($name) { return array('Field'=>$name); }, array('id','jet_product_id','jet_product_percent','jet_product_meseci','jet_product_price','jet_product_start','jet_product_end'))); }
		if (strpos($sql,'SHOW INDEX')===0 && strpos($sql,'jet_kop')!==false) { return $this->rows(array(array('Key_name'=>'PRIMARY','Non_unique'=>0,'Seq_in_index'=>1,'Column_name'=>'id'))); }
		if (strpos($sql,'SELECT version,xml')===0) { return $this->rows($this->mods); }
		if (strpos($sql,'SELECT code,`key`,value,serialized')===0) { return $this->rows(array_intersect(array_keys($this->settings),array('legacy','module_mt_jet_credit','payment_jet','payment_jet_card'))?array(array('code'=>'payment_jet','key'=>'payment_jet_total','value'=>'75','serialized'=>0)):array()); }
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
if (($argv[1] ?? '') === '--invalid-secret') {
 define('JETCREDIT_OFFER_SECRET','invalid');$probe=new InstallDb();
 freject(function()use($probe){(new JetCreditInstallation($probe,DB_PREFIX))->install(new FConfig(),function(){});},'secret_initialization_failed');
 fcheck(!$probe->createCount && !$probe->seeds,'invalid pre-provisioned secret refuses before DDL');
 exit(0);
}
$db=new InstallDb();$db->counterExists=false;$config=new FConfig();unset($config->values['jetcredit_install_marker'],$config->values['jetcredit_offer_secret']);
$install=new JetCreditInstallation($db,DB_PREFIX);
freject(function () use ($install,$config) { $install->load($config); },'missing_schema');
$result=$install->install($config,function () use ($db) { $db->settings=JetCreditInstallation::defaults(3); });
fcheck($result==='installed'&&$db->createCount===2&&$db->seeds===1&&!$db->locked,'fresh schema + atomic counter/secret init + lock cleanup');
fcheck(is_string($db->secret)&&preg_match('/^[a-f0-9]{64}$/D',$db->secret)&&$config->get('jetcredit_offer_secret')===$db->secret,'secret generated once and loaded server-side');
fcheck($db->settings['module_mt_jet_credit']['module_mt_jet_credit_status']==='0'&&$db->settings['module_mt_jet_credit']['module_mt_jet_credit_mail']===''&&$db->counter===0,'explicit safe disabled/manual defaults; no banking reference consumed');
$secret=$db->secret;$db->counter=23;$db->claims[99]=array('intent'=>'historical');$db->promos=array(array('id'=>7));$settings=$db->settings;
fcheck($install->install($config,function () { throw new RuntimeException('must not reset'); })==='reactivated','reactivation loads existing installation');
fcheck($db->secret===$secret&&$db->counter===23&&isset($db->claims[99])&&$db->promos===array(array('id'=>7))&&$db->settings===$settings&&$db->seeds===1,'reactivation preserves claims/counter/promotions/secret/settings');
foreach (array('promo','settings','partial','marker') as $legacy) {
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
freject(function () use ($instance,$config) { $instance->install($config,function () {}); });
$db2->failAfterCreate=false;
freject(function () use ($instance,$config) { $instance->install($config,function () {}); });
fcheck($db2->createCount===1&&$db2->seeds===0,'interrupted DDL never auto-recreates or erases evidence');
foreach (array('secret','marker','counter') as $bad) {
 $copy=clone $db;$cfg=new FConfig();
 if ($bad==='secret') { $copy->secret='broken'; }
 if ($bad==='marker') { $copy->marker='incompatible'; }
 if ($bad==='counter') { $copy->counterExists=false; }
 freject(function () use ($copy,$cfg) { (new JetCreditInstallation($copy,DB_PREFIX))->load($cfg); });
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
 if ($failure==='rate') { $probe->currency['value']='0'; }
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

// Fresh missing-table probe never reads absent table or columns, even with warnings promoted.
$probe=new InstallDb(); set_error_handler(function($level,$message){throw new RuntimeException($message);});
try { freject(function()use($probe){(new JetCreditInstallation($probe,DB_PREFIX))->load(new FConfig());},'missing_schema'); }
finally { restore_error_handler(); }
fcheck(!array_filter($probe->seen,function($sql){return strpos($sql,'SELECT install_marker')===0;}),'missing schema detected before dependent SELECT');
foreach(array('EUR','RON','USD')as$base){
 $probe=new InstallDb();$probe->legacyOrders=true;$probe->settings=array('unrelated_extension'=>array('layout'=>'left'));$cfg=new FConfig();$cfg->values['config_currency']=$base;
 $out=(new JetCreditInstallation($probe,DB_PREFIX))->install($cfg,function(){});
 fcheck($out==='installed_historical_only' && $probe->seeds===1 && $probe->legacyOrders && isset($probe->settings['unrelated_extension']),'historical orders/unrelated residual settings preserved; base '.$base);
}
foreach(array('module_mt_jet_credit','payment_jet','payment_jet_card')as$code){
 $probe=new InstallDb();$probe->settings=array($code=>array('old'=>'BGN'));$before=$probe->settings;
 freject(function()use($probe){(new JetCreditInstallation($probe,DB_PREFIX))->install(new FConfig(),function(){});},'legacy_conflict_owner_review');
 fcheck($probe->settings===$before && !$probe->createCount,'legacy financial settings never rewritten');
}
$probe=new InstallDb();$probe->mods=array(array('version'=>'1.9.1','xml'=>'legacy'));
freject(function()use($probe){(new JetCreditInstallation($probe,DB_PREFIX))->install(new FConfig(),function(){});},'legacy_ocmod_owner_review');
class BrokenSchemaDb extends InstallDb {
 public $missingColumn=false; public $revision=2; public $missingPromo=false;
 public function query($sql){
  $result=parent::query($sql);
  if(strpos($sql,'SHOW COLUMNS')===0 && ($this->missingColumn || ($this->missingPromo && strpos($sql,'jet_kop')!==false))){$result->rows=array_slice($result->rows,1);}
  if(strpos($sql,'SELECT schema_version')===0){$result->row['schema_version']=$this->revision;}
  return $result;
 }
}
foreach(array('column','revision','promo','engine','indexes')as$case){
 $probe=new BrokenSchemaDb();$cfg=new FConfig();$install=new JetCreditInstallation($probe,DB_PREFIX);$install->install($cfg,function(){});$before=$probe->seeds;
 if($case==='column'){$probe->missingColumn=true;}
 if($case==='revision'){$probe->revision=1;}
 if($case==='promo'){$probe->missingPromo=true;}
 if($case==='engine'){$probe->engine='MyISAM';}
 if($case==='indexes'){$probe->indexes=false;}
 freject(function()use($install,$cfg){$install->load($cfg);});
 fcheck($probe->seeds===$before,'incompatible schema never resets seed '.$case);
}
fcheck(strpos(JetCreditInstallation::diagnostic(new RuntimeException('PRIVATE SQL secret')),'PRIVATE')===false,'unknown error never leaks raw details');

class HarmlessStatusDb extends InstallDb {
 public function query($sql){if(strpos($sql,'SELECT code,`key`,value,serialized')===0){return (object)array('num_rows'=>1,'rows'=>array(array('code'=>'payment_jet','key'=>'payment_jet_status','value'=>'0','serialized'=>0)));}return parent::query($sql);}
}
$probe=new HarmlessStatusDb();fcheck((new JetCreditInstallation($probe,DB_PREFIX))->install(new FConfig(),function(){})==='installed','disabled-status-only residue is harmless');
foreach(array(false,true)as$conflict){
 $r=sfixture();$probe=new InstallDb();if($conflict){$probe->settings=array('payment_jet'=>array('old'=>'BGN'));}$r->set('db',$probe);
 $r->set('user',new class{public function isLogged(){return true;}public function hasPermission($a,$b){return true;}});
 $r->get('request')->get=array('user_token'=>'token');$r->get('session')->data['user_token']='token';
 $r->set('model_setting_setting',new class($probe){private $db;public function __construct($db){$this->db=$db;}public function editSetting($code,$values,$store){$this->db->settings[$code]=$values;}});
 $result=(new ControllerExtensionModuleMTJetCredit($r))->install();
 fcheck($result['jetcredit_installed']===!$conflict && empty($r->get('response')->output),'real install hook scoped success/failure without HTML');
 if($conflict){fcheck(strpos($result['message'],'[legacy_conflict_owner_review]')===0,'hook exposes exact allowlisted diagnostic');}
}
// Explicit settings navigation still composes the normal admin layout once.
if(!defined('HTTP_CATALOG')){define('HTTP_CATALOG','https://catalog.example.test/');define('HTTPS_CATALOG','https://catalog.example.test/');}
class InstallViewLoad extends FLoad {public $parts=array();public function controller($path){$this->parts[]=$path;return $path;}}
$r=sfixture();$db=new InstallDb();$cfg=$r->get('config');$cfg->values['config_currency']='RON';
(new JetCreditInstallation($db,DB_PREFIX))->install($cfg,function(){});$r->set('db',$db);$r->get('request')->server['REQUEST_METHOD']='GET';$r->get('session')->data['user_token']='token';
$r->set('document',new class{public function setTitle($title){}});$load=new InstallViewLoad();$r->set('load',$load);
$r->set('model_extension_module_jetcredit',new class{public function getAllKops(){return array();}});
(new ControllerExtensionModuleMTJetCredit($r))->index();$view=$r->get('response')->output;
fcheck($load->parts===array('common/header','common/column_left','common/footer'),'settings layout composed once');
fcheck($view['jet_finance_diagnostic']==='' && $view['header']==='common/header' && $view['footer']==='common/footer','normal non-EUR base settings rendering without false currency error');
define('JETCREDIT_OFFER_SECRET',hash('sha256','synthetic pre-provisioned installation key'));
$preconfigured=new InstallDb();$cfg=new FConfig();
(new JetCreditInstallation($preconfigured,DB_PREFIX))->install($cfg,function () {});
fcheck($preconfigured->secret===JETCREDIT_OFFER_SECRET&&$preconfigured->seeds===1,'fresh installation preserves valid pre-provisioned module secret');
$status=0;$output=array();exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --invalid-secret 2>&1',$output,$status);
fcheck($status===0,'invalid pre-provisioned secret subprocess: '.implode("\n",$output));
echo "T-INSTALL PASS: $fchecks checks; actual definitions/lifecycle with SQL doubles, no real DDL/DB.\n";
