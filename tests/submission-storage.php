<?php
// SQL/lock/transport doubles only. No store, database, network or disk writes.
error_reporting(E_ALL);
require dirname(__DIR__) . '/upload/system/library/jet_submission.php';
$checks = 0;
function check($value, $message) { global $checks; ++$checks; if (!$value) throw new RuntimeException($message); }
function rejected($fn, $class = 'Throwable') { try { $fn(); } catch (Throwable $e) { check($e instanceof $class, 'expected exception '.$class); return $e; } throw new RuntimeException('Expected rejection'); }
require_once __DIR__ . '/fixtures/submission-memory.php';
function environment($memory=null){$memory=$memory?:new SubmissionMemory;$db=new SubmissionConnection($memory);$store=new JetSubmissionStore($db,'oc_');return [new JetSubmission($store),$store,$db,$memory];}
[$service,$store,$db,$memory]=environment();$store->prepare('7');$key=$memory->meta['digest_key'];
$store->prepare('999');check($memory->meta['next_reference']==='7'&&$memory->meta['digest_key']===$key,'repeat preparation preserves seed/key');
$a=$service->createIntent(0,'session-A','product','same product');$b=$service->createIntent(0,'session-A','product','same product');check($a['intent_id']!==$b['intent_id'],'independent forms never merged');
$cart=$service->createIntent(0,'session-A','cart','basket');$checkout=$service->createIntent(0,'session-A','checkout','order',23);$same=$service->createIntent(0,'session-A','checkout','order',23);check($same['intent_id']===$checkout['intent_id'],'Checkout order shares one identity');
$otherStore=$service->createIntent(1,'session-A','checkout','order',23);check($otherStore['intent_id']!==$checkout['intent_id'],'store isolation');
rejected(function()use($service,$a){$service->resolve($a['intent_id'],0,'other');});rejected(function()use($service,$a){$service->resolve($a['intent_id'],1,'session-A');});
rejected(function()use($service){$service->createIntent(0,'other','checkout','order',23);});
$service->updateDraft($a['intent_id'],0,'session-A','new quote same form');check($service->resolve($a['intent_id'],0,'session-A')['state']==='draft','draft update retains intent');
$data=['amount'=>'600.00','egn'=>'9001010000','email'=>'secret@example.invalid'];
$claim=$service->claim($a['intent_id'],0,'session-A','jet',$data);$token=$claim['execution_token'];check($claim['acquired']&&$claim['row']['reference_no']==='7'&&$memory->meta['next_reference']==='8','claim reserves N and advances N+1');
$retry=$service->claim($a['intent_id'],0,'session-A','jet',array_reverse($data,true));check(!$retry['acquired']&&!isset($retry['execution_token'])&&$memory->meta['next_reference']==='8','replay no reservation or execution authority');
rejected(function()use($service,$a,$data){$service->claim($a['intent_id'],0,'session-A','jet_card',$data);});rejected(function()use($service,$a){$service->claim($a['intent_id'],0,'session-A','jet',['amount'=>'1']);});
rejected(function()use($service,$a){$service->updateDraft($a['intent_id'],0,'session-A','late quote');});
rejected(function()use($service,$a){$service->startAction($a['intent_id'],0,'session-A',str_repeat('0',64),'order');});
rejected(function()use($service,$a,$token){$service->startAction($a['intent_id'],0,'session-A',$token,'bank');});
$service->startAction($a['intent_id'],0,'session-A',$token,'order');
rejected(function()use($service,$a,$token){$service->startAction($a['intent_id'],0,'session-A',$token,'order');});
$service->recordOrder($a['intent_id'],0,'session-A',$token,90);
foreach(['bank','admin','customer','history'] as $action){$service->startAction($a['intent_id'],0,'session-A',$token,$action);$service->finishAction($a['intent_id'],0,'session-A',$token,$action,$action==='admin'?'failed':'done');}
$done=$service->complete($a['intent_id'],0,'session-A',$token,'bank_sent');check($done['state']==='completed'&&$done['admin_state']==='failed','secondary failure preserves primary success');
check($service->replay($a['intent_id'],0,'session-A','jet',$data)===$done,'durable completed replay');
rejected(function()use($service,$a,$token){$service->startAction($a['intent_id'],0,'session-A',$token,'bank');});
check(strpos(json_encode($memory->ledger),'9001010000')===false&&strpos(json_encode($memory->ledger),'secret@example.invalid')===false,'no raw personal data in ledger');
check(!isset($done['claim_digest'],$done['owner_digest'],$done['payload_digest']),'safe public metadata');
// Two connections contend at the lock boundary. This models rejection, not real DB scheduling.
[$second]=environment($memory);$collision=false;
$memory->hook=function()use($second,$b,$data,&$collision){rejected(function()use($second,$b,$data){$second->claim($b['intent_id'],0,'session-A','jet',$data);});$collision=true;};
$winner=$service->claim($b['intent_id'],0,'session-A','jet',$data);check($collision&&$winner['acquired'],'same-intent simulated contention');
check(!$second->claim($b['intent_id'],0,'session-A','jet',$data)['acquired'],'contender replay after winner commit');
// Different rows contend on shared counter; failed attempt rolls back and reserves later.
$c=$service->createIntent(0,'session-A','product','third');$d=$service->createIntent(0,'session-A','product','fourth');
$memory->hook=function()use($memory,$second,$d,$data){$memory->hook=function()use($second,$d,$data){rejected(function()use($second,$d,$data){$second->claim($d['intent_id'],0,'session-A','jet',$data);});};};
$third=$service->claim($c['intent_id'],0,'session-A','jet',$data);$fourth=$second->claim($d['intent_id'],0,'session-A','jet',$data);check($third['row']['reference_no']!==$fourth['row']['reference_no'],'distinct simulated concurrent claims have distinct references');
// Interrupted action cannot start again, including after process reconstruction.
$service->startAction($b['intent_id'],0,'session-A',$winner['execution_token'],'order');$service->uncertain($b['intent_id'],0,'session-A',$winner['execution_token']);
check($second->replay($b['intent_id'],0,'session-A','jet',$data)['order_state']==='uncertain','interrupted action durable');
rejected(function()use($second,$b,$winner){$second->startAction($b['intent_id'],0,'session-A',$winner['execution_token'],'order');});
// Bank uncertainty still permits independent secondary attempts, never bank restart.
$q=$service->claim($checkout['intent_id'],0,'session-A','jet_card',$data);$t=$q['execution_token'];
$service->startAction($checkout['intent_id'],0,'session-A',$t,'bank');$service->finishAction($checkout['intent_id'],0,'session-A',$t,'bank','uncertain');
foreach(['admin','customer'] as $action){$service->startAction($checkout['intent_id'],0,'session-A',$t,$action);$service->finishAction($checkout['intent_id'],0,'session-A',$t,$action,'done');}
rejected(function()use($service,$checkout,$t){$service->complete($checkout['intent_id'],0,'session-A',$t,'bank_sent');});$service->uncertain($checkout['intent_id'],0,'session-A',$t);
foreach(['before','after'] as $failure){
 [$s,$st,$connection]=environment($memory);$intent=$s->createIntent(0,'session-A','product','commit '.$failure);$before=$memory->meta['next_reference'];$connection->commitFailure=$failure;
 rejected(function()use($s,$intent,$data){$s->claim($intent['intent_id'],0,'session-A','jet',$data);},'JetSubmissionCommitUnknown');
 rejected(function()use($s,$intent){$s->resolve($intent['intent_id'],0,'session-A');});
 [$reader]=environment($memory);$row=$reader->resolve($intent['intent_id'],0,'session-A');check($row['state']===($failure==='after'?'processing':'draft'),'unknown COMMIT distinguished only by fresh read');
 if($failure==='after')check(!$reader->claim($intent['intent_id'],0,'session-A','jet',$data)['acquired'],'unknown commit never grants new execution');
 else check($memory->meta['next_reference']===$before,'rolled back reservation unchanged');
}
$ledger=$memory->ledger;$counter=$memory->meta;$store->prepare('1');check($memory->ledger===$ledger&&$memory->meta===$counter,'reinstall preserves complete ledger and counter');
foreach(['innodb_flush_log_at_trx_commit'=>'2','log_bin'=>'ON'] as $var=>$value){$m=clone $memory;$m->vars[$var]=$value;[$s,$st]=environment($m);rejected(function()use($st){$st->verify();});}
$m=clone $memory;$m->tables['oc_jet_submission']['engine']='MyISAM';[, $st]=environment($m);rejected(function()use($st){$st->verify();});
$m=clone $memory;array_pop($m->tables['oc_jet_submission']['indexes']);[, $st]=environment($m);rejected(function()use($st){$st->verify();});
$m=clone $memory;$m->meta=null;unset($m->tables['oc_jet_submission_meta']);[, $st]=environment($m);rejected(function()use($st){$st->prepare('1');});

