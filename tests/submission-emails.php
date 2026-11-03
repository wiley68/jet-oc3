<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$temp = sys_get_temp_dir() . '/jet-mail-' . bin2hex(random_bytes(6));
mkdir($temp . '/library', 0700, true);
define('DIR_SYSTEM', $temp . '/');
define('DIR_APPLICATION', getcwd().'/upload/catalog/');define('DB_PREFIX','oc_');define('HTTP_SERVER','http://test/');define('HTTPS_SERVER','https://test/');
#[AllowDynamicProperties] class Controller {}
#[AllowDynamicProperties] class Model {}
require_once 'upload/catalog/model/extension/mt_jet_credit/jetcredit.php';
require_once 'upload/catalog/model/extension/payment/jet.php';
require_once 'upload/catalog/model/extension/payment/jet_card.php';
class Config { public $values=[];function get($k){return $this->values[$k]??'';} }
class Response {public $output;function addHeader($x){}function setOutput($x){$this->output=$x;} }
class Currency {public $rates;function __construct($base){$this->rates=$base==='EUR'?['EUR'=>1,'USD'=>2]:['USD'=>1,'EUR'=>0.5];}function convert($v,$from,$to){return $v*$this->rates[$to]/$this->rates[$from];}function getId($c){return 1;}function getValue($c){return $this->rates[$c];}}
class Tax {function calculate($v,...$rest){return $v*1.2;}function getTax($v,...$rest){return $v/6;}}
class DB {public $queries=[],$geoMatches=1;function query($s){$this->queries[]=$s;return (object)['num_rows'=>strpos($s,'zone_to_geo_zone')!==false?$this->geoMatches:1];}function escape($s){return $s;}}
class Customer {function isLogged(){return false;}}
class Cart {public $cleared=false;public $price;public $failure='';function getProducts(){return [['product_id'=>1,'quantity'=>2,'price'=>$this->price,'tax_class_id'=>1,'option'=>[['value'=>'red']]]];}function clear(){if($this->failure==='cart')throw new RuntimeException('mock cart clear failure');$this->cleared=true;}}
class Products {public $price;function getProduct($id){return ['name'=>'Product','price'=>$this->price,'special'=>0,'quantity'=>10,'tax_class_id'=>1,'model'=>'P','subtract'=>1,'reward'=>0];}function getCategories($id){return [['category_id'=>1]];}function getProductOptions($id){return [['required'=>true,'product_option_id'=>1,'type'=>'select','product_option_value'=>[['price'=>$this->price/10,'name'=>'red','product_option_value_id'=>1,'option_value_id'=>1]]]];}}
class Category {function getCategory($id){return ['name'=>'Category'];}}
class Promo extends ModelExtensionMtJetCreditJetcredit {public $normal=1.2,$card=0.8,$show=true;function getPromo(...$args){return ['jet_show_button'=>$this->show,'jet_purcent'=>$this->normal,'jet_purcent_card'=>$this->card];}function getJetWideButtonInlineStyle($v){return '';}}
class Trace {public static $events=[];}
class Orders {public $orders=[],$histories=[],$total,$failure='', $snapshot=[], $code='jet', $coefficient=1, $rows=null, $options=null;
 function getOrder($id){Trace::$events[]='read_order';if($this->failure==='missing')return false;return array_merge(['order_id'=>$id,'store_id'=>0,'customer_id'=>0,'payment_code'=>$this->code,'payment_country_id'=>1,'payment_zone_id'=>1,'currency_code'=>'EUR','currency_value'=>$this->coefficient,'total'=>$this->total,'firstname'=>'Test','lastname'=>'User','telephone'=>'123','email'=>'test@example.invalid'],$this->snapshot);}
 function getOrderProducts($id){return $this->rows??[['order_product_id'=>1,'product_id'=>1,'name'=>'Product','quantity'=>2,'price'=>$this->total/2/1.2,'tax'=>$this->total/2-$this->total/2/1.2]];}
 function getOrderOptions($id,$product){return isset($this->options[$product][0])?$this->options[$product]:($this->options??[['value'=>'red']]);}
 function addOrder($d){Trace::$events[]='create_order';if($this->failure==='create')throw new RuntimeException('mock create failure');$this->orders[]=$d;return 99;}
 function addOrderHistory(...$args){Trace::$events[]='history';if($this->failure==='history')throw new RuntimeException('mock native notification failure');$this->histories[]=$args;}
}
class Loader {public $views=[];function language($s){}function model($s){}function view($s,$d){$this->views[]=$d;return $d;}}
class Url {function link($s,...$r){return $s;}}

