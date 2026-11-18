<?php
// ARCH-02 public native evaluator with isolated SQL/order/mail doubles.
require __DIR__.'/cart-native-totals.php';
require __DIR__.'/opencart-request.php';
$cartStart=$checks;
define('HTTP_SERVER','http://test/'); define('HTTPS_SERVER','https://test/');
$repo=dirname(__DIR__);
require_once __DIR__.'/fixtures/submission-memory.php';
foreach (['PHPMailer','SMTP','Exception'] as $name) {
    $target=DIR_APPLICATION.'controller/extension/mt_jet_credit/PHPMailer/'.$name.'.php';
    if (!is_dir(dirname($target))) mkdir(dirname($target),0700,true);
    copy($repo.'/upload/catalog/controller/extension/mt_jet_credit/PHPMailer/'.$name.'.php',$target);
}
require $repo.'/upload/catalog/controller/extension/mt_jet_credit/jetcredit.php';
$old=shell_exec('git show HEAD:upload/catalog/controller/extension/mt_jet_credit/jetcredit.php');
$old=str_replace('class ControllerExtensionMtJetCreditJetCredit extends Controller','class CartLegacyControllerBase extends Controller',$old);
file_put_contents($scratch.'legacy-cart.php',$old); require $scratch.'legacy-cart.php';
// HEAD also requires durable submission dependencies; inject the same doubles/owner
// as CartFinancialController without replacing intent, quote or claim validation.
class CartLegacyController extends CartLegacyControllerBase {
    protected function cartModel(){return $this->model_extension_mt_jet_credit_jetcredit;}
    protected function submissionService(){return $this->registry->get('test_submission_service');}
    protected function submissionOwner(){return $this->session->getId();}
}
class CartFinancialMailer extends PHPMailer\PHPMailer\PHPMailer {
    public static $messages=[];
    function send(){self::$messages[]=['to'=>$this->getToAddresses(),'body'=>$this->Body,'subject'=>$this->Subject];return true;}
}
$helper=file_get_contents($repo.'/upload/system/library/jet_submission_mail.php');
$helper=str_replace('new \\PHPMailer\\PHPMailer\\PHPMailer(true)','new \\CartFinancialMailer(true)',$helper);
file_put_contents(DIR_SYSTEM.'library/jet_submission_mail.php',$helper);
class CartFinancialDB extends CartNativeMemoryDB {
    public $cartRows=null,$promotion=null,$optionKinds=[];
    public $promotionRules = null;
    function query($sql){
        if (!preg_match('/^\s*SELECT\s/i',$sql)) {$this->writes[]=$sql;return (object)['rows'=>[],'row'=>[],'num_rows'=>1];}
        $rows=null;
        if (strpos($sql,'FROM oc_cart WHERE')!==false && $this->cartRows!==null) $rows=$this->cartRows;
        elseif (strpos($sql,'FROM oc_product_option po ')!==false) {
            preg_match("/po.product_option_id = '(\d+)'/",$sql,$m);$id=(int)$m[1];
            $rows=[['product_option_id'=>$id,'option_id'=>80+$id,'name'=>'Опция "O\'Reilly" &amp; <b>цвят</b>','type'=>$this->optionKinds[$id]??'select']];
        } elseif (strpos($sql,'FROM oc_product_option_value pov ')!==false) {
            preg_match("/pov.product_option_value_id = '(\d+)'/",$sql,$m);$id=(int)$m[1];
            $rows=[['option_value_id'=>100+$id,'name'=>'стойност '.$id.' "цитат" &amp; апостроф\'','quantity'=>10,'subtract'=>0,'price'=>10,'price_prefix'=>$id===11?'-':'+','points'=>0,'points_prefix'=>'+','weight'=>0,'weight_prefix'=>'+']];
        } elseif (strpos($sql,'FROM oc_jet_kop')!==false) {
            $rows = $this->promotionRules === null
                ? ($this->promotion === null ? [] : [$this->promotion])
                : $this->matchingPromotion($sql);
        }
        if ($rows!==null) {$this->queries[]=$sql;return (object)['rows'=>$rows,'row'=>$rows[0]??[],'num_rows'=>count($rows)];}
        $result=parent::query($sql);
        if (strpos($sql,'FROM oc_product_to_store ')!==false && $result->num_rows) {
            preg_match("/p2s.product_id = '(\d+)'/",$sql,$m);$result->row['product_id']=(int)$m[1];$result->rows=[$result->row];
        }
        return $result;
    }
    private function matchingPromotion($sql) {
        check(preg_match("/WHERE \(jet_product_id = '\*' OR jet_product_id = '(\d+)'\)/", $sql, $product)
            && preg_match('/FIND_IN_SET\((\d+), REPLACE\(jet_product_meseci/', $sql, $term)
            && preg_match('/AND (\d+(?:\.\d+)?) >= jet_product_price/', $sql, $principal)
            && strpos($sql, "CASE WHEN jet_product_id = '{$product[1]}' THEN 0 ELSE 1 END,") !== false
            && strpos($sql, 'jet_product_start <= CURDATE()') !== false
            && strpos($sql, 'jet_product_end >= CURDATE()') !== false
            && preg_match('/id ASC\s+LIMIT 1/', $sql), 'per-product promotion query and precedence');
        $rows = array_filter($this->promotionRules, function ($row) use ($product, $term, $principal) {
            return in_array($row['jet_product_id'], ['*', $product[1]], true)
                && in_array($term[1], explode('_', $row['jet_product_meseci']), true)
                && (float)$principal[1] >= (float)$row['jet_product_price']
                && $row['jet_product_start'] <= '2026-06-01' && $row['jet_product_end'] >= '2026-06-01';
        });
        usort($rows, function ($left, $right) {
            return [$left['jet_product_id'] === '*', $left['id']] <=> [$right['jet_product_id'] === '*', $right['id']];
        });
        preg_match('/SELECT (.+?) FROM oc_jet_kop/s', preg_replace('/\s+/', ' ', $sql), $select);
        $columns = array_map('trim', explode(',', $select[1]));
        return $rows ? [array_intersect_key($rows[0], array_flip($columns))] : [];
    }

}
class CartFinancialCart extends Cart\Cart {public $clears=0;function clear(){++$this->clears;}}
class CartFinancialModel extends ModelExtensionMtJetCreditJetcredit {
    // The historical controller fixture passes a single product key.
    function getCartPromotion($product_ids, $term, $principal) {
        return parent::getCartPromotion((array)$product_ids, $term, $principal);
    }
}
class CartFinancialController extends ControllerExtensionMtJetCreditJetCredit {
    protected function cartModel(){return $this->model_extension_mt_jet_credit_jetcredit;}
    protected function submissionService(){return $this->registry->get('test_submission_service');}
    protected function submissionOwner(){return $this->session->getId();}
}
class CartFinancialResponse {public $output;function addHeader($v){}function setOutput($v){$this->output=$v;}}
class CartFinancialOrders {
    public $orders=[],$history=[];
    function addOrder($data){$this->orders[]=$data;return 99;}
    function getOrder($id){return ['order_id'=>$id];}
    function addOrderHistory(...$args){$this->history[]=$args;}
}
class CartFinancialCatalog {
    private $r;function __construct($r){$this->r=$r;}
    function getCategories($id){return [['category_id'=>1]];}
    function getCategory($id){return ['name'=>'Category'];}
    function getProduct($id){foreach($this->r->get('cart')->getProducts() as $p)if($p['product_id']==$id){$p['reward']/=$p['quantity'];return $p;}return false;}
    function getCustomer($id){return ['customer_group_id'=>1,'firstname'=>'Test','lastname'=>'User','email'=>'client@example.invalid','telephone'=>'123','fax'=>'','custom_field'=>'[]'];}
}
class CartFinancialLoader {
    function language($route,$key=""){return $this->native->language($route,$key);}
    private $r,$native;function __construct($r){$this->r=$r;$this->native=new Loader($r);}
    function model($route){
        if(in_array($route,['catalog/product','catalog/category','account/customer'],true))$this->r->set('model_'.str_replace('/','_',$route),new CartFinancialCatalog($this->r));
        else $this->native->model($route);
    }
}
function cartEnvironment($configure=null){
    [, $r]=environment();$memory=new SubmissionMemory;$r->set('test_submission_memory',$memory);$storage=new JetSubmissionStore(new SubmissionConnection($memory),'oc_');$storage->prepare('1');$r->set('test_submission_service',new JetSubmission($storage));$db=new CartFinancialDB;$r->set('db',$db);
    put($r->get('currency'),'currencies',['EUR'=>['currency_id'=>1,'value'=>1],'USD'=>['currency_id'=>2,'value'=>2]]);
    foreach(['cart','customer','tax','currency','weight'] as $k)put($r->get($k),'db',$db);
    $cart=blank('CartFinancialCart');foreach(get_object_vars($r->get('cart')) as $k=>$v)put($cart,$k,$v);
    foreach((new ReflectionClass('Cart\\Cart'))->getProperties() as $property){$property->setAccessible(true);$property->setValue($cart,$property->getValue($r->get('cart')));}
    $r->set('cart',$cart);
    foreach(['coupon','reward','voucher','shipping_method'] as $key)unset($r->get('session')->data[$key]);
    $r->get('session')->data['jet_cart_quotes']=[];
    foreach(['module_mt_jet_credit_status'=>1,'module_mt_jet_credit_cart_show'=>1,'module_mt_jet_credit_status_card'=>1,'module_mt_jet_credit_price'=>75,
        'module_mt_jet_credit_vnoski'=>12,'module_mt_jet_credit_purcent'=>1.2,'module_mt_jet_credit_purcent_card'=>0.8,'module_mt_jet_credit_count'=>1,
        'config_email'=>'admin@example.invalid','module_mt_jet_credit_mail'=>'bank@example.invalid','module_mt_jet_credit_id'=>'Shop',
        'payment_jet_order_status_id'=>1,'payment_jet_card_order_status_id'=>1] as $k=>$v)$r->get('config')->set($k,$v);
    $r->get('request')->server=['HTTPS'=>true,'REMOTE_ADDR'=>'127.0.0.1'];$r->get('request')->cookie=[];
    $r->set('response',new CartFinancialResponse);$r->set('model_checkout_order',new CartFinancialOrders);
    $r->set('model_extension_mt_jet_credit_jetcredit',new CartFinancialModel($r));
    $r->set('load',new CartFinancialLoader($r));
    if($configure)$configure($r);
    return [new CartFinancialController($r),$r];
}
function nativePasses($r){return count(array_filter($r->get('db')->queries,function($sql){return strpos($sql,'FROM oc_extension ')!==false;}));}
function cartPayload($m,$context,$down='100',$card=0,$term=12){
    return ['jet_priceall'=>$context['amount'],'jet_parva'=>$m->cartFixed((float)($down===''?0:$down)),'jet_downpayment_raw'=>$down,
        'jet_card'=>(string)$card,'jet_vnoski'=>(string)$term,'jet_products'=>$context['products_key'],'jet_products_qt'=>$context['products_qt'],
        'jet_products_pr'=>$context['products_pr'],'jet_products_vr'=>json_encode($context['options']),'jet_cart_rows'=>$context['cart_rows'],
        'jet_lname'=>'','jet_name'=>'Test','jet_lastname'=>'User','jet_egn'=>'9001010000','jet_phone'=>'+359888888888','jet_email'=>'client@example.invalid'];
}
function cartWire($r,$wire){$r->get('request')->post=(new OpenCartProductRequestDouble($wire))->post;}
function quoteCart($configure=null,$down='100',$card=0,$term=12){
    [$c,$r]=cartEnvironment($configure);$m=$r->get('model_extension_mt_jet_credit_jetcredit');$ctx=$m->getCartContext();check((bool)$ctx,'valid native Cart context: '.$m->getCartFailureCode());
    $wire=cartPayload($m,$ctx,$down,$card,$term);cartWire($r,$wire);$passes=nativePasses($r);$c->jet_calculate_cart();check(nativePasses($r)===$passes+1,'one native pipeline per calculation endpoint');$q=json_decode($r->get('response')->output,true);
    if(!isset($q['jet_quote_ref'])){
        try {$debug=internal($c,'cartCalculate',$m,$ctx,$r->get('request')->post);}catch(Throwable $e){$debug=$e->getMessage();}
        check(false,'legitimate native Cart quoted: '.json_encode([$q,$debug,$m->cartTransportMatches($ctx,$r->get('request')->post)]));
    }
    $wire['jet_quote_ref']=$q['jet_quote_ref'];$wire['jet_intent_id']=$q['jet_intent_id'];$wire['jet_parva']=$q['jet_parva'];
    foreach(['vnoska','total_credit_price','obshto','gpr','glp'] as $f)$wire['jet_'.$f]=$q['jet_'.$f.($card&&$f!=='total_credit_price'?'_card':'')];
    cartWire($r,$wire);return [$c,$r,$wire,$q];
}
function rejectCart($c,$r,$stale=null,$expectedPasses=1){
    CartFinancialMailer::$messages=[];$reservation=$r->get('test_submission_memory')->meta['next_reference'];$before=serialize($r->get('session')->data);$writes=count($r->get('db')->writes);
    $orders=count($r->get('model_checkout_order')->orders);$history=count($r->get('model_checkout_order')->history);$clear=$r->get('cart')->clears;
    $passes=nativePasses($r);$c->jet_send_cart();check(nativePasses($r)===$passes+$expectedPasses,'one native pipeline per authorized submission, none before owner rejection');$out=json_decode($r->get('response')->output,true);
    check(($out['success']??null)!=='success','rejected financial request');
    if($stale!==null)check(($out['jet_cart_stale']??false)===$stale,'confirmed stale classification');
     $after=$r->get('session')->data;$original=unserialize($before);
    if(!isset($after['voucher']))unset($original['voucher']);
    check(serialize($after)===serialize($original),'only native voucher cleanup allowed on rejection');
    check(count($r->get('db')->writes)===$writes,'zero counter/DB write');
    check($r->get('test_submission_memory')->meta['next_reference']===$reservation,'zero durable reference reservation after financial rejection');
    check(count($r->get('model_checkout_order')->orders)===$orders && count($r->get('model_checkout_order')->history)===$history,'zero order/history');
    check(!CartFinancialMailer::$messages && $r->get('cart')->clears===$clear,'zero emails/cart clear');
}

