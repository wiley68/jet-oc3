<?php
// Reuse native-admin boundary doubles; schema preparation is a spy, never a real DB.
require __DIR__ . '/admin-schema.php';
$start = $checks;
class SubmissionAdminController extends ControllerExtensionModuleMTJetCredit {
    public $storage;
    protected function submissionStore() { return $this->storage; }
}
class SubmissionPreparationSpy {
    public $seeds=[], $fail=false, $next='812';
    function getNextReference() { if($this->fail)throw new RuntimeException('secret SQL credential detail');return $this->next; }
    function prepare($seed) { $this->seeds[]=$seed; if($this->fail)throw new RuntimeException('secret SQL credential detail'); }
}
class SubmissionSettingsSpy {
    public $settings=['module_mt_jet_credit_count'=>'17','module_mt_jet_credit_id'=>'Merchant'], $edits=[];
    function getSetting($code) { check($code==='module_mt_jet_credit','read persistent module settings');return $this->settings; }
    function editSetting($code,$data) { $this->edits[]=$data;$this->settings=$data; }
}
class SubmissionAdminDB {
    public $queries=[];
    function query($sql) { $this->queries[]=$sql;check(strpos($sql,'CREATE TABLE IF NOT EXISTS `oc_jet_kop`')===0,'only existing promotion schema creation');return true; }
}
class SubmissionAdminLoader {
    public $loaded=[], $controllers=[], $viewData;
    function model($route) { $this->loaded[]=$route;check(in_array($route,['setting/setting','extension/module/jetcredit'],true),'only existing module/settings models loaded'); }
    function language($route) {}
    function view($route,$data) { $this->viewData=$data;return 'admin view'; }
    function controller($route) { $this->controllers[]=$route; }
}
function foundationAdmin(){
    [$old]=environment();$c=new SubmissionAdminController;
    foreach(get_object_vars($old) as $k=>$v)$c->$k=$v;
    $c->log=new class {public $messages=[];function write($message){$this->messages[]=$message;}};
    $c->storage=new SubmissionPreparationSpy;$c->model_setting_setting=new SubmissionSettingsSpy;$c->load=new SubmissionAdminLoader;$c->db=new SubmissionAdminDB;
    $c->url=new SchemaURLDouble('https://test.invalid/admin/');
    $c->config=new class {function get($key){return '';}};
    $c->document=new class {function setTitle($title){}};
    $c->cache=new class {public $deleted=[];function delete($key){$this->deleted[]=$key;}};
    $c->model_extension_module_jetcredit=new class {function getAllKops(){return [['promotion'=>'preserved']];}};
    $c->response=new class extends SchemaResponseDouble {public $redirects=[];function redirect($url){$this->redirects[]=$url;}};
    return $c;
}
foreach(['guest','no-access','no-modify','GET','missing-token','wrong-token'] as $case){
    $c=foundationAdmin();
    if($case==='guest')$c->user->logged=false;
    if($case==='no-access')$c->user->access=false;
    if($case==='no-modify')$c->user->modify=false;
    if($case==='GET')$c->request->server['REQUEST_METHOD']='GET';
    if($case==='missing-token')unset($c->request->get['user_token']);
    if($case==='wrong-token')$c->request->get['user_token']='bad';
    dispatchAdmin($c,'prepareSubmissionSchema');
    check(!$c->storage->seeds&&!$c->db->queries,'unauthorized preparation has no mutations');
}
foreach(['missing','wrong'] as $case){$c=foundationAdmin();$c->request->get=$case==='missing'?[]:['user_token'=>'bad'];$c->prepareSubmissionSchema();check(!$c->storage->seeds,'direct preparation checks token');}
$c=foundationAdmin();$settings=$c->model_setting_setting->settings;$c->request->post['module_mt_jet_credit_count']='1';
dispatchAdmin($c,'prepareSubmissionSchema');check($c->storage->seeds===['17'],'upgrade seed comes from persistent settings, not POST/config');
check(json_decode($c->response->output,true)===['success'=>'success'],'preparation reports success');
check($c->model_setting_setting->settings===$settings&&!$c->model_setting_setting->edits&&!$c->db->queries,'upgrade preserves merchant data');
$c->storage->fail=true;$c->prepareSubmissionSchema();check(json_decode($c->response->output,true)===['success'=>'unsuccess','error'=>'Хранилището не е подготвено или е несъвместимо. Подготовката е отказана; броячът не е нулиран.'],'safe administrative failure without storage details');
$c=foundationAdmin();$settings=$c->model_setting_setting->settings;$c->install();$c->install();
check($c->storage->seeds===['17','17']&&!$c->model_setting_setting->edits&&$c->model_setting_setting->settings===$settings,'reinstall preserves persistent settings');
$c=foundationAdmin();$c->model_setting_setting->settings=[];$c->install();check($c->storage->seeds===[null]&&count($c->model_setting_setting->edits)===1,'settings absence alone never supplies default seed');
$c=foundationAdmin();$c->storage->fail=true;$c->session->data['success']='old success';$c->install();check(count($c->response->redirects)===1&&isset($c->session->data['jet_submission_install_error'])&&!isset($c->session->data['success']),'install failure redirects with safe persistent admin error');
check(count($c->log->messages)===1&&strpos($c->log->messages[0],'stage=storage-preparation')!==false&&strpos($c->log->messages[0],'secret')===false,'install logs safe stage/source without original sensitive message');
check(!$c->db->queries&&!$c->model_setting_setting->edits,'storage failure stops later install mutations');
$c=foundationAdmin();$c->uninstall();check(!$c->db->queries&&!$c->model_setting_setting->edits&&!$c->storage->seeds,'uninstall preserves ledger, settings and promotion data');
check($c->load->controllers===['extension/modification/refresh'],'native modification refresh retained');
foreach(['install','uninstall'] as $method){$c=foundationAdmin();$c->user->modify=false;if($method==='uninstall'){try{$c->$method();throw new LogicException('expected permission denial');}catch(RuntimeException $e){check(!$c->db->queries&&!$c->storage->seeds,'unauthorized uninstall has no effects');}}else{$c->install();check(in_array('HTTP/1.1 403 Forbidden',$c->response->headers,true)&&!$c->db->queries&&!$c->storage->seeds,'unauthorized install returns controlled 403 without effects');}}
// IMP-03: real index path, including native whole-group settings replacement.
foreach(['1','999999','broken',[],null] as $posted){
 $c=foundationAdmin();$c->request->post=['module_mt_jet_credit_gap'=>'0','module_mt_jet_credit_id'=>'Updated merchant','module_mt_jet_credit_price'=>'75','module_mt_jet_credit_eur'=>'forged'];
 if($posted!==null)$c->request->post['module_mt_jet_credit_count']=$posted;
 $c->model_setting_setting->settings['module_mt_jet_credit_eur']='1.95583';
 $c->index();$data=$c->load->viewData;
 check($c->model_setting_setting->settings['module_mt_jet_credit_count']==='17','persistent seed survives forged/stale/missing/invalid POST');
 check($c->model_setting_setting->settings['module_mt_jet_credit_eur']==='1.95583'&&$c->model_setting_setting->settings['module_mt_jet_credit_id']==='Updated merchant','unrelated settings preserved and ordinary save works');
 check(count($c->model_setting_setting->edits)===1&&count($c->response->redirects)===1,'valid settings save completes');
 check($data['module_mt_jet_credit_count']==='812'&&!$c->storage->seeds,'save displays durable value without touching storage');
 check($data['module_mt_jet_schemes']===[['promotion'=>'preserved']],'promotion display preserved');
 $c->request->server['REQUEST_METHOD']='GET';$c->request->post=[];$c->index();check($c->load->viewData['module_mt_jet_credit_count']==='812','redirect GET displays durable value');
}
$c=foundationAdmin();$c->request->post=['module_mt_jet_credit_count'=>'malicious','module_mt_jet_credit_gap'=>'bad','module_mt_jet_credit_id'=>'','module_mt_jet_credit_price'=>'bad'];$c->index();
check($c->load->viewData['module_mt_jet_credit_count']==='812'&&$c->load->viewData['error_warning']!==''&&!$c->model_setting_setting->edits,'validation error displays actual durable counter and saves nothing');
$c=foundationAdmin();$c->storage->fail=true;$c->request->server['REQUEST_METHOD']='GET';$c->index();
check($c->load->viewData['module_mt_jet_credit_count']==='','unavailable storage never displays legacy authority or internal error');
$c=foundationAdmin();unset($c->model_setting_setting->settings['module_mt_jet_credit_count']);$c->prepareSubmissionSchema();check($c->storage->seeds===[null],'ambiguous upgrade never guesses seed');
$c=foundationAdmin();$c->model_setting_setting->settings['module_mt_jet_credit_count']='invalid';$c->prepareSubmissionSchema();check($c->storage->seeds===['invalid'],'existing-storage-first decision belongs to verified store');
$c=foundationAdmin();$c->model_setting_setting->settings=[];$c->request->post=['module_mt_jet_credit_count'=>'forged'];$c->install();check($c->storage->seeds===[null]&&!isset($c->model_setting_setting->settings['module_mt_jet_credit_count']),'install never persists or uses client seed');
$c=foundationAdmin();unset($c->model_setting_setting->settings['module_mt_jet_credit_count']);$c->request->post=['module_mt_jet_credit_gap'=>'0','module_mt_jet_credit_id'=>'Merchant','module_mt_jet_credit_price'=>'75','module_mt_jet_credit_count'=>'999'];$c->index();check(!isset($c->model_setting_setting->settings['module_mt_jet_credit_count']),'ordinary save cannot create absent seed');
$twig=file_get_contents(dirname(__DIR__).'/upload/admin/view/template/extension/module/mt_jet_credit.twig');
preg_match('/<input[^>]*id="module_mt_jet_credit_count"[^>]*>/', $twig,$field);
check(isset($field[0])&&strpos($field[0],'readonly')!==false&&!preg_match('/\bname\s*=/', $field[0]),'counter input readonly without submit name');
check(strpos($twig,'>№ Поръчка</label>')!==false&&strpos($twig,'>Следваща референция</label>')===false,'exact original Bulgarian counter label');
check(strpos($field[0],'placeholder=""')!==false,'unprepared counter has no diagnostic placeholder');
// Integrate the production controller and production store against the SQL double.
require_once __DIR__.'/fixtures/submission-memory.php';
$c=foundationAdmin();$m=new SubmissionMemory;$connection=new SubmissionConnection($m);$c->storage=new JetSubmissionStore($connection,'oc_');
$c->prepareSubmissionSchema();check(json_decode($c->response->output,true)===['success'=>'success']&&$c->storage->getNextReference()==='17','upgrade prepares real storage from persistent verified seed');
$service=new JetSubmission($c->storage);$intent=$service->createIntent(0,'admin-test-owner','product','test form');$claim=$service->claim($intent['intent_id'],0,'admin-test-owner','jet',['amount'=>'100.00']);
check($claim['row']['reference_no']==='17'&&$c->storage->getNextReference()==='18','reserved reference and admin display agree');
$c->model_setting_setting->settings['module_mt_jet_credit_count']='invalid';$before=serialize([$m->meta,$m->ledger]);$queries=count($connection->queries);
$c->prepareSubmissionSchema();$c->prepareSubmissionSchema();
check(json_decode($c->response->output,true)===['success'=>'success']&&serialize([$m->meta,$m->ledger])===$before,'existing valid storage ignores bad persistent seed');
check(!preg_match('/CREATE|INSERT|UPDATE/',implode(' ',array_slice($connection->queries,$queries))),'repeat preparation of existing storage performs no DDL or reseed');
$c->uninstall();$c->install();check(serialize([$m->meta,$m->ledger])===$before,'real storage ledger/counter/key preserved across normal uninstall/reinstall');
$c->request->server['REQUEST_METHOD']='GET';$c->index();check($c->load->viewData['module_mt_jet_credit_count']==='18','actual durable counter rendered after reinstall');
foreach([null,'',false,'01'] as $seed){
 $c=foundationAdmin();$c->model_setting_setting->settings=['module_mt_jet_credit_id'=>'Existing merchant'];if($seed!==null)$c->model_setting_setting->settings['module_mt_jet_credit_count']=$seed;
 $m=new SubmissionMemory;$c->storage=new JetSubmissionStore(new SubmissionConnection($m),'oc_');$c->prepareSubmissionSchema();
 check(json_decode($c->response->output,true)['success']==='unsuccess'&&!$m->tables,'ambiguous or invalid upgrade refused without creating tables');
}
$c=foundationAdmin();$c->model_setting_setting->settings=[];$m=new SubmissionMemory;$c->storage=new JetSubmissionStore(new SubmissionConnection($m),'oc_');$c->install();check(!$m->tables&&$m->meta===null&&count($c->response->redirects)===1,'missing seed and storage cause controlled refusal without default or DDL');
check(strpos($c->log->messages[0],'reason=reference-unavailable-or-invalid')!==false,'safe reference diagnosis retained');
$c->request->server['REQUEST_METHOD']='GET';$c->index();check($c->load->viewData['module_mt_jet_credit_count']===''&&$c->load->viewData['error_warning']!==''&&!isset($c->session->data['jet_submission_install_error']),'redirect renders admin failure outside empty counter and consumes warning');
$c->index();check($c->load->viewData['error_warning']==='','failure warning consumed once');

