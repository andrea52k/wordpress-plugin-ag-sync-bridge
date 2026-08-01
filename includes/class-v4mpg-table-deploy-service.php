<?php
namespace AGSyncBridge;

use RuntimeException;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fail-closed, table-scoped deployment protocol for the V4MPG MySQL runtime.
 *
 * This service deliberately knows only the three runtime tables. It never
 * imports a general SQL dump and never mutates WordPress options or content.
 */
class V4MPG_Table_Deploy_Service {
	const PROTOCOL_VERSION = 1;
	const MAX_DATASETS      = 8;
	const MAX_ROWS          = 10000;
	const MAX_CHANGES       = 5000;
	const MAX_BODY_BYTES    = 16777216;
	const BACKUP_TTL        = 1800;
	const ALLOWED_CONTENT_FIELDS = array( 'h1_variant','intro_variant','meta_desc','meta_title','section_problem','city_custom_intro','hero_h1','local_business_context','meta_description','seo_title' );

	/** @var Config */
	private $config;
	/** @var Logger */
	private $logger;
	/** @var object */
	private $wpdb;
	/** @var Remote_Operation_Runtime */
	private $runtime;
	/** @var string */
	private $state_file;

	public function __construct( Config $config, Logger $logger, $wpdb = null, $runtime = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->config = $config;
		$this->logger = $logger;
		$this->wpdb   = $wpdb;
		$this->runtime = $runtime instanceof Remote_Operation_Runtime ? $runtime : new Remote_Operation_Runtime( $config, $logger );
		$root         = trailingslashit( $config->get_storage_dir() ) . 'temp';
		$this->state_file = $root . '/v4mpg-table-operation.json';
	}

