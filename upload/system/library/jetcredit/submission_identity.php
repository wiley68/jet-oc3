<?php
require_once __DIR__ . '/offer.php';
/** Immutable generations; session fields are hints, never concurrency authority. */
class JetCreditSubmissionIdentity {
 private $session; private $signer; private $store; private $customer;
 public function __construct($session, $signer, $store, $customer) { $this->session=$session; $this->signer=$signer; $this->store=$store; $this->customer=(int)$customer; }
 public function scope($context,$entity) {
  if (!in_array($context,array('product','cart','checkout'),true) || !is_string($this->session->getId()) || $this->session->getId()==='') { throw new JetCreditFinancialException('invalid_application_context'); }
  return $this->signer->digest(array('purpose'=>'purchase-scope-v2','store'=>0,'session'=>$this->session->getId(),'customer'=>$this->customer,'context'=>$context,'entity'=>$context==='product'?JetCreditFinancial::positiveInteger($entity):null));
 }
 public function application($scope,$generation) { return $this->signer->digest(array('purpose'=>'application-intent-v2','scope'=>$scope,'generation'=>JetCreditFinancial::positiveInteger($generation))); }
 public function first($context,$entity) { return $this->application($this->scope($context,$entity),1); }
 public function intent($context,$entity,$create=false) {
  $scope=$this->scope($context,$entity); $row=$this->store->latestGeneration($scope);
  $id=$row ? $row['intent'] : $this->application($scope,1);
  $this->session->data['jetcredit_intents'][$context.':'.($context==='product'?(int)$entity:'current')]=$id;
  return $id;
 }
 public function generation($context,$entity,$intent) {
  if (!is_string($intent) || !preg_match('/^[a-f0-9]{64}$/D',$intent)) { throw new JetCreditFinancialException('invalid_application_context'); }
  $scope=$this->scope($context,$entity);
  if (hash_equals($this->application($scope,1),$intent)) { return 1; }
  $row=$this->store->generation($scope,$intent);
  if (!$row || !hash_equals($this->application($scope,(int)$row['purchase_generation']),$intent)) { throw new JetCreditFinancialException('invalid_application_context'); }
  return (int)$row['purchase_generation'];
 }
 public function authorization($store,$customer,$context,$entity,$submitted) {
  if ($store!==0 || (int)$customer!==$this->customer) { throw new JetCreditFinancialException('invalid_application_context'); }
  $this->generation($context,$entity,$submitted);
  return $this->signer->digest(array('purpose'=>'submission-owner-v2','store'=>0,'session'=>$this->session->getId(),'customer'=>$this->customer,'context'=>$context,'entity'=>$context==='product'?(int)$entity:null,'intent'=>$submitted));
 }
 public function assertCurrent($context,$entity,$intent) {
  if (!hash_equals($this->intent($context,$entity),$intent)) { throw new JetCreditFinancialException('invalid_application_context'); }
 }
 public function advance($context,$entity,$prior,$request) {
  $auth=$this->authorization(0,$this->customer,$context,$entity,$prior);
  if (!is_string($request) || !preg_match('/^[a-f0-9]{64}$/D',$request)) { throw new JetCreditFinancialException('invalid_application_context'); }
  $scope=$this->scope($context,$entity); $number=$this->generation($context,$entity,$prior)+1;
  if ($number>1000000000) { throw new JetCreditFinancialException('review_required'); }
  $next=$this->application($scope,$number);
  return $this->store->advanceGeneration($scope,$prior,$auth,array('intent'=>$next,'authorization'=>$this->authorizationDigest($context,$entity,$next),'generation'=>$number,'request'=>$this->signer->digest(array('transition-v2',$prior,$request))));
 }
 private function authorizationDigest($context,$entity,$intent) {
  return $this->signer->digest(array('purpose'=>'submission-owner-v2','store'=>0,'session'=>$this->session->getId(),'customer'=>$this->customer,'context'=>$context,'entity'=>$context==='product'?(int)$entity:null,'intent'=>$intent));
 }
}