// HOTFIX-01: native installer grants DB rights after the current user snapshot.
class HotfixInstallUser extends SchemaUserDouble {
 public $coreModify=true;
 function hasPermission($type,$route){
  check(in_array($route,['extension/module/mt_jet_credit','extension/extension/module'],true),'only module or native installer authorization checked');
  return $route==='extension/extension/module' ? ($type==='modify'&&$this->coreModify) : $this->$type;
 }
}
function nativeInstallAdmin(){
 $c=foundationAdmin();$c->user=new HotfixInstallUser;$c->user->modify=false;
 $c->request->get['route']='extension/extension/module/install';$c->request->get['extension']='mt_jet_credit';return $c;
}
$c=nativeInstallAdmin();$c->install();check($c->storage->seeds===['17']&&count($c->db->queries)===1&&!$c->response->headers,'core-authorized native install tolerates stale module permission snapshot');
foreach(['core-denied','guest','wrong-route','missing-route','wrong-extension','missing-extension','wrong-token','missing-token','empty-session-token','array-token'] as $case){
 $c=nativeInstallAdmin();
 if($case==='core-denied')$c->user->coreModify=false;
 if($case==='guest')$c->user->logged=false;
 if($case==='wrong-route')$c->request->get['route']='extension/module/mt_jet_credit/install';
 if($case==='missing-route')unset($c->request->get['route']);
 if($case==='wrong-extension')$c->request->get['extension']='other_module';
 if($case==='missing-extension')unset($c->request->get['extension']);
 if($case==='wrong-token')$c->request->get['user_token']='forged';
 if($case==='missing-token')unset($c->request->get['user_token']);
 if($case==='empty-session-token')$c->session->data['user_token']='';
 if($case==='array-token')$c->request->get['user_token']=[];
 $c->install();check(in_array('HTTP/1.1 403 Forbidden',$c->response->headers,true)&&!$c->storage->seeds&&!$c->db->queries&&!$c->model_setting_setting->edits,'unauthorized native/direct install refused: '.$case);
}
$c=nativeInstallAdmin();$c->prepareSubmissionSchema();check(!$c->storage->seeds&&in_array('HTTP/1.1 403 Forbidden',$c->response->headers,true),'native install exception never applies to explicit preparation');
$c=nativeInstallAdmin();$c->request->post=['module_mt_jet_credit_gap'=>'0','module_mt_jet_credit_id'=>'Merchant','module_mt_jet_credit_price'=>'75'];$c->index();check(!$c->model_setting_setting->edits&&$c->load->viewData['error_warning']!=='','native installer permission cannot authorize settings mutation');
// Real storage objects with a simulated native settings deletion before uninstall hook.
$c=foundationAdmin();$m=new SubmissionMemory;$connection=new SubmissionConnection($m);$c->storage=new JetSubmissionStore($connection,'oc_');$c->install();
$service=new JetSubmission($c->storage);$intent=$service->createIntent(0,'owner','cart','basket');$service->claim($intent['intent_id'],0,'owner','jet',['amount'=>'100.00']);
$before=serialize([$m->meta,$m->ledger]);$c->model_setting_setting->settings=[];$c->uninstall();$c->install();
check(serialize([$m->meta,$m->ledger])===$before&&$c->storage->getNextReference()==='18','native settings removal plus uninstall/reinstall preserves durable counter/key/ledger');
check(!isset($c->session->data['jet_submission_install_error']),'valid existing storage needs no legacy seed after uninstall');
foreach(['one-table','missing-singleton','bad-index'] as $case){
 $copy=clone $m;
 if($case==='one-table')unset($copy->tables['oc_jet_submission']);
 if($case==='missing-singleton')$copy->meta=null;
 if($case==='bad-index')array_pop($copy->tables['oc_jet_submission']['indexes']);
 $before=serialize([$copy->tables,$copy->meta,$copy->ledger]);$c=foundationAdmin();$c->model_setting_setting->settings=[];
 $db=new SubmissionConnection($copy);$c->storage=new JetSubmissionStore($db,'oc_');$c->install();
 check(serialize([$copy->tables,$copy->meta,$copy->ledger])===$before&&count($c->response->redirects)===1,'partial/incompatible install controlled refusal without state reset: '.$case);
 check(!preg_match('/CREATE|INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER/',implode(' ',$db->queries)),'invalid existing durable storage never repaired automatically');
}
$c=foundationAdmin();$m=new SubmissionMemory;$c->model_setting_setting->settings['module_mt_jet_credit_count']='1';$c->storage=new JetSubmissionStore(new SubmissionConnection($m),'oc_');$c->install();check($c->storage->getNextReference()==='1','new storage initializes from verified persistent seed including explicit one');
// Native Response::redirect exits, preventing core's unconditional success assignment.
class HotfixRedirectExit extends RuntimeException {}
$c=foundationAdmin();$c->storage->fail=true;$c->response=new class extends SchemaResponseDouble {function redirect($url){throw new HotfixRedirectExit;}};
try{$c->install();$c->session->data['success']='incorrect core success';throw new LogicException('expected terminal redirect');}catch(HotfixRedirectExit $done){}
check(!isset($c->session->data['success'])&&isset($c->session->data['jet_submission_install_error']),'terminal native redirect prevents false core success after failed hook');
$c=foundationAdmin();$c->db=new class {function query($sql){throw new RuntimeException('password=secret user_token=private SQL with PII');}};$c->install();
check(count($c->response->redirects)===1&&strpos($c->log->messages[0],'stage=promotion-preparation')!==false&&strpos(implode(' ',$c->log->messages),'secret')===false,'post-storage failure has useful safe stage and no sensitive log data');
$c=foundationAdmin();$c->log=new class {function write($message){throw new RuntimeException('log write failed');}};$c->storage->fail=true;$c->install();check(count($c->response->redirects)===1,'logging failure does not escape controlled install failure');
echo 'PASS '.($checks-$start)." submission admin preparation/lifecycle checks; no live database\n";