	public static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				return array_map( array( __CLASS__, 'canonicalize' ), array_values( $value ) );
			}
			ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize( $item );
			}
		}
		return $value;
	}

	public static function canonical_json( $value ) {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to encode canonical V4MPG JSON.' );
		}
		return $json;
	}

	public static function sha256( $value ) {
		return hash( 'sha256', self::canonical_json( $value ) );
	}

	public static function assert_deploy_response_binding( array $result, array $request, array $local_backup_receipt ) {
		$deployment=$request['deployment']??null;$release=is_array($deployment)?($deployment['release']??null):null;$expected_site=$request['expected_site']??null;
		if('deployed'!==($result['status']??'')||!is_array($release)||!is_array($expected_site)||!hash_equals((string)($result['operation_id']??''),(string)($request['operation_id']??''))||!hash_equals(strtolower((string)($result['payload_sha256']??'')),strtolower((string)($request['payload_sha256']??'')))||!hash_equals(strtolower((string)($result['backup_sha256']??'')),strtolower((string)($request['backup_sha256']??'')))||!hash_equals(strtolower((string)($request['backup_sha256']??'')),strtolower((string)($local_backup_receipt['sha256']??'')))||self::sha256($result['site']??array())!==self::sha256($expected_site)||!hash_equals(strtolower((string)($result['release_sha256']??'')),self::sha256($release))||self::sha256($result['release']??array())!==self::sha256($release)){throw new RuntimeException('Remote deploy response is not bound to the exact request and local backup receipt.');}
		return true;
	}

	public function plan( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys( $request, array( 'protocol', 'expected_site', 'targets' ) );
		$this->assert_protocol( $request );
		$this->assert_site_identity( $request['expected_site'] );
		$targets = $this->validate_targets( $request['targets'] );
		$this->assert_tables_exist();
		$current = array();
		foreach ( $targets as $target ) {
			$pointer = $this->read_active( $target['project_id'], $target['dataset_id'], false );
			$current[] = $this->verify_version_database( $pointer['active_version_id'], $target['project_id'], $target['dataset_id'] );
		}
		return array(
			'protocol'    => self::PROTOCOL_VERSION,
			'site'        => $this->site_identity(),
			'table_prefix'=> (string) $this->wpdb->prefix,
			'targets'     => $current,
			'planned_at'  => gmdate( 'c' ),
			'mutated'     => false,
		);
	}

	/** Export a checksum-bound backup in the response; the caller must persist it locally. */
	public function backup( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys( $request, array( 'protocol', 'operation_id', 'expected_site', 'targets' ) );
		$this->assert_protocol( $request );
		$this->assert_operation_id( $request['operation_id'] );
		$this->assert_site_identity( $request['expected_site'] );
		$targets = $this->validate_targets( $request['targets'] );
		$this->assert_tables_exist();

		$artifact = array(
			'protocol'       => self::PROTOCOL_VERSION,
			'artifact_type'  => 'v4mpg-table-backup',
			'operation_id'   => (string) $request['operation_id'],
			'site'           => $this->site_identity(),
			'table_prefix'   => (string) $this->wpdb->prefix,
			'created_at'     => gmdate( 'c' ),
			'tables'         => array_values( $this->tables() ),
			'datasets'       => array(),
		);
		foreach ( $targets as $target ) {
			$active = $this->read_active( $target['project_id'], $target['dataset_id'], false );
			$this->assert_expected_matches( $active, $target['expected_previous'] );
			$exported = $this->export_active( $active );
			$exported['derived_evidence'] = $this->read_derived_evidence( $target['project_id'], $active );
			$artifact['datasets'][] = $exported;
		}
		$sha   = self::sha256( $artifact );
		$token = wp_generate_uuid4();
		$this->reserve_remote_operation( $request['operation_id'], 'v4mpg-table-backup', 'backup-manifest' );
		$saved=set_transient(
			'ag_sync_v4mpg_backup_' . md5( $token ),
			array(
				'kind'         => 'open',
				'operation_id' => (string) $request['operation_id'],
				'manifest_sha256' => $sha,
				'target_sha256'=> self::sha256( $this->target_identity( $targets ) ),
				'site_sha256'  => self::sha256( $this->site_identity() ),
				'targets'      => $targets,
				'served'       => array(),
			),
			self::BACKUP_TTL
		);
		if(!$saved){$this->finalize_remote_operation($request['operation_id'],'failed',array('stage'=>'backup-session-failed','target_mutated'=>false));throw new RuntimeException('Unable to persist scoped backup session.');}
		return array(
			'protocol'       => self::PROTOCOL_VERSION,
			'status'         => 'backup-exported',
			'backup_token'   => $token,
			'backup_manifest_sha256' => $sha,
			'backup_bytes'   => strlen( self::canonical_json( $artifact ) ),
			'artifact'       => $artifact,
			'mutated'        => false,
		);
	}

	public function backup_page( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys($request,array('protocol','operation_id','expected_site','backup_token','project_id','dataset_id','offset','limit'));
		$this->assert_protocol($request);$this->assert_operation_id($request['operation_id']);$this->assert_site_identity($request['expected_site']);
		$key='ag_sync_v4mpg_backup_'.md5((string)$request['backup_token']);$state=get_transient($key);
		if(!is_array($state)||'open'!==($state['kind']??'')||!hash_equals((string)$state['operation_id'],(string)$request['operation_id'])){throw new RuntimeException('Scoped backup session is missing or expired.');}
		$this->heartbeat_remote_operation($request['operation_id'],'backup-page',min(90,10+count($state['served'])));
		$project_id=(int)$request['project_id'];$dataset_id=(string)$request['dataset_id'];$offset=(int)$request['offset'];$limit=(int)$request['limit'];
		if($offset<0||0!==$offset%100||100!==$limit){throw new RuntimeException('Scoped backup pages use exact 100-row boundaries.');}
		$target=null;foreach($state['targets'] as $item){if((int)$item['project_id']===$project_id&&$item['dataset_id']===$dataset_id){$target=$item;break;}}
		if(!is_array($target)){throw new RuntimeException('Scoped backup page is outside declared targets.');}
		$active=$this->read_active($project_id,$dataset_id,false);$this->assert_expected_matches($active,$target['expected_previous']);
		$tables=$this->tables();$rows=$this->wpdb->get_results($this->wpdb->prepare("SELECT row_index,url_path,city,province,row_data,row_sha256 FROM `{$tables['rows']}` WHERE version_id=%d ORDER BY row_index LIMIT %d OFFSET %d",$active['active_version_id'],$limit,$offset),ARRAY_A);
		$expected_count=min(100,$active['row_count']-$offset);if($expected_count<1||!is_array($rows)||count($rows)!==$expected_count){throw new RuntimeException('Scoped backup page is incomplete.');}
		foreach($rows as $position=>&$row){$row['row_index']=(int)$row['row_index'];if($row['row_index']!==$offset+$position||!hash_equals(strtolower((string)$row['row_sha256']),hash('sha256',(string)$row['row_data']))){throw new RuntimeException('Scoped backup row ordering or hash mismatch.');}}unset($row);
		$page=array('project_id'=>$project_id,'dataset_id'=>$dataset_id,'active_version_id'=>$active['active_version_id'],'offset'=>$offset,'row_count'=>count($rows),'rows'=>$rows);
		$page_sha=self::sha256($page);$state['served'][$project_id.':'.$offset]=array('row_count'=>count($rows),'first_row_index'=>$offset,'last_row_index'=>$offset+count($rows)-1,'page_sha256'=>$page_sha);if(!set_transient($key,$state,self::BACKUP_TTL)){throw new RuntimeException('Unable to persist scoped backup page proof.');}
		return array('protocol'=>self::PROTOCOL_VERSION,'status'=>'backup-page','page'=>$page,'page_sha256'=>$page_sha,'mutated'=>false);
	}

	public function backup_seal( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys($request,array('protocol','operation_id','expected_site','backup_token','local_backup_sha256','datasets'));
		$this->assert_protocol($request);$this->assert_operation_id($request['operation_id']);$this->assert_site_identity($request['expected_site']);$this->assert_hash($request['local_backup_sha256']);
		$open_key='ag_sync_v4mpg_backup_'.md5((string)$request['backup_token']);$state=get_transient($open_key);
		if(!is_array($state)||'open'!==($state['kind']??'')||!hash_equals((string)$state['operation_id'],(string)$request['operation_id'])){throw new RuntimeException('Scoped backup session cannot be sealed.');}
		$proofs=array();foreach($request['datasets'] as $proof){$this->assert_request_keys($proof,array('project_id','dataset_id','row_count','ordered_digest','pages_sha256'));$this->assert_hash($proof['ordered_digest']);$this->assert_hash($proof['pages_sha256']);$proofs[(string)$proof['dataset_id']]=$proof;}
		$expected_page_keys=array();foreach($state['targets'] as $target){$active=$this->read_active($target['project_id'],$target['dataset_id'],false);$this->assert_expected_matches($active,$target['expected_previous']);$proof=$proofs[$target['dataset_id']]??null;if(!is_array($proof)||(int)$proof['project_id']!==(int)$target['project_id']||(int)$proof['row_count']!==(int)$active['row_count']||!hash_equals(strtolower($proof['ordered_digest']),$active['dataset_sha256'])){throw new RuntimeException('Local scoped backup proof does not match live metadata.');}$page_hashes=array();for($offset=0;$offset<$active['row_count'];$offset+=100){$page_key=$target['project_id'].':'.$offset;$expected_page_keys[]=$page_key;$served=$state['served'][$page_key]??null;$count=min(100,$active['row_count']-$offset);if(!is_array($served)||(int)$served['row_count']!==$count||(int)$served['first_row_index']!==$offset||(int)$served['last_row_index']!==$offset+$count-1){throw new RuntimeException('Scoped backup cannot be sealed before every exact page was downloaded.');}$page_hashes[]=$served['page_sha256'];}if(!hash_equals(strtolower($proof['pages_sha256']),self::sha256($page_hashes))){throw new RuntimeException('Local scoped backup page-hash proof mismatch.');}}
		sort($expected_page_keys);$actual_page_keys=array_keys($state['served']);sort($actual_page_keys);if($expected_page_keys!==$actual_page_keys){throw new RuntimeException('Scoped backup page set contains gaps or extras.');}
		$sealed=wp_generate_uuid4();$sealed_key='ag_sync_v4mpg_backup_'.md5($sealed);if(!set_transient($sealed_key,array('kind'=>'sealed','operation_id'=>$state['operation_id'],'sha256'=>strtolower($request['local_backup_sha256']),'target_sha256'=>$state['target_sha256'],'site_sha256'=>$state['site_sha256']),self::BACKUP_TTL)){throw new RuntimeException('Unable to persist sealed scoped backup proof.');}delete_transient($open_key);if(false!==get_transient($open_key)||!is_array(get_transient($sealed_key))){delete_transient($sealed_key);throw new RuntimeException('Scoped backup seal transition could not be verified.');}
		$this->finalize_remote_operation($request['operation_id'],'complete',array('stage'=>'backup-sealed','target_mutated'=>false,'local_backup_sha256'=>strtolower($request['local_backup_sha256'])));
		return array('protocol'=>self::PROTOCOL_VERSION,'status'=>'backup-sealed-local-proof','backup_token'=>$sealed,'backup_sha256'=>strtolower($request['local_backup_sha256']),'remote_artifact_retained'=>false,'mutated'=>false);
	}

	public function backup_abort( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys($request,array('protocol','operation_id','expected_site','backup_token','reason'));
		$this->assert_protocol($request);$this->assert_operation_id($request['operation_id']);$this->assert_site_identity($request['expected_site']);
		$key='ag_sync_v4mpg_backup_'.md5((string)$request['backup_token']);$state=get_transient($key);
		if(!is_array($state)||'open'!==($state['kind']??'')||!hash_equals((string)$state['operation_id'],(string)$request['operation_id'])){throw new RuntimeException('Scoped backup abort token is missing, expired, or already sealed.');}
		delete_transient($key);if(false!==get_transient($key)){throw new RuntimeException('Scoped backup session could not be removed.');}
		$this->finalize_remote_operation($request['operation_id'],'failed',array('stage'=>'backup-aborted','target_mutated'=>false,'rollback_required'=>false,'reason'=>sanitize_text_field((string)$request['reason'])));
		return array('protocol'=>self::PROTOCOL_VERSION,'status'=>'backup-aborted','operation_id'=>(string)$request['operation_id'],'remote_artifact_retained'=>false,'mutated'=>false);
	}

	public function deploy( array $request ) {
		$this->assert_remote_role();
		$this->assert_body_size( $request );
		$this->assert_request_keys( $request, array( 'protocol', 'operation_id', 'expected_site', 'backup_token', 'backup_sha256', 'payload_sha256', 'deployment' ) );
		$this->assert_protocol( $request );
		$this->assert_operation_id( $request['operation_id'] );
		$this->assert_site_identity( $request['expected_site'] );
		if ( ! hash_equals( strtolower( (string) $request['payload_sha256'] ), self::sha256( $request['deployment'] ) ) ) {
			throw new RuntimeException( 'Deployment payload checksum mismatch.' );
		}
		$deployment = $this->validate_deployment( $request['deployment'] );
		$existing = $this->read_state();
		if ( is_array( $existing ) && isset( $existing['operation_id'] ) && hash_equals( (string) $existing['operation_id'], (string) $request['operation_id'] ) ) {
			if ( 'deployed' === (string) ( $existing['status'] ?? '' ) && isset($existing['receipt']) && is_array($existing['receipt']) && hash_equals( (string) ( $existing['payload_sha256'] ?? '' ), strtolower( (string) $request['payload_sha256'] ) ) && hash_equals((string)($existing['receipt']['payload_sha256']??''),strtolower((string)$request['payload_sha256'])) && hash_equals((string)($existing['receipt']['backup_sha256']??''),strtolower((string)$request['backup_sha256'])) && hash_equals((string)($existing['receipt']['release_sha256']??''),self::sha256($deployment['release'])) && self::sha256($existing['receipt']['site']??array())===self::sha256($this->site_identity()) ) {
				return $existing['receipt'];
			}
			throw new RuntimeException( 'Operation id already exists with a non-idempotent state.' );
		}
		$backup     = get_transient( 'ag_sync_v4mpg_backup_' . md5( (string) $request['backup_token'] ) );
		if ( ! is_array( $backup ) || 'sealed' !== ($backup['kind']??'') || ! hash_equals( (string) $backup['operation_id'], (string) $request['operation_id'] ) || ! hash_equals( (string) $backup['sha256'], strtolower( (string) $request['backup_sha256'] ) ) || ! hash_equals( (string) $backup['target_sha256'], self::sha256( $this->target_identity( $deployment['targets'] ) ) ) || ! hash_equals( (string) $backup['site_sha256'], self::sha256( $this->site_identity() ) ) ) {
			throw new RuntimeException( 'Fresh scoped backup proof is missing or does not bind this deployment.' );
		}

		if(is_array($existing)&&!empty($existing['operation_id'])&&!hash_equals((string)$existing['operation_id'],(string)$request['operation_id'])&&$this->is_ambiguous_state((string)($existing['status']??''))){throw new RuntimeException('A prior V4MPG WAL state is unresolved; use signed status/recover before another deployment.');}

		$this->reserve_remote_operation( $request['operation_id'], 'v4mpg-table-deploy', 'deploy-start' );
		$barrier=null;
		try {
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'deploying', 'payload_sha256' => strtolower( (string) $request['payload_sha256'] ), 'updated_at' => gmdate( 'c' ) ) );
			$receipt = $this->deploy_transaction( $request, $deployment, $barrier );
			$this->advance_cache_epoch_barrier($barrier,'deploy-committed');
			delete_transient( 'ag_sync_v4mpg_backup_' . md5( (string) $request['backup_token'] ) );
			try {
				$this->verify_receipt_active($receipt['datasets'],'deployed');
				$this->assert_derived_unchanged( $receipt['datasets'] );
				$receipt['cache'] = $this->clear_targeted_cache( $receipt['datasets'], false );
			} catch ( Throwable $post_commit_error ) {
				try {
					$this->restore_receipt_exact( $receipt );
					$this->advance_cache_epoch_barrier($barrier,'auto-restore-committed');
					$this->verify_receipt_active($receipt['datasets'],'rolled-back');
					$this->clear_targeted_cache($receipt['datasets'],false);
					$this->assert_derived_unchanged($receipt['datasets']);
					$this->end_cache_epoch_barrier($barrier);
					$barrier=null;
					$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'rolled-back-after-post-commit-failure', 'error' => $post_commit_error->getMessage(), 'receipt' => $receipt, 'updated_at' => gmdate( 'c' ) ) );
				} catch ( Throwable $rollback_error ) {
					$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'rollback_required', 'error' => $post_commit_error->getMessage(), 'rollback_error' => $rollback_error->getMessage(), 'receipt' => $receipt, 'updated_at' => gmdate( 'c' ) ) );
					throw new RuntimeException( 'Post-commit verification failed and exact restore failed: ' . $rollback_error->getMessage(), 0, $post_commit_error );
				}
				throw new RuntimeException( 'Post-commit verification failed; previous runtime versions were restored: ' . $post_commit_error->getMessage(), 0, $post_commit_error );
			}
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'deployed-needs-barrier-release', 'payload_sha256' => strtolower( (string) $request['payload_sha256'] ), 'receipt' => $receipt, 'updated_at' => gmdate( 'c' ) ) );
			$this->end_cache_epoch_barrier($barrier);$barrier=null;
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'deployed-needs-runtime-finalize', 'payload_sha256' => strtolower( (string) $request['payload_sha256'] ), 'receipt' => $receipt, 'updated_at' => gmdate( 'c' ) ) );
			$this->finalize_remote_operation($request['operation_id'],'complete',array('stage'=>'deployed','target_mutated'=>false,'receipt_sha256'=>self::sha256($receipt)));
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'deployed', 'payload_sha256' => strtolower( (string) $request['payload_sha256'] ), 'receipt' => $receipt, 'updated_at' => gmdate( 'c' ) ) );
			return $receipt;
		} catch ( Throwable $error ) {
			$current_state=$this->read_state();$protected_status=(string)($current_state['status']??'');if(in_array($protected_status,array('deployed-needs-barrier-release','deployed-needs-runtime-finalize','deployed'),true)){$current_state['control_plane_error']=$error->getMessage();$current_state['updated_at']=gmdate('c');$this->write_state($current_state);throw $error;}$mutation_uncertain=in_array($protected_status,array('prepared-to-commit','committed-needs-postverify','rollback_required'),true);if(!$mutation_uncertain&&is_array($barrier)){$this->end_cache_epoch_barrier($barrier);$barrier=null;}if(!in_array($protected_status,array('prepared-to-commit','committed-needs-postverify','rollback_required','rolled-back-after-post-commit-failure'),true)){$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'failed', 'payload_sha256' => strtolower( (string) $request['payload_sha256'] ), 'error' => $error->getMessage(), 'updated_at' => gmdate( 'c' ) ));}$this->finalize_remote_operation($request['operation_id'],'failed',array('stage'=>'deploy-failed','target_mutated'=>$mutation_uncertain,'rollback_required'=>$mutation_uncertain,'error'=>$error->getMessage()));
			throw $error;
		} finally { if(is_array($barrier)&&!$this->is_ambiguous_state((string)($this->read_state()['status']??''))){$this->end_cache_epoch_barrier($barrier);} }
	}

	public function verify( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys( $request, array( 'protocol', 'expected_site', 'datasets' ) );
		$this->assert_protocol( $request );
		$this->assert_site_identity( $request['expected_site'] );
		$datasets = $this->validate_expected_datasets( $request['datasets'] );
		$result   = array();
		foreach ( $datasets as $expected ) {
			$pointer = $this->read_active( $expected['project_id'], $expected['dataset_id'], false );
			$active = $this->verify_version_database( $pointer['active_version_id'], $expected['project_id'], $expected['dataset_id'] );
			$this->assert_expected_matches( $active, $expected );
			$result[] = $active;
		}
		return array( 'protocol' => self::PROTOCOL_VERSION, 'status' => 'verified', 'site' => $this->site_identity(), 'datasets' => $result, 'verified_at' => gmdate( 'c' ), 'mutated' => false );
	}

	public function status( array $request ) {
		$this->assert_remote_role();$this->assert_request_keys($request,array('protocol','expected_site'));$this->assert_protocol($request);$this->assert_site_identity($request['expected_site']);$state=$this->read_state();
		return array('protocol'=>self::PROTOCOL_VERSION,'status'=>empty($state)?'empty':'observed','site'=>$this->site_identity(),'state'=>$state,'state_sha256'=>self::sha256($state),'mutated'=>false,'observed_at'=>gmdate('c'));
	}

	public function recover( array $request ) {
		$this->assert_remote_role();$this->assert_request_keys($request,array('protocol','operation_id','expected_site','state_sha256','action'));$this->assert_protocol($request);$this->assert_operation_id($request['operation_id']);$this->assert_site_identity($request['expected_site']);$this->assert_hash($request['state_sha256']);
		$action=(string)$request['action'];if(!in_array($action,array('accept-deployed','accept-rolled-back'),true)){throw new RuntimeException('Recovery action must explicitly accept deployed or rolled-back pointers.');}
		$state=$this->read_state();if(empty($state)){throw new RuntimeException('V4MPG recovery journal is empty.');}$state_sha=self::sha256($state);$prior_recovery=is_array($state['recovery_receipt']??null)?$state['recovery_receipt']:null;if(is_array($prior_recovery)&&hash_equals((string)($prior_recovery['operation_id']??''),(string)$request['operation_id'])&&hash_equals((string)($prior_recovery['action']??''),$action)&&(hash_equals($state_sha,strtolower((string)$request['state_sha256']))||hash_equals((string)($prior_recovery['source_state_sha256']??''),strtolower((string)$request['state_sha256'])))){return $prior_recovery;}if(!hash_equals($state_sha,strtolower((string)$request['state_sha256']))||!hash_equals((string)($state['operation_id']??''),(string)$request['operation_id'])){throw new RuntimeException('V4MPG recovery state changed or operation identity does not match.');}
		if(!$this->is_ambiguous_state((string)($state['status']??''))){throw new RuntimeException('V4MPG journal is not in a recoverable ambiguous state.');}
		$basis=is_array($state['source_receipt']??null)?$state['source_receipt']:($state['receipt']??null);if(!is_array($basis)||empty($basis['datasets'])){throw new RuntimeException('Ambiguous V4MPG journal has no exact deployment receipt.');}
		$inspection=$this->inspect_recovery_outcome($basis['datasets']);$expected='accept-deployed'===$action?'deployed':'rolled-back';if($inspection['outcome']!==$expected){throw new RuntimeException('Requested recovery action does not match the exact database pointer outcome.');}
		$barrier=null;try{$barrier=$this->begin_cache_epoch_barrier($request['operation_id']);$repeat=$this->inspect_recovery_outcome($basis['datasets'],false);if($repeat['outcome']!==$expected||self::sha256($repeat['datasets'])!==self::sha256($inspection['datasets'])){throw new RuntimeException('V4MPG pointers changed while entering recovery barrier.');}$this->clear_targeted_cache($basis['datasets'],false);$this->assert_derived_unchanged($basis['datasets']);$this->advance_cache_epoch_barrier($barrier,'recovery-verified');$receipt=array('protocol'=>self::PROTOCOL_VERSION,'status'=>'recovered','operation_id'=>(string)$request['operation_id'],'action'=>$action,'outcome'=>$expected,'source_state_sha256'=>strtolower((string)$request['state_sha256']),'source_operation_id'=>(string)($basis['operation_id']??''),'source_payload_sha256'=>(string)($basis['payload_sha256']??''),'source_receipt_sha256'=>self::sha256($basis),'datasets'=>$repeat['datasets'],'recovered_at'=>gmdate('c'));
			$state['status']='recovery-verified-needs-barrier-release';$state['recovery_receipt']=$receipt;$state['updated_at']=gmdate('c');$this->write_state($state);$this->end_cache_epoch_barrier($barrier);$barrier=null;$state['status']='recovery-verified-needs-runtime-finalize';$state['updated_at']=gmdate('c');$this->write_state($state);$this->finalize_recovered_runtime($request['operation_id'],$expected);if('deployed'===$expected){$state['operation_id']=(string)$basis['operation_id'];$state['receipt']=$basis;$state['payload_sha256']=(string)($basis['payload_sha256']??'');$state['status']='deployed';}else{$state['status']='rolled-back-after-recovery';}$state['updated_at']=gmdate('c');$this->write_state($state);return $receipt;
		}finally{if(is_array($barrier)&&!$this->is_ambiguous_state((string)($this->read_state()['status']??''))){$this->end_cache_epoch_barrier($barrier);}}
	}

	public function rollback( array $request ) {
		$this->assert_remote_role();
		$this->assert_request_keys( $request, array( 'protocol', 'operation_id', 'expected_site', 'source_operation_id', 'source_receipt_sha256', 'datasets' ) );
		$this->assert_protocol( $request );
		$this->assert_operation_id( $request['operation_id'] );
		$this->assert_operation_id( $request['source_operation_id'] );
		$this->assert_hash( $request['source_receipt_sha256'] );
		$this->assert_site_identity( $request['expected_site'] );
		$datasets = $this->validate_rollback_datasets( $request['datasets'] );
		$source_state=$this->read_state();$source_receipt=$source_state['receipt']??null;
		if('rolled-back'===($source_state['status']??'')&&is_array($source_receipt)&&hash_equals((string)$source_receipt['operation_id'],(string)$request['operation_id'])&&hash_equals((string)$source_receipt['source_operation_id'],(string)$request['source_operation_id'])){return $source_receipt;}
		if('deployed'!==($source_state['status']??'')||!is_array($source_receipt)||!hash_equals((string)$source_receipt['operation_id'],(string)$request['source_operation_id'])||!hash_equals(self::sha256($source_receipt),strtolower($request['source_receipt_sha256']))||self::sha256($this->rollback_contract($source_receipt['datasets']))!==self::sha256($datasets)){throw new RuntimeException('Rollback is not bound to the exact durable deployment receipt.');}
		$this->reserve_remote_operation( $request['operation_id'], 'v4mpg-table-rollback', 'rollback-start' );
		$barrier=null;
		try {
			$this->begin_transaction();
			$restored = array();$prepared=array();
			foreach ( $datasets as $item ) {
				$current = $this->read_active_for_update( $item['project_id'], $item['dataset_id'] );
				if ( (int) $current['active_version_id'] === (int) $item['previous_version_id'] && hash_equals( (string) $current['dataset_sha256'], (string) $item['previous_dataset_sha256'] ) ) {
					$prepared[]=array('item'=>$item,'already_restored'=>true);
					continue;
				}
				if ( (int) $current['active_version_id'] !== (int) $item['new_version_id'] || ! hash_equals( (string) $current['dataset_sha256'], (string) $item['new_dataset_sha256'] ) || ! hash_equals( (string) $current['version_key'], (string) $item['new_version_key'] ) ) {
					throw new RuntimeException( 'Rollback precondition failed: active version is neither the exact deployment nor the exact restored version.' );
				}
				$previous = $this->verify_version_database( $item['previous_version_id'], $item['project_id'], $item['dataset_id'] );
				if ( ! hash_equals( (string) $previous['dataset_sha256'], (string) $item['previous_dataset_sha256'] ) || ! hash_equals( (string) $previous['urls_sha256'], (string) $item['previous_urls_sha256'] ) || (int) $previous['row_count'] !== (int) $item['previous_row_count'] ) {
					throw new RuntimeException( 'Retained previous version no longer matches rollback receipt.' );
				}
				$prepared[]=array('item'=>$item,'already_restored'=>false);
			}
			$this->heartbeat_remote_operation($request['operation_id'],'rollback-candidates-verified',70,array('dataset_count'=>count($prepared)));
			$barrier=$this->begin_cache_epoch_barrier($request['operation_id']);
			foreach($prepared as $entry){$item=$entry['item'];$current=$this->read_active_for_update($item['project_id'],$item['dataset_id']);if($entry['already_restored']){if((int)$current['active_version_id']!==(int)$item['previous_version_id']||!hash_equals((string)$current['dataset_sha256'],(string)$item['previous_dataset_sha256'])){throw new RuntimeException('Rollback idempotency precondition changed before commit.');}}else{if((int)$current['active_version_id']!==(int)$item['new_version_id']||!hash_equals((string)$current['dataset_sha256'],(string)$item['new_dataset_sha256'])||!hash_equals((string)$current['version_key'],(string)$item['new_version_key'])){throw new RuntimeException('Rollback CAS precondition changed before commit.');}$this->switch_pointer($item['project_id'],$item['new_version_id'],$item['previous_version_id'],'rolled_back');}$restored[]=$this->read_active_for_update($item['project_id'],$item['dataset_id']);
			}
			$receipt = array( 'protocol' => self::PROTOCOL_VERSION, 'status' => 'rolled-back', 'operation_id' => $request['operation_id'], 'source_operation_id' => $request['source_operation_id'], 'source_receipt_sha256'=>strtolower($request['source_receipt_sha256']), 'site' => $this->site_identity(), 'datasets' => $restored, 'rolled_back_at' => gmdate( 'c' ) );
			$this->write_state(array('operation_id'=>$request['operation_id'],'status'=>'rollback-prepared-to-commit','receipt'=>$receipt,'source_receipt'=>$source_receipt,'target_mutated'=>true,'updated_at'=>gmdate('c')));
			$this->commit_transaction();
			$this->advance_cache_epoch_barrier($barrier,'rollback-committed');
			$this->write_state(array('operation_id'=>$request['operation_id'],'status'=>'rollback-committed-needs-verify','receipt'=>$receipt,'source_receipt'=>$source_receipt,'target_mutated'=>true,'updated_at'=>gmdate('c')));
			foreach ( $datasets as $index => $item ) {
				$this->assert_expected_matches( $restored[ $index ], array(
					'project_id' => $item['project_id'], 'dataset_id' => $item['dataset_id'],
					'active_version_id' => $item['previous_version_id'], 'dataset_sha256' => $item['previous_dataset_sha256'],
					'urls_sha256' => $item['previous_urls_sha256'], 'row_count' => $item['previous_row_count'],
				) );
			}
			$this->verify_receipt_active($source_receipt['datasets'],'rolled-back');
			$this->clear_targeted_cache( $restored, false );
			$this->assert_derived_unchanged($source_receipt['datasets']);
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'rolled-back-needs-barrier-release', 'receipt' => $receipt, 'source_receipt'=>$source_receipt, 'updated_at' => gmdate( 'c' ) ) );
			$this->end_cache_epoch_barrier($barrier);$barrier=null;
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'rolled-back-needs-runtime-finalize', 'receipt' => $receipt, 'source_receipt'=>$source_receipt, 'updated_at' => gmdate( 'c' ) ) );
			$this->finalize_remote_operation($request['operation_id'],'complete',array('stage'=>'rolled-back','target_mutated'=>false));
			$this->write_state( array( 'operation_id' => $request['operation_id'], 'status' => 'rolled-back', 'receipt' => $receipt, 'source_receipt'=>$source_receipt, 'updated_at' => gmdate( 'c' ) ) );
			return $receipt;
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			if(is_array($barrier)){try{$this->advance_cache_epoch_barrier($barrier,'rollback-transaction-aborted');}catch(Throwable $barrier_error){$error=new RuntimeException($error->getMessage().' | Cache rollback barrier failed: '.$barrier_error->getMessage(),0,$error);}}
			$current=$this->read_state();$status=(string)($current['status']??'');if(in_array($status,array('rolled-back-needs-barrier-release','rolled-back-needs-runtime-finalize','rolled-back'),true)){$current['control_plane_error']=$error->getMessage();$current['updated_at']=gmdate('c');$this->write_state($current);throw $error;}$uncertain=in_array($status,array('rollback-prepared-to-commit','rollback-committed-needs-verify'),true);if(!$uncertain&&is_array($barrier)){$this->end_cache_epoch_barrier($barrier);$barrier=null;}$this->finalize_remote_operation($request['operation_id'],'failed',array('stage'=>'rollback-failed','target_mutated'=>$uncertain,'rollback_required'=>$uncertain,'error'=>$error->getMessage()));
			throw $error;
		} finally { if(is_array($barrier)&&!$this->is_ambiguous_state((string)($this->read_state()['status']??''))){$this->end_cache_epoch_barrier($barrier);} }
	}

	private function deploy_transaction( array $request, array $deployment, &$barrier ) {
		$this->assert_tables_exist();
		$this->begin_transaction();
		$inserted = array();
		$verified = array();
		$receipt = null;
		try {
			foreach ( $deployment['targets'] as $target_index=>$target ) {
				$current = $this->read_active_for_update( $target['project_id'], $target['dataset_id'] );
				$this->assert_expected_matches( $current, $target['expected_previous'] );
				$inserted[] = $this->insert_candidate( $request['operation_id'], $target, $current );
				$this->heartbeat_remote_operation($request['operation_id'],'candidate-verified',30+(int)floor((($target_index+1)/count($deployment['targets']))*40),array('verified_candidates'=>$target_index+1));
			}
			$this->heartbeat_remote_operation($request['operation_id'],'candidates-verified',75,array('candidate_count'=>count($inserted)));
			$barrier=$this->begin_cache_epoch_barrier($request['operation_id']);
			// Repeat the CAS immediately before the atomic pointer switches.
			foreach ( $deployment['targets'] as $index => $target ) {
				$current = $this->read_active_for_update( $target['project_id'], $target['dataset_id'] );
				$this->assert_expected_matches( $current, $target['expected_previous'] );
				$this->switch_pointer( $target['project_id'], $current['active_version_id'], $inserted[ $index ]['new_version_id'], 'rollback' );
			}
			foreach ( $inserted as $item ) {
				$pointer = $this->read_active_for_update( $item['project_id'], $item['dataset_id'] );
				if ( (int) $pointer['active_version_id'] !== (int) $item['new_version_id'] ) { throw new RuntimeException( 'Verified V4MPG version is not the active pointer.' ); }
				$this->assert_expected_matches( $pointer, array( 'project_id'=>$item['project_id'],'dataset_id'=>$item['dataset_id'],'active_version_id'=>$item['new_version_id'],'dataset_sha256'=>$item['new_dataset_sha256'],'urls_sha256'=>$item['new_urls_sha256'],'row_count'=>$item['new_row_count'] ), false );
				$verified[] = $item;
			}
			$receipt=array( 'protocol'=>self::PROTOCOL_VERSION,'status'=>'deployed','operation_id'=>(string)$request['operation_id'],'site'=>$this->site_identity(),'table_prefix'=>(string)$this->wpdb->prefix,'payload_sha256'=>strtolower((string)$request['payload_sha256']),'backup_sha256'=>strtolower((string)$request['backup_sha256']),'release'=>$deployment['release'],'release_sha256'=>self::sha256($deployment['release']),'datasets'=>$verified,'deployed_at'=>gmdate('c') );
			$this->write_state(array('operation_id'=>$request['operation_id'],'status'=>'prepared-to-commit','payload_sha256'=>strtolower((string)$request['payload_sha256']),'receipt'=>$receipt,'target_mutated'=>false,'updated_at'=>gmdate('c')));
			$this->heartbeat_remote_operation($request['operation_id'],'prepared-to-commit',90,array('prepared_receipt_sha256'=>self::sha256($receipt)));
			$this->commit_transaction();
			$this->write_state(array('operation_id'=>$request['operation_id'],'status'=>'committed-needs-postverify','payload_sha256'=>strtolower((string)$request['payload_sha256']),'receipt'=>$receipt,'target_mutated'=>true,'updated_at'=>gmdate('c')));
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			if(is_array($barrier)){try{$this->advance_cache_epoch_barrier($barrier,'deploy-transaction-aborted');}catch(Throwable $barrier_error){$error=new RuntimeException($error->getMessage().' | Cache rollback barrier failed: '.$barrier_error->getMessage(),0,$error);}}
			throw $error;
		}

		return $receipt;
	}

	private function insert_candidate( $operation_id, array $target, array $previous ) {
		$candidate = $target['candidate'];
		if ( ! hash_equals( (string) $previous['urls_sha256'], (string) $candidate['urls_sha256'] ) ) {
			throw new RuntimeException( 'This scoped release accepts content-only candidates; URL changes require a separate migration.' );
		}
		if ( (int) $previous['row_count'] !== (int) $candidate['row_count'] || (int) $previous['column_count'] !== (int) $candidate['column_count'] || ! hash_equals( (string) $previous['header_sha256'], (string) $candidate['header_sha256'] ) ) {
			throw new RuntimeException( 'This scoped release cannot change headers, row count, or column count.' );
		}
		if ( ! hash_equals( (string) $previous['source_sha256'], (string) $candidate['source_sha256'] ) ) { throw new RuntimeException( 'Cloned V4MPG candidates must retain the live source SHA-256.' ); }
		$tables    = $this->tables();
		$key       = 'agsb-' . substr( preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $operation_id ) ), 0, 48 ) . '-' . $target['dataset_id'] . '-' . substr( $candidate['dataset_sha256'], 0, 12 );
		$exists    = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM `{$tables['versions']}` WHERE project_id=%d AND version_key=%s LIMIT 1", $target['project_id'], $key ) );
		if ( $exists ) {
			throw new RuntimeException( 'Remote version key collision; candidate IDs are never reused.' );
		}
		$ok = $this->wpdb->insert( $tables['versions'], array(
			'project_id' => $target['project_id'], 'dataset_id' => $target['dataset_id'], 'version_key' => $key,
			'source_sha256' => $candidate['source_sha256'], 'dataset_sha256' => $candidate['dataset_sha256'],
			'header_sha256' => $candidate['header_sha256'], 'header_json' => $previous['header_json'],
			'urls_sha256' => $candidate['urls_sha256'], 'urls_json' => $previous['urls_json'],
			'url_change_count' => 0, 'row_count' => $candidate['row_count'],
			'column_count' => $candidate['column_count'], 'status' => 'staging', 'created_at' => current_time( 'mysql', true ),
		), array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s' ) );
		if ( false === $ok || (int) $this->wpdb->insert_id < 1 ) {
			throw new RuntimeException( 'Unable to create fresh remote V4MPG version.' );
		}
		$version_id = (int) $this->wpdb->insert_id;
		$cloned = $this->wpdb->query( $this->wpdb->prepare( "INSERT INTO `{$tables['rows']}` (version_id,project_id,row_index,url_path,city,province,row_data,row_sha256) SELECT %d,project_id,row_index,url_path,city,province,row_data,row_sha256 FROM `{$tables['rows']}` WHERE version_id=%d ORDER BY row_index", $version_id, $previous['active_version_id'] ) );
		if ( (int) $cloned !== (int) $candidate['row_count'] ) {
			throw new RuntimeException( 'Remote V4MPG base clone row count mismatch.' );
		}
		foreach ( $this->materialize_delta_rows( $previous, $candidate ) as $row_index => $row ) {
			$updated = $this->wpdb->query( $this->wpdb->prepare( "UPDATE `{$tables['rows']}` SET row_data=%s,row_sha256=%s WHERE version_id=%d AND row_index=%d", $row['row_data'], $row['row_sha256'], $version_id, $row_index ) );
			if ( 1 !== (int) $updated ) { throw new RuntimeException( 'Unable to apply an exact V4MPG delta row.' ); }
		}
		$stored = $this->verify_version_database( $version_id, $target['project_id'], $target['dataset_id'] );
		$this->assert_expected_matches( $stored, array( 'project_id' => $target['project_id'], 'dataset_id' => $target['dataset_id'], 'active_version_id' => $version_id, 'dataset_sha256' => $candidate['dataset_sha256'], 'urls_sha256' => $candidate['urls_sha256'], 'row_count' => $candidate['row_count'] ), false );
		if ( ! hash_equals( $candidate['ordered_digest'], $stored['ordered_digest'] ) || ! hash_equals( $candidate['header_sha256'], $stored['header_sha256'] ) || ! hash_equals( $candidate['source_sha256'], $stored['source_sha256'] ) ) {
			throw new RuntimeException( 'Remote V4MPG candidate verification failed.' );
		}
		if(false===$this->wpdb->update( $tables['versions'], array( 'status' => 'validated', 'validated_at' => current_time( 'mysql', true ) ), array( 'id' => $version_id ), array( '%s','%s' ), array( '%d' ) )){throw new RuntimeException('Unable to mark verified V4MPG candidate as validated.');}
		return array(
			'project_id' => $target['project_id'], 'dataset_id' => $target['dataset_id'],
			'previous_version_id' => $previous['active_version_id'], 'previous_dataset_sha256' => $previous['dataset_sha256'],
			'previous_header_sha256' => $previous['header_sha256'], 'previous_urls_sha256' => $previous['urls_sha256'], 'previous_row_count' => $previous['row_count'],
			'new_version_id' => $version_id, 'new_version_key' => $key, 'new_dataset_sha256' => $candidate['dataset_sha256'],
			'new_header_sha256' => $candidate['header_sha256'], 'new_urls_sha256' => $candidate['urls_sha256'], 'new_row_count' => $candidate['row_count'],
			'ordered_digest' => $stored['ordered_digest'], 'url_paths' => $stored['url_paths'],
			'derived_before' => $this->read_derived_evidence( $target['project_id'], $previous ),
		);
	}

	private function materialize_delta_rows( array $previous, array $candidate ) {
		$headers = json_decode( (string) $previous['header_json'], true );
		if ( ! is_array( $headers ) || count( $headers ) !== (int) $previous['column_count'] ) { throw new RuntimeException( 'Active V4MPG headers are invalid.' ); }
		$columns = array_flip( array_map( 'strval', $headers ) );
		$geo_column = array_search( 'geo_id', array_map( 'strval', $headers ), true );
		if ( false === $geo_column ) { throw new RuntimeException( 'Immutable V4MPG header has no geo_id column.' ); }
		$by_geo = array();
		foreach ( $candidate['declared_changes'] as $change ) {
			if ( ! array_key_exists( $change['field'], $columns ) || ! in_array( $change['field'], self::ALLOWED_CONTENT_FIELDS, true ) ) { throw new RuntimeException( 'Declared V4MPG field is not in the immutable content allowlist.' ); }
			$by_geo[ $change['geo_id'] ][] = $change;
		}
		$geo_ids = array_keys( $by_geo ); sort( $geo_ids, SORT_STRING );
		$tables = $this->tables();
		$placeholders = implode( ',', array_fill( 0, count( $geo_ids ), '%s' ) );
		$sql = $this->wpdb->prepare( "SELECT row_index,row_data,row_sha256 FROM `{$tables['rows']}` WHERE version_id=%d AND JSON_UNQUOTE(JSON_EXTRACT(row_data,'$[" . (int)$geo_column . "]')) IN ({$placeholders}) ORDER BY row_index", array_merge( array( (int)$previous['active_version_id'] ), $geo_ids ) );
		$source_rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $source_rows ) || count( $source_rows ) !== count( $geo_ids ) ) { throw new RuntimeException( 'Declared V4MPG geo_id rows are missing or non-unique in the active version.' ); }
		$result = array(); $applied = 0;
		foreach ( $source_rows as $source ) {
			$index=(int)$source['row_index'];$values=json_decode((string)$source['row_data'],true);
			if(!is_array($values)||count($values)!==count($headers)||!hash_equals(strtolower((string)$source['row_sha256']),hash('sha256',(string)$source['row_data']))){throw new RuntimeException('Active V4MPG delta source row integrity failed.');}
			$geo_id=(string)$values[$geo_column];if(!isset($by_geo[$geo_id])){throw new RuntimeException('Resolved V4MPG geo_id is outside the declaration.');}
			foreach($by_geo[$geo_id] as $change){$column=(int)$columns[$change['field']];$before=$this->content_value_hash($values[$column]);if(!hash_equals($before,$change['before_hash'])){throw new RuntimeException('Live V4MPG before_hash precondition failed.');}if(!hash_equals($this->content_value_hash($change['after']),$change['after_hash'])||$values[$column]===$change['after']){throw new RuntimeException('Declared V4MPG after value is invalid or unchanged.');}$values[$column]=$change['after'];$applied++;}
			$json=wp_json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($json)){throw new RuntimeException('Unable to encode patched V4MPG row.');}$result[$index]=array('row_data'=>$json,'row_sha256'=>hash('sha256',$json));
		}
		if($applied!==(int)$candidate['changed_cell_count']){throw new RuntimeException('Applied V4MPG delta count mismatch.');}
		return $result;
	}

	private function verify_version_database( $version_id, $project_id, $dataset_id ) {
		$stored=$this->read_version($version_id,$project_id,$dataset_id,false);$tables=$this->tables();
		$this->wpdb->query('SET SESSION group_concat_max_len=16777216');
		$sql=$this->wpdb->prepare("SELECT COUNT(*),SUM(CASE WHEN SHA2(row_data,256)<>row_sha256 THEN 1 ELSE 0 END),COUNT(DISTINCT url_path),SHA2(GROUP_CONCAT(CONCAT(CAST(row_index AS CHAR),CHAR(0),url_path,CHAR(0),row_sha256,CHAR(10)) ORDER BY row_index SEPARATOR ''),256) FROM `{$tables['rows']}` WHERE version_id=%d",$version_id);
		$proof=$this->wpdb->get_row($sql,ARRAY_N);if(!is_array($proof)||4!==count($proof)||(int)$proof[0]!==$stored['row_count']||0!==(int)$proof[1]||(int)$proof[2]!==$stored['row_count']){throw new RuntimeException('Remote V4MPG database proof failed.');}
		$stored['ordered_digest']=strtolower((string)$proof[3]);$stored['url_paths']=json_decode((string)$stored['urls_json'],true);
		if(!hash_equals($stored['dataset_sha256'],$stored['ordered_digest'])||!is_array($stored['url_paths'])||count($stored['url_paths'])!==$stored['row_count']){throw new RuntimeException('Remote V4MPG ordered digest proof failed.');}
		return $stored;
	}

	private function switch_pointer( $project_id, $old_version_id, $new_version_id, $old_status ) {
		$tables = $this->tables();
		$changed = $this->wpdb->query( $this->wpdb->prepare( "UPDATE `{$tables['projects']}` SET active_version_id=%d,enabled=1,updated_at=UTC_TIMESTAMP() WHERE project_id=%d AND active_version_id=%d AND enabled=1", $new_version_id, $project_id, $old_version_id ) );
		if ( 1 !== (int) $changed ) {
			throw new RuntimeException( 'Atomic V4MPG pointer compare-and-swap failed.' );
		}
		if ( false === $this->wpdb->update( $tables['versions'], array( 'status' => $old_status ), array( 'id' => $old_version_id ), array( '%s' ), array( '%d' ) ) || false === $this->wpdb->update( $tables['versions'], array( 'status' => 'active', 'activated_at' => current_time( 'mysql', true ) ), array( 'id' => $new_version_id ), array( '%s','%s' ), array( '%d' ) ) ) {
			throw new RuntimeException( 'Unable to update V4MPG version statuses.' );
		}
	}

	private function read_active( $project_id, $dataset_id, $with_rows ) {
		$tables = $this->tables();
		$sql = $this->wpdb->prepare( "SELECT p.project_id,p.active_version_id,p.enabled,v.dataset_id,v.version_key,v.source_sha256,v.dataset_sha256,v.header_sha256,v.header_json,v.urls_sha256,v.urls_json,v.url_change_count,v.row_count,v.column_count,v.status FROM `{$tables['projects']}` p JOIN `{$tables['versions']}` v ON v.id=p.active_version_id WHERE p.project_id=%d AND v.dataset_id=%s LIMIT 1", $project_id, $dataset_id );
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) || 1 !== (int) $row['enabled'] || 'active' !== (string) $row['status'] ) {
			throw new RuntimeException( 'Declared V4MPG project/dataset is not active.' );
		}
		$this->assert_single_active_version( $project_id, $row['active_version_id'] );
		return $this->hydrate_version( $row, $with_rows );
	}

	private function read_active_for_update( $project_id, $dataset_id ) {
		$tables = $this->tables();
		$sql = $this->wpdb->prepare( "SELECT p.project_id,p.active_version_id,p.enabled,v.dataset_id,v.version_key,v.source_sha256,v.dataset_sha256,v.header_sha256,v.header_json,v.urls_sha256,v.urls_json,v.url_change_count,v.row_count,v.column_count,v.status FROM `{$tables['projects']}` p JOIN `{$tables['versions']}` v ON v.id=p.active_version_id WHERE p.project_id=%d AND v.dataset_id=%s LIMIT 1 FOR UPDATE", $project_id, $dataset_id );
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) || 1 !== (int) $row['enabled'] || 'active' !== (string) $row['status'] ) {
			throw new RuntimeException( 'Declared V4MPG project/dataset is not active inside transaction.' );
		}
		$this->assert_single_active_version( $project_id, $row['active_version_id'] );
		return $this->hydrate_version( $row, false );
	}

	private function read_version( $version_id, $project_id, $dataset_id, $with_rows ) {
		$tables = $this->tables();
		$sql = $this->wpdb->prepare( "SELECT project_id,id AS active_version_id,1 AS enabled,dataset_id,version_key,source_sha256,dataset_sha256,header_sha256,header_json,urls_sha256,urls_json,url_change_count,row_count,column_count,status FROM `{$tables['versions']}` WHERE id=%d AND project_id=%d AND dataset_id=%s LIMIT 1", $version_id, $project_id, $dataset_id );
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'Retained V4MPG version is missing.' );
		}
		return $this->hydrate_version( $row, $with_rows );
	}

	private function hydrate_version( array $row, $with_rows ) {
		$row['project_id']       = (int) $row['project_id'];
		$row['active_version_id']= (int) $row['active_version_id'];
		$row['enabled']          = (int) $row['enabled'];
		$row['row_count']        = (int) $row['row_count'];
		$row['column_count']     = (int) $row['column_count'];
		$row['url_change_count'] = (int) $row['url_change_count'];
		if ( ! $with_rows ) {
			return $row;
		}
		$tables = $this->tables();
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT row_index,url_path,city,province,row_data,row_sha256 FROM `{$tables['rows']}` WHERE version_id=%d ORDER BY row_index ASC", $row['active_version_id'] ), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) !== $row['row_count'] ) {
			throw new RuntimeException( 'V4MPG row count does not match version metadata.' );
		}
		$digest = hash_init( 'sha256' );
		$urls   = array();
		foreach ( $rows as &$item ) {
			$item['row_index'] = (int) $item['row_index'];
			if ( ! hash_equals( strtolower( (string) $item['row_sha256'] ), hash( 'sha256', (string) $item['row_data'] ) ) ) {
				throw new RuntimeException( 'Stored V4MPG row hash mismatch.' );
			}
			hash_update( $digest, $item['row_index'] . "\0" . $item['url_path'] . "\0" . $item['row_sha256'] . "\n" );
			$urls[] = (string) $item['url_path'];
		}
		unset( $item );
		$row['ordered_digest'] = hash_final( $digest );
		$row['url_paths']       = $urls;
		$row['rows']            = $rows;
		if ( ! hash_equals( (string) $row['dataset_sha256'], $row['ordered_digest'] ) || ! hash_equals( (string) $row['header_sha256'], hash( 'sha256', (string) $row['header_json'] ) ) || ! hash_equals( (string) $row['urls_sha256'], hash( 'sha256', (string) $row['urls_json'] ) ) || json_decode( (string) $row['urls_json'], true ) !== $urls ) {
			throw new RuntimeException( 'Stored V4MPG version integrity mismatch.' );
		}
		return $row;
	}

	private function export_active( array $active ) {
		return array( 'project' => array( 'project_id' => $active['project_id'], 'active_version_id' => $active['active_version_id'], 'enabled' => $active['enabled'] ), 'version' => array_diff_key( $active, array_flip( array( 'enabled','rows','url_paths','ordered_digest' ) ) ), 'ordered_digest' => $active['dataset_sha256'], 'rows_download' => array( 'mode' => 'signed-pages', 'page_size' => 100, 'row_count' => $active['row_count'] ) );
	}

	private function validate_deployment( $raw ) {
		if ( ! is_array( $raw ) ) {
			throw new RuntimeException( 'Deployment must be an object.' );
		}
		$this->assert_request_keys( $raw, array( 'release', 'targets' ) );
		$targets = $this->validate_targets( $raw['targets'], true );
		$release = $this->validate_release( $raw['release'], $targets );
		return array( 'release' => $release, 'targets' => $targets );
	}

	private function validate_release( $raw, array $targets ) {
		if(!is_array($raw)){throw new RuntimeException('Release evidence must be an object.');}
		$this->assert_request_keys($raw,array('release_id','activation_receipt_sha256','catalog_generation','catalog_sha256','candidate_summary_sha256','authoring_runs','datasets'));
		if(!is_string($raw['release_id'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/',$raw['release_id'])||!is_string($raw['catalog_generation'])||''===$raw['catalog_generation']){throw new RuntimeException('Release identity is invalid.');}
		foreach(array('activation_receipt_sha256','catalog_sha256','candidate_summary_sha256') as $key){$this->assert_hash($raw[$key]);}
		if(!is_array($raw['authoring_runs'])||empty($raw['authoring_runs'])||!self::is_list($raw['authoring_runs'])){throw new RuntimeException('Release authoring evidence is missing.');}
		$runs=array();$seen_runs=array();foreach($raw['authoring_runs'] as $run){$this->assert_request_keys($run,array('run_id','receipt_sha256','patch_manifest_sha256'));$id=(string)$run['run_id'];if(''===$id||isset($seen_runs[$id])){throw new RuntimeException('Release authoring run is invalid or duplicate.');}$this->assert_hash($run['receipt_sha256']);$this->assert_hash($run['patch_manifest_sha256']);$seen_runs[$id]=true;$runs[]=$run;}
		if(!is_array($raw['datasets'])||count($raw['datasets'])!==count($targets)||!self::is_list($raw['datasets'])){throw new RuntimeException('Release dataset evidence does not match target count.');}
		$release_datasets=array();foreach($raw['datasets'] as $dataset){$this->assert_request_keys($dataset,array('dataset_id','final_dataset_sha256','changed_cell_count'));$this->assert_hash($dataset['final_dataset_sha256']);$release_datasets[(string)$dataset['dataset_id']]=$dataset;}
		foreach($targets as $target){$id=$target['dataset_id'];if(!isset($release_datasets[$id])||!hash_equals(strtolower($release_datasets[$id]['final_dataset_sha256']),$target['candidate']['dataset_sha256'])||(int)$release_datasets[$id]['changed_cell_count']!==(int)$target['candidate']['changed_cell_count']){throw new RuntimeException('Release evidence is not bound to the declared dataset candidate.');}}
		return array_merge($raw,array('authoring_runs'=>$runs,'datasets'=>array_values($raw['datasets'])));
	}

	private function validate_targets( $raw, $with_candidate = false ) {
		if ( ! is_array( $raw ) || empty( $raw ) || count( $raw ) > self::MAX_DATASETS || ! self::is_list( $raw ) ) {
			throw new RuntimeException( 'Targets must be a non-empty bounded list.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $raw as $target ) {
			$keys = array( 'project_id', 'dataset_id', 'expected_previous' );
			if ( $with_candidate ) { $keys[] = 'candidate'; }
			$this->assert_request_keys( $target, $keys );
			$project_id = (int) $target['project_id'];
			$dataset_id = (string) $target['dataset_id'];
			if ( $project_id < 1 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $dataset_id ) || isset( $seen[ $project_id ] ) ) {
				throw new RuntimeException( 'Invalid or duplicate V4MPG target.' );
			}
			$this->assert_target_allowed( $project_id, $dataset_id );
			$seen[ $project_id ] = true;
			$expected = $this->validate_expected( $target['expected_previous'], $project_id, $dataset_id );
			$item = array( 'project_id' => $project_id, 'dataset_id' => $dataset_id, 'expected_previous' => $expected );
			if ( $with_candidate ) { $item['candidate'] = $this->validate_candidate( $target['candidate'], $project_id, $dataset_id ); }
			$result[] = $item;
		}
		return $result;
	}

	private function validate_candidate( $raw, $project_id, $dataset_id ) {
		if ( ! is_array( $raw ) ) { throw new RuntimeException( 'Candidate must be an object.' ); }
		$keys = array( 'source_sha256','dataset_sha256','header_sha256','urls_sha256','row_count','column_count','ordered_digest','changed_cell_count','declared_changes' );
		$this->assert_request_keys( $raw, $keys );
		foreach ( array( 'source_sha256','dataset_sha256','header_sha256','urls_sha256','ordered_digest' ) as $key ) { $this->assert_hash( $raw[ $key ] ); }
		if ( isset( $raw['id'] ) || isset( $raw['version_id'] ) || isset( $raw['version_key'] ) ) { throw new RuntimeException( 'Local version identifiers are forbidden.' ); }
		if ( (int)$raw['row_count'] < 1 || (int)$raw['row_count'] > self::MAX_ROWS || (int)$raw['column_count'] < 1 || ! hash_equals( strtolower($raw['dataset_sha256']), strtolower($raw['ordered_digest']) ) ) { throw new RuntimeException( 'Candidate metadata count or digest is invalid.' ); }
		if(!is_array($raw['declared_changes'])||!self::is_list($raw['declared_changes'])||count($raw['declared_changes'])!==(int)$raw['changed_cell_count']||count($raw['declared_changes'])>self::MAX_CHANGES){throw new RuntimeException('Declared cell changes are invalid or exceed the limit.');}
		$declared=array();$seen=array();foreach($raw['declared_changes'] as $change){$this->assert_request_keys($change,array('geo_id','field','before_hash','after_hash','after'));$geo_id=(string)$change['geo_id'];$field=(string)$change['field'];$this->assert_hash($change['before_hash']);$this->assert_hash($change['after_hash']);$key=$geo_id."\0".$field;if(!preg_match('/^[A-Za-z0-9._:-]{1,80}$/',$geo_id)||!in_array($field,self::ALLOWED_CONTENT_FIELDS,true)||isset($seen[$key])||(!is_string($change['after'])&&null!==$change['after'])){throw new RuntimeException('Declared cell change identity/value is invalid or duplicate.');}$seen[$key]=true;$declared[]=array('geo_id'=>$geo_id,'field'=>$field,'before_hash'=>strtolower($change['before_hash']),'after_hash'=>strtolower($change['after_hash']),'after'=>$change['after']);}
		return array_merge( $raw, array( 'declared_changes'=>$declared,'changed_cell_count'=>count($declared),'project_id'=>$project_id,'dataset_id'=>$dataset_id,'row_count'=>(int)$raw['row_count'],'column_count'=>(int)$raw['column_count'] ) );
	}

	private function content_value_hash( $value ) {
		if(null===$value){return hash('sha256',"v4mpg-content-v1\0null");}
		if(!is_string($value)){throw new RuntimeException('V4MPG cell values must be text or null.');}
		return hash('sha256',"v4mpg-content-v1\0text\0".$value);
	}

	private function validate_expected( $raw, $project_id, $dataset_id ) {
		if ( ! is_array( $raw ) ) { throw new RuntimeException( 'Expected state must be an object.' ); }
		$this->assert_request_keys( $raw, array( 'project_id','dataset_id','active_version_id','dataset_sha256','urls_sha256','row_count' ) );
		if ( (int)$raw['project_id'] !== $project_id || (string)$raw['dataset_id'] !== $dataset_id || (int)$raw['active_version_id'] < 1 || (int)$raw['row_count'] < 1 ) { throw new RuntimeException( 'Expected state identity invalid.' ); }
		$this->assert_hash( $raw['dataset_sha256'] ); $this->assert_hash( $raw['urls_sha256'] );
		return array( 'project_id'=>$project_id,'dataset_id'=>$dataset_id,'active_version_id'=>(int)$raw['active_version_id'],'dataset_sha256'=>strtolower($raw['dataset_sha256']),'urls_sha256'=>strtolower($raw['urls_sha256']),'row_count'=>(int)$raw['row_count'] );
	}

	private function validate_expected_datasets( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw ) || count( $raw ) > self::MAX_DATASETS ) { throw new RuntimeException( 'Expected datasets invalid.' ); }
		$result=array(); foreach($raw as $item){ $result[]=$this->validate_expected($item,(int)$item['project_id'],(string)$item['dataset_id']); } return $result;
	}

	private function validate_rollback_datasets( $raw ) {
		$keys=array('project_id','dataset_id','previous_version_id','previous_dataset_sha256','previous_urls_sha256','previous_row_count','new_version_id','new_version_key','new_dataset_sha256');
		if(!is_array($raw)||empty($raw)||count($raw)>self::MAX_DATASETS){throw new RuntimeException('Rollback datasets invalid.');}
		foreach($raw as &$item){$this->assert_request_keys($item,$keys);foreach(array('previous_dataset_sha256','previous_urls_sha256','new_dataset_sha256') as $key){$this->assert_hash($item[$key]);}if((int)$item['project_id']<1||(int)$item['previous_version_id']<1||(int)$item['new_version_id']<1||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',(string)$item['dataset_id'])||''===(string)$item['new_version_key']){throw new RuntimeException('Rollback identity invalid.');}}
		unset($item); return $raw;
	}

	private function assert_expected_matches( array $actual, array $expected, $require_active = true ) {
		foreach(array('project_id','dataset_id','active_version_id','dataset_sha256','urls_sha256','row_count') as $key){if(!array_key_exists($key,$expected)){throw new RuntimeException('Expected state field missing: '.$key);}if((string)$actual[$key] !== (string)$expected[$key]){throw new RuntimeException('Live precondition mismatch for '.$expected['dataset_id'].': '.$key);}}
		if($require_active && (1!==(int)$actual['enabled']||'active'!==(string)$actual['status'])){throw new RuntimeException('Expected version is not active.');}
	}

	private function tables() { $prefix=(string)$this->wpdb->prefix; if(!preg_match('/^[A-Za-z0-9_]+$/',$prefix)){throw new RuntimeException('Unsafe WordPress table prefix.');} return array('versions'=>$prefix.'mpg_runtime_dataset_versions','rows'=>$prefix.'mpg_runtime_dataset_rows','projects'=>$prefix.'mpg_runtime_projects'); }
	private function assert_tables_exist(){
		$required=array('versions'=>array('id','project_id','dataset_id','version_key','dataset_sha256','header_sha256','urls_sha256','row_count','column_count','status'),'rows'=>array('version_id','project_id','row_index','url_path','row_data','row_sha256'),'projects'=>array('project_id','active_version_id','enabled'));
		foreach($this->tables() as $key=>$table){$found=$this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s',$table));if((string)$found!==$table){throw new RuntimeException('Required V4MPG runtime table missing.');}$status=$this->wpdb->get_row($this->wpdb->prepare('SHOW TABLE STATUS LIKE %s',$table),ARRAY_A);if(!is_array($status)||'innodb'!==strtolower((string)($status['Engine']??''))){throw new RuntimeException('V4MPG atomic deployment requires InnoDB tables.');}$columns=$this->wpdb->get_col("SHOW COLUMNS FROM `{$table}`",0);foreach($required[$key] as $column){if(!in_array($column,$columns,true)){throw new RuntimeException('V4MPG runtime schema is missing a required column.');}}}
		$tables=$this->tables();$project_primary=(int)$this->wpdb->get_var("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{$tables['projects']}' AND index_name='PRIMARY' AND column_name='project_id'");$row_primary=(int)$this->wpdb->get_var("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{$tables['rows']}' AND index_name='PRIMARY' AND column_name IN ('version_id','row_index')");if(1!==$project_primary||2!==$row_primary){throw new RuntimeException('V4MPG runtime primary keys are not the expected atomic schema.');}
	}
	private function site_identity(){return array('home_url'=>untrailingslashit(home_url()),'site_url'=>untrailingslashit(site_url()),'home_host'=>strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)));}
	private function assert_site_identity($expected){if(!is_array($expected)||self::sha256($expected)!==self::sha256($this->site_identity())){throw new RuntimeException('Remote site identity mismatch.');}}
	private function assert_remote_role(){if('remote'!==$this->config->get_role()){throw new RuntimeException('V4MPG table deployment is accepted only by the configured remote role.');}}
	private function assert_protocol($request){if((int)($request['protocol']??0)!==self::PROTOCOL_VERSION){throw new RuntimeException('Unsupported V4MPG table protocol.');}}
	private function assert_operation_id($value){if(!is_string($value)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/',$value)){throw new RuntimeException('Invalid operation id.');}}
	private function assert_hash($value){if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/',strtolower($value))){throw new RuntimeException('Invalid SHA-256 value.');}}
	private function assert_request_keys($value,$expected){if(!is_array($value)){throw new RuntimeException('JSON object expected.');}$actual=array_keys($value);sort($actual);sort($expected);if($actual!==$expected){throw new RuntimeException('Unexpected or missing JSON fields.');}}
	private function assert_body_size($value){if(strlen(self::canonical_json($value))>self::MAX_BODY_BYTES){throw new RuntimeException('V4MPG deployment body exceeds limit.');}}
	private function assert_target_allowed($project_id,$dataset_id){$policy=defined('AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS')?(string)AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS:'';$allowed=array_filter(array_map('trim',explode(',',$policy)));if(empty($allowed)||!in_array((int)$project_id.':'.(string)$dataset_id,$allowed,true)){throw new RuntimeException('V4MPG target is not present in the explicit remote allowlist.');}}
	private function target_identity(array $targets){return array_map(static function($target){return array('project_id'=>(int)$target['project_id'],'dataset_id'=>(string)$target['dataset_id'],'expected_previous'=>$target['expected_previous']);},$targets);}
	private function rollback_contract(array $datasets){$keys=array('project_id','dataset_id','previous_version_id','previous_dataset_sha256','previous_urls_sha256','previous_row_count','new_version_id','new_version_key','new_dataset_sha256');return array_map(static function($item)use($keys){return array_intersect_key($item,array_flip($keys));},$datasets);}
	private function is_ambiguous_state($status){return in_array((string)$status,array('prepared-to-commit','committed-needs-postverify','rollback_required','rollback-prepared-to-commit','rollback-committed-needs-verify','deployed-needs-barrier-release','deployed-needs-runtime-finalize','rolled-back-needs-barrier-release','rolled-back-needs-runtime-finalize','recovery-verified-needs-barrier-release','recovery-verified-needs-runtime-finalize'),true);}
	private function verify_receipt_active(array $datasets,$expected_outcome){
		$result=array();foreach($datasets as $item){$rolled_back='rolled-back'===$expected_outcome;$version_id=$rolled_back?(int)$item['previous_version_id']:(int)$item['new_version_id'];$expected=array('project_id'=>(int)$item['project_id'],'dataset_id'=>(string)$item['dataset_id'],'active_version_id'=>$version_id,'dataset_sha256'=>(string)($rolled_back?$item['previous_dataset_sha256']:$item['new_dataset_sha256']),'urls_sha256'=>(string)($rolled_back?$item['previous_urls_sha256']:$item['new_urls_sha256']),'row_count'=>(int)($rolled_back?$item['previous_row_count']:$item['new_row_count']));$pointer=$this->read_active($expected['project_id'],$expected['dataset_id'],false);$this->assert_expected_matches($pointer,$expected);$verified=$this->verify_version_database($version_id,$expected['project_id'],$expected['dataset_id']);$this->assert_expected_matches($verified,$expected);$result[]=$verified;}return $result;
	}
	private function inspect_recovery_outcome(array $datasets,$deep=true){
		$evidence=array();foreach($datasets as $item){$active=$this->read_active((int)$item['project_id'],(string)$item['dataset_id'],false);$evidence[]=array('project_id'=>(int)$item['project_id'],'dataset_id'=>(string)$item['dataset_id'],'active_version_id'=>(int)$active['active_version_id'],'version_key'=>(string)$active['version_key'],'dataset_sha256'=>(string)$active['dataset_sha256'],'urls_sha256'=>(string)$active['urls_sha256'],'row_count'=>(int)$active['row_count']);}$outcome=$this->classify_recovery_evidence($datasets,$evidence);if($deep){foreach($datasets as $index=>$item){$active=$evidence[$index];$is_new='deployed'===$outcome;$verified=$this->verify_version_database((int)$active['active_version_id'],(int)$item['project_id'],(string)$item['dataset_id']);$expected=array('project_id'=>(int)$item['project_id'],'dataset_id'=>(string)$item['dataset_id'],'active_version_id'=>(int)$active['active_version_id'],'dataset_sha256'=>(string)($is_new?$item['new_dataset_sha256']:$item['previous_dataset_sha256']),'urls_sha256'=>(string)($is_new?$item['new_urls_sha256']:$item['previous_urls_sha256']),'row_count'=>(int)($is_new?$item['new_row_count']:$item['previous_row_count']));$this->assert_expected_matches($verified,$expected);}}return array('outcome'=>$outcome,'datasets'=>$evidence);
	}
	private function classify_recovery_evidence(array $datasets,array $evidence){if(empty($datasets)||count($datasets)!==count($evidence)){throw new RuntimeException('Recovery evidence set is incomplete.');}$outcome=null;foreach($datasets as $index=>$item){$active=$evidence[$index];if((int)($active['project_id']??0)!==(int)$item['project_id']||(string)($active['dataset_id']??'')!==(string)$item['dataset_id']){throw new RuntimeException('Recovery evidence identity mismatch.');}$is_new=(int)$active['active_version_id']===(int)$item['new_version_id']&&hash_equals((string)$active['dataset_sha256'],(string)$item['new_dataset_sha256'])&&hash_equals((string)$active['version_key'],(string)$item['new_version_key'])&&hash_equals((string)$active['urls_sha256'],(string)$item['new_urls_sha256'])&&(int)$active['row_count']===(int)$item['new_row_count'];$is_old=(int)$active['active_version_id']===(int)$item['previous_version_id']&&hash_equals((string)$active['dataset_sha256'],(string)$item['previous_dataset_sha256'])&&hash_equals((string)$active['urls_sha256'],(string)$item['previous_urls_sha256'])&&(int)$active['row_count']===(int)$item['previous_row_count'];if($is_new===$is_old){throw new RuntimeException('Recovery found a pointer outside the exact old/new receipt states.');}$item_outcome=$is_new?'deployed':'rolled-back';if(null!==$outcome&&$outcome!==$item_outcome){throw new RuntimeException('Recovery found a mixed multi-dataset pointer outcome and refuses to guess.');}$outcome=$item_outcome;}return $outcome;}
	private function finalize_recovered_runtime($operation_id,$outcome){$operation=$this->runtime->inspect();if(is_wp_error($operation)){throw new RuntimeException($operation->get_error_message());}if(empty($operation)){return;}if(!hash_equals((string)($operation['id']??''),(string)$operation_id)){throw new RuntimeException('Another remote operation owns the recovery control plane.');}$status=(string)($operation['status']??'');if(in_array($status,array('complete','reconciled','cancelled','failed','error'),true)){return;}if('rollback_required'===$status){$verification=array('note'=>'Exact V4MPG pointer and full dataset digest verified by signed recovery.','target_integrity_verified'=>'deployed'===$outcome,'rollback_verified'=>'rolled-back'===$outcome);$resolved=$this->runtime->resolve_recovery($operation_id,(string)($operation['kind']??''),(string)($operation['updated_at']??''),$verification);if(is_wp_error($resolved)){throw new RuntimeException($resolved->get_error_message());}return;}$this->finalize_remote_operation($operation_id,'complete',array('stage'=>'v4mpg-recovered','target_mutated'=>false,'recovery_outcome'=>$outcome));}
	private function begin_transaction(){if(false===$this->wpdb->query('START TRANSACTION')){throw new RuntimeException('Unable to start the V4MPG transaction.');}}
	private function commit_transaction(){if(false===$this->wpdb->query('COMMIT')){throw new RuntimeException('Unable to commit the V4MPG transaction.');}}

	private function read_state(){if(!is_file($this->state_file)){return array();}$raw=file_get_contents($this->state_file);if(false===$raw){throw new RuntimeException('Unable to read the existing V4MPG operation journal.');}$decoded=json_decode($raw,true);if(!is_array($decoded)||JSON_ERROR_NONE!==json_last_error()){throw new RuntimeException('Existing V4MPG operation journal is corrupt; recovery is required.');}return $decoded;}
	private function write_state(array $state){$dir=dirname($this->state_file);if(!is_dir($dir)){wp_mkdir_p($dir);}$tmp=$this->state_file.'.'.wp_generate_uuid4().'.tmp';$handle=fopen($tmp,'x+b');if(false===$handle){throw new RuntimeException('Unable to create V4MPG operation journal.');}try{$bytes=self::canonical_json($state);if(fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle)||(function_exists('fsync')&&!fsync($handle))){throw new RuntimeException('Unable to durably flush V4MPG operation journal.');}}finally{fclose($handle);}if(!rename($tmp,$this->state_file)){@unlink($tmp);throw new RuntimeException('Unable to persist V4MPG operation state.');}}

	private function clear_targeted_cache(array $datasets,$purge_page_cache=true){
		$deleted=0;
		$prefix=(string)$this->wpdb->prefix;$db_cache_tables=array($prefix.'mpg_cache',$prefix.'mpg_dataset_rows');
		foreach($datasets as $item){$project_id=(int)$item['project_id'];wp_cache_delete('runtime_project_'.$project_id,'mpg');wp_cache_delete('project_'.$project_id,'mpg');foreach($db_cache_tables as $table){$exists=$this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s',$table));if((string)$exists===$table){if(false===$this->wpdb->delete($table,array('project_id'=>$project_id),array('%d'))||(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE project_id=%d",$project_id))!==0){throw new RuntimeException('Targeted V4MPG database-cache cleanup failed.');}}}$urls=$item['url_paths']??json_decode((string)($item['urls_json']??'[]'),true);if(function_exists('di_wordpress_runtime_cache_dir')&&function_exists('di_wordpress_runtime_cache_path_from_url')&&function_exists('di_wordpress_runtime_cache_delete_entry')){foreach((array)$urls as $url){$path=di_wordpress_runtime_cache_path_from_url($url);$file=trailingslashit(di_wordpress_runtime_cache_dir()).(''===$path?'index.html':$path.'/index.html');$existed=is_file($file)||is_file(dirname($file).'/index.meta.json');di_wordpress_runtime_cache_delete_entry($file);if(is_file($file)||is_file(dirname($file).'/index.meta.json')){throw new RuntimeException('Targeted page-cache cleanup could not be verified.');}if($existed){$deleted++;}}}}
		if($purge_page_cache&&function_exists('di_full_page_cache_flush')){di_full_page_cache_flush();if(function_exists('di_full_page_cache_dir')){$root=di_full_page_cache_dir();if(is_dir($root)){$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($iterator as $entry){if($entry->isFile()&&preg_match('/\.(?:html|json)$/i',$entry->getFilename())){throw new RuntimeException('Full-page cache flush could not be verified.');}}}}}
		do_action('ag_sync_bridge_v4mpg_table_switched',$datasets);
		return array('scope'=>'declared-v4mpg-urls','verified_deleted_entries'=>$deleted,'url_count'=>array_sum(array_map(static function($item){return count($item['url_paths']??array());},$datasets)));
	}

	private function read_derived_evidence( $project_id, array $active ) {
		$project_table = (string) $this->wpdb->prefix . 'mpg_projects';
		$location_table = (string) $this->wpdb->prefix . 'mpg_location_index';
		foreach ( array( $project_table, $location_table ) as $table ) {
			$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( (string) $found !== $table ) { throw new RuntimeException( 'Required read-only V4MPG derived table is missing.' ); }
		}
		$project = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id,headers,urls_array,updated_at,sitemap_filename FROM `{$project_table}` WHERE id=%d LIMIT 1", $project_id ), ARRAY_A );
		if ( ! is_array( $project ) || ! is_string( $project['headers'] ) || ! is_string( $project['urls_array'] ) || ! hash_equals( (string) $active['header_sha256'], hash( 'sha256', $project['headers'] ) ) || ! hash_equals( (string) $active['urls_sha256'], hash( 'sha256', $project['urls_array'] ) ) ) {
			throw new RuntimeException( 'Primary MPG project URL index diverges from the active runtime version.' );
		}
		$locations = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id,dataset_token,province,city,url FROM `{$location_table}` WHERE project_id=%d ORDER BY id ASC", $project_id ), ARRAY_A );
		if ( ! is_array( $locations ) || empty( $locations ) ) { throw new RuntimeException( 'Location index evidence is missing for the declared project.' ); }
		$location_digest = hash_init( 'sha256' );
		$location_urls = array();
		foreach ( $locations as $row ) {
			hash_update( $location_digest, (int)$row['id']."\0".$row['dataset_token']."\0".$row['province']."\0".$row['city']."\0".$row['url']."\n" );
			$location_urls[(string)$row['url']] = true;
		}
		$filename = sanitize_file_name( (string) $project['sitemap_filename'] );
		$sitemap_path = '' === $filename ? '' : ABSPATH . $filename . ( '.xml' === substr( $filename, -4 ) ? '' : '.xml' );
		return array(
			'project_headers_sha256' => hash( 'sha256', $project['headers'] ),
			'project_urls_sha256' => hash( 'sha256', $project['urls_array'] ),
			'project_updated_at' => (int) $project['updated_at'],
			'location_row_count' => count( $locations ),
			'location_distinct_url_count' => count( $location_urls ),
			'location_sha256' => hash_final( $location_digest ),
			'sitemap_filename' => $filename,
			'sitemap_exists' => '' !== $sitemap_path && is_file( $sitemap_path ),
			'sitemap_sha256' => '' !== $sitemap_path && is_file( $sitemap_path ) ? hash_file( 'sha256', $sitemap_path ) : '',
		);
	}

	private function assert_derived_unchanged( array $datasets ) {
		foreach ( $datasets as $item ) {
			$active = $this->read_active( $item['project_id'], $item['dataset_id'], false );
			$after = $this->read_derived_evidence( $item['project_id'], $active );
			if ( self::sha256( $after ) !== self::sha256( $item['derived_before'] ) ) { throw new RuntimeException( 'Read-only MPG project/index/sitemap evidence changed unexpectedly.' ); }
		}
	}

	private function restore_receipt_exact( array $receipt ) {
		$this->begin_transaction();
		try {
			foreach ( $receipt['datasets'] as $item ) {
				$current = $this->read_active_for_update( $item['project_id'], $item['dataset_id'] );
				if ( (int)$current['active_version_id'] !== (int)$item['new_version_id'] || !hash_equals($current['dataset_sha256'],$item['new_dataset_sha256']) ) { throw new RuntimeException( 'Automatic restore CAS precondition failed.' ); }
				$this->switch_pointer( $item['project_id'], $item['new_version_id'], $item['previous_version_id'], 'rolled_back' );
			}
			$this->commit_transaction();
		} catch ( Throwable $error ) { $this->wpdb->query( 'ROLLBACK' ); throw $error; }
		foreach ( $receipt['datasets'] as $item ) { $active=$this->read_active($item['project_id'],$item['dataset_id'],false);$this->assert_expected_matches($active,array('project_id'=>$item['project_id'],'dataset_id'=>$item['dataset_id'],'active_version_id'=>$item['previous_version_id'],'dataset_sha256'=>$item['previous_dataset_sha256'],'urls_sha256'=>$item['previous_urls_sha256'],'row_count'=>$item['previous_row_count'])); }
	}

	private function assert_single_active_version( $project_id, $active_version_id ) {
		$tables = $this->tables();
		$count = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM `{$tables['versions']}` WHERE project_id=%d AND status='active' AND id=%d", $project_id, $active_version_id ) );
		$total = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM `{$tables['versions']}` WHERE project_id=%d AND status='active'", $project_id ) );
		if ( 1 !== (int) $count || 1 !== (int) $total ) { throw new RuntimeException( 'V4MPG runtime has an ambiguous active version.' ); }
	}

	private static function is_list(array $value){if(function_exists('array_is_list')){return array_is_list($value);}return array() === $value || array_keys($value)===range(0,count($value)-1);}
	private function reserve_remote_operation($operation_id,$kind,$stage){$reserved=$this->runtime->reserve($kind,array('id'=>(string)$operation_id,'stage'=>$stage,'progress'=>0,'target_mutated'=>false));if(is_wp_error($reserved)){throw new RuntimeException($reserved->get_error_message());}$claimed=$this->runtime->claim($operation_id);if(is_wp_error($claimed)){throw new RuntimeException($claimed->get_error_message());}}
	private function heartbeat_remote_operation($operation_id,$stage,$progress,array $changes=array()){$result=$this->runtime->heartbeat($operation_id,$stage,$progress,$changes);if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}}
	private function finalize_remote_operation($operation_id,$status,array $changes=array()){$result=$this->runtime->finalize($operation_id,$status,$changes);if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}}
	private function begin_cache_epoch_barrier($operation_id){if(!function_exists('di_cache_epoch_barrier_begin')||!function_exists('di_cache_epoch_barrier_bump')||!function_exists('di_cache_epoch_barrier_end')){throw new RuntimeException('Required exclusive cache epoch barrier API is unavailable; V4MPG mutation is blocked.');}$token=di_cache_epoch_barrier_begin((string)$operation_id);if(!is_array($token)){throw new RuntimeException('Unable to acquire the exclusive cache epoch barrier.');}return $token;}
	private function advance_cache_epoch_barrier(array &$barrier,$reason){$after=di_cache_epoch_barrier_bump($barrier,(string)$reason);if(!is_string($after)||''===$after){throw new RuntimeException('Cache epoch barrier advance failed after '.$reason.'.');}return $after;}
	private function end_cache_epoch_barrier($barrier){if(!is_array($barrier)||!function_exists('di_cache_epoch_barrier_end')||true!==di_cache_epoch_barrier_end($barrier)){throw new RuntimeException('Unable to release the exclusive cache epoch barrier safely.');}return true;}
}
