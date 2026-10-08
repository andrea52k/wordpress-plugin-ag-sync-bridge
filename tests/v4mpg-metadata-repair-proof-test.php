<?php
// Test integrity failures against the real repair proof, without touching WordPress.
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');define('AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS','6:example-6');
function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_THROW_ON_ERROR);}
require_once dirname(__DIR__).'/includes/class-v4mpg-table-deploy-service.php';
class RepairProofDb {
 public $prefix='wp_';public $last_error='';public $meta;public $rows;public $queries=[];
 public function prepare($sql,...$args){return $sql;}
 public function get_row($sql,$format){$this->queries[]=$sql;return $this->meta;}
 public function get_var($sql){$this->queries[]=$sql;return 1;}
 public function get_results($sql,$format){$this->queries[]=$sql;return $this->rows;}
}
function check_repair($condition,$message){if(!$condition)throw new LogicException($message);}
function rejected_repair($method,$service,$target,$contains){try{$method->invoke($service,$target,false);}catch(RuntimeException $e){check_repair(strpos($e->getMessage(),$contains)!==false,'Wrong rejection: '.$e->getMessage());return;}throw new LogicException('Unsafe repair proof accepted.');}
$db=new RepairProofDb();$headers='["city"]';$urls='["/one/","/two/"]';$ctx=hash_init('sha256');
foreach(['One','Two'] as $i=>$city){$json=json_encode([$city]);$path=$i===0?'/one/':'/two/';$sha=hash('sha256',$json);$db->rows[]=['row_index'=>$i,'url_path'=>$path,'row_data'=>$json,'row_sha256'=>$sha];hash_update($ctx,$i."\0".$path."\0".$sha."\n");}
$measured=hash_final($ctx);
$db->meta=['project_id'=>6,'active_version_id'=>42,'enabled'=>1,'dataset_id'=>'example-6','status'=>'active','row_count'=>2,'column_count'=>1,'url_change_count'=>0,'dataset_sha256'=>str_repeat('a',64),'header_json'=>$headers,'header_sha256'=>hash('sha256',$headers),'urls_json'=>$urls,'urls_sha256'=>hash('sha256',$urls)];
$expected=array_intersect_key($db->meta,array_flip(['project_id','dataset_id','active_version_id','dataset_sha256','urls_sha256','row_count']));
$target=['project_id'=>6,'dataset_id'=>'example-6','expected_previous'=>$expected,'expected_measured_sha256'=>$measured];
$ref=new ReflectionClass(AGSyncBridge\V4MPG_Table_Deploy_Service::class);$service=$ref->newInstanceWithoutConstructor();$prop=$ref->getProperty('wpdb');$prop->setAccessible(true);$prop->setValue($service,$db);$method=$ref->getMethod('prove_metadata_repair_target');$method->setAccessible(true);$validator=$ref->getMethod('validate_metadata_repair_targets');$validator->setAccessible(true);
$validated=$validator->invoke($service,[$target]);$proof=$method->invoke($service,$validated[0],false);
check_repair($proof['verified_row_count']===2&&$proof['measured_dataset_sha256']===$measured&&$proof['content_writes']===0,'Valid full proof failed.');
$original=$db->rows;$db->rows[0]['row_sha256']=str_repeat('b',64);rejected_repair($method,$service,$target,'row integrity');$db->rows=$original;
$db->rows=array_reverse($original);rejected_repair($method,$service,$target,'row integrity');$db->rows=$original;
$db->rows[1]['url_path']='/one/';rejected_repair($method,$service,$target,'row integrity');$db->rows=$original;
$db->rows[0]['row_data']='{}';$db->rows[0]['row_sha256']=hash('sha256','{}');rejected_repair($method,$service,$target,'row integrity');$db->rows=$original;
$bad=$target;$bad['expected_measured_sha256']=str_repeat('c',64);rejected_repair($method,$service,$bad,'measured digest precondition');
$bad=$target;$bad['expected_previous']['active_version_id']=43;rejected_repair($method,$service,$bad,'precondition');
$db->meta['header_sha256']=str_repeat('d',64);rejected_repair($method,$service,$target,'header or URL proof');$db->meta['header_sha256']=hash('sha256',$headers);
$db->rows=array_slice($original,0,1);rejected_repair($method,$service,$target,'row read');$db->rows=$original;
$bad=$target;$bad['project_id']=7;$bad['dataset_id']='example-7';try{$validator->invoke($service,[$bad]);throw new LogicException('Disallowed target accepted.');}catch(RuntimeException $e){check_repair(strpos($e->getMessage(),'allowlist')!==false,'Incorrect target rejection.');}
foreach($db->queries as $sql)check_repair(strpos($sql,'SELECT ')===0,'Read-only proof wrote database state.');
echo "v4mpg metadata repair proof: ok\n";