$count=0;function check($b,$msg){global $count;$count++;if(!$b)throw new Exception($msg);}
function setup($class,$base='EUR',$selected='EUR'){
 $c=new $class;$c->config=new Config;$c->config->values=['config_currency'=>$base,'module_mt_jet_credit_status'=>1,'module_mt_jet_credit_cart_show'=>1,'module_mt_jet_credit_vnoski'=>12,'module_mt_jet_credit_price'=>75,'module_mt_jet_credit_eur'=>3,'payment_jet_status'=>1,'payment_jet_card_status'=>1,'module_mt_jet_credit_count'=>1,'config_tax'=>1,'config_email'=>'admin@example.invalid','module_mt_jet_credit_mail'=>'bank@example.invalid','module_mt_jet_credit_id'=>'Test','payment_jet_order_status_id'=>1,'payment_jet_card_order_status_id'=>1];
 $c->session=(object)['data'=>['currency'=>$selected,'order_id'=>1,'payment_method'=>['code'=>'jet']]];
 $c->request=(object)['post'=>['jet_priceall'=>'600','jet_parva'=>'100','jet_vnoski'=>12,'jet_product_id'=>1,'jet_products'=>'1','jet_products_qt'=>'2','jet_products_pr'=>'300','jet_products_vr'=>'[[]]','jet_variation_id'=>'[]','jet_quantity'=>2,'jet_vnoska'=>'50','jet_obshto'=>'600','jet_total_credit_price'=>'500','jet_gpr'=>'10','jet_lname'=>'','jet_egn'=>'1234567890','jet_name'=>'Test','jet_lastname'=>'User','jet_phone'=>'123','jet_email'=>'test@example.invalid','jet_card'=>0,'product_id'=>1,'option'=>[1=>1]],'get'=>['product_id'=>1],'server'=>['HTTPS'=>true,'REMOTE_ADDR'=>'127.0.0.1'],'cookie'=>[]];
 $c->response=new Response;$c->currency=new Currency($base);$c->tax=new Tax;$c->db=new DB;$c->customer=new Customer;$c->cart=new Cart;$c->cart->price=$base==='EUR'?250:500;$c->load=new Loader;$c->url=new Url;
 $c->model_catalog_product=new Products;$c->model_catalog_product->price=$base==='EUR'?250:500;$c->model_catalog_category=new Category;$c->model_extension_mt_jet_credit_jetcredit=new Promo;$c->model_checkout_order=new Orders;$c->model_checkout_order->total=$base==='EUR'?600:1200;
 $c->model_checkout_order->coefficient=$base==='EUR'?1:0.5;
 foreach(['jet'=>'ModelExtensionPaymentJet','jet_card'=>'ModelExtensionPaymentJetCard'] as $key=>$modelClass){
  $payment=new $modelClass;foreach(['config','db','currency','session'] as $property)$payment->$property=$c->$property;
  $c->{'model_extension_payment_'.$key}=$payment;
 }
 foreach(['config','customer','session','load','model_checkout_order','model_extension_payment_jet','model_extension_payment_jet_card'] as $property)$c->model_extension_mt_jet_credit_jetcredit->$property=$c->$property;
 $c->model_extension_mt_jet_credit_jetcredit->model_extension_mt_jet_credit_jetcredit=$c->model_extension_mt_jet_credit_jetcredit;
 return $c;
}

