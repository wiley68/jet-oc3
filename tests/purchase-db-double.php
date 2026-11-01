<?php
/** Statement-level shared-row double; no claims about real InnoDB locking. */
trait PurchaseDbDouble {
 public $purchaseRows=array(); public $purchaseAffected=0; public $purchaseOnInsert; public $purchaseFailure;
 public function purchaseQuery($sql) {
  $this->purchaseAffected=0;
  if ($this->purchaseFailure && strpos($sql,$this->purchaseFailure)!==false) { $this->purchaseFailure=null; throw new RuntimeException('synthetic purchase persistence failure'); }
  $result=function($rows=array()){return (object)array('num_rows'=>count($rows),'row'=>$rows[0]??array(),'rows'=>array_values($rows));};
  $value=function($where,$key){preg_match('/(?<![a-zA-Z_])`?'.preg_quote($key,'/').'`?=(NULL|\x27[^\x27]*\x27|[0-9]+)/',$where,$m);return isset($m[1])?($m[1]==='NULL'?null:trim($m[1],"'")):null;};
  if(strpos($sql,'INSERT INTO')===0){
   preg_match('/\((record_type.*?)\) VALUES \((.*?)\)(?: ON DUPLICATE|$)/s',$sql,$m);
   $data=array_combine(explode(',',$m[1]),str_getcsv($m[2],',',"'",'\\'));
   foreach($data as&$v){if($v==='NULL')$v=null;}unset($v);
   foreach($this->purchaseRows as$row){if($row['record_type']===$data['record_type']&&$row['intent']===$data['intent'])return$result();}
   $key=$data['record_type'].':'.$data['intent'];
   $this->purchaseRows[$key]=$data+array('order_id'=>null,'order_digest'=>null,'purchase_scope'=>null,'purchase_generation'=>null,'previous_intent'=>null);
   $this->purchaseAffected=1;
   if($this->purchaseOnInsert){$hook=$this->purchaseOnInsert;$this->purchaseOnInsert=null;$hook();}
   return$result();
  }
  if(strpos($sql,'SELECT *')===0){
   $rows=array();foreach($this->purchaseRows as$row){$ok=true;foreach(array('record_type','intent','purchase_scope','previous_intent')as$key){$expected=$value($sql,$key);if($expected!==null&&$row[$key]!==$expected)$ok=false;}if($ok)$rows[]=$row;}
   if(strpos($sql,'ORDER BY purchase_generation')!==false){usort($rows,function($a,$b){return(int)$b['purchase_generation']<=>(int)$a['purchase_generation'];});$rows=array_slice($rows,0,1);}
   return$result($rows);
  }
  if(strpos($sql,'UPDATE')===0){
   list($set,$where)=explode(' WHERE ',explode(' SET ',$sql,2)[1],2);
   foreach($this->purchaseRows as$key=>$row){$ok=true;foreach(array('record_type','intent','owner_run','state')as$column){$v=$value($where,$column);if($v!==null&&$row[$column]!==$v)$ok=false;}if(!$ok)continue;
    foreach(array('state','order_id','order_digest')as$column){$v=$value($set,$column);if($v!==null)$row[$column]=$v;}$this->purchaseRows[$key]=$row;++$this->purchaseAffected;
   }return$result();
  }
  throw new RuntimeException('Unsupported purchase SQL double');
 }
}