// IMP-03: existing-first preparation, visible counter and capability contracts.
foreach(['bad',null,[],false] as $seed){$before=serialize([$memory->meta,$memory->ledger]);$store->prepare($seed);check(serialize([$memory->meta,$memory->ledger])===$before,'existing storage ignores invalid legacy seed');}
check($store->getNextReference()===$memory->meta['next_reference'],'durable display uses current value');
foreach([null,'',false,'01'] as $seed){[, $st,$connection,$m]=environment();rejected(function()use($st,$seed){$st->prepare($seed);});check(!$m->tables,'invalid fresh seed performs no DDL');}
[, $st,,$m]=environment();$st->prepare('0');check($st->getNextReference()==='0','empty ledger permits approved zero seed');
foreach(['0','7'] as $bad){$m=clone $memory;$m->meta['next_reference']=$bad;[, $st,$connection]=environment($m);$before=serialize([$m->meta,$m->ledger]);rejected(function()use($st){$st->prepare('999');});check(serialize([$m->meta,$m->ledger])===$before,'inconsistent counter is never repaired');}
$m=clone $memory;$m->meta['next_reference']=$m->ledger[$a['intent_id']]['reference_no'];[, $st]=environment($m);rejected(function()use($st){$st->getNextReference();});
$m=clone $memory;$m->ledger=[];$m->meta=null;[, $st,$connection]=environment($m);rejected(function()use($st){$st->prepare('1');});check(!$m->meta&&!preg_match('/CREATE|INSERT|UPDATE/',implode(' ',$connection->queries)),'existing empty metadata is not reseeded');
$m=clone $memory;unset($m->tables['oc_jet_submission']);[, $st]=environment($m);rejected(function()use($st){$st->prepare('1');});
$m=clone $memory;$m->tables['oc_jet_submission']['indexes'][]=['Key_name'=>'state_lookup','Column_name'=>'state','Seq_in_index'=>1,'Non_unique'=>1,'Sub_part'=>null];
foreach($m->tables['oc_jet_submission']['indexes'] as &$index)if($index['Key_name']==='bank_reference')$index['Key_name']='renamed_reference';unset($index);
[, $st]=environment($m);check($st->getNextReference()===$m->meta['next_reference'],'extra nonunique and renamed equivalent unique indexes accepted');
$m=clone $memory;foreach($m->tables['oc_jet_submission']['indexes'] as &$index)if($index['Key_name']==='bank_reference')$index['Non_unique']=1;unset($index);[, $st]=environment($m);rejected(function()use($st){$st->verify();});
foreach(['0','2','3'] as $flush){$m=clone $memory;$m->vars['innodb_flush_log_at_trx_commit']=$flush;[, $st]=environment($m);rejected(function()use($st){$st->verify();});}
$m=clone $memory;$m->vars['autocommit']='OFF';[, $st,$connection]=environment($m);$st->verify();check(in_array('SET SESSION autocommit=1',$connection->queries,true)&&$m->vars['autocommit']==='OFF','only dedicated session normalized, global default unchanged');
foreach([['log_bin'=>'ON','sync_binlog'=>'1'],['log_bin'=>'ON','sync_binlog'=>'0','binlog_storage_engine'=>'innodb']] as $config){$m=clone $memory;$m->vars=array_merge($m->vars,$config);[, $st]=environment($m);$st->verify();check($st->getNextReference()===$m->meta['next_reference'],'safe durable binary log capability accepted');}
foreach([['log_bin'=>'ON','sync_binlog'=>'0','binlog_storage_engine'=>'file'],['log_bin'=>'ON','sync_binlog'=>'0','binlog_storage_engine'=>'unknown'],['log_bin'=>'UNKNOWN']] as $config){$m=clone $memory;$m->vars=array_merge($m->vars,$config);[, $st]=environment($m);rejected(function()use($st){$st->verify();});}

