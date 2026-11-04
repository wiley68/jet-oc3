<?php
// Real endpoints/service with existing SQL/order/mail doubles. Never contacts a store.
$durableProductCartTests = function () {
global $count;
$startChecks=$count;
function durableEnvironment($family,$card=0,$memory=null){
    $c=setup($family==='cart'?'NewCartCalc':'NewCalc');
    if($memory!==null){$c->testSubmissionMemory=$memory;$c->testSubmissionConnection=new SubmissionConnection($memory);$c->testSubmissionService=new JetSubmission(new JetSubmissionStore($c->testSubmissionConnection,'oc_'));}
    if($family==='cart'){$model=new CartMailPromo;foreach(get_object_vars($c->model_extension_mt_jet_credit_jetcredit) as $k=>$v)$model->$k=$v;$c->model_extension_mt_jet_credit_jetcredit=$model;}
    $c->request->post['jet_options']='[]';$c->request->post['jet_cart_rows']='11';$c->request->post['jet_products_vr']='[]';$c->request->post['jet_card']=(string)$card;
    $fn=$family==='cart'?'jet_calculate_cart':'jet_calculate';$c->$fn();$q=json_decode($c->response->output,true);
    check(isset($q['jet_intent_id'],$q['jet_quote_ref']),'valid financially bound intent and quote');
    foreach(['priceall','parva','vnoski','quote_ref','intent_id'] as $f)$c->request->post['jet_'.$f]=$q['jet_'.$f];
    foreach(['vnoska','total_credit_price','obshto','gpr','glp'] as $f)$c->request->post['jet_'.$f]=$q['jet_'.$f.($card&&$f!=='total_credit_price'?'_card':'')];
    $c->testFamily=$family;$c->testQuote=$q;
    SubmissionTestMailer::$messages=SubmissionTestMailer::$attempts=SubmissionTestMailer::$failures=[];Trace::$events=[];
    return $c;
}
function durableSend($c){$fn=$c->testFamily==='cart'?'jet_send_cart':'jet_send';$c->$fn();return json_decode($c->response->output,true);}
function durableRow($c){return $c->testSubmissionService->resolve($c->request->post['jet_intent_id'],0,$c->testOwner);}
function durableEffects($c){return [count($c->model_checkout_order->orders),count($c->model_checkout_order->histories),count(SubmissionTestMailer::$attempts),$c->cart->cleared,$c->testSubmissionMemory->meta['next_reference'],Trace::$events];}
class DurableHookOrders extends Orders {
    public $hook;
    function addOrder($data){if($this->hook){$hook=$this->hook;$this->hook=null;$hook();}return parent::addOrder($data);}
}
foreach(['product','cart'] as $family)foreach([0,1] as $card){
    $c=durableEnvironment($family,$card);$id=$c->request->post['jet_intent_id'];$memory=$c->testSubmissionMemory;
    check(durableRow($c)['state']==='draft'&&$memory->meta['next_reference']==='1','calculation creates no reference reservation');
    $firstQuote=$c->testQuote;$c->request->post['jet_previous_quote']=$firstQuote['jet_quote_ref'];
    if($family==='cart')$c->request->post['jet_cart_lineage']=$firstQuote['jet_cart_lineage'];
    $fn=$family==='cart'?'jet_calculate_cart':'jet_calculate';$c->$fn();$new=json_decode($c->response->output,true);
    check($new['jet_intent_id']===$id&&$new['jet_quote_ref']!==$firstQuote['jet_quote_ref'],'recalculation retains identity and replaces only quote');
    $c->request->post['jet_quote_ref']=$new['jet_quote_ref'];
    $result=durableSend($c);check($result['success']==='success','valid Product/Cart Jet/Card submission');
    $before=durableEffects($c);$row=durableRow($c);check($row['state']==='completed'&&$row['reference_no']==='1'&&$memory->meta['next_reference']==='2','one global N to N+1 reservation including Cart');
    check($row['order_state']==='done'&&$row['bank_state']==='done'&&$row['history_state']==='done','irreversible actions recorded');
    check($row['clear_state']===($family==='cart'?'done':'skipped'),'Cart-clear rules preserved');
    check(durableSend($c)===$result&&durableEffects($c)===$before,'successful replay returns same result without effects after quote consumption');
    check(!array_filter($c->db->queries,function($sql){return strpos($sql,'UPDATE oc_setting')!==false;}),'Product/Cart no legacy counter writer');
    $c->request->post['jet_priceall']='1';check(durableSend($c)['success']==='unsuccess'&&durableEffects($c)===$before,'changed-payload replay has no effects');
    // Independent form for identical goods, sharing durable counter and owner.
    $second=durableEnvironment($family,$card,$memory);check($second->request->post['jet_intent_id']!==$id,'independent form gets independent application');
    check(durableSend($second)['success']==='success'&&durableRow($second)['reference_no']==='2'&&$memory->meta['next_reference']==='3','distinct form reserves distinct next reference');
    // Ownership, financial validation and expiry all precede claim/reservation.
    foreach(['tamper','expired','owner','wrong-intent'] as $attack){
        $c=durableEnvironment($family,$card);$before=durableEffects($c);
        if($attack==='tamper')$c->request->post['jet_priceall']='1';
        if($attack==='expired'){$space=$family==='cart'?'jet_cart_quotes':'jet_product_quotes';$c->session->data[$space][$c->request->post['jet_quote_ref']]['expires']=time()-1;}
        if($attack==='owner')$c->testOwner='foreign-session';
        if($attack==='wrong-intent'){$other=durableEnvironment($family,$card,$c->testSubmissionMemory);$c->request->post['jet_intent_id']=$other->request->post['jet_intent_id'];}
        $out=durableSend($c);check($out['success']==='unsuccess'&&durableEffects($c)===$before,'rejected '.$attack.' has no order/mail/counter/history/clear effect');
    }
    // An overlapping request arrives while first order creation is in progress.
    $c=durableEnvironment($family,$card);$second=clone $c;$second->request=clone $c->request;$second->response=new Response;
    $second->session=(object)['data'=>$c->session->data];$second->testSubmissionService=new JetSubmission(new JetSubmissionStore(new SubmissionConnection($c->testSubmissionMemory),'oc_'));
    $orders=new DurableHookOrders;$orders->total=600;$c->model_checkout_order=$orders;$second->model_checkout_order=$orders;$nested=null;
    $orders->hook=function()use($second,&$nested){$nested=durableSend($second);};
    check(durableSend($c)['success']==='success'&&($nested['jet_submission_review']??false),'overlapping same-intent request cannot execute');
    check(count($orders->orders)===1&&count(SubmissionTestMailer::$attempts)===3&&$c->testSubmissionMemory->meta['next_reference']==='2','overlap produces one order, bank attempt and reservation');
    foreach(['create','missing','bank','secondary','history','cart'] as $failure){
        if($failure==='cart'&&$family!=='cart')continue;
        $c=durableEnvironment($family,$card);
        if(in_array($failure,['create','missing','history'],true))$c->model_checkout_order->failure=$failure;
        if($failure==='cart')$c->cart->failure='cart';
        if($failure==='bank')SubmissionTestMailer::$failures=['bank'=>'throw'];
        if($failure==='secondary')SubmissionTestMailer::$failures=['admin'=>'throw','customer'=>'false'];
        $out=durableSend($c);$before=durableEffects($c);$row=durableRow($c);$again=durableSend($c);
        check(durableEffects($c)===$before,'no repeated effects after '.$failure);
        check($row['reference_no']==='1'&&$c->testSubmissionMemory->meta['next_reference']==='2','failed/uncertain reference never reused');
        if(in_array($failure,['create','missing','bank'],true))check($row['state']==='uncertain'&&($again['jet_submission_review']??false),'uncertain '.$failure.' cannot restart');
        else check($out['success']==='success'&&$row['state']==='completed'&&$again===$out,'secondary/native hook failure preserves primary result');
        if($failure==='missing')check(count($c->model_checkout_order->orders)===1&&!SubmissionTestMailer::$attempts,'interrupted order before verified ID creates no mail or second order');
        if($failure==='bank')check(SubmissionTestMailer::$attempts===['bank','admin','customer'],'uncertain bank still has independent secondary sequence');
    }
    foreach([1,2] as $commitNumber){
        $c=durableEnvironment($family,$card);$c->testSubmissionConnection->commitCountdown=$commitNumber;
        $out=durableSend($c);check(($out['jet_submission_review']??false)&&!$c->model_checkout_order->orders&&!SubmissionTestMailer::$attempts,'unknown claim/action COMMIT cannot reach order/mail');
        $c->testSubmissionService=new JetSubmission(new JetSubmissionStore(new SubmissionConnection($c->testSubmissionMemory),'oc_'));
        $before=durableEffects($c);check((durableSend($c)['jet_submission_review']??false)&&durableEffects($c)===$before,'fresh connection never automatically takes over uncertain execution');
    }
    foreach([3,4,5,6,7] as $commitNumber){
        $c=durableEnvironment($family,$card);$c->testSubmissionConnection->commitCountdown=$commitNumber;
        $out=durableSend($c);check(($out['jet_submission_review']??false)&&count($c->model_checkout_order->orders)===1,'unknown order/mail persistence requires review');
        $attempts=SubmissionTestMailer::$attempts;
        check(count($attempts)===($commitNumber<=4?0:($commitNumber<=6?1:2)),'no unpersisted action is executed');
        $c->testSubmissionService=new JetSubmission(new JetSubmissionStore(new SubmissionConnection($c->testSubmissionMemory),'oc_'));
        $before=durableEffects($c);durableSend($c);check(durableEffects($c)===$before,'unknown order/mail result never repeats an irreversible action');
    }
    $c=durableEnvironment($family,$card);$before=durableEffects($c);$c->testSubmissionService=new JetSubmission(new JetSubmissionStore(new SubmissionConnection(new SubmissionMemory),'oc_'));
    check((durableSend($c)['jet_submission_review']??false)&&durableEffects($c)===$before,'unprepared durable storage has no legacy fallback');
}
echo 'PASS '.($count-$startChecks)." Product/Cart durable endpoint checks; simulated SQL/concurrency, mocked orders/mail only\n";

};
require __DIR__.'/submission-emails.php';
