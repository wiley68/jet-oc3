<?php
require_once DIR_SYSTEM . 'library/jetcredit/runtime.php';
require_once DIR_SYSTEM . 'library/jetcredit/submission_identity.php';
/** Durable reservation commits before native addOrder. Never retry an unresolved owner. */
class ModelExtensionMtJetCreditCheckout extends Model {
 public function orderForIntent($data) {
  JetCreditRuntime::boot($this->registry);
  $scheme=$data['payment_code']??null;
  if (!in_array($scheme,array('jet','jet_card'),true) || (int)$data['store_id']!==0 || (int)$data['customer_id']!==(int)$this->customer->getId() || ($this->session->data['payment_method']['code']??null)!==$scheme) { throw new JetCreditFinancialException('invalid_order_context'); }
  $this->load->model('extension/mt_jet_credit/financial'); $this->model_extension_mt_jet_credit_financial->ready($scheme);
  if (!in_array($this->config->get('payment_'.$scheme.'_status'),array(1,'1'),true)) { throw new JetCreditFinancialException('financing_unavailable'); }
  $this->load->model('extension/mt_jet_credit/submission'); $facade=$this->model_extension_mt_jet_credit_submission;
  $identity=$facade->identity();
  // Explicit old native POSTs retain their immutable context. An unbound native
  // page GET bootstraps the active server generation, but never advances it.
  $intent=$this->request->post['jet_intent']??$identity->intent('checkout',null,true);
  $auth=$identity->authorization(0,$this->customer->getId(),'checkout',null,$intent);
  $store=$facade->purchaseRepository(); $claim=$store->find($intent);
  $this->load->model('checkout/order');
  if ($claim) {
   if (!hash_equals((string)$claim['authorization'],$auth) || !$claim['order_id']) { throw new JetCreditFinancialException('invalid_application_context'); }
   $order=$this->model_checkout_order->getOrder($claim['order_id']);
   if (!$order || (int)$order['store_id']!==0 || (int)$order['customer_id']!==(int)$this->customer->getId()) { throw new JetCreditFinancialException('invalid_order_context'); }
   // Historical outcome only; submission replays the claim without executing this new data.
   return JetCreditFinancial::positiveInteger($claim['order_id']);
  }
  $identity->assertCurrent('checkout',null,$intent);
  $signer=new JetCreditOffer(JetCreditOffer::configuredSecret($this->config)); $snapshot=$data;
  foreach(array('ip','forwarded_ip','user_agent','accept_language','tracking') as $key){unset($snapshot[$key]);}
  $digest=$signer->digest($snapshot); $run=bin2hex(random_bytes(32));
  $reservation=$store->reserveDraft($intent,$auth,$digest,$scheme,$run); $row=$reservation['row'];
  if (!$reservation['acquired']) {
   if ($row['state']!=='draft_bound' || !$row['order_id']) { throw new JetCreditFinancialException('review_required'); }
   if ($row['request_digest']!==$digest || $row['scheme']!==$scheme) { throw new JetCreditFinancialException('stale_order'); }
   $actual=$this->persisted($row['order_id']);
   if (!hash_equals((string)$row['order_digest'],$signer->digest($actual))) { throw new JetCreditFinancialException('stale_order'); }
   return (int)$row['order_id'];
  }
  // No DB transaction spans this call. Native MyISAM writes may persist on failure.
  // Any exception/death leaves draft_creating permanently held, including before addOrder.
  $id=JetCreditFinancial::positiveInteger($this->model_checkout_order->addOrder($data));
  $actual=$this->persisted($id);
  $expected=$this->canonical($data,$data['products'],$data['totals'],function($product){return $product['option'];});
  if ($actual!==$expected) { throw new JetCreditFinancialException('invalid_persisted_order'); }
  $store->bindDraft($intent,$run,$id,$signer->digest($actual));
  return $id;
 }
 private function persisted($id) {
  $order=$this->model_checkout_order->getOrder($id);
  if (!$order || (int)$order['order_status_id']!==0) { throw new JetCreditFinancialException('stale_order'); }
  return $this->canonical($order,$this->model_checkout_order->getOrderProducts($id),$this->model_checkout_order->getOrderTotals($id),function($product)use($id){return $this->model_checkout_order->getOrderOptions($id,$product['order_product_id']);});
 }
 private function canonical($order,$products,$totals,$options) {
  $header=array();
  foreach(array('store_id','customer_id','email','firstname','lastname','telephone','payment_code','currency_code','payment_address_1','payment_address_2','payment_city','payment_postcode','shipping_address_1','shipping_address_2','shipping_city','shipping_postcode')as$key){$header[$key]=(string)($order[$key]??'');}
  foreach(array('currency_value','total')as$key){$header[$key]=number_format(JetCreditFinancial::number($order[$key]),4,'.','');}
  $lines=array();
  foreach($products as$product){
   $line=array('product_id'=>(int)$product['product_id'],'quantity'=>(int)$product['quantity']);
   foreach(array('price','total','tax')as$key){$line[$key]=number_format(JetCreditFinancial::number($product[$key]),4,'.','');}
   $selected=array();foreach(call_user_func($options,$product)as$option){$selected[]=array((int)$option['product_option_id'],(int)($option['product_option_value_id']??0),(string)$option['type'],(string)$option['value']);}sort($selected);$line['options']=$selected;$lines[]=$line;
  }
  $values=array();foreach($totals as$total){$values[]=array($total['code'],(string)$total['title'],number_format(JetCreditFinancial::number($total['value']),4,'.',''),(int)$total['sort_order']);}sort($values);
  if (!$lines || !$values) { throw new JetCreditFinancialException('invalid_order_context'); }
  return array('header'=>$header,'lines'=>$lines,'totals'=>$values);
 }
}