$m=clone $memory;$refs=array_filter(array_column($m->ledger,'reference_no'),function($value){return $value!==null;});$m->meta['next_reference']=(string)max($refs);[, $st]=environment($m);rejected(function()use($st){$st->verify();});
$m=clone $memory;$m->vars['autocommit']='UNKNOWN';[, $st]=environment($m);rejected(function()use($st){$st->verify();});
foreach(['ack-without-change','failure'] as $mode){
 $m=clone $memory;$m->vars['autocommit']='OFF';
 $connection=new class($m) extends SubmissionConnection {public $mode;function query($sql){if($sql==='SET SESSION autocommit=1')return $this->mode==='ack-without-change';return parent::query($sql);}};$connection->mode=$mode;
 $st=new JetSubmissionStore($connection,'oc_');rejected(function()use($st){$st->verify();});
}
$m=clone $memory;unset($m->vars['sync_binlog']);[, $st]=environment($m);$st->verify();check($st->getNextReference()===$m->meta['next_reference'],'disabled binary log does not require irrelevant sync variable');
$m=clone $memory;unset($m->vars['innodb_flush_log_at_trx_commit']);[, $st]=environment($m);rejected(function()use($st){$st->verify();});
$m=clone $memory;$m->tables['oc_jet_submission']['indexes'][3]['Sub_part']=8;[, $st]=environment($m);rejected(function()use($st){$st->verify();});