foreach([0,1] as $card){
    [$c,$r,$wire,$q]=quoteCart(null,'100',$card);
    check($q['jet_priceall']==='600.00' && $q['jet_total_credit_price']==='500.00' && $q['jet_vnoska']==='47.67' && $q['jet_vnoska_card']==='45.67','independent financing golden');
    $legacy=new CartLegacyController($r);CartFinancialMailer::$messages=[];$legacy->jet_send_cart();
    check((json_decode($r->get('response')->output,true)['success']??null)==='success','HEAD golden fixture completes durable submission');
    $oldOrders=$r->get('model_checkout_order')->orders;$oldMail=CartFinancialMailer::$messages;
    check(count($oldOrders)===1,'HEAD golden fixture creates exactly one mock order');
    [$c,$r,$wire,$q]=quoteCart(null,'100',$card);CartFinancialMailer::$messages=[];$passes=nativePasses($r);$c->jet_send_cart();check(nativePasses($r)===$passes+1,'one native pipeline on successful submission');
    check(json_decode($r->get('response')->output,true)['success']==='success','valid Jet/Card submission: '.$r->get('response')->output.' orders='.count($r->get('model_checkout_order')->orders).' writes='.count($r->get('db')->writes));
    check(count($r->get('model_checkout_order')->orders)===1,'valid Jet/Card submission creates exactly one mock order');
    check($r->get('model_checkout_order')->orders===$oldOrders,'native legacy order array byte/type golden');
    check(CartFinancialMailer::$messages===$oldMail && count($oldMail)===3,'bank/admin/client complete message goldens');
    check(strpos($oldMail[1]['body'],'ЕГН: **********;')!==false,'masking unchanged');
    check($r->get('cart')->clears===1 && count($r->get('model_checkout_order')->history)===1,'success history and cart lifecycle');
    foreach(['priceall','parva','vnoski','card','vnoska','total_credit_price','obshto','gpr','glp','products','products_qt','products_pr','cart_rows','products_vr'] as $field){
        foreach(['1x2',[],null,'NaN','1e999','-1'] as $bad){[$c,$r]=quoteCart(null,'100',$card);$r->get('request')->post['jet_'.$field]=$bad;rejectCart($c,$r,false);}
        [$c,$r]=quoteCart(null,'100',$card);unset($r->get('request')->post['jet_'.$field]);rejectCart($c,$r,false);
    }
}
foreach(['12.345'=>'12.35','1.005'=>'1.00','2.675'=>'2.67','0.125'=>'0.13',''=>'0.00'] as $raw=>$expected){[,,$wire,$q]=quoteCart(null,(string)$raw);check($q['jet_parva']===$expected,'canonical downpayment golden '.$raw);}
foreach(['-0.004','-1','600','599.999','NaN','INF','12x',[]] as $bad){[$c,$r]=cartEnvironment();$m=$r->get('model_extension_mt_jet_credit_jetcredit');$ctx=$m->getCartContext();$wire=cartPayload($m,$ctx);$wire['jet_downpayment_raw']=$bad;cartWire($r,$wire);$c->jet_calculate_cart();check(!isset(json_decode($r->get('response')->output,true)['jet_quote_ref']),'invalid downpayment cannot quote');}
[,,$wire,$q]=quoteCart(null,'540',0,3);check($q['jet_total_credit_price']==='60.00','Cart full-amount minimum, not Product principal minimum');
foreach([3,6,9,10,12,15,18,24,30,36] as $term){[,,$wire,$q]=quoteCart(function($r){$r->get('db')->price=300;},'0',0,$term);check($q['jet_vnoski']===$term,'allowed financing term '.$term);}
foreach([[400.99,15,false],[401,15,true],[600.99,30,false],[601,30,true]] as [$amount,$term,$allowed]){
    [$c,$r]=cartEnvironment(function($r)use($amount){$r->get('db')->price=$amount/2/1.2;});$m=$r->get('model_extension_mt_jet_credit_jetcredit');$ctx=$m->getCartContext();cartWire($r,cartPayload($m,$ctx,'0',0,$term));$c->jet_calculate_cart();check(isset(json_decode($r->get('response')->output,true)['jet_quote_ref'])===$allowed,'raw threshold '.$amount);
}
foreach([0,-1] as $percent){[$c,$r]=cartEnvironment(function($r)use($percent){$r->get('db')->promotion=['jet_product_percent'=>$percent,'jet_product_id'=>'*','id'=>1];});$m=$r->get('model_extension_mt_jet_credit_jetcredit');cartWire($r,cartPayload($m,$m->getCartContext(),'0'));$c->jet_calculate_cart();$q=json_decode($r->get('response')->output,true);check(isset($q['jet_quote_ref'])===($percent===0),'promo zero/prohibition enforced');if($percent===0)check($q['jet_vnoska']==='50.00','zero-rate golden');}
foreach(['module_mt_jet_credit_status','module_mt_jet_credit_cart_show','module_mt_jet_credit_status_card'] as $flag){[$c,$r,$wire]=quoteCart(null,'100',1);$r->get('config')->set($flag,0);rejectCart($c,$r,true);}
foreach(['quantity','price','option','shipping','tax','currency_rate','customer','session','promotion'] as $change){
    $prepare=$change==='option'?function($r){$r->get('db')->cartRows=[['cart_id'=>11,'product_id'=>1,'quantity'=>2,'option'=>'{"1":"10"}','recurring_id'=>0]];}:($change==='currency_rate'?function($r){$r->get('config')->set('config_currency','USD');}:null);
    [$c,$r]=quoteCart($prepare,'0');
    if($change==='quantity')$r->get('db')->cartRows=[['cart_id'=>11,'product_id'=>1,'quantity'=>3,'option'=>'{}','recurring_id'=>0]];
    if($change==='price')$r->get('db')->price=260;
    if($change==='option')$r->get('db')->cartRows[0]['option']='{"1":"12"}';
    if($change==='shipping')$r->get('session')->data['shipping_method']=['cost'=>10,'tax_class_id'=>1,'title'=>'Shipping'];
    if($change==='tax')put($r->get('tax'),'tax_rates',[1=>[1=>['tax_rate_id'=>1,'name'=>'VAT','rate'=>21,'type'=>'P','priority'=>1]]]);
    if($change==='currency_rate')put($r->get('currency'),'currencies',['EUR'=>['value'=>1],'USD'=>['value'=>4]]);
    if($change==='customer')put($r->get('customer'),'customer_id',5);
    if($change==='session')put($r->get('session'),'session_id','foreign-session');
    if($change==='promotion')$r->get('db')->promotion=['jet_product_percent'=>-1,'jet_product_id'=>'*','id'=>2];
    rejectCart($c,$r,$change!=='session',$change==='session'?0:1);
}
foreach(['coupon','voucher'] as $discount){[$c,$r]=quoteCart(function($r)use($discount){$r->get('session')->data[$discount]=$discount==='coupon'?'C10':'V75';},'0');if($discount==='coupon')$r->get('db')->coupon=false;else$r->get('db')->balance=0;rejectCart($c,$r,true);}
foreach(['expired','missing','foreign','malformed'] as $kind){[$c,$r,$wire,$q]=quoteCart();if($kind==='expired')$r->get('session')->data['jet_cart_quotes'][$q['jet_quote_ref']]['expires']=time()-1;elseif($kind==='missing')unset($r->get('request')->post['jet_quote_ref']);elseif($kind==='foreign')$r->get('request')->post['jet_quote_ref']=str_repeat('f',48);else$r->get('request')->post['jet_quote_ref']=[];rejectCart($c,$r,false);}

