<?php
// Exercise the real database verifier: mismatch stays rejected and exports no row content.
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
function wp_json_encode($value,$flags=0){return json_encode($value,$flags|JSON_THROW_ON_ERROR);}
require_once dirname(__DIR__).'/includes/class-v4mpg-table-deploy-service.php';
class DigestEvidenceDb {
 public $prefix='wp_';public $measured;public $urls='["/example/"]';public $queries=[];
 public function prepare($sql,...$args){return $sql;}
 public function query($sql){$this->queries[]=$sql;return true;}
 public function get_row($sql,$format){
  if($format===ARRAY_N)return [1,0,1,$this->measured];
  return ['project_id'=>6,'active_version_id'=>42,'enabled'=>1,'dataset_id'=>'example-6','row_count'=>1,'column_count'=>1,'url_change_count'=>0,'dataset_sha256'=>str_repeat('a',64),'urls_json'=>$this->urls,'header_json'=>'["private-content-field"]'];
 }
}
$reflection=new ReflectionClass(AGSyncBridge\V4MPG_Table_Deploy_Service::class);$service=$reflection->newInstanceWithoutConstructor();$db=new DigestEvidenceDb();$property=$reflection->getProperty('wpdb');$property->setAccessible(true);$property->setValue($service,$db);$method=$reflection->getMethod('verify_version_database');$method->setAccessible(true);
$db->measured=str_repeat('b',64);
try{$method->invoke($service,42,6,'example-6');throw new LogicException('Digest mismatch accepted.');}catch(RuntimeException $error){
 $prefix='Remote V4MPG ordered digest proof failed. Evidence: ';
 if(strpos($error->getMessage(),$prefix)!==0)throw $error;
 $evidence=json_decode(substr($error->getMessage(),strlen($prefix)),true,512,JSON_THROW_ON_ERROR);
 if($evidence['stored_dataset_sha256']!==str_repeat('a',64)||$evidence['measured_dataset_sha256']!==str_repeat('b',64)||$evidence['project_id']!==6||$evidence['version_id']!==42||strpos($error->getMessage(),'private-content-field')!==false||strpos($error->getMessage(),'/example/')!==false)throw new LogicException('Invalid or excessive diagnostic evidence.');
}
$db->measured=str_repeat('a',64);$valid=$method->invoke($service,42,6,'example-6');if($valid['ordered_digest']!==$db->measured)throw new LogicException('Valid digest rejected.');
$db->urls='[]';try{$method->invoke($service,42,6,'example-6');throw new LogicException('URL count mismatch accepted.');}catch(RuntimeException $error){if(strpos($error->getMessage(),'"declared_url_count":0')===false)throw $error;}
foreach($db->queries as $sql)if(strpos($sql,'SET SESSION group_concat_max_len=')!==0)throw new LogicException('Unexpected database write.');
echo "v4mpg digest failure evidence: ok\n";
