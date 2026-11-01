<?php
/** OWNER-ONLY disposable DB test. Default invocation never opens a connection. */
if (getenv('JET_TEST_DB_APPROVED') !== 'DISPOSABLE_ONLY') {
	echo "DEFERRED: no approved disposable MySQL database. See docs/MYSQL-TEST.md.\n"; exit(0);
}
$host = getenv('JET_TEST_DB_HOST') ?: '127.0.0.1'; $database = getenv('JET_TEST_DB_NAME') ?: '';
if (!in_array($host,array('localhost','127.0.0.1','::1'),true) || !preg_match('/^jet_oc3_disposable_[a-z0-9_]+$/D',$database) || !extension_loaded('mysqli') || !function_exists('pcntl_fork')) { throw new RuntimeException('Only an explicitly approved local disposable dataset with mysqli/pcntl is supported.'); }
require __DIR__ . '/../upload/system/library/jetcredit/submission_schema.php';
require __DIR__ . '/../upload/system/library/jetcredit/submission_store.php';
require __DIR__ . '/../upload/system/library/jetcredit/installation.php';
require __DIR__ . '/../upload/system/library/jetcredit/submission.php';
class DisposableDb {
	private $link;
	public function __construct() {
		$this->link = new mysqli(getenv('JET_TEST_DB_HOST') ?: '127.0.0.1', getenv('JET_TEST_DB_USER') ?: '', getenv('JET_TEST_DB_PASSWORD') ?: '', getenv('JET_TEST_DB_NAME'), (int)(getenv('JET_TEST_DB_PORT') ?: 3306));
		$this->link->set_charset('utf8mb4');
	}
	public function query($sql) {
		$result=$this->link->query($sql);
		if (!$result instanceof mysqli_result) { return $result; }
		$rows=$result->fetch_all(MYSQLI_ASSOC);return (object)array('num_rows'=>count($rows),'row'=>$rows[0]??array(),'rows'=>$rows);
	}
	public function close(){if($this->link){$this->link->close();$this->link=null;}}
	public function escape($value){return $this->link->real_escape_string($value);}
	public function countAffected(){return $this->link->affected_rows;}
}
function dbRecord($intent,$order,$run) {return array('intent'=>hash('sha256',$intent),'authorization'=>hash('sha256','synthetic-owner'),'owner_run'=>hash('sha256',$run),'request_digest'=>hash('sha256','synthetic-request'),'context'=>'checkout','scheme'=>'jet','order_id'=>$order,'recipient_mode'=>'automatic','consent_flags'=>3,'consent_version'=>hash('sha256','synthetic-consent'));}
function dbAssert($value,$label){if(!$value){throw new RuntimeException($label);}}
class DisposableConfig {
	private $values=array('config_store_id'=>0,'config_currency'=>'EUR');
	public function get($key){return $this->values[$key]??null;}
	public function set($key,$value){$this->values[$key]=$value;}
}
$db=new DisposableDb();
dbAssert((int)$db->query('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE()')->row['n']===0,'Disposable database must be completely empty. No existing tables will be touched.');
$prefix='jet_probe_';$table=$prefix.'jet_submission';$created=false;$directory=sys_get_temp_dir().'/jet-mysql-'.bin2hex(random_bytes(8));mkdir($directory,0700);
try {
	// Minimal synthetic native tables, not a full OpenCart installation or real orders.
	$created=true;
	$db->query('CREATE TABLE `jet_probe_setting` (setting_id INT,store_id INT,code VARCHAR(64)) ENGINE=InnoDB');
	$db->query('CREATE TABLE `jet_probe_order` (order_id INT,payment_code VARCHAR(64)) ENGINE=InnoDB');
	$db->query('CREATE TABLE `jet_probe_currency` (currency_id INT,status INT,value DECIMAL(15,8),code CHAR(3)) ENGINE=InnoDB');
	$db->query("INSERT INTO `jet_probe_currency` VALUES (1,1,1,'EUR')");
	$config=new DisposableConfig();$defaultsCalls=0;
	$installer=new JetCreditInstallation($db,$prefix);
	dbAssert($installer->install($config,function()use(&$defaultsCalls){++$defaultsCalls;})==='installed'&&$defaultsCalls===1,'Actual fresh installation must initialize exactly once.');
	$store=new JetCreditSubmissionStore($db,$prefix);$store->ready();
	$runWorkers=function($same)use($prefix,$directory){
		$pids=array();$barrier=$directory.'/'.($same?'same':'different');
		for($i=0;$i<8;++$i){
			$pid=pcntl_fork();if($pid===-1){file_put_contents($barrier,'abort');foreach($pids as $started){pcntl_waitpid($started,$ignored);}throw new RuntimeException('fork');}
			if($pid===0){
				try{
					$child=new DisposableDb();$repo=new JetCreditSubmissionStore($child,$prefix);$repo->ready();
					$deadline=microtime(true)+10;while(!is_file($barrier)){if(microtime(true)>$deadline){throw new RuntimeException('barrier timeout');}usleep(1000);}
					if(file_get_contents($barrier)!=='go'){throw new RuntimeException('barrier aborted');}
					$record=dbRecord($same?'one-intent':'intent-'.$i,$same?100:200+$i,bin2hex(random_bytes(16)));$claim=$repo->acquire($record);$reference=null;
					if($claim['acquired']){$repo->change($claim['row']['submission_id'],$record['owner_run'],'claimed','order_ready');$reference=$repo->reserve($claim['row']['submission_id'],$record['owner_run']);}
					file_put_contents($barrier.'-'.$i,json_encode(array('winner'=>$claim['acquired'],'reference'=>$reference)));exit(0);
				}catch(Throwable $e){file_put_contents($barrier.'-'.$i,json_encode(array('error'=>'worker failed')));exit(1);}
			}
			$pids[]=$pid;
		}
		file_put_contents($barrier,'go');$workersOk=true;foreach($pids as $pid){pcntl_waitpid($pid,$status);$workersOk=$workersOk&&pcntl_wexitstatus($status)===0;}dbAssert($workersOk,'Worker failure; inspect disposable test locally.');
		$results=array();for($i=0;$i<8;++$i){$results[]=json_decode(file_get_contents($barrier.'-'.$i),true);}return $results;
	};
	$db->close(); $db=null; unset($store);
	$same=$runWorkers(true);dbAssert(count(array_filter($same,function($r){return $r['winner'];}))===1,'Exactly one concurrent claim winner required.');
	$distinct=$runWorkers(false);$references=array_column($distinct,'reference');sort($references);
	dbAssert($references===range(2,9),'Concurrent references must be unique and serialized.');
	$db=new DisposableDb();$store=new JetCreditSubmissionStore($db,$prefix);$installer=new JetCreditInstallation($db,$prefix);
	$duplicate=$store->acquire(dbRecord('different-intent-same-order',100,'other-run'));
	dbAssert(!$duplicate['acquired'],'Unique native order binding must reject another intent.');
	class RollbackDb extends DisposableDb { public function query($sql){if(strpos($sql,"state='bank_attempting'")!==false){throw new RuntimeException('deliberate rollback probe');}return parent::query($sql);} }
	$failureDb=new RollbackDb();$repo=new JetCreditSubmissionStore($failureDb,$prefix);$record=dbRecord('rollback',999,'rollback-run');$claim=$repo->acquire($record);$repo->change($claim['row']['submission_id'],$record['owner_run'],'claimed','order_ready');
	try{$repo->reserve($claim['row']['submission_id'],$record['owner_run']);throw new RuntimeException('Expected rollback');}catch(JetCreditFinancialException $expected){}
	$counter=$db->query("SELECT counter_value FROM `$table` WHERE record_type='counter'")->row['counter_value'];
	dbAssert((int)$counter===9&&$store->find($record['intent'])['state']==='order_ready','Reference and claim state must roll back together.');
	$failureDb->close();unset($repo);
	// Simulated process failure AFTER a committed reservation: a fresh connection must
	// see the attempt marker and refuse both duplicate acquisition and a second reservation.
	$record=dbRecord('after-reservation',1000,'reservation-run');$claim=$store->acquire($record);$id=$claim['row']['submission_id'];
	$store->change($id,$record['owner_run'],'claimed','order_ready');
	dbAssert($store->reserve($id,$record['owner_run'])===10,'Reference 10 must be committed before transport.');
	$freshDb=new DisposableDb();$freshStore=new JetCreditSubmissionStore($freshDb,$prefix);$freshStore->ready();
	dbAssert($freshStore->find($record['intent'])['state']==='bank_attempting','Attempting state must survive connection replacement.');
	$replay=new JetCreditSubmission($freshStore,null,null,null,null);
	$noValidation=function(){throw new RuntimeException('Replay must not validate or perform side effects.');};
	$outcome=$replay->submit('checkout','jet',$record['intent'],$record['authorization'],1000,$noValidation);
	dbAssert($outcome['code']==='review_required','Interrupted bank attempt must remain blocked.');
	$competitor=$record;$competitor['owner_run']=hash('sha256','competitor');
	dbAssert(!$freshStore->acquire($competitor)['acquired'],'No takeover after committed reservation.');
	try{$freshStore->reserve($id,$record['owner_run']);throw new RuntimeException('Double reservation unexpectedly allowed');}catch(JetCreditFinancialException $expected){}
	$store->change($id,$record['owner_run'],'bank_attempting','bank_unknown',array('bank_state'=>'unknown'));
	dbAssert($replay->submit('checkout','jet',$record['intent'],$record['authorization'],1000,$noValidation)['code']==='review_required','Unknown delivery must remain durably blocked.');
	foreach(array('order_uncertain','before_transport_failed','finalization_failed')as$index=>$blocked){
		$blockedRecord=dbRecord('blocked-'.$blocked,1100+$index,'run-'.$blocked);$blockedClaim=$store->acquire($blockedRecord);
		$store->change($blockedClaim['row']['submission_id'],$blockedRecord['owner_run'],'claimed',$blocked);
		dbAssert($replay->submit('checkout','jet',$blockedRecord['intent'],$blockedRecord['authorization'],1100+$index,$noValidation)['code']==='review_required','Persisted uncertain state must block replay.');
	}
	// Actual manual service path with real SQL storage; order/notification seams never
	// invoke native order APIs or a mailer. Notification preflight deliberately fails.
	class DisposableOrders {
		public function verify($order,$scheme,$prepared){}
		public function finalize($order,$scheme,$status,$result){}
		public function disarmSuccess($order){}
		public function cleanup($order,$context,$prepared){return 'not_needed';}
	}
	class NoDelivery {public function prepare($addresses,$subject,$body){throw new RuntimeException('No mail delivery in DB tests.');}}
	$manualRecord=dbRecord('manual',1200,'unused');$config->set('config_email','owner@example.invalid');
	$service=new JetCreditSubmission($freshStore,new DisposableOrders(),new NoDelivery(),new JetCreditOffer($config->get('jetcredit_offer_secret')),$config);
	$manual=$service->submit('checkout','jet',$manualRecord['intent'],$manualRecord['authorization'],1200,function(){return array('source'=>array(),'calculation'=>array('amount'=>180),'person'=>array('firstname'=>'Test','lastname'=>'User','telephone'=>'0000000000','email'=>'customer@example.invalid'),'recipient'=>array('mode'=>'manual','addresses'=>array()),'status'=>1,'prepared'=>array(),'consent'=>array('flags'=>3,'version'=>hash('sha256','synthetic-consent')));});
	dbAssert($manual['code']==='manual_registered','Manual registration must complete through actual service with SQL storage.');
	$manualRow=$store->find($manualRecord['intent']);
	dbAssert($manualRow['bank_reference']===null&&(int)$db->query("SELECT counter_value FROM `$table` WHERE record_type='counter'")->row['counter_value']===10,'Manual mode must not reserve or increment.');
	dbAssert($replay->submit('checkout','jet',$manualRecord['intent'],$manualRecord['authorization'],1200,$noValidation)===$manual,'Completed authorized replay must be read-only.');
	$before=$db->query("SELECT * FROM `$table` WHERE record_type='counter'")->row;
	dbAssert($installer->install($config,function(){throw new RuntimeException('Reactivation must not reset defaults.');})==='reactivated','Actual installer must validate existing installation.');
	dbAssert($before===$db->query("SELECT * FROM `$table` WHERE record_type='counter'")->row,'Reactivation must preserve counter, marker and secret byte-for-byte.');
	$freshDb->close();
	// R2: actual InnoDB coordination probes. Metadata only, never native orders/mail.
	$db->close();$db=null;
	$purchaseWorkers=function($kind)use($directory,$prefix,$manualRecord){
	 $pids=array();$barrier=$directory.'/purchase-'.$kind;
	 for($i=0;$i<8;++$i){
	  $pid=pcntl_fork();if($pid===-1){file_put_contents($barrier,'abort');foreach($pids as$p){pcntl_waitpid($p,$ignored);}throw new RuntimeException('fork');}
	  if($pid===0){try{
	   $connection=new DisposableDb();$repository=new JetCreditSubmissionStore($connection,$prefix);$repository->ready();$deadline=microtime(true)+10;
	   while(!is_file($barrier)){if(microtime(true)>$deadline)throw new RuntimeException('barrier');usleep(1000);}if(file_get_contents($barrier)!=='go')throw new RuntimeException('abort');
	   if($kind==='generation'){$value=$repository->advanceGeneration(hash('sha256','mysql-generation-scope'),$manualRecord['intent'],$manualRecord['authorization'],array('intent'=>hash('sha256','mysql-next-intent'),'authorization'=>hash('sha256','mysql-next-owner'),'generation'=>2,'request'=>hash('sha256','mysql-transition-'.$i)));}
	   else{$run=bin2hex(random_bytes(32));$reservation=$repository->reserveDraft(hash('sha256','mysql-draft-intent'),hash('sha256','mysql-draft-owner'),hash('sha256','mysql-draft-data'),'jet',$run);$value=$reservation['acquired'];if($value)$repository->bindDraft(hash('sha256','mysql-draft-intent'),$run,1300,hash('sha256','mysql-persisted-draft'));}
	   file_put_contents($barrier.'-'.$i,json_encode(array('value'=>$value)));$connection->close();exit(0);
	  }catch(Throwable$e){file_put_contents($barrier.'-'.$i,json_encode(array('error'=>'purchase worker failed')));exit(1);}}
	  $pids[]=$pid;
	 }
	 file_put_contents($barrier,'go');foreach($pids as$pid){pcntl_waitpid($pid,$status);dbAssert(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'Purchase worker failed.');}
	 $values=array();for($i=0;$i<8;++$i){$values[]=json_decode(file_get_contents($barrier.'-'.$i),true)['value'];}return$values;
	};
	$children=$purchaseWorkers('generation');dbAssert(count(array_unique($children))===1,'Overlapping transitions must return one child.');
	$owners=$purchaseWorkers('draft');dbAssert(count(array_filter($owners))===1,'Exactly one durable native-draft reservation owner.');
	$db=new DisposableDb();$store=new JetCreditSubmissionStore($db,$prefix);$store->ready();
	dbAssert((int)$db->query("SELECT COUNT(*) AS n FROM `$table` WHERE record_type='generation'")->row['n']===1,'One persisted purchase generation.');
	dbAssert($store->draft(hash('sha256','mysql-draft-intent'))['state']==='draft_bound','One persistent order binding.');
	dbAssert((int)$db->query("SELECT counter_value FROM `$table` WHERE record_type='counter'")->row['counter_value']===10,'Generation/draft metadata must not consume banking references.');

	echo "ACTUAL MYSQL PASS: fresh installer/InnoDB/unique indexes; 8 parallel same-intent claims; 8 parallel references; unique order binding; rollback; after-reservation interruption; durable blocked states; manual service/replay without increment; reactivation without reset; 8 concurrent generation transitions and 8 draft reservation owners. Order/SMTP seams are doubles; no native order API or delivery.\n";
} finally {
	if($created){if(!$db){$db=new DisposableDb();}foreach(array('jet_submission','jet_kop','setting','order','currency')as$name){$db->query('DROP TABLE IF EXISTS `'.$prefix.$name.'`');}}
	foreach(glob($directory.'/*')?:array()as$file){unlink($file);}rmdir($directory);
}