// Separate native row identities, repeated IDs, priced/non-priced options and real HTTP cleaning.
foreach(['select','radio','checkbox','text','textarea','file','date','datetime','time'] as $type){
    [$c,$r,$wire,$q]=quoteCart(function($r)use($type){$r->get('db')->optionKinds[1]=$type;$value=$type==='checkbox'?['10','11']:(in_array($type,['select','radio'])?'10':'стойност "O\'Reilly" &amp; <b>текст</b>');$r->get('db')->cartRows=[['cart_id'=>11,'product_id'=>1,'quantity'=>1,'option'=>json_encode([1=>$value]),'recurring_id'=>0],['cart_id'=>12,'product_id'=>1,'quantity'=>1,'option'=>'{}','recurring_id'=>0]];},'0');
    check($wire['jet_products']==='1_1' && $wire['jet_cart_rows']==='11_12','duplicate product IDs not merged');
    $doc=new DOMDocument();@$doc->loadHTML('<meta charset="utf-8"><input id="options" value="'.htmlspecialchars($wire['jet_products_vr'],ENT_QUOTES,'UTF-8').'">');
    $wire['jet_products_vr']=$doc->getElementsByTagName('input')->item(0)->getAttribute('value');cartWire($r,$wire);
    CartFinancialMailer::$messages=[];$c->jet_send_cart();check(json_decode($r->get('response')->output,true)['success']==='success','HTML attribute / DOM / FormData value / Request::clean valid '.$type);
    check(count($r->get('model_checkout_order')->orders[0]['products'])===2,'variant rows retained in order');
}
[$c,$r,$wire,$q]=quoteCart();$other=$wire;unset($other['jet_quote_ref']);cartWire($r,$other);$c->jet_calculate_cart();$second=json_decode($r->get('response')->output,true);
check($second['jet_quote_ref']!==$q['jet_quote_ref'] && isset($r->get('session')->data['jet_cart_quotes'][$q['jet_quote_ref']]['quote']),'independent tabs do not evict each other');
$other['jet_previous_quote']=$q['jet_quote_ref'];$other['jet_cart_lineage']=$q['jet_cart_lineage'];cartWire($r,$other);$c->jet_calculate_cart();$replacement=json_decode($r->get('response')->output,true);
check(isset($replacement['jet_quote_ref']) && !$r->get('model_extension_mt_jet_credit_jetcredit')->findCartQuote($q['jet_quote_ref']) && isset($r->get('session')->data['jet_cart_quotes'][$second['jet_quote_ref']]['quote']),'explicit own-lineage replacement');
cartWire($r,$other);$c->jet_calculate_cart();check(isset(json_decode($r->get('response')->output,true)['jet_quote_ref']),'concurrent calculation recognizes its replaced predecessor');
$other['jet_previous_quote']=$second['jet_quote_ref'];cartWire($r,$other);$c->jet_calculate_cart();check(!isset(json_decode($r->get('response')->output,true)['jet_quote_ref']),'foreign lineage cannot replace another tab');
check(isset($r->get('session')->data['jet_product_quotes']['product']),'Product quote namespace untouched');
// Capacity is bounded without evicting a different live tab.
[$c,$r,$wire,$q]=quoteCart();$model=$r->get('model_extension_mt_jet_credit_jetcredit');
for($i=1;$i<64;$i++)check($model->storeCartQuote([],[],[],[])!==false,'independent quote capacity '.$i);
$before=serialize($r->get('session')->data);
check($model->storeCartQuote([],[],[],[])===false && serialize($r->get('session')->data)===$before,'capacity rejection leaves every live quote intact');
$replace=['jet_previous_quote'=>$q['jet_quote_ref'],'jet_cart_lineage'=>$q['jet_cart_lineage']];
for($i=0;$i<140;$i++){
    $next=$model->storeCartQuote([],[],[],$replace);check($next!==false,'own replacement at full active capacity');
    $replace=['jet_previous_quote'=>$next['jet_quote_ref'],'jet_cart_lineage'=>$next['jet_cart_lineage']];
    check(count($r->get('session')->data['jet_cart_quotes'])<=128,'tombstones bounded');
}
// Custom totals are authoritative at both points; changed totals never silently submit.
foreach ([0,1] as $card) {
 [$c,$r]=quoteCart(function($r){
  $r->get('db')->extensionOrder=['total','voucher','tax','reward','coupon','shipping','sub_total','merchant'];
  $r->get('config')->set('total_merchant_status',1);$r->get('config')->set('total_merchant_sort_order',99);
  $GLOBALS['merchant_amount']=600.0;$GLOBALS['merchant_calls']=0;unset($GLOBALS['merchant_hook']);
 },'100',$card);
 $GLOBALS['merchant_amount']=601.0;$calls=$GLOBALS['merchant_calls'];rejectCart($c,$r,true);
 check($GLOBALS['merchant_calls']===$calls+1,'custom extension executes once at submission');
}
// An otherwise identical promotion selected from a different rule is still stale.
[$c,$r]=quoteCart(function($r){$r->get('db')->promotion=['jet_product_percent'=>1.2,'jet_product_id'=>'*','id'=>1];});
$r->get('db')->promotion['id']=2;rejectCart($c,$r,true);
// Re-entry through the endpoint cannot reach any JetCredit business action.
[$c,$r]=quoteCart(function($r){
 $r->get('db')->extensionOrder=['total','merchant'];$r->get('config')->set('total_merchant_status',1);$r->get('config')->set('total_merchant_sort_order',99);
 $GLOBALS['merchant_amount']=600.0;unset($GLOBALS['merchant_hook']);
});
$GLOBALS['merchant_hook']=function($registry){
 $result=(new ModelExtensionMtJetCreditJetcredit($registry))->evaluateCartNativeTotals();
 check(!$result['success'],'nested financial evaluation rejected');
};
rejectCart($c,$r,false);unset($GLOBALS['merchant_hook']);
// FIX01: the public API remains override-compatible; evidence is explicit per call.
$method=new ReflectionMethod('ModelExtensionMtJetCreditJetcredit','getPromo');
check($method->getNumberOfParameters()===3 && $method->getNumberOfRequiredParameters()===3,'original getPromo arity restored');
foreach($method->getParameters() as $parameter)check(!$parameter->isPassedByReference(),'getPromo has no reference parameters');
[$c,$r]=cartEnvironment();$model=$r->get('model_extension_mt_jet_credit_jetcredit');
foreach([null,0,-1,1.2] as $percent){
 $r->get('db')->promotion=$percent===null?null:['jet_product_percent'=>$percent,'jet_product_id'=>'*','id'=>1];
 $expected=['jet_show_button'=>$percent!==-1,'jet_purcent'=>($percent===null||$percent===-1)?1.2:(float)$percent,'jet_purcent_card'=>($percent===null||$percent===-1)?0.8:(float)$percent];
 check($model->getPromo('1',12,500)===$expected,'unchanged public promotion return contract '.var_export($percent,true));
 $passes=nativePasses($r);$queries=count($r->get('db')->queries);
 $result=$model->getCartPromotion(['1'],12,500);
 check($result['promotion']===$expected,'Cart uses same promotion selection');
 check(count($r->get('db')->queries)===$queries+1 && nativePasses($r)===$passes,'Cart evidence uses one promotion query, no totals pass');
}
$r->get('db')->promotion=['jet_product_percent'=>1.2,'jet_product_id'=>'*','id'=>11];
$first=$model->getCartPromotion(['1'],12,500);
$r->get('db')->promotion['id']=12;$second=$model->getCartPromotion(['1'],12,500);
check($first['fingerprint']!==$second['fingerprint'],'same-rate different-rule evidence changes fingerprint');
$r->get('db')->promotion['id']=11;
check($model->getCartPromotion(['1'],12,500)===$first,'interleaved calls do not retain other quote evidence');
function basketRule($product, $rate, $id = 1, $terms = '12', $minimum = 0, $end = '2026-12-31') {
    return ['jet_product_id' => $product, 'jet_product_percent' => $rate, 'id' => $id,
        'jet_product_meseci' => $terms, 'jet_product_price' => $minimum,
        'jet_product_start' => '2026-01-01', 'jet_product_end' => $end];
}
function basketCart($ids, $rules) {
    return function ($r) use ($ids, $rules) {
        $db = $r->get('db');
        $db->promotionRules = $rules;
        $db->cartRows = [];
        foreach ($ids as $index => $id) {
            $db->cartRows[] = ['cart_id' => 11 + $index, 'product_id' => $id,
                'quantity' => 2, 'option' => '{}', 'recurring_id' => 0];
        }
    };
}
function basketCheckoutFixture($r, $context) {
    $r->set('load', new class($r->get('load')) {
        private $native;
        function __construct($native) { $this->native = $native; }
        function model($route) {
            if (!in_array($route, ['checkout/order', 'extension/payment/jet', 'extension/payment/jet_card',
                'extension/mt_jet_credit/jetcredit'], true)) $this->native->model($route);
        }
    });
    $r->set('model_checkout_order', new class($context) {
        public $code;
        private $context;
        function __construct($context) { $this->context = $context; }
        function getOrder($id) {
            return ['order_id' => $id, 'store_id' => 0, 'customer_id' => 0,
                'email' => 'client@example.invalid', 'total' => $this->context['amount'],
                'currency_value' => 1, 'currency_code' => 'EUR', 'payment_code' => $this->code];
        }
        function getOrderProducts($id) {
            $rows = [];
            foreach ($this->context['products'] as $index => $product) {
                $rows[] = $product + ['order_product_id' => $index + 1, 'tax' => 0];
            }
            return $rows;
        }
        function getOrderOptions($id, $line) { return []; }
    });
    $payment = new class { function getMethod($address, $total, $amount) { return true; } };
    $r->set('model_extension_payment_jet', $payment);
    $r->set('model_extension_payment_jet_card', $payment);
    $session = $r->get('session');
    unset($session->data['customer_id']);
    $session->data['order_id'] = 1;
    $session->data['guest'] = ['email' => 'client@example.invalid'];
}
function basketParity($ids, $rules, $recalculations) {
    [$c, $r] = cartEnvironment(basketCart($ids, $rules));
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    $context = $model->getCartContext();
    check($context['product_ids'] === $ids, 'Cart keeps native product IDs in row order');
    $cartQuotes = [];
    foreach ([0, 1] as $card) {
        $previous = [];
        foreach ($recalculations as [$down, $term, $rates]) {
            cartWire($r, cartPayload($model, $context, $down, $card, $term) + $previous);
            $c->jet_calculate_cart();
            $quote = json_decode($r->get('response')->output, true);
            check(($quote['success'] === 'success') === ($rates !== null), 'Cart promotion availability for method ' . json_encode([$card, $down, $term, $ids]));
            $cartQuotes[$card][] = $quote;
            if (isset($quote['jet_quote_ref'])) {
                $previous = ['jet_previous_quote' => $quote['jet_quote_ref'],
                    'jet_cart_lineage' => $quote['jet_cart_lineage'], 'jet_intent_id' => $quote['jet_intent_id']];
            }
        }
    }
    basketCheckoutFixture($r, $context);
    foreach (['jet', 'jet_card'] as $card => $code) {
        $r->get('session')->data['payment_method'] = ['code' => $code];
        $r->get('model_checkout_order')->code = $code;
        $snapshot = $model->getCheckoutSnapshot($code);
        check($snapshot && array_map('strval', $snapshot['product_ids']) === $ids, 'Checkout keeps native product IDs in row order');
        foreach ($recalculations as $index => [$down, $term, $rates]) {
            cartWire($r, ['jet_checkout_context' => $code, 'jet_priceall' => $context['amount'],
                'jet_parva' => $down, 'jet_vnoski' => (string)$term]);
            $c->jet_calculate_cart();
            $quote = json_decode($r->get('response')->output, true);
            check(($quote['success'] === 'success') === ($rates !== null), 'Checkout promotion availability for ' . $code);
            if ($rates === null) continue;
            $principal = (float)$context['amount'] - (float)$down;
            foreach (['', '_card'] as $method => $suffix) {
                $expected = number_format($principal / $term * (1 + $term * $rates[$method] / 100), 2, '.', '');
                $field = 'jet_vnoska' . $suffix;
                check($quote[$field] === $expected && $cartQuotes[$card][$index][$field] === $expected,
                    'Cart and Checkout installment matches expected rate for ' . $code . $suffix);
            }
        }
    }
}
$a = basketRule('1', '0.50');
$b = basketRule('2', '0.99', 2);
$excluded = basketRule('1', '-1');
foreach ([
    [['1'], [], [1.20, 0.80]],
    [['1'], [$a], [0.50, 0.50]],
    [['1'], [$excluded], null],
    [['1', '2'], [$a, basketRule('2', '0.5', 2)], [0.50, 0.50]],
    [['1', '2'], [$a, $b], [0.99, 0.99]],
    [['1', '2', '3'], [basketRule('1', '0.00'), basketRule('2', '0.50', 2), basketRule('3', '1.20', 3)], [1.20, 1.20]],
    [['1', '2'], [$a], [1.20, 0.80]],
    [['1', '2'], [], [1.20, 0.80]],
    [['1', '2'], [$excluded, $b], null],
    [['1'], [basketRule('*', '-1', 1), basketRule('1', '0.50', 2)], [0.50, 0.50]],
    [['1', '2'], [basketRule('*', '-1', 1), basketRule('1', '0.50', 2)], null],
    [['1'], [basketRule('1', '0.50', 1), basketRule('1', '-1', 2)], [0.50, 0.50]],
    [['2', '1'], [$excluded], null],
    [['1', '2'], [basketRule('*', '0.25', 1), basketRule('1', '0.50', 2), basketRule('1', '0.99', 3)], [0.50, 0.50]],
    [['1', '2'], [basketRule('*', '0.25', 1), basketRule('1', '0.99', 2, '12', 0, '2026-05-31')], [0.25, 0.25]],
    [['1', '2', '1'], [$a, $b], [0.99, 0.99]],
] as [$ids, $rules, $rates]) {
    basketParity($ids, $rules, [['100', 12, $rates]]);
}
basketParity(['1', '2'], [basketRule('1', '0.50', 1, '12', 1100), $b], [
    ['100', 12, [0.99, 0.99]],
    ['101', 12, [1.20, 0.80]],
    ['100', 9, [1.20, 0.80]],
    ['100', 12, [0.99, 0.99]],
]);