require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/PHPMailer.php';
require_once DIR_APPLICATION . 'controller/extension/mt_jet_credit/PHPMailer/Exception.php';
class SubmissionTestMailer extends \PHPMailer\PHPMailer\PHPMailer {
 public static $messages=[],$attempts=[],$failures=[];
 public function send(){
  $to=array_column($this->getToAddresses(),0);
  $kind=$to[0]==='admin@example.invalid'?'admin':($to[0]==='test@example.invalid'?'customer':'bank');
  self::$attempts[]=$kind;Trace::$events[]='mail_'.$kind;
  if((self::$failures[$kind]??'')==='throw')throw new \PHPMailer\PHPMailer\Exception('mock uncertain transport');
  if((self::$failures[$kind]??'')==='false')return false;
  self::$messages[]=['kind'=>$kind,'to'=>$to,'cc'=>$this->getCcAddresses(),'bcc'=>$this->getBccAddresses(),'subject'=>$this->Subject,'body'=>$this->Body,'type'=>$this->ContentType,'encoding'=>$this->Encoding,'charset'=>$this->CharSet,'mailer'=>$this->Mailer];return true;
 }
}
try {
 $helper=file_get_contents('upload/system/library/jet_submission_mail.php');
 $helper=str_replace('new \\PHPMailer\\PHPMailer\\PHPMailer(true)', 'new \\SubmissionTestMailer(true)', $helper);
 file_put_contents(DIR_SYSTEM.'library/jet_submission_mail.php',$helper);
 foreach(['Calc'=>'upload/catalog/controller/extension/mt_jet_credit/jetcredit.php','Jet'=>'upload/catalog/controller/extension/payment/jet.php','Card'=>'upload/catalog/controller/extension/payment/jet_card.php'] as $name=>$path){
  foreach(['Old','New'] as $prefix){
   // Immutable pre-Checkout-F01 golden: HEAD now requires context absent from the legacy fixtures.
   $source=$prefix==='Old'?shell_exec('git show '.escapeshellarg('23c4563c9b00c79e5d0f1ecf6baf97d873064f2e:'.$path)):file_get_contents($path);
   $source=preg_replace('/class Controller\w+ extends Controller/','class '.$prefix.$name.' extends Controller',$source,1);
   $source=str_replace('new PHPMailer(true)','new \\SubmissionTestMailer(true)',$source);
   $file=$temp.'/'.$prefix.$name.'.php';file_put_contents($file,$source);require $file;
  }
 }
 function submit($prefix,$kind,$bank='bank@example.invalid',$failures=[],$orderFailure='',$smtp=false,$currency='EUR'){
  SubmissionTestMailer::$messages=[];SubmissionTestMailer::$attempts=[];SubmissionTestMailer::$failures=$failures;Trace::$events=[];
  $c=setup($prefix.(str_starts_with($kind,'Product')||str_starts_with($kind,'Cart')?'Calc':$kind),'EUR',$currency);
  $c->model_checkout_order->failure=$orderFailure;$c->cart->failure=$orderFailure;$c->config->values['module_mt_jet_credit_mail']=$bank;
  if($smtp){$c->config->values['config_mail_engine']='smtp';$c->config->values['config_mail_smtp_password']='mock';}
  if($kind==='Jet'||$kind==='Card'){
   $key=$kind==='Jet'?'jet':'jet_card';$c->session->data['payment_method']['code']=$key;
   $c->session->data['guest']=['email'=>'test@example.invalid'];$c->model_checkout_order->code=$key;
   // Financial fixtures follow the committed calculator, not arbitrary installment values.
   $calculator=setup('OldCalc');$calculator->jet_calculate_cart();$quote=json_decode($calculator->response->output,true);
   foreach(['vnoska','total_credit_price','obshto','gpr','glp'] as $field)$c->request->post['jet_'.$field]=$quote['jet_'.$field.($key==='jet_card'&&$field!=='total_credit_price'?'_card':'')];
   foreach($c->request->post as $k=>$v)if(str_starts_with($k,'jet_'))$c->request->post[$key.substr($k,3)]=$v;
   $c->request->post[$key.'_products_vr']='red';$c->request->post[$key.'_products_pr']='300.00';$c->confirm();
  }else{$c->request->post['jet_card']=str_ends_with($kind,'Card')?1:0;$fn=str_starts_with($kind,'Product')?'jet_send':'jet_send_cart';$c->$fn();}
  return ['result'=>json_decode($c->response->output,true),'messages'=>SubmissionTestMailer::$messages,'attempts'=>SubmissionTestMailer::$attempts,'events'=>Trace::$events,'orders'=>$c->model_checkout_order->orders,'history'=>$c->model_checkout_order->histories,'cleared'=>$c->cart->cleared];
 }
 foreach(['Product','ProductCard','Cart','CartCard','Jet','Card'] as $kind){
  foreach([false,true] as $smtp){
   $baseline=submit('Old',$kind,'bank@example.invalid',[],'',$smtp);
   $reference=$baseline['messages'][0];
   foreach(['bank@example.invalid','one@example.invalid, two@example.invalid',''," \t\r\n "] as $bank){
    $r=submit('New',$kind,$bank,[],'',$smtp);$empty=trim($bank)==='';
    check($r['result']['success']==='success'&&$r['result']['code']===($empty?'manual_registered':'bank_sent'),'primary success '.$kind);
    check($r['attempts']===($empty?['admin','customer']:['bank','admin','customer']),'separate dispatch order');
    check($r['orders']===$baseline['orders']&&$r['history']===$baseline['history']&&$r['cleared']===$baseline['cleared'],'native order data/status/cart behavior preserved');
    foreach($r['messages'] as $m){
     check(!$m['cc']&&!$m['bcc'],'no CC/BCC leakage');
     foreach(['subject','type','encoding','charset','mailer'] as $field)check($m[$field]===$reference[$field],'original message metadata: '.$field);
     $masked=str_replace('ЕГН: 1234567890;','ЕГН: **********;',$reference['body']);
     if($m['kind']==='bank'){
      check($m['body']===$reference['body'],'bank body byte identical');
      check($m['to']===explode(',',str_replace(' ','',$bank)),'bank list unchanged');
      $event=strpos(implode(',',$r['events']),'mail_bank');
      check(($persisted=strpos(implode(',',$r['events']),$r['orders']?'create_order':'read_order'))!==false && $persisted<$event,'native order before bank');
     }elseif($m['kind']==='admin'){
      check($m['to']===['admin@example.invalid']&&$m['body']===$masked,'admin masks EGN only');
     }else{
      check($m['to']===['test@example.invalid']&&$m['body']==='Вашата заявка за кредитно финансиране чрез ПБ Лични Финанси беше подадена успешно за обработка.' . "\r\n\r\n" . $masked,'customer exact intro and masked admin body');
     }
    }
   }
  }
  foreach([['admin'=>'false'],['admin'=>'throw'],['customer'=>'false'],['customer'=>'throw'],['admin'=>'throw','customer'=>'throw']] as $failures){
   $r=submit('New',$kind,'bank@example.invalid',$failures);
   check($r['result']['success']==='success'&&$r['result']['code']==='bank_sent','secondary failures cannot reverse bank acceptance');
   check($r['attempts']===['bank','admin','customer'],'independent secondary attempts without retry');
   foreach($failures as $recipient=>$failure)check($r['result']['notifications'][$recipient]===false,'notification failure reported separately');
  }
  foreach(['false'=>'bank_failed','throw'=>'bank_uncertain'] as $failure=>$code){
   $r=submit('New',$kind,'bank@example.invalid',['bank'=>$failure]);
   check($r['result']['success']==='unsuccess'&&$r['result']['code']===$code,'bank failure/uncertainty never reported as acceptance');
   check($r['attempts']===['bank','admin','customer']&&!$r['history']&&!$r['cleared'],'no bank retry or success-side effects');
   check(count($r['orders'])===(in_array($kind,['Jet','Card'])?0:1),'created order preserved, never recreated');
  }
  foreach(['create','missing'] as $failure){
   if($failure==='create'&&in_array($kind,['Jet','Card']))continue;
   $r=submit('New',$kind,'bank@example.invalid',[],$failure);
   check($r['result']['success']==='unsuccess'&&!$r['attempts'],'unpersisted/missing order prevents all bank delivery');
  }
  $r=submit('New',$kind,'bank@example.invalid',[],'history');
  check($r['result']['success']==='success'&&$r['result']['order_status_updated']===false,'native status/email hook failure cannot reverse accepted bank message');
  if(str_starts_with($kind,'Cart')) {
   $r=submit('New',$kind,'bank@example.invalid',[],'cart');
   check($r['result']['success']==='success'&&$r['result']['cart_cleared']===false,'cart cleanup cannot reverse accepted bank delivery');
  }
  $r=submit('New',$kind,'', ['admin'=>'throw','customer'=>'false']);
  check($r['result']['success']==='success'&&$r['result']['code']==='manual_registered'&&$r['attempts']===['admin','customer'],'empty bank primary registration independent of notifications');
  $r=submit('New',$kind,'bank@example.invalid',[],'',false,'USD');
  check(!$r['attempts']&&!$r['orders']&&!$r['history'],'EUR restriction unchanged');
 }
 if (isset($checkoutFinancialTests)) $checkoutFinancialTests();
 echo "PASS $count submission/email checks; real recipient validation; mocked transport/orders/database\n";
} finally {
 foreach(glob($temp.'/*.php') as $file)unlink($file);
 unlink(DIR_SYSTEM.'library/jet_submission_mail.php');rmdir(DIR_SYSTEM.'library');rmdir($temp);
}
