<?php
// Wire POST -> actual native Request cleaning -> real Product routes and validation.
require_once __DIR__ . '/opencart-request.php';
$productFinancialTests = function () {
    $checks = 0;
    $assert = function ($condition, $message) use (&$checks) {
        $checks++;
        if (!$condition) throw new RuntimeException($message);
    };
    $value = function ($id, $price, $prefix = '+') {
        return ['product_option_value_id'=>(string)$id,'option_value_id'=>(string)($id+16),'name'=>'Checkbox '.($id-25),
            'image'=>'','quantity'=>'0','subtract'=>'0','price'=>$price,'price_prefix'=>$prefix,'weight'=>'1.00000000','weight_prefix'=>'+'];
    };
    $definition = function ($type='checkbox', $required=true) use ($value) {
        return ['product_option_id'=>'232','option_id'=>'2','name'=>'Native option','type'=>$type,'value'=>'','required'=>$required?'1':'0',
            'product_option_value'=>in_array($type,['select','radio','checkbox']) ? [
                $value(26,'0.0000'),$value(27,'8.3333'),$value(28,'16.6667'),$value(29,'8.3333','-')
            ] : []];
    };
    $wireOptions = function ($type, $contents) {
        if ($contents === null) return '[]';
        $entries=[];
        foreach (is_array($contents)?$contents:[$contents] as $value) $entries[]=['name'=>'option[232]'.($type==='checkbox'?'[]':''),'value'=>$value];
        return json_encode($entries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    };
    $dispatch = function ($c, $method, $wire) {
        // Always start from wire data, never clean an already processed request twice.
        $c->request->post=(new OpenCartProductRequestDouble($wire))->post;
        $c->$method();
        return json_decode($c->response->output,true);
    };
    $fixture = function ($definitions, $options, $amount='869.00') {
        $c=setup('NewCalc');
        $c->model_catalog_product->price=707.5; // Native 20% tax produces the owner's 849 EUR base.
        $c->model_catalog_product->options=$definitions;
        $c->model_tool_upload=new class {function getUploadByCode($code){return $code==='native-upload-code'?['code'=>$code,'name'=>'native.txt']:false;}};
        $c->model_extension_mt_jet_credit_jetcredit->model_tool_upload=$c->model_tool_upload;
        $wire=['jet_product_id'=>'42','jet_quantity'=>'1','jet_options'=>$options,'jet_priceall'=>$amount,'jet_parva'=>'0.00',
            'jet_downpayment_raw'=>'0','jet_vnoski'=>'12','jet_previous_quote'=>''];
        return [$c,$wire];
    };
    $reset = function ($c) {
        SubmissionTestMailer::$messages=SubmissionTestMailer::$attempts=SubmissionTestMailer::$failures=[];
        Trace::$events=[];$c->db->queries=[];
    };
    $assertNoEffects = function ($c, $label) use ($assert) {
        $assert(!$c->model_checkout_order->orders && !$c->model_checkout_order->histories,$label.': no order/history');
        $assert(!SubmissionTestMailer::$attempts,$label.': no email attempts');
        $assert(!array_filter($c->db->queries,function($sql){return preg_match('/^\s*(UPDATE|INSERT|DELETE)/i',$sql);}),$label.': no counter/database mutation');
    };
    $submitWire = function ($wire, $quote, $card=0) {
        $wire+=['jet_lname'=>'','jet_name'=>'Test','jet_lastname'=>'User','jet_egn'=>'1234567890','jet_phone'=>'1234567890','jet_email'=>'test@example.invalid'];
        $wire['jet_card']=(string)$card;$wire['jet_quote_ref']=$quote['jet_quote_ref'];
        foreach(['vnoska','total_credit_price','obshto','gpr','glp'] as $field)$wire['jet_'.$field]=$quote['jet_'.$field.($card&&$field!=='total_credit_price'?'_card':'')];
        return $wire;
    };

    $owner='[{"name":"option[232][]","value":"28"}]';
    $cleaned=(new OpenCartProductRequestDouble(['jet_options'=>$owner]))->post['jet_options'];
    $assert($cleaned==='[{&quot;name&quot;:&quot;option[232][]&quot;,&quot;value&quot;:&quot;28&quot;}]','exact failing Request::clean transformation');
    [$c,$wire]=$fixture([$definition()],$owner);
    $decoded=$c->model_extension_mt_jet_credit_jetcredit->productSelections($cleaned);
    echo 'Owner transport reproduction: ',json_encode(['cleaned'=>$cleaned,'decoded'=>$decoded]),PHP_EOL;
    $assert($decoded===[232=>['28']],'owner payload must decode after native Request::clean');
    $quote=$dispatch($c,'jet_calculate',$wire);
    $assert(($quote['success']??'')==='success' && isset($quote['jet_quote_ref']),'exact owner calculation succeeds');
    $assert($quote['jet_priceall']==='869.00' && $quote['jet_total_credit_price']==='869.00','869.00004 rounds to authoritative 869.00 without tolerance');
    $context=$c->model_extension_mt_jet_credit_jetcredit->getProductContext('42','1',$decoded);
    $assert(abs($context['raw_amount']-869.00004)<1e-10,'native component calculation unchanged');

    $cases=[['none',[],null,'849.00'],['optional omitted',[$definition('radio',false)],null,'849.00'],
        ['optional blank',[$definition('select',false)],'','849.00'],['select',[$definition('select')],'28','869.00'],
        ['radio',[$definition('radio')],'28','869.00'],['checkbox',[$definition()],['28'],'869.00'],
        ['mixed checkbox',[$definition()],['28','29'],'859.00'],['negative radio',[$definition('radio')],'29','839.00']];
    foreach(['text','textarea','date','datetime','time','file'] as $type) {
        $contents=['text'=>'Юникод "quote" \'apostrophe\' &quot; &amp; <tag> %2B \\ end',
            'textarea'=>"First\nВтори ред &amp; &quot;",'date'=>'2026-11-03','datetime'=>'2026-11-03 18:30','time'=>'18:30','file'=>'native-upload-code'][$type];
        $cases[]=[$type,[$definition($type)],$contents,'849.00'];
    }
    foreach($cases as [$label,$definitions,$contents,$amount])foreach([0,1] as $card){
        $type=$definitions?$definitions[0]['type']:'checkbox';
        [$c,$wire]=$fixture($definitions,$wireOptions($type,$contents),$amount);
        $quote=$dispatch($c,'jet_calculate',$wire);
        $assert(($quote['success']??'')==='success' && isset($quote['jet_quote_ref']),$label.': quote after native cleaning');
        $selection=$c->model_extension_mt_jet_credit_jetcredit->productSelections($c->request->post['jet_options']);
        $assert($selection===($contents===null?[]:[232=>$contents]),$label.': exact original contents, no double decoding');
        $reset($c);$result=$dispatch($c,'jet_send',$submitWire($wire,$quote,$card));
        $assert(($result['success']??'')==='success',$label.': native-cleaned valid submission');
        $assert(count($c->model_checkout_order->orders)===1 && count(SubmissionTestMailer::$attempts)===3,$label.': mocked delivery only');
    }

    $badJson=['[{bad json]','null','"scalar"','[true]','{"name":"option[232][]","value":"28"}',
        '[{"name":"option[232][]","value":28}]','[{"name":"option[232][]","value":["28"]}]',
        htmlspecialchars($owner,ENT_COMPAT,'UTF-8'),rawurlencode($owner)];
    foreach($badJson as $options){
        [$c,$wire]=$fixture([$definition()],$options);$reset($c);
        $result=$dispatch($c,'jet_calculate',$wire);
        $assert(($result['success']??'')==='unsuccess','malformed/unexpected transport rejected');$assertNoEffects($c,'bad transport');
        $assert(empty($c->session->data['jet_product_quotes']),'invalid transport issues no quote');
    }
    foreach(['foreign option','foreign value','duplicates','wrong scalar','duplicate scalar','amount','expired quote','invalid quote'] as $attack){
        [$c,$wire]=$fixture([$definition()],$owner);$quote=$dispatch($c,'jet_calculate',$wire);$wire=$submitWire($wire,$quote);
        if($attack==='foreign option')$wire['jet_options']='[{"name":"option[999][]","value":"28"}]';
        if($attack==='foreign value')$wire['jet_options']='[{"name":"option[232][]","value":"999"}]';
        if($attack==='duplicates')$wire['jet_options']='[{"name":"option[232][]","value":"28"},{"name":"option[232][]","value":"28"}]';
        if($attack==='wrong scalar')$wire['jet_options']='[{"name":"option[232]","value":"28"}]';
        if($attack==='duplicate scalar')$wire['jet_options']='[{"name":"option[232]","value":"28"},{"name":"option[232]","value":"28"}]';
        if($attack==='amount')$wire['jet_priceall']='1.00';
        if($attack==='expired quote')$c->session->data['jet_product_quotes'][$quote['jet_quote_ref']]['expires']=time()-1;
        if($attack==='invalid quote')$wire['jet_quote_ref']=str_repeat('0',48);
        $reset($c);$result=$dispatch($c,'jet_send',$wire);
        $assert(($result['success']??'')==='unsuccess',$attack.': native-cleaned submission rejected');$assertNoEffects($c,$attack);
    }
    echo "PASS $checks native Request/Product option transport and security checks\n";
};
require __DIR__ . '/submission-emails.php';