// An edit to the non-winning rule preserves the result but invalidates the quote.
[$c, $r] = quoteCart(basketCart(['1', '2'], [$a, $b]));
$model = $r->get('model_extension_mt_jet_credit_jetcredit');
$before = $model->getCartPromotion(['1', '2'], 12, 1100);
$r->get('db')->promotionRules[0]['jet_product_price'] = 100;
$after = $model->getCartPromotion(['1', '2'], 12, 1100);
check($before['promotion'] === $after['promotion'] && $before['fingerprint'] !== $after['fingerprint'],
    'same-rate in-place rule edit changes basket promotion evidence');
rejectCart($c, $r, true);

// The exact requested term is either offered or excluded. No alternative is selected.
foreach ([0, 1] as $card) {
    [$c, $r] = cartEnvironment(basketCart(['1', '2'], [basketRule('1', '-1'), basketRule('2', '0.50')]));
    $model = $r->get('model_extension_mt_jet_credit_jetcredit');
    foreach ([12, 9] as $term) {
        $wire = cartPayload($model, $model->getCartContext(), '100', $card, $term);
        $passes = nativePasses($r);
        cartWire($r, $wire); $c->jet_calculate_cart();
        $result = json_decode($r->get('response')->output, true);
        check(nativePasses($r) === $passes + 1, 'one native totals pass');
        check(($result['jet_promotion_excluded'] ?? false) === ($term === 12), 'one excluded product vetoes only the requested term');
        check(($result['jet_vnoski'] ?? null) === ($term === 12 ? null : $term), 'no automatic term replacement');
        check(isset($result['jet_quote_ref']) === ($term === 9), 'no quote persisted for an exclusion');
    }
}
// Replay after actual native Cart financial validation skips the totals pipeline entirely.
[$c,$r]=quoteCart();CartFinancialMailer::$messages=[];$c->jet_send_cart();$result=$r->get('response')->output;
$passes=nativePasses($r);$effects=[count($r->get('model_checkout_order')->orders),count($r->get('model_checkout_order')->history),$r->get('cart')->clears,count(CartFinancialMailer::$messages),$r->get('test_submission_memory')->meta['next_reference']];
$c->jet_send_cart();check($r->get('response')->output===$result&&nativePasses($r)===$passes,'accepted replay returns prior result without a new native Cart evaluation');
check($effects===[count($r->get('model_checkout_order')->orders),count($r->get('model_checkout_order')->history),$r->get('cart')->clears,count(CartFinancialMailer::$messages),$r->get('test_submission_memory')->meta['next_reference']],'accepted Cart replay has zero business effects');
echo 'PASS '.($checks-$cartStart)." Cart financial/transport/order/email security checks; native evaluator with SQL/order/mail doubles\n";