// Manual registration and known bank failure are terminal outcomes, not retry grants.
foreach(['manual_registered','bank_failed'] as $code){
 $intent=$service->createIntent(0,'session-A','cart','terminal');$result=$service->claim($intent['intent_id'],0,'session-A','jet',$data);$t=$result['execution_token'];$id=$intent['intent_id'];
 $service->startAction($id,0,'session-A',$t,'order');$service->recordOrder($id,0,'session-A',$t,91);
 if($code==='manual_registered')$service->skipAction($id,0,'session-A',$t,'bank');
 else{$service->startAction($id,0,'session-A',$t,'bank');$service->finishAction($id,0,'session-A',$t,'bank','failed');}
 foreach(['admin','customer'] as $action){$service->startAction($id,0,'session-A',$t,$action);$service->finishAction($id,0,'session-A',$t,$action,'failed');}
 if($code==='manual_registered')foreach(['history','clear'] as $action){$service->startAction($id,0,'session-A',$t,$action);$service->finishAction($id,0,'session-A',$t,$action,'done');}
 else foreach(['history','clear'] as $action)$service->skipAction($id,0,'session-A',$t,$action);
 check($service->complete($id,0,'session-A',$t,$code)['result_code']===$code,'terminal result '.$code);
 $next=$memory->meta['next_reference'];check(!$service->claim($id,0,'session-A','jet',$data)['acquired']&&$memory->meta['next_reference']===$next,'terminal retry has no reservation');
}
// Lost action-start COMMIT acknowledgement: never re-enter the irreversible action.
[$s,$st,$connection]=environment($memory);$intent=$s->createIntent(0,'session-A','product','lost action commit');$id=$intent['intent_id'];$result=$s->claim($id,0,'session-A','jet',$data);$t=$result['execution_token'];$connection->commitFailure='after';
rejected(function()use($s,$id,$t){$s->startAction($id,0,'session-A',$t,'order');},'JetSubmissionCommitUnknown');
[$s]=environment($memory);check($s->resolve($id,0,'session-A')['order_state']==='started','action boundary persisted before caller can execute');
rejected(function()use($s,$id,$t){$s->startAction($id,0,'session-A',$t,'order');});
// Existing tables with a missing singleton must not reseed a nonempty ledger.
$m=clone $memory;$m->meta=null;[, $st]=environment($m);rejected(function()use($st){$st->prepare('1');});check($m->meta===null,'missing singleton never resets reserved numbers');
$m=clone $memory;$m->tables['oc_jet_submission']['fields'][0]['Collation']='ascii_general_ci';[, $st]=environment($m);rejected(function()use($st){$st->verify();});
$m=clone $memory;$m->tables['oc_jet_submission']['fields'][9]['Default']='completed';[, $st]=environment($m);rejected(function()use($st){$st->verify();});
foreach([true,1.2,-1,'01','18446744073709551616',[]] as $bad)rejected(function()use($bad){JetSubmissionStore::reference($bad);});
check(JetSubmissionStore::reference('0')==='0','existing zero reference is not silently renumbered');
[$s,$st,,$m]=environment();$st->prepare('9999999999999999999');$intent=$s->createIntent(0,'owner','product','large reference');$result=$s->claim($intent['intent_id'],0,'owner','jet',$data);check($result['row']['reference_no']==='9999999999999999999'&&$m->meta['next_reference']==='10000000000000000000','decimal reservation independent of PHP integer/float precision');
[$s,$st,,$m]=environment();$st->prepare('18446744073709551615');$intent=$s->createIntent(0,'owner','product','exhausted');rejected(function()use($s,$intent,$data){$s->claim($intent['intent_id'],0,'owner','jet',$data);});check($m->meta['next_reference']==='18446744073709551615'&&$s->resolve($intent['intent_id'],0,'owner')['state']==='draft','counter exhaustion fails without wrap or claim');
check(!preg_match('/\b(DROP|TRUNCATE|DELETE|ALTER)\b/',implode("\n",$db->queries)),'no destructive migration or retention SQL');
echo "PASS $checks submission storage/service checks; SQL/lock doubles only; real contention/durability NOT TESTED\n";
