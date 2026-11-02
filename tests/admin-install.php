<?php
// Execute the OCMOD-applied native install controller with an actual Jet hook.
require __DIR__ . '/financial-bootstrap.php';
require __DIR__ . '/../upload/admin/controller/extension/module/mt_jet_credit.php';
require $argv[1] . '/admin/controller/extension/extension/module.php';
class AdminModuleProbe extends ControllerExtensionExtensionModule {
 public $lists=0;
 protected function validate(){return true;}
 protected function getList(){++$this->lists;}
}
class AdminUser {public function isLogged(){return true;}public function getGroupId(){return 1;}public function hasPermission($a,$b){return true;}}
class AdminExtension {public $installed=0;public function install($a,$b){++$this->installed;}}
class AdminGroup {public function addPermission($a,$b,$c){}}
class AdminLanguage {public function get($key){return 'native success';}}
class AdminLoad {
 public $r; public $hookResult;
 public function __construct($r){$this->r=$r;}
 public function language($a){} public function model($a){}
 public function controller($route){return $this->hookResult;}
}
foreach(array(true,false)as$success){
 $r=ffixture();$r->get('request')->get=array('extension'=>'mt_jet_credit','user_token'=>'token');$r->get('session')->data['user_token']='token';
 $r->set('user',new AdminUser());$r->set('language',new AdminLanguage());$r->set('model_setting_extension',new AdminExtension());$r->set('model_user_user_group',new AdminGroup());
 $load=new AdminLoad($r);$load->hookResult=array('jetcredit_installed'=>$success,'message'=>'[incomplete_schema] owner review');$r->set('load',$load);
 $controller=new AdminModuleProbe($r);$controller->install();
 fcheck($controller->lists===1,'native list rendered once');
 fcheck(isset($r->get('session')->data['success'])===$success,'native caller success only on initialized result');
 fcheck(empty($r->get('response')->output),'no full admin document from hook');
 // An unauthorized real hook returns an allowlisted error without redirect/output.
 $r->get('request')->get['user_token']='wrong';
 $hook=(new ControllerExtensionModuleMTJetCredit($r))->install();
 fcheck($hook['jetcredit_installed']===false && strpos($hook['message'],'[authorization]')===0,'real hook validates token');
 fcheck(empty($r->get('response')->output),'real refused hook emits no full page');
}
echo "T-ADMIN-INSTALL PASS: $fchecks checks; applied native controller/Jet permission hook, no browser/database.\n";
