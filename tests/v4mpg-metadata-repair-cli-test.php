<?php
// Exercise the real CLI persistence path with a simulated lost remote response.
define('ABSPATH',__DIR__.'/');define('WP_CLI',true);define('AG_SYNC_BRIDGE_PLUGIN_DIR',dirname(__DIR__).'/');
$root=str_replace('\\','/',sys_get_temp_dir()).'/agsb-mrcli-'.bin2hex(random_bytes(6));mkdir($root);define('AG_SYNC_BRIDGE_LOCAL_BACKUP_ROOT',$root);
class WP_CLI_Command{}class WP_CLI{public static function log($value){}}
function wp_normalize_path($value){return str_replace('\\','/',$value);}function wp_mkdir_p($dir){return is_dir($dir)||mkdir($dir,0777,true);}function wp_generate_uuid4(){return bin2hex(random_bytes(16));}function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_THROW_ON_ERROR);}
require_once dirname(__DIR__).'/includes/class-v4mpg-table-deploy-service.php';require_once dirname(__DIR__).'/includes/class-v4mpg-table-cli.php';
use AGSyncBridge\V4MPG_Table_Deploy_Service as Service;
class MetadataCliClient {
 public $plan;public $result;public $pending;public $calls=[];public $phase='timeout';
 public function request($action,$request){$this->calls[]=$action;if($action==='metadata-repair-plan')return $this->plan;if(!is_file($this->pending))throw new LogicException('Remote action preceded durable local journal.');if($this->phase==='timeout')throw new RuntimeException('Simulated lost response.');$result=$this->result;if($this->phase==='wrong-response')$result['operation_id']='wrong-operation';return $result;}
}
function expect_cli($ok,$message){if(!$ok)throw new LogicException($message);}
function reject_cli($call,$message){try{$call();}catch(RuntimeException $e){return;}throw new LogicException($message);}
$files=[$root.'/plan-request.json',$root.'/plan.json',$root.'/repair-request.json',$root.'/wrong-request.json',$root.'/result.json',$root.'/result.json.pending.json'];
try {
 $site=['home_url'=>'https://example.test','site_url'=>'https://example.test','home_host'=>'example.test'];$before=['project_id'=>6,'dataset_id'=>'example-6','active_version_id'=>42,'dataset_sha256'=>str_repeat('a',64),'urls_sha256'=>str_repeat('c',64),'row_count'=>2];$measured=str_repeat('b',64);
 $target=['project_id'=>6,'dataset_id'=>'example-6','expected_previous'=>$before,'expected_measured_sha256'=>$measured];$plan_request=['protocol'=>1,'expected_site'=>$site,'targets'=>[$target]];
 $proof=['before'=>$before,'measured_dataset_sha256'=>$measured,'verified_row_count'=>2,'content_writes'=>0,'url_writes'=>0,'active_pointer_writes'=>0];
 $plan=['protocol'=>1,'status'=>'metadata-repair-plan-verified','site'=>$site,'request_sha256'=>Service::sha256($plan_request),'before_metadata_sha256'=>Service::sha256([$before]),'datasets'=>[$proof],'mutated'=>false];
 $request=$plan_request+['operation_id'=>'cli-metadata-repair-0001','before_metadata_sha256'=>$plan['before_metadata_sha256'],'confirmation'=>'REPAIR V4MPG DIGESTS'];
 $after=$proof;$after['before']['dataset_sha256']=$measured;
 $result=['protocol'=>1,'status'=>'metadata-repaired','site'=>$site,'operation_id'=>$request['operation_id'],'request_sha256'=>Service::sha256($request),'before_metadata_sha256'=>$plan['before_metadata_sha256'],'before'=>[$proof],'after'=>[$after],'content_writes'=>0,'url_writes'=>0,'active_pointer_writes'=>0];
 file_put_contents($files[0],json_encode($plan_request));file_put_contents($files[2],json_encode($request));$wrong=$request;$wrong['operation_id']='different-operation';file_put_contents($files[3],json_encode($wrong));
 $client=new MetadataCliClient();$client->plan=$plan;$client->result=$result;$client->pending=$files[5];$reflection=new ReflectionClass(AGSyncBridge\V4MPG_Table_CLI::class);$property=$reflection->getProperty('client');$property->setAccessible(true);$property->setValue(null,$client);$cli=new AGSyncBridge\V4MPG_Table_CLI();
 $cli->metadata_repair_plan([],['request'=>$files[0],'output-receipt'=>$files[1]]);$plan_sha=hash_file('sha256',$files[1]);
 $assoc=['request'=>$files[2],'before-receipt'=>$files[1],'output-receipt'=>$files[4]];
 reject_cli(fn()=>$cli->metadata_repair([],$assoc),'Lost response did not fail.');expect_cli(is_file($files[5])&&!file_exists($files[4]),'Interrupted operation lost pending journal.');
 $calls=count($client->calls);reject_cli(fn()=>$cli->metadata_repair([],$assoc),'Second mutation accepted pending operation.');expect_cli(count($client->calls)===$calls,'Retry repeated remote mutation.');
 $wrong_assoc=$assoc;$wrong_assoc['request']=$files[3];reject_cli(fn()=>$cli->metadata_repair_recover([],$wrong_assoc),'Wrong pending request accepted.');expect_cli(count($client->calls)===$calls,'Wrong recovery called remote.');
 $client->phase='wrong-response';reject_cli(fn()=>$cli->metadata_repair_recover([],$assoc),'Wrong response binding accepted.');expect_cli(is_file($files[5])&&!file_exists($files[4]),'Bad response consumed pending proof.');
 $client->phase='success';$cli->metadata_repair_recover([],$assoc);expect_cli(is_file($files[4])&&!file_exists($files[5]),'Recovery did not persist receipt before cleanup.');
 expect_cli(hash_file('sha256',$files[1])===$plan_sha,'Original metadata receipt overwritten.');expect_cli(Service::sha256(json_decode(file_get_contents($files[4]),true))===Service::sha256($result),'Saved response differs from verified receipt.');
 echo "v4mpg metadata repair CLI: ok\n";
} finally {foreach($files as $file)if(is_file($file))unlink($file);foreach(glob($root.'/.agsb-output-probe-*') as $file)unlink($file);rmdir($root);}
