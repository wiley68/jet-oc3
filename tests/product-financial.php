<?php
// Actual Product routes and models with the existing in-memory order/mail/service doubles.
require_once __DIR__ . '/opencart-request.php';
$productFinancialTests = function () {
    $checks = 0;
    $assert = function ($condition, $label) use (&$checks) {
        $checks++;
        if (!$condition) throw new RuntimeException($label);
    };
    $entries = function ($options) {
        $result = [];
        foreach ($options as $id => $value) foreach (is_array($value) ? $value : [$value] as $item) {
            $result[] = ['name' => 'option[' . $id . ']' . (is_array($value) ? '[]' : ''), 'value' => $item];
        }
        // These JSON strings reach Product routes after native Request::clean(), not directly from the wire.
        return (new OpenCartProductRequestDouble(['jet_options'=>json_encode($result)]))->post['jet_options'];
    };
    $definition = function ($type, $required = true) {
        return ['product_option_id' => 1, 'option_id' => 80, 'name' => 'Option', 'type' => $type, 'required' => $required,
            'product_option_value' => in_array($type, ['select','radio','checkbox']) ? [
                ['product_option_value_id'=>'11','option_value_id'=>'101','name'=>'red','price'=>10,'price_prefix'=>'+','subtract'=>0,'quantity'=>10],
                ['product_option_value_id'=>'12','option_value_id'=>'102','name'=>'blue','price'=>10,'price_prefix'=>'-','subtract'=>0,'quantity'=>10],
                ['product_option_value_id'=>'13','option_value_id'=>'103','name'=>'green','price'=>10,'price_prefix'=>'+','subtract'=>0,'quantity'=>10]
            ] : []];
    };
    $environment = function ($options = [], $selected = [], $down = '0', $card = 0, $term = 12, $base = 'EUR') use ($entries, $assert) {
        $c = setup('NewCalc', $base);
        $c->model_catalog_product->price = $base === 'EUR' ? 500 : 1000;
        $c->model_catalog_product->options = $options;
        $c->model_tool_upload = new class { function getUploadByCode($code) { return $code === 'valid-upload' ? ['name'=>'native.txt','code'=>$code] : false; } };
        $c->model_extension_mt_jet_credit_jetcredit->model_tool_upload = $c->model_tool_upload;
        $c->request->post['jet_quantity']='1';
        $c->request->post['jet_options']=$entries($selected);
        $context=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,'1',$selected);
        $assert($context && !isset($context['error']), 'valid native context');
        $c->request->post['jet_priceall']=$context['amount'];
        $c->request->post['jet_downpayment_raw']=$down;
        $c->request->post['jet_parva']=$c->model_extension_mt_jet_credit_jetcredit->normalizeProductDownpayment($down);
        $c->request->post['jet_vnoski']=(string)$term;
        $c->request->post['jet_card']=$card;
        $c->jet_calculate();
        $quote=json_decode($c->response->output,true);
        $assert(($quote['success']??'')==='success' && isset($quote['jet_quote_ref']), 'valid Product quote');
        $c->request->post['jet_quote_ref']=$quote['jet_quote_ref'];
        foreach (['vnoska','total_credit_price','obshto','gpr','glp'] as $field) $c->request->post['jet_'.$field]=$quote['jet_'.$field.($card && $field!=='total_credit_price'?'_card':'')];
        return [$c,$quote];
    };
    $reset = function ($c) {
        SubmissionTestMailer::$messages=SubmissionTestMailer::$attempts=SubmissionTestMailer::$failures=[];
        Trace::$events=[]; $c->db->queries=[];
    };
    $reject = function ($c,$label) use ($assert,$reset) {
        $reset($c); $c->jet_send(); $result=json_decode($c->response->output,true);
        $assert(($result['success']??null)==='unsuccess',$label.': fails closed');
        $assert(!SubmissionTestMailer::$attempts,$label.': no email attempts');
        $assert(!$c->model_checkout_order->orders && !$c->model_checkout_order->histories,$label.': no orders/history');
        $assert(!array_filter($c->db->queries,function($sql){return preg_match('/^\s*(UPDATE|INSERT|DELETE)/i',$sql);}),$label.': no counter/database writes');
    };
    foreach ([0,1] as $card) {
        foreach (['priceall','vnoska','total_credit_price','obshto','gpr','glp','vnoski','parva','quantity','product_id'] as $field) {
            foreach (['1x2',[],null,true,'NaN','INF','1e999','-1'] as $bad) {
                [$c]=$environment([],[],'0',$card); $c->request->post['jet_'.$field]=$bad;
                $reject($c,'card'.$card.' '.$field.' '.gettype($bad));
            }
            [$c]=$environment([],[],'0',$card);unset($c->request->post['jet_'.$field]);$reject($c,'missing '.$field);
        }
        foreach (['priceall','vnoska','total_credit_price','obshto','gpr','glp'] as $field) {
            [$c]=$environment([],[],'0',$card);$c->request->post['jet_'.$field]='1.00';$reject($c,'original F01 attack '.$field);
        }
        foreach (['0','7','-1',[],null] as $reference) {
            [$c]=$environment([],[],'0',$card);$c->request->post['jet_quote_ref']=$reference;$reject($c,'invalid quote');
        }
        foreach ([0,-12,999,12.5] as $term) {
            [$c]=$environment([],[],'0',$card);$c->request->post['jet_vnoski']=$term;$reject($c,'invalid term');
        }
        foreach (['hidden','minimum','guest-price','bad-rate','changed-label','changed-prefix','changed-option-price'] as $change) {
            [$c]=$environment([$definition('select')],[1=>'11'],'0',$card);
            if($change==='hidden')$c->model_extension_mt_jet_credit_jetcredit->show=false;
            if($change==='minimum')$c->config->values['module_mt_jet_credit_price']=1000;
            if($change==='guest-price')$c->config->values['config_customer_price']=1;
            if($change==='bad-rate')$c->currency->rates['EUR']=0;
            if($change==='changed-label')$c->model_catalog_product->options[0]['name']='changed';
            if($change==='changed-prefix')$c->model_catalog_product->options[0]['product_option_value'][0]['price_prefix']='-';
            if($change==='changed-option-price')$c->model_catalog_product->options[0]['product_option_value'][0]['price']=20;
            $reject($c,$change);
        }
        foreach (['module','card','currency','store','group','language','customer','price','special','stock','rate','promotion','expired','other-session','quantity'] as $change) {
            [$c]=$environment([],[],'0',$card);
            if($change==='module')$c->config->values['module_mt_jet_credit_status']=0;
            if($change==='card'){$c->config->values['module_mt_jet_credit_status_card']=0;$c->request->post['jet_card']=1;}
            if($change==='currency')$c->session->data['currency']='USD';
            if($change==='store')$c->config->values['config_store_id']=3;
            if($change==='group')$c->config->values['config_customer_group_id']=3;
            if($change==='language')$c->config->values['config_language_id']=3;
            if($change==='customer')$c->customer=$c->model_extension_mt_jet_credit_jetcredit->customer=new class {function isLogged(){return true;}function getId(){return 7;}};
            if($change==='price')$c->model_catalog_product->price=510;
            if($change==='special')$c->model_catalog_product->special=400;
            if($change==='stock')$c->model_catalog_product->stock=0;
            if($change==='rate')$c->currency->rates['EUR']=0.9;
            if($change==='promotion')$c->model_extension_mt_jet_credit_jetcredit->normal=$c->model_extension_mt_jet_credit_jetcredit->card=2;
            if($change==='expired')$c->session->data['jet_product_quotes'][$c->request->post['jet_quote_ref']]['expires']=time()-1;
            if($change==='other-session')$c->session->data['jet_product_quotes']=[];
            if($change==='quantity')$c->request->post['jet_quantity']='2';
            if($change==='currency'){
                $reset($c);$c->jet_send();$assert(!SubmissionTestMailer::$attempts && !$c->db->queries && !$c->model_checkout_order->orders,'existing currency guard no effects');
            }else $reject($c,'stale '.$change);
        }
        foreach (['select','radio','checkbox','text','textarea','file','date','datetime','time'] as $type) {
            $selected=in_array($type,['select','radio'])?'11':($type==='checkbox'?['11','12']:($type==='file'?'valid-upload':"native value\nsecond line"));
            [$c,$quote]=$environment([$definition($type)],[1=>$selected],'12.345',$card);
            $assert($quote['jet_parva']==='12.35','one normalized downpayment '.$type);
            $assert($quote['jet_total_credit_price']===number_format((float)$quote['jet_priceall']-12.35,2,'.',''),'consistent principal');
            $c->request->post['jet_variation_id']='[{"name":"forged","value":"FORGED","price":0,"price_prefix":"-","type":"file"}]';
            $old=setup('OldCalc');
            foreach(['config','currency','tax','model_catalog_product'] as $property)$old->$property=$c->$property;
            $old->request->post=$c->request->post;
            $legacyOptions=[];
            if(in_array($type,['select','radio','checkbox']))foreach($type==='checkbox'?['11','12']:['11'] as $index=>$id){
                $legacyOptions[]=['product_option_id'=>1,'product_option_value_id'=>$id,'option_id'=>$type==='checkbox'?$index:0,'option_value_id'=>$id==='11'?'101':'102','name'=>'Option','value'=>$id==='11'?'red':'blue','type'=>$type];
            }
            $old->request->post['jet_variation_id']=json_encode($legacyOptions);
            $reset($old);$old->jet_send();$goldenMessages=SubmissionTestMailer::$messages;$goldenOrders=$old->model_checkout_order->orders;
            $reset($c);$c->jet_send();$result=json_decode($c->response->output,true);
            $assert(($result['success']??'')==='success','valid '.$type.' Product card'.$card);
            $assert(count($c->model_checkout_order->orders)===1 && count(SubmissionTestMailer::$attempts)===3,'one mocked order and separate notifications');
            $assert(SubmissionTestMailer::$messages===$goldenMessages && $c->model_checkout_order->orders===$goldenOrders,'accepted legacy order and all three message outputs exact after approved normalization');
            $body=SubmissionTestMailer::$messages[0]['body'];
            $assert(strpos($body,'FORGED')===false && strpos($body,'Първоначална вноска: 12.35;')!==false,'metadata never authoritative; normalized email');
            $priced=in_array($type,['select','radio','checkbox']);
            $assert(count($c->model_checkout_order->orders[0]['products'][0]['option'])===($priced?($type==='checkbox'?2:1):0),'non-priced order output omission preserved');
            if(!$priced)$assert(strpos($body,(string)$selected)===false,'non-priced email output omission preserved');
            [$c]=$environment([$definition($type)],[1=>$selected],'0',$card);
            $c->request->post['jet_options']=$entries([1=>$type==='checkbox'?['11']:($type==='file'?'other-upload':'changed')]);
            $reject($c,'stale '.$type.' contents');
            [$c]=$environment([$definition($type,false)],[],'0',$card);
            $reset($c);$c->jet_send();$assert(json_decode($c->response->output,true)['success']==='success','optional omitted '.$type);
        }
        [$c]=$environment([$definition('select')],[1=>'11'],'0',$card);
        $c->request->post['jet_options']=$entries([1=>'13']);$reject($c,'equal-price different option');
        foreach (['','0'] as $empty) {
            [$c]=$environment([$definition('text',false)],[],'0',$card);
            $c->request->post['jet_options']=$entries([1=>$empty]);$reject($c,'complete optional empty contents participate in quote identity');
        }
        foreach (['select','radio','checkbox','text','textarea','file','date','datetime','time'] as $type) {
            [$c]=$environment([],[],'0',$card);$c->model_catalog_product->options=[$definition($type)];
            $c->request->post['product_id']=1;$c->request->post['option']=[];$c->jet_check();
            $assert(isset(json_decode($c->response->output,true)['error']['option'][1]),'required presence '.$type);
            $reject($c,'required selection missing '.$type);
        }
        foreach ([[1=>'999'],[999=>'11'],[1=>['11','11']],[1=>['11','999']]] as $bad) {
            [$c]=$environment([$definition('checkbox')],[1=>['11']],'0',$card);$c->request->post['jet_options']=$entries($bad);$reject($c,'foreign/duplicate selection');
        }
        [$c]=$environment([$definition('select')],[1=>'11'],'0',$card);
        $c->request->post['jet_options']='[{"name":"option[1]","value":"11"},{"name":"option[1]","value":"11"}]';$reject($c,'duplicate option ID');
        foreach (['600','600.001','599.999','-0.004','bad','NaN','INF',[],null,true] as $bad) {
            [$c]=$environment([],[],'0',$card);$c->request->post['jet_downpayment_raw']=$bad;$reject($c,'invalid/excessive downpayment');
        }
        foreach (['12.345'=>'12.35','1.005'=>'1.00','2.675'=>'2.67','0.125'=>'0.13',''=>'0.00','  '=>'0.00'] as $raw=>$expected) {
            [$c,$q]=$environment([],[],(string)$raw,$card);$assert($q['jet_parva']===$expected,'JS rounding '.$raw);
            $reset($c);$c->jet_send();$assert(strpos(SubmissionTestMailer::$messages[0]['body'],'Размер на кредита: '.number_format(600-(float)$expected,2,'.','').';')!==false,'normalized principal in email');
        }
        foreach ([3,6,9,10,12,15,18,24,30,36] as $term) {
            [$c]=$environment([],[],'0',$card,12);$c->model_catalog_product->price=2500;
            $context=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,1,[]);
            $c->request->post['jet_priceall']=$context['amount'];$c->request->post['jet_vnoski']=(string)$term;$c->jet_calculate();
            $periodQuote=json_decode($c->response->output,true);
            $assert(isset($periodQuote['jet_quote_ref']),'allowed period '.$term);
            $c->request->post['jet_quote_ref']=$periodQuote['jet_quote_ref'];
            foreach(['vnoska','total_credit_price','obshto','gpr','glp'] as $field)$c->request->post['jet_'.$field]=$periodQuote['jet_'.$field.($card&&$field!=='total_credit_price'?'_card':'')];
            $reset($c);$c->jet_send();$assert(json_decode($c->response->output,true)['success']==='success','valid submission term '.$term);
        }
        foreach ([['400.9999',15,false],['401',15,true],['600.9999',30,false],['601',30,true]] as [$amount,$term,$valid]) {
            [$c]=$environment([],[],'0',$card);$c->tax=$c->model_extension_mt_jet_credit_jetcredit->tax=new class {function calculate($price,...$rest){return $price;}};
            $c->model_catalog_product->price=(float)$amount;$ctx=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,1,[]);
            $c->request->post['jet_priceall']=$ctx['amount'];$c->request->post['jet_vnoski']=(string)$term;$c->jet_calculate();
            $assert(isset(json_decode($c->response->output,true)['jet_quote_ref'])===$valid,'raw period boundary '.$amount);
        }
        [$c]=$environment([],[],'0',$card,12,'USD');$reset($c);$c->jet_send();$assert($c->model_checkout_order->orders[0]['total']==1200,'EUR to non-EUR order conversion unchanged');
        foreach ([0,400] as $special) {
            [$c]=$environment([],[],'0',$card);$c->model_catalog_product->special=$special;
            $ctx=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,3,[]);
            $assert((float)$ctx['amount']===($special?1440.0:1800.0),'nonzero special and quantity multiplication semantics');
        }
        foreach (['1','0','-600','600.01',[],null] as $amount) {
            [$c]=$environment([],[],'0',$card);$c->request->post['jet_priceall']=$amount;$c->jet_calculate();
            $assert(json_decode($c->response->output,true)['success']==='unsuccess','calculator rejects untrusted amount before issuing quote');
        }
        [$c]=$environment([],[],'0',$card);$c->model_catalog_product->price=75;
        $ctx=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,1,[]);$c->request->post['jet_priceall']=$ctx['amount'];$c->jet_calculate();$small=json_decode($c->response->output,true);
        $c->request->post['jet_quote_ref']=$small['jet_quote_ref'];
        foreach(['vnoska','total_credit_price','obshto','gpr','glp'] as $field)$c->request->post['jet_'.$field]=$small['jet_'.$field.($card&&$field!=='total_credit_price'?'_card':'')];
        $reject($c,'monthly minimum enforced at submit');
    }
    foreach ([0,5] as $fixed) foreach ([0,0.2] as $percent) {
        [$c]=$environment([$definition('checkbox')],[1=>['11','12']]);
        $c->tax=$c->model_extension_mt_jet_credit_jetcredit->tax=new class($fixed,$percent){private $f,$p;function __construct($f,$p){$this->f=$f;$this->p=$p;}function calculate($price,...$args){return $price*(1+$this->p)+$this->f;}};
        $ctx=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,2,[1=>['11','12']]);
        $assert((float)$ctx['amount']===(float)((500*(1+$percent)+$fixed)*2),'separate percentage/fixed/mixed tax components cancel signed options');
        $ctx=$c->model_extension_mt_jet_credit_jetcredit->getProductContext(1,3,[1=>['11']]);
        $assert((float)$ctx['amount']===(float)((500*(1+$percent)+$fixed+10*(1+$percent)+$fixed)*3),'positive option has its separate fixed/mixed tax component');
    }
    [$c]=$environment();$c->request->post=['jet_priceall'=>'1'];$reject($c,'original direct POST without quote or transport');
    [$c]=$environment();$first=$c->request->post;$ref=$first['jet_quote_ref'];
    $c->request->post['jet_parva']='10.00';$c->request->post['jet_downpayment_raw']='10';$c->jet_calculate();$second=json_decode($c->response->output,true);
    $assert($second['jet_quote_ref']!==$ref && isset($c->session->data['jet_product_quotes'][$ref]),'independent unpredictable references preserve another tab');
    $c->request->post=$first;$reset($c);$c->jet_send();$assert(isset($c->session->data['jet_product_quotes'][$second['jet_quote_ref']]),'submission does not remove another tab');
    [$c]=$environment();$refs=[$c->request->post['jet_quote_ref']];
    for($i=1;$i<64;$i++){$c->jet_calculate();$refs[]=json_decode($c->response->output,true)['jet_quote_ref'];}
    $c->jet_calculate();$assert(json_decode($c->response->output,true)['success']==='unsuccess' && count($c->session->data['jet_product_quotes'])===64,'bounded capacity without evicting valid tabs');
    $c->request->post['jet_previous_quote']=$refs[0];$c->jet_calculate();$assert(isset(json_decode($c->response->output,true)['jet_quote_ref']) && count($c->session->data['jet_product_quotes'])===64,'own quote replacement at capacity');
    $assert(count(array_unique($refs))===64,'quote references unique');
    echo "PASS $checks Product financial/security/option/output checks; all rejected submissions have zero side effects\n";
};
require __DIR__ . '/submission-emails.php';
