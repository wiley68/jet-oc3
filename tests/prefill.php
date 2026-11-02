<?php
require __DIR__ . '/financial-bootstrap.php';
foreach(array('product','cart')as$context){
 $r=ffixture();$customer=$r->get('customer');
 $customer->firstname='Иван <script>alert(1)</script>';$customer->lastname='Петров';$customer->email='profile@example.test';$customer->telephone='+359123456789';
 $f=$r->get('model_extension_mt_jet_credit_financial');$data=$f->frontendData($context,'jet',42);
 fcheck($data['is_active_jet']===true,'profile prefill does not bypass readiness');
 fcheck($data['jet_name']===$customer->firstname && $data['jet_lastname']===$customer->lastname && $data['jet_email']===$customer->email && $data['jet_phone']===$customer->telephone,'only current customer profile mapped by native APIs');
 fcheck($data['jet_sign']==='евро','visible currency suffix');
 fcheck(in_array('Cache-Control: private, no-store',$r->get('response')->headers,true),'authenticated HTML is not share-cacheable');
 fcheck(!array_key_exists('jet_egn',$data),'no invented identity number');
 $input=finput();if($context==='cart')$input['jet_context']='cart';
 $quote=$f->quote($context,'jet',$input);$json=json_encode($quote);
 foreach(array($customer->firstname,$customer->email,$customer->telephone)as$private){fcheck(strpos($json,$private)===false,'quote contains no profile PII');}
 fcheck($quote['currency']==='EUR','technical quote currency unchanged');
 $parts=explode('.',$quote['offer']);$payload=base64_decode(strtr($parts[0],'-_','+/'));
 fcheck(strpos($payload,$customer->email)===false && strpos($payload,$customer->telephone)===false,'HMAC offer has no profile fields');
 $customer->id=0;$data=$f->frontendData($context,'jet',42);
 foreach(array('jet_name','jet_lastname','jet_email','jet_phone')as$key){fcheck($data[$key]==='','logged out gets empty editable field');}
}
// Profile getters are the only prefill source; no order or historical-customer lookup.
$r=ffixture();$r->get('customer')->firstname="Bad\r\nName";$r->get('customer')->telephone='';
$data=$r->get('model_extension_mt_jet_credit_financial')->frontendData('product','jet',42);
fcheck($data['jet_name']==='' && $data['jet_phone']==='','invalid/missing profile fields stay blank');
echo "T-PREFILL PASS: $fchecks checks; synthetic current-customer profile/DB, no external access.\n";
