<?php
// Real Checkout endpoints and shared durable service; existing SQL/order/mail doubles only.
$durableCheckoutTests = function () {
global $count;
$startChecks=$count;
function checkoutEnvironment($code='jet',$orderId=11,$memory=null){
    $c=setup($code==='jet'?'NewJet':'NewCard');
    if($memory!==null){$c->testSubmissionMemory=$memory;$c->testSubmissionConnection=new SubmissionConnection($memory);$c->testSubmissionService=new JetSubmission(new JetSubmissionStore($c->testSubmissionConnection,'oc_'));}
    $c->session->data['order_id']=$orderId;$c->session->data['payment_method']['code']=$code;$c->session->data['guest']=['email'=>'test@example.invalid'];$c->model_checkout_order->code=$code;
    $old=setup('OldCalc');$old->jet_calculate_cart();$q=json_decode($old->response->output,true);$post=[];
    foreach(['priceall','vnoska','total_credit_price','obshto','gpr','glp'] as $f)$post[$code.'_'.$f]=$q['jet_'.$f.($code==='jet_card'&&in_array($f,['vnoska','obshto','gpr','glp'])?'_card':'')];
    $post[$code.'_parva']='100';$post[$code.'_vnoski']='12';$post[$code.'_egn']='1234567890';$post[$code.'_products']='1';$post[$code.'_products_qt']='2';$post[$code.'_products_pr']='300.00';$post[$code.'_products_vr']='red';$c->request->post=$post;
    SubmissionTestMailer::$messages=SubmissionTestMailer::$attempts=SubmissionTestMailer::$failures=[];Trace::$events=[];
    return $c;
}
function checkoutSend($c){$c->confirm();return json_decode($c->response->output,true);}
function checkoutRow($c){return $c->testSubmissionService->resolveCheckout(0,$c->testOwner,$c->session->data['order_id']);}
function checkoutEffects($c){return [count(SubmissionTestMailer::$attempts),$c->testSubmissionMemory->meta['next_reference'],array_values(array_filter(Trace::$events,function($event){return $event!=='read_order';}))];}
function checkoutFreshConnection($c){$c->testSubmissionConnection=new SubmissionConnection($c->testSubmissionMemory);$c->testSubmissionService=new JetSubmission(new JetSubmissionStore($c->testSubmissionConnection,'oc_'));}
class CheckoutHookOrders extends Orders {
    public $hook;
    function addOrderHistory(...$args){if($this->hook){$hook=$this->hook;$this->hook=null;$hook();}return parent::addOrderHistory(...$args);}
}
foreach(['jet','jet_card'] as $code){
    $c=checkoutEnvironment($code);$out=checkoutSend($c);$row=checkoutRow($c);
    check($out['success']==='success'&&$out['code']==='bank_sent'&&$out['order_id']===11,'first valid '.$code.' succeeds');
    check($row['family']==='checkout'&&$row['checkout_order_id']==='11'&&$row['native_order_id']==='11'&&$row['channel']===$code,'native Checkout identity fixed at claim');
    check($row['reference_no']==='1'&&$c->testSubmissionMemory->meta['next_reference']==='2','shared N to N+1 reference');
    check(!$c->model_checkout_order->orders&&count($c->model_checkout_order->histories)===1&&!$c->cart->cleared,'no native Checkout order creation or Cart clear');
    check(SubmissionTestMailer::$attempts===['bank','admin','customer'],'independent existing mail sequence');
    check(!array_intersect(['execution_token','payload_digest','claim_digest','owner_digest'],array_keys($out)),'safe customer result');
    $effects=checkoutEffects($c);check(checkoutSend($c)===$out&&checkoutEffects($c)===$effects,'identical sequential replay returns recorded result without effects');
    // New controller and independent session snapshot model another tab/lost response retry.
    $tab=checkoutEnvironment($code,11,$c->testSubmissionMemory);SubmissionTestMailer::$attempts=['bank','admin','customer'];Trace::$events=[];$before=checkoutEffects($tab);
    check(checkoutSend($tab)===$out&&checkoutEffects($tab)===$before&&!$tab->model_checkout_order->histories,'authenticated second-tab/lost-response replay has no execution');
    // A different native order with identical goods/customer gets a different intent/reference.
    $different=checkoutEnvironment($code,12,$c->testSubmissionMemory);check(checkoutSend($different)['success']==='success','legitimate different native order submits');
    check(checkoutRow($different)['intent_id']!==$row['intent_id']&&checkoutRow($different)['reference_no']==='2','distinct order identity and distinct shared number');
    foreach(['amount','term','downpayment','installment','selected-method','order-method','currency-rate'] as $attack){
        $c=checkoutEnvironment($code);$before=checkoutEffects($c);
        if($attack==='amount')$c->request->post[$code.'_priceall']='1';
        if($attack==='term')$c->request->post[$code.'_vnoski']='999';
        if($attack==='downpayment')$c->request->post[$code.'_parva']='600';
        if($attack==='installment')$c->request->post[$code.'_vnoska']='1';
        if($attack==='selected-method')$c->session->data['payment_method']['code']='cod';
        if($attack==='order-method')$c->model_checkout_order->code='cod';
        if($attack==='currency-rate')$c->model_checkout_order->coefficient=2;
        check(checkoutSend($c)['success']==='unsuccess'&&checkoutEffects($c)===$before&&!$c->testSubmissionMemory->ledger,'financial rejection before intent claim/reservation: '.$attack);
    }
    foreach(['foreign-store','foreign-customer','guest-email','missing-session-order','forged-native-order','foreign-ledger-owner'] as $attack){
        $c=checkoutEnvironment($code);$before=checkoutEffects($c);
        if($attack==='foreign-store')$c->model_checkout_order->snapshot['store_id']=1;
        if($attack==='foreign-customer')$c->model_checkout_order->snapshot['customer_id']=99;
        if($attack==='guest-email')$c->session->data['guest']['email']='foreign@example.invalid';
        if($attack==='missing-session-order')unset($c->session->data['order_id']);
        if($attack==='forged-native-order')$c->model_checkout_order->snapshot['order_id']=99;
        if($attack==='foreign-ledger-owner')$c->testSubmissionService->createIntent(0,'foreign','checkout','checkout',11);
        check(checkoutSend($c)['success']==='unsuccess'&&checkoutEffects($c)===$before,'ownership rejection without effects: '.$attack);
    }
    $c=checkoutEnvironment($code);$c->request->post['order_id']='99';$c->request->post['jet_intent_id']=str_repeat('f',48);$out=checkoutSend($c);
    check($out['success']==='success'&&$out['order_id']===11&&checkoutRow($c)['checkout_order_id']==='11','browser order/intent fields never determine authoritative identity');
    // A saved receipt must still verify current native/session ownership and payload.
    foreach(['owner','email','amount','store','session-order'] as $change){
        $c=checkoutEnvironment($code);checkoutSend($c);$before=checkoutEffects($c);
        if($change==='owner')$c->testOwner='foreign';
        if($change==='email')$c->session->data['guest']['email']='foreign@example.invalid';
        if($change==='amount')$c->request->post[$code.'_priceall']='1';
        if($change==='store')$c->config->values['config_store_id']=1;
        if($change==='session-order')unset($c->session->data['order_id']);
        check(checkoutSend($c)['success']==='unsuccess'&&checkoutEffects($c)===$before,'replay retains ownership/payload checks: '.$change);
    }
    // Two independently loaded sessions contend for an already existing draft intent.
    $c=checkoutEnvironment($code);$draft=$c->testSubmissionService->createIntent(0,$c->testOwner,'checkout','checkout',11);
    $tab=checkoutEnvironment($code,11,$c->testSubmissionMemory);$nested=null;
    $c->testSubmissionMemory->hook=function()use($tab,&$nested){$nested=checkoutSend($tab);};
    $out=checkoutSend($c);check($out['success']==='success'&&($nested['jet_submission_review']??false),'simulated concurrent claim has one executor');
    check(count(SubmissionTestMailer::$attempts)===3&&$c->testSubmissionMemory->meta['next_reference']==='2'&&count($c->model_checkout_order->histories)===1&&!$tab->model_checkout_order->histories,'overlap cannot duplicate mail, counter or module history');
    // The initial create race is also serialized by the shared singleton/unique order key.
    $c=checkoutEnvironment($code);$tab=checkoutEnvironment($code,11,$c->testSubmissionMemory);$nested=null;
    $c->testSubmissionMemory->hook=function()use($tab,&$nested){$nested=checkoutSend($tab);};
    check(checkoutSend($c)['success']==='success'&&($nested['jet_submission_review']??false)&&count($c->testSubmissionMemory->ledger)===1,'simulated simultaneous initial creation yields one Checkout intent');
    foreach(['false','throw','manual','secondary','history'] as $failure){
        $c=checkoutEnvironment($code);
        if($failure==='manual')$c->config->values['module_mt_jet_credit_mail']='';
        if($failure==='false'||$failure==='throw')SubmissionTestMailer::$failures=['bank'=>$failure];
        if($failure==='secondary')SubmissionTestMailer::$failures=['admin'=>'throw','customer'=>'false'];
        if($failure==='history')$c->model_checkout_order->failure='history';
        $out=checkoutSend($c);$before=checkoutEffects($c);$again=checkoutSend($c);$row=checkoutRow($c);
        check(checkoutEffects($c)===$before,'replay never repeats effects after '.$failure);
        check($row['reference_no']==='1'&&$c->testSubmissionMemory->meta['next_reference']==='2','failure never returns/reuses reserved number');
        if($failure==='throw')check($out['code']==='bank_uncertain'&&$row['state']==='uncertain'&&($again['jet_submission_review']??false),'bank timeout/unknown result blocks all automatic resend');
        else check($out===$again&&$row['state']==='completed','known primary result retained with '.$failure);
        if($failure==='secondary'||$failure==='history')check($out['success']==='success'&&$out['code']==='bank_sent','later notification/history failure does not reverse bank success');
        if($failure==='manual')check($out['code']==='manual_registered'&&SubmissionTestMailer::$attempts===['admin','customer'],'manual registration keeps independent secondary delivery');
        if($failure==='false')check($out['code']==='bank_failed'&&!$c->model_checkout_order->histories,'known bank failure has no success history');
    }
    // Lost acknowledgement at claim and every irreversible boundary; no real DB involved.
    foreach([1,2,3,4,5,6,7,8,9,10] as $commitNumber){
        $c=checkoutEnvironment($code);$c->testSubmissionService->createIntent(0,$c->testOwner,'checkout','checkout',11);$c->testSubmissionConnection->commitCountdown=$commitNumber;
        $out=checkoutSend($c);check(($out['jet_submission_review']??false),'unknown COMMIT returns review at boundary '.$commitNumber);
        $expectedAttempts=$commitNumber<=2?0:($commitNumber<=4?1:($commitNumber<=6?2:3));
        check(count(SubmissionTestMailer::$attempts)===$expectedAttempts,'no unacknowledged action start executes');
        $before=checkoutEffects($c);checkoutFreshConnection($c);$again=checkoutSend($c);check(checkoutEffects($c)===$before,'unknown commit never grants a second executor');
        check($c->testSubmissionMemory->meta['next_reference']==='2','unknown COMMIT reserves one reference');
        if($commitNumber===10)check($again['success']==='success','lost completion acknowledgement recovers recorded primary result');
    }
    $c=checkoutEnvironment($code);$c->testSubmissionConnection->commitCountdown=1;$out=checkoutSend($c);
    check(($out['jet_submission_review']??false)&&!SubmissionTestMailer::$attempts&&$c->testSubmissionMemory->meta['next_reference']==='1','unknown intent creation commit has no reservation or mail');
    checkoutFreshConnection($c);check(checkoutSend($c)['success']==='success'&&count(SubmissionTestMailer::$attempts)===3,'explicit retry of verified unclaimed draft can execute once');
    $c=checkoutEnvironment($code);$before=checkoutEffects($c);$c->testSubmissionService=new JetSubmission(new JetSubmissionStore(new SubmissionConnection(new SubmissionMemory),'oc_'));
    check((checkoutSend($c)['jet_submission_review']??false)&&checkoutEffects($c)===$before,'unprepared storage has no old-counter fallback');
    $c=checkoutEnvironment($code);$c->customer=new class {function isLogged(){return true;}function getId(){return 7;}};$c->model_checkout_order->snapshot['customer_id']=7;$c->model_extension_mt_jet_credit_jetcredit->customer=$c->customer;
    check(checkoutSend($c)['success']==='success','verified authenticated customer may submit own order');
    // Reentrant native history hooks cannot re-execute this module's history action.
    $c=checkoutEnvironment($code);$tab=checkoutEnvironment($code,11,$c->testSubmissionMemory);$orders=new CheckoutHookOrders;$orders->code=$code;$orders->total=600;$c->model_checkout_order=$orders;$c->model_extension_mt_jet_credit_jetcredit->model_checkout_order=$orders;$nested=null;
    $orders->hook=function()use($tab,&$nested){$nested=checkoutSend($tab);};check(checkoutSend($c)['success']==='success'&&($nested['jet_submission_review']??false)&&count($orders->histories)===1,'module-local history start prevents reentrant duplicate history');
}
// Jet/Card changes share the order key both before and after first claim.
foreach([['jet','jet_card'],['jet_card','jet']] as $switch){
    [$old,$new]=$switch;$c=checkoutEnvironment($old);$draft=$c->testSubmissionService->createIntent(0,$c->testOwner,'checkout','checkout',11);
    $changed=checkoutEnvironment($new,11,$c->testSubmissionMemory);check(checkoutSend($changed)['success']==='success'&&checkoutRow($changed)['intent_id']===$draft['intent_id']&&checkoutRow($changed)['channel']===$new,'method before claim follows current validated choice using same intent');
    $opposite=checkoutEnvironment($old,11,$c->testSubmissionMemory);$before=checkoutEffects($opposite);check((checkoutSend($opposite)['jet_submission_review']??false)&&checkoutEffects($opposite)===$before,'method switch after claim never makes second application');
    $changed->session->data['payment_method']['code']=$old;$changed->model_checkout_order->code=$old;$before=checkoutEffects($changed);
    check(checkoutSend($changed)['success']==='success'&&checkoutEffects($changed)===$before,'original authenticated receipt survives later method changes without re-execution');
}
// All four channels reserve from the same ledger/counter, not config or channel buckets.
$memory=new SubmissionMemory;$storage=new JetSubmissionStore(new SubmissionConnection($memory),'oc_');$storage->prepare('40');$references=[];
foreach(['product','cart','jet','jet_card'] as $family){
    if($family==='jet'||$family==='jet_card'){$c=checkoutEnvironment($family,$family==='jet'?21:22,$memory);$out=checkoutSend($c);$row=checkoutRow($c);}
    else{
        $c=setup($family==='cart'?'NewCartCalc':'NewCalc');$c->testSubmissionService=new JetSubmission(new JetSubmissionStore(new SubmissionConnection($memory),'oc_'));
        if($family==='cart'){$model=new CartMailPromo;foreach(get_object_vars($c->model_extension_mt_jet_credit_jetcredit) as $k=>$v)$model->$k=$v;$c->model_extension_mt_jet_credit_jetcredit=$model;}
        $c->request->post['jet_options']='[]';$c->request->post['jet_cart_rows']='11';$c->request->post['jet_products_vr']='[]';$fn=$family==='cart'?'jet_calculate_cart':'jet_calculate';$c->$fn();$q=json_decode($c->response->output,true);
        foreach(['priceall','parva','vnoski','quote_ref','intent_id','vnoska','total_credit_price','obshto','gpr','glp'] as $f)$c->request->post['jet_'.$f]=$q['jet_'.$f];
        $fn=$family==='cart'?'jet_send_cart':'jet_send';$c->$fn();$out=json_decode($c->response->output,true);$row=$c->testSubmissionService->resolve($q['jet_intent_id'],0,$c->testOwner);
    }
    check($out['success']==='success','shared counter successful '.$family);$references[]=$row['reference_no'];
    check(!array_filter($c->db->queries,function($sql){return strpos($sql,'UPDATE oc_setting')!==false;}),'no legacy setting UPDATE for '.$family);
}
check($references===['40','41','42','43']&&$memory->meta['next_reference']==='44','four channel counter reservations are globally sequential');
echo 'PASS '.($count-$startChecks)." Checkout durable identity/replay/counter checks; simulated contention and mocked mail/history only\n";
};
require __DIR__.'/submission-emails.php';
