<?php
// Shared SQL/lock doubles; never connects to a database.
require_once dirname(__DIR__, 2) . '/upload/system/library/jet_submission.php';
class SubmissionMemory {
    public $tables = [], $meta = null, $ledger = [], $locks = [], $hook = null;
    public $vars = ['autocommit'=>'ON','innodb_flush_log_at_trx_commit'=>'1','log_bin'=>'OFF','sync_binlog'=>'0'];
}
class SubmissionConnection {
    public $memory, $queries = [], $commitFailure = '', $rollbackFailure = false, $commitCountdown = 0;
    private $active = false, $writes = [], $id, $autocommit = null;
    function __construct($memory) { $this->memory=$memory; $this->id=spl_object_hash($this); }
    function escape($value) { return str_replace(["\\", "'"], ["\\\\", "\\'"], $value); }
    private function result($rows) { return (object)['rows'=>array_values($rows),'row'=>$rows ? reset($rows) : [],'num_rows'=>count($rows)]; }
    private function value($literal) { return $literal === 'NULL' ? null : str_replace(["\\'","\\\\"],["'","\\"],trim($literal,"'")); }
    private function lock($key) {
        if (isset($this->memory->locks[$key]) && $this->memory->locks[$key] !== $this->id) throw new RuntimeException('mock lock wait conflict');
        $this->memory->locks[$key]=$this->id;
    }
    private function release() { foreach ($this->memory->locks as $k=>$v) if($v===$this->id)unset($this->memory->locks[$k]); $this->active=false;$this->writes=[]; }
    private function row($id) { return $this->writes[$id] ?? ($id==='meta' ? $this->memory->meta : ($this->memory->ledger[$id]??null)); }
    private function write($id,$row) { if(!$this->active)throw new RuntimeException('write outside transaction');$this->writes[$id]=$row; }
    function query($sql) {
        $this->queries[]=$sql;$m=$this->memory;
        if($sql==='SET SESSION autocommit=1'){$this->autocommit='ON';return true;}
        if($sql==='SHOW ENGINES')return $this->result([['Engine'=>'InnoDB','Support'=>'DEFAULT','Transactions'=>'YES']]);
        if(preg_match("/^SHOW VARIABLES LIKE '([^']+)'$/",$sql,$r)){ $value=$r[1]==='autocommit'&&$this->autocommit!==null?$this->autocommit:($m->vars[$r[1]]??null);return $this->result($value!==null?[['Value'=>$value]]:[]); }
        if(preg_match("/^SHOW TABLE STATUS WHERE Name = '([^']+)'$/",$sql,$r))return $this->result(isset($m->tables[$r[1]])?[['Engine'=>$m->tables[$r[1]]['engine']]]:[]);
        if(preg_match('/^CREATE TABLE IF NOT EXISTS `([^`]+)` \((.*)\) ENGINE=InnoDB/s',$sql,$r)){
            if(isset($m->tables[$r[1]]))return true;
            $fields=[];preg_match_all('/`(\w+)` ((?:tinyint|int|bigint|char|varchar)(?:\(\d+\))?(?: unsigned)?(?: NOT NULL| NULL)(?: DEFAULT \'[^\']+\')?)/',$r[2],$matches,PREG_SET_ORDER);
            foreach($matches as $f){preg_match('/^([a-z]+(?:\(\d+\))?(?: unsigned)?)/',$f[2],$t);preg_match("/DEFAULT '([^']+)'/",$f[2],$d);$fields[]=['Field'=>$f[1],'Type'=>$t[1],'Null'=>strpos($f[2],'NOT NULL')!==false?'NO':'YES','Default'=>$d[1]??null,'Extra'=>'','Collation'=>strpos($t[1],'char')!==false?'ascii_bin':null];}
            $indexes=[];preg_match_all('/(PRIMARY KEY|UNIQUE KEY `(\w+)`) \(([^)]+)\)/',$r[2],$matches,PREG_SET_ORDER);
            foreach($matches as $index)foreach(explode(',',$index[3]) as $i=>$column)$indexes[]=['Key_name'=>$index[2]?:'PRIMARY','Column_name'=>trim($column,'`'),'Seq_in_index'=>$i+1,'Non_unique'=>0,'Sub_part'=>null];
            $m->tables[$r[1]]=['engine'=>'InnoDB','fields'=>$fields,'indexes'=>$indexes];return true;
        }
        if(preg_match('/^SHOW FULL COLUMNS FROM `([^`]+)`$/',$sql,$r))return $this->result($m->tables[$r[1]]['fields']??[]);
        if(preg_match('/^SHOW INDEX FROM `([^`]+)`$/',$sql,$r))return $this->result($m->tables[$r[1]]['indexes']??[]);
        if($sql==='START TRANSACTION'){if($this->active)throw new RuntimeException('nested');$this->active=true;return true;}
        if($sql==='ROLLBACK'){if($this->rollbackFailure)throw new RuntimeException('mock rollback failure');$this->release();return true;}
        if($sql==='COMMIT'){
            if($this->commitCountdown>0 && --$this->commitCountdown===0)$this->commitFailure='after';
            if($this->commitFailure==='before'){ $this->release();throw new RuntimeException('mock commit failed'); }
            foreach($this->writes as $id=>$row){if($id==='meta')$m->meta=$row;else $m->ledger[$id]=$row;}
            $this->release();if($this->commitFailure==='after')throw new RuntimeException('mock lost commit reply');return true;
        }
        if(preg_match('/^INSERT INTO `\w+jet_submission_meta` .* VALUES \(1,1,\'([0-9]+)\',\'([a-f0-9]+)\'\)/',$sql,$r)){
            if($m->meta===null)$m->meta=['singleton'=>'1','schema_revision'=>'1','next_reference'=>$r[1],'digest_key'=>$r[2]];return true;
        }
        if(preg_match('/^SELECT m\.\*, \(SELECT MAX\(reference_no\) FROM `\w+jet_submission`\) AS highest_reference FROM `\w+jet_submission_meta` m$/',$sql)){
            $row=$this->row('meta');$highest=null;
            foreach($m->ledger as $entry){$ref=$entry['reference_no'];if($ref!==null&&($highest===null||strlen($ref)>strlen($highest)||(strlen($ref)===strlen($highest)&&strcmp($ref,$highest)>0)))$highest=$ref;}
            if($row)$row['highest_reference']=$highest;return $this->result($row?[$row]:[]);
        }
        if(preg_match('/^SELECT \* FROM `\w+jet_submission_meta`/',$sql)){
            if(strpos($sql,'FOR UPDATE')!==false)$this->lock('meta');$row=$this->row('meta');$rows=$row?[$row]:[];
        }elseif(preg_match('/^SELECT (?:\*|intent_id) FROM `\w+jet_submission`/',$sql)){
            $rows=array_merge($m->ledger,array_filter($this->writes,function($key){return $key!=='meta';},ARRAY_FILTER_USE_KEY));
            foreach(['intent_id','store_id','checkout_order_id'] as $field)if(preg_match("/\\b".$field."='([^']+)'/",$sql,$r))$rows=array_filter($rows,function($row)use($field,$r){return (string)$row[$field]===$r[1];});
            if(strpos($sql,'FOR UPDATE')!==false)foreach($rows as $row)$this->lock($row['intent_id']);
            if(strpos($sql,'LIMIT 1')!==false)$rows=array_slice($rows,0,1);
        }elseif(preg_match('/^INSERT INTO `\w+jet_submission` \(([^)]+)\) VALUES \((.*)\)$/',$sql,$r)){
            $columns=explode(',',$r[1]);$values=explode(',',$r[2]);$row=[];
            foreach($m->tables['oc_jet_submission']['fields'] as $field)$row[$field['Field']]=$field['Default'];
            foreach($columns as $i=>$column)$row[$column]=$this->value($values[$i]);
            foreach($m->ledger as $existing)if($existing['intent_id']===$row['intent_id']||($row['checkout_order_id']!==null&&$existing['store_id']===$row['store_id']&&$existing['checkout_order_id']===$row['checkout_order_id']))throw new RuntimeException('mock duplicate key');
            $this->write($row['intent_id'],$row);return true;
        }elseif(preg_match('/^UPDATE `\w+jet_submission_meta` SET next_reference=\'([0-9]+)\' WHERE singleton=1$/',$sql,$r)){
            $row=$this->row('meta');$row['next_reference']=$r[1];$this->write('meta',$row);return true;
        }elseif(preg_match('/^UPDATE `\w+jet_submission` SET (.*) WHERE intent_id=\'([a-f0-9]+)\'$/',$sql,$r)){
            $row=$this->row($r[2]);foreach(explode(',',$r[1]) as $assignment){preg_match('/`(\w+)`=(.*)/',$assignment,$a);$row[$a[1]]=$this->value($a[2]);}
            foreach($m->ledger as $id=>$existing)if($id!==$r[2]&&$row['reference_no']!==null&&$row['reference_no']===$existing['reference_no'])throw new RuntimeException('mock duplicate reference');
            $this->write($r[2],$row);return true;
        }else throw new RuntimeException('Unhandled SQL double: '.$sql);
        if($m->hook && strpos($sql,'FOR UPDATE')!==false){$fn=$m->hook;$m->hook=null;$fn();}
        return $this->result($rows);
    }
}
