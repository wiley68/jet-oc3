<?php
// Includes installation definitions/doubles and their regression, never a DB connection.
require __DIR__ . '/install.php';
class SettingsResponse extends FResponse {public $redirects=array();public function redirect($url){$this->redirects[]=$url;}}
class SettingsUser {public $modify=true;public function isLogged(){return true;}public function hasPermission($kind,$route){return $this->modify;}}
class SettingsPersistence {public $writes=array();public function editSetting($code,$values,$store){$this->writes[]=array($code,$values,$store);}}
function settingsFixture(){
 $r=sfixture();$db=new BrokenSchemaDb();$cfg=$r->get('config');(new JetCreditInstallation($db,DB_PREFIX))->install($cfg,function(){});$r->set('db',$db);
 // Payment extensions may be unconfigured while saving module display/manual settings.
 $cfg->values['payment_jet_order_status_id']=0;$cfg->values['payment_jet_card_order_status_id']=0;
 $r->set('response',new SettingsResponse());$r->set('user',new SettingsUser());$r->set('model_setting_setting',new SettingsPersistence());
 $r->set('cache',new class{public function delete($key){}});$r->set('document',new class{public function setTitle($title){}});
 $r->set('model_extension_module_jetcredit',new class{public function getAllKops(){return array();}});
 $r->get('request')->get=array('user_token'=>'token');$r->get('session')->data['user_token']='token';
 $r->get('request')->post=JetCreditInstallation::defaults()['module_mt_jet_credit'];$r->get('request')->post['module_mt_jet_credit_id']='MERCHANT';$r->get('request')->post['module_mt_jet_credit_status']='1';
 return $r;
}
foreach(array('unchanged','modified','valid_mail','optional_empty','only_required','disabled')as$case){
 $r=settingsFixture();$post=&$r->get('request')->post;
 if($case==='modified'){$post['module_mt_jet_credit_gap']='25';$post['module_mt_jet_credit_btn_text']='Нов текст';}
 if($case==='valid_mail'){$post['module_mt_jet_credit_mail']='bank@example.test';}
 if($case==='optional_empty'){foreach($post as$key=>&$value){if(!in_array($key,array('module_mt_jet_credit_id','module_mt_jet_credit_mail'),true)){$value='';}}unset($value);}
 if($case==='only_required'){$post=array('module_mt_jet_credit_id'=>'MERCHANT','module_mt_jet_credit_mail'=>'');}
 if($case==='disabled'){$post['module_mt_jet_credit_status']='0';}
 $db=$r->get('db');$before=array($db->secret,$db->counter,$db->seeds,$db->createCount);
 (new ControllerExtensionModuleMTJetCredit($r))->index();$writes=$r->get('model_setting_setting')->writes;
 fcheck(count($writes)===1 && count($r->get('response')->redirects)===1,'save accepts '.$case);
 fcheck($writes[0][0]==='module_mt_jet_credit' && $writes[0][2]===0,'only allowlisted default-store settings persisted');
 $saved=$writes[0][1];fcheck($saved['module_mt_jet_credit_id']==='MERCHANT','shop identifier persisted');
 fcheck(JetCreditSubmissionInput::recipients($saved['module_mt_jet_credit_mail'])['mode']===($case==='valid_mail'?'automatic':'manual'),'recipient mode preserved '.$case);
 fcheck(array($db->secret,$db->counter,$db->seeds,$db->createCount)===$before,'save never reseeds secret/counter');
 if($case==='modified'){fcheck($saved['module_mt_jet_credit_gap']===25 && $saved['module_mt_jet_credit_btn_text']==='Нов текст','modified values persisted');}
 // Follow-up unchanged save uses the persisted configuration.
 foreach($saved as$key=>$value){$r->get('config')->values[$key]=$value;}$r->get('request')->post=$saved;
 (new ControllerExtensionModuleMTJetCredit($r))->index();fcheck(count($r->get('model_setting_setting')->writes)===2,'repeat unchanged save '.$case);
}
foreach(array('mail_missing','mail_invalid','id_missing','id_empty','token','permission','schema_missing','schema_columns','schema_revision','optional_invalid')as$case){
 $r=settingsFixture();$post=&$r->get('request')->post;
 switch($case){
 case 'mail_missing':unset($post['module_mt_jet_credit_mail']);$code='missing_recipient_field';break;
 case 'mail_invalid':$post['module_mt_jet_credit_mail']='invalid';$code='invalid_recipient_configuration';break;
 case 'id_missing':unset($post['module_mt_jet_credit_id']);$r->get('config')->values['module_mt_jet_credit_id']='';$code='invalid_shop_identifier';break;
 case 'id_empty':$post['module_mt_jet_credit_id']=' ';$code='invalid_shop_identifier';break;
 case 'token':$r->get('request')->get['user_token']='wrong';$code='authorization';break;
 case 'permission':$r->get('user')->modify=false;$code='authorization';break;
 case 'schema_missing':unset($r->get('db')->tables['jet_submission']);$code='missing_schema';break;
 case 'schema_columns':$r->get('db')->missingColumn=true;$code='incomplete_schema';break;
 case 'schema_revision':$r->get('db')->revision=1;$code='incorrect_schema_revision';break;
 case 'optional_invalid':$post['module_mt_jet_credit_gap']='bad';$code='invalid_setting';break;
 }
 $db=$r->get('db');$before=array($db->secret,$db->counter,$db->seeds,$db->createCount);
 (new ControllerExtensionModuleMTJetCredit($r))->index();
 fcheck(!$r->get('model_setting_setting')->writes && !$r->get('response')->redirects,'failed save cannot persist '.$case);
 $data=$r->get('response')->output;
 $expected=array(
 'missing_recipient_field'=>'Полето за банков email липсва в изпратената форма.',
 'invalid_recipient_configuration'=>'Въведете валиден банков email/списък',
 'invalid_shop_identifier'=>'Попълнете валиден идентификатор на магазина.',
 'authorization'=>'Неуспешна проверка на права, token или store scope.',
 'missing_schema'=>'Новата submission таблица липсва;',
 'incomplete_schema'=>'Схемата или seed записът е непълен;',
 'incorrect_schema_revision'=>'Несъвместима schema revision;',
 'invalid_setting'=>'Проверете поле: Разстояние над бутона.'
 );
 fcheck(strpos($data['error_warning'],$expected[$code])===0,'understandable Bulgarian validation '.$case);
 fcheck(strpos($data['error_warning'],'[')===false,'no internal diagnostic prefix in settings error '.$case);
 if($code==='invalid_shop_identifier'){fcheck($data['error_warning']===$expected[$code],'exact identifier message');}
 fcheck(strpos($data['error_warning'],$db->secret)===false,'diagnostic never exposes secret');
 fcheck(array($db->secret,$db->counter,$db->seeds,$db->createCount)===$before,'failed save never alters initialization');
}
// Identifier remains mandatory even while disabled.
$r=settingsFixture();$r->get('request')->post['module_mt_jet_credit_status']='0';$r->get('request')->post['module_mt_jet_credit_id']='';
(new ControllerExtensionModuleMTJetCredit($r))->index();fcheck(!$r->get('model_setting_setting')->writes && $r->get('response')->output['error_warning']==='Попълнете валиден идентификатор на магазина.','identifier required regardless of enabled flag');
// Presentation changes must not change the accepted identifier domain or byte limit.
foreach(array(str_repeat('A',128),'Магазин / Shop #42: alpha-beta_7')as$id){
 $r=settingsFixture();$r->get('request')->post['module_mt_jet_credit_id']=$id;
 $saved=JetCreditAdminSettings::module($r->get('request')->post,$r->get('config'));
 fcheck($saved['module_mt_jet_credit_id']===$id,'existing identifier domain accepted without a new pattern');
}
foreach(array(''=>'invalid_shop_identifier','   '=>'invalid_shop_identifier',str_repeat('A',129)=>'invalid_customer_input')as$id=>$internalCode){
 $r=settingsFixture();$r->get('request')->post['module_mt_jet_credit_id']=$id;
 try{JetCreditAdminSettings::module($r->get('request')->post,$r->get('config'));fcheck(false,'invalid identifier rejected');}
 catch(JetCreditAdminSettingException $e){
  fcheck($e->getMessage()===$internalCode && $e->field==='id','original internal diagnostic preserved');
  fcheck(JetCreditAdminSettings::diagnostic($e)==='Попълнете валиден идентификатор на магазина.','exact public message for empty/long identifier');
 }
}
$e=new JetCreditFinancialException('protected_configuration');
fcheck(JetCreditAdminSettings::diagnostic($e)==='Формулярът съдържа защитени настройки и не може да бъде записан.','other protected-setting message remains Bulgarian without internal code');
echo "T-ADMIN-SETTINGS PASS: $fchecks cumulative checks; controller validation/persistence doubles, no real DB.\n";
