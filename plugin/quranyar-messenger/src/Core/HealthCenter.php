<?php
if (!defined('ABSPATH')) exit;

/**
 * Production health telemetry for QuranYar. Stores aggregate operational data
 * only; no member identifiers, message bodies, tokens, or raw network errors.
 */
final class QYS_Health_Center {
    private const HEARTBEAT_OPTION='qys_worker_heartbeat';
    private const INCIDENTS_OPTION='qys_health_incidents';
    private const SIGNATURE_OPTION='qys_health_signature';
    private const LOCK_OPTION='qys_batch_lock';
    private const STALE_WORKER_SECONDS=180;
    private const STALE_LOCK_SECONDS=120;
    private const STALE_SENDING_SECONDS=180;
    private const CRON_WARNING_SECONDS=90;
    private const CRON_CRITICAL_SECONDS=180;
    private const INCIDENT_LIMIT=50;
    private const SNAPSHOT_HOOK='qys_health_snapshot';
    private const SNAPSHOT_SCHEDULE='qys_health_15m';
    private const SNAPSHOT_INTERVAL_SECONDS=900;
    private const SNAPSHOT_META_OPTION='qys_health_snapshot_meta';

    private static $started_micro=null;
    private static $run_id='';
    private static $run_result='ok';

    public static function init(): void {
        add_action('qys_send_batch',[__CLASS__,'heartbeat_start'],0);
        add_action('qys_send_batch',[__CLASS__,'heartbeat_finish'],999);
        add_filter('cron_schedules',[__CLASS__,'cron_schedules']);
        add_action('init',[__CLASS__,'ensure_snapshot_schedule'],25);
        add_action(self::SNAPSHOT_HOOK,[__CLASS__,'scheduled_snapshot'],10);
        add_action('admin_menu',[__CLASS__,'menu'],30);
        add_action('wp_abilities_api_init',[__CLASS__,'register_ability']);
    }

    public static function heartbeat_start(): void {
        self::$started_micro=microtime(true);
        self::$run_id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():uniqid('qys_',true);
        self::$run_result='ok';
        $now=time();
        $heartbeat=get_option(self::HEARTBEAT_OPTION,[]);
        if(!is_array($heartbeat))$heartbeat=[];
        $heartbeat['last_started_utc']=gmdate('c',$now);
        $heartbeat['last_started_epoch']=$now;
        $heartbeat['last_source']=self::run_source();
        $heartbeat['last_run_id']=self::$run_id;
        $heartbeat['last_result']='running';
        update_option(self::HEARTBEAT_OPTION,$heartbeat,false);
    }

    public static function mark_result(string $result): void {
        $allowed=['ok','locked','missing_webapp_token','tables_not_ready','tenant_metadata_hold','schema_not_ready','recovered_stale_lock','recovered_stale_sending','budget_exhausted','rate_limited','network_uncertain'];
        self::$run_result=in_array($result,$allowed,true)?$result:'ok';
    }

    public static function heartbeat_finish(): void {
        $now=time();
        $heartbeat=get_option(self::HEARTBEAT_OPTION,[]);
        if(!is_array($heartbeat))$heartbeat=[];
        // Do not let an older overlapping request overwrite a newer run's start metadata.
        if(self::$run_id!=='' && isset($heartbeat['last_run_id']) && $heartbeat['last_run_id']!==self::$run_id){
            return;
        }
        $duration=self::$started_micro!==null?(int)round((microtime(true)-self::$started_micro)*1000):0;
        $heartbeat['last_finished_utc']=gmdate('c',$now);
        $heartbeat['last_finished_epoch']=$now;
        $heartbeat['last_duration_ms']=max(0,$duration);
        $heartbeat['last_result']=self::$run_result;
        update_option(self::HEARTBEAT_OPTION,$heartbeat,false);
    }

    public static function cron_schedules(array $schedules): array {
        if(!isset($schedules[self::SNAPSHOT_SCHEDULE])){
            $schedules[self::SNAPSHOT_SCHEDULE]=[
                'interval'=>self::SNAPSHOT_INTERVAL_SECONDS,
                'display'=>'Every 15 minutes (QuranYar health snapshot)',
            ];
        }
        return $schedules;
    }

    public static function ensure_snapshot_schedule(): void {
        if(wp_next_scheduled(self::SNAPSHOT_HOOK)!==false)return;
        wp_schedule_event(time()+self::SNAPSHOT_INTERVAL_SECONDS,self::SNAPSHOT_SCHEDULE,self::SNAPSHOT_HOOK);
    }

    public static function scheduled_snapshot(): void {
        $started=microtime(true);
        $snapshot=self::snapshot(true);
        $now=time();
        update_option(self::SNAPSHOT_META_OPTION,[
            'last_run_utc'=>gmdate('c',$now),
            'last_run_epoch'=>$now,
            'last_duration_ms'=>max(0,(int)round((microtime(true)-$started)*1000)),
            'last_status'=>(string)($snapshot['status']??'unknown'),
        ],false);
    }

    public static function snapshot_contract(): array {
        return [
            'ready'=>true,
            'heartbeat'=>'lightweight',
            'full'=>'periodic_on_demand',
            'interval_seconds'=>self::SNAPSHOT_INTERVAL_SECONDS,
            'worker_full_snapshot'=>false,
        ];
    }

    public static function snapshot_schedule_status(): array {
        $meta=get_option(self::SNAPSHOT_META_OPTION,[]);
        if(!is_array($meta))$meta=[];
        $next=wp_next_scheduled(self::SNAPSHOT_HOOK);
        $last=max(0,(int)($meta['last_run_epoch']??0));
        return array_merge(self::snapshot_contract(),[
            'scheduled'=>$next!==false,
            'next_utc'=>$next!==false?gmdate('c',(int)$next):'',
            'last_run_utc'=>(string)($meta['last_run_utc']??''),
            'last_run_age_seconds'=>$last>0?max(0,time()-$last):-1,
            'last_duration_ms'=>max(0,(int)($meta['last_duration_ms']??0)),
            'last_status'=>(string)($meta['last_status']??'never'),
        ]);
    }

    private static function run_source(): string {
        if(function_exists('wp_doing_cron') && wp_doing_cron())return 'cron';
        if(defined('DOING_CRON') && DOING_CRON)return 'cron';
        if(defined('WP_CLI') && WP_CLI)return 'cli';
        return 'manual';
    }

    public static function menu(): void {
        if(!QYS_Security_Service::is_global_admin() || !class_exists('QYS_Tenant_Context') || QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL)return;
        add_submenu_page('qys-messaging','سلامت سیستم','سلامت سیستم','manage_options','qys-health',[__CLASS__,'admin'],90);
    }

    public static function snapshot(bool $record_incidents=false): array {
        $now=time();
        $heartbeat=get_option(self::HEARTBEAT_OPTION,[]);
        if(!is_array($heartbeat))$heartbeat=[];
        $last_started=max(0,(int)($heartbeat['last_started_epoch']??0));
        $last_finished=max(0,(int)($heartbeat['last_finished_epoch']??0));
        $worker_age=$last_finished>0?max(0,$now-$last_finished):($last_started>0?max(0,$now-$last_started):-1);

        $lock_started=max(0,(int)get_option(self::LOCK_OPTION,0));
        $lock_active=$lock_started>0;
        $lock_age=$lock_active?max(0,$now-$lock_started):0;
        $lock_stale=$lock_active && $lock_age>self::STALE_LOCK_SECONDS;

        $next=wp_next_scheduled('qys_send_batch');
        $cron_scheduled=$next!==false;
        $cron_lateness=$cron_scheduled?max(0,$now-(int)$next):-1;

        // When the upgrade gate is closed, Health Center must remain usable
        // without touching domain tables that may be mid-migration. Only safe
        // option/cron/ledger telemetry is read until Installer::ready() is true.
        $installer_ready=!class_exists('QYS_Installer') || QYS_Installer::ready();
        if($installer_ready){
            $queue=QYS_Campaign_Repository::health_metrics();
            $ready=QYS_Messenger::readiness();
            $birthday=QYS_Messenger::birthday_readiness();
            $welcome=QYS_Messenger::welcome_log_ready()?QYS_Messenger::onboarding_delivery_counts('welcome'):[];
            $activation=QYS_Messenger::activation_log_ready()?QYS_Messenger::onboarding_delivery_counts('activation'):[];
        }else{
            $queue=[];$birthday=[];$welcome=[];$activation=[];
            $ready=[
                'schema_version'=>(string)get_option('qys_schema_version',''),
                'webapp_token_configured'=>QYS_Token_Store::secret('webapp')!=='',
                'delivery_token_configured'=>QYS_Token_Store::secret('delivery')!=='',
            ];
        }
        $retention=class_exists('QYS_Retention_Service')?QYS_Retention_Service::status():[];
        $migrations=class_exists('QYS_Migration_Ledger')?QYS_Migration_Ledger::status():[];
        $upgrade=class_exists('QYS_Upgrade_Coordinator')?QYS_Upgrade_Coordinator::status():[];
        $modules=class_exists('QYS_Module_Manager')?QYS_Module_Manager::status():[];
        $registry=class_exists('QYS_Module_Registry')?QYS_Module_Registry::health():[];

        $issues=[];$status='healthy';
        if((string)($ready['schema_version']??'')!==QYS_Config::SCHEMA_VERSION)$issues[]='schema_mismatch';
        if(empty($ready['webapp_token_configured']))$issues[]='webapp_token_missing';
        if(!$cron_scheduled)$issues[]='cron_unscheduled';
        elseif($cron_lateness>self::CRON_CRITICAL_SECONDS)$issues[]='cron_overdue_critical';
        elseif($cron_lateness>self::CRON_WARNING_SECONDS)$issues[]='cron_overdue_warning';

        if($last_finished===0){
            if($last_started>0 && $worker_age>self::STALE_WORKER_SECONDS)$issues[]='worker_not_finishing';
            else $issues[]='worker_awaiting_first_finish';
        } elseif($worker_age>self::STALE_WORKER_SECONDS)$issues[]='worker_stale';

        if($lock_stale)$issues[]='batch_lock_stale';
        $worker_result=(string)($heartbeat['last_result']??'never');
        if($worker_result==='recovered_stale_lock')$issues[]='batch_lock_recovered';
        if($worker_result==='recovered_stale_sending')$issues[]='queue_sending_recovered';
        if($worker_result==='tenant_metadata_hold')$issues[]='tenant_metadata_hold';
        if($worker_result==='tables_not_ready')$issues[]='queue_tables_not_ready';
        if($worker_result==='rate_limited')$issues[]='queue_rate_limited';
        if($worker_result==='network_uncertain')$issues[]='queue_network_uncertain';
        if((int)($queue['sending_lease_expired']??0)>0)$issues[]='queue_sending_lease_expired';
        if((int)($queue['sending_unleased']??0)>0 && (int)($queue['oldest_sending_age_seconds']??0)>self::STALE_SENDING_SECONDS)$issues[]='queue_sending_unleased_stale';
        if(!empty($birthday['enabled']) && (empty($birthday['profile_schema_ready']) || empty($birthday['birthday_log_ready']) || empty($birthday['archive_ready'])))$issues[]='birthday_not_ready';
        if((int)($welcome['sending_stale']??0)>0)$issues[]='welcome_delivery_stale';
        if((int)($activation['sending_stale']??0)>0)$issues[]='activation_delivery_stale';
        if(empty($retention['scheduled']))$issues[]='retention_unscheduled';
        if((string)($retention['last_result']??'never')==='error')$issues[]='retention_cleanup_failed';
        $retention_age=(int)($retention['last_run_age_seconds']??-1);$retention_init_age=(int)($retention['initialized_age_seconds']??-1);
        if(($retention_age>172800)||($retention_age<0&&$retention_init_age>172800))$issues[]='retention_cleanup_stale';
        if(empty($migrations['initialized']))$issues[]='migration_ledger_uninitialized';
        elseif((int)($migrations['failed']??0)>0)$issues[]='migration_failed';
        elseif(!empty($migrations['running_stale']))$issues[]='migration_running_stale';
        elseif((int)($migrations['pending']??0)>0 || (int)($migrations['running']??0)>0)$issues[]='migration_pending';
        if(empty($upgrade['ready']))$issues[]='upgrade_gate_closed';
        if(in_array((string)($upgrade['last_result']??''),['migration_failed','ledger_save_failed'],true))$issues[]='upgrade_runner_failed';
        if((int)($modules['critical_failed']??0)>0)$issues[]='module_critical_failed';
        elseif((int)($modules['degraded']??0)>0)$issues[]='module_degraded';

        $critical=['schema_mismatch','migration_ledger_uninitialized','webapp_token_missing','cron_unscheduled','cron_overdue_critical','worker_not_finishing','worker_stale','batch_lock_stale','queue_sending_lease_expired','queue_sending_unleased_stale','queue_tables_not_ready','welcome_delivery_stale','activation_delivery_stale','migration_failed','migration_running_stale','upgrade_gate_closed','upgrade_runner_failed','module_critical_failed'];
        foreach($issues as $issue){if(in_array($issue,$critical,true)){$status='critical';break;}}
        if($status==='healthy' && $issues)$status='warning';

        $out=[
            'status'=>$status,
            'checked_at_utc'=>gmdate('c',$now),
            'issues'=>array_values($issues),
            'worker_last_started_utc'=>(string)($heartbeat['last_started_utc']??''),
            'worker_last_finished_utc'=>(string)($heartbeat['last_finished_utc']??''),
            'worker_age_seconds'=>$worker_age,
            'worker_duration_ms'=>max(0,(int)($heartbeat['last_duration_ms']??0)),
            'worker_result'=>$worker_result,
            'worker_source'=>(string)($heartbeat['last_source']??'unknown'),
            'lock_active'=>$lock_active,
            'lock_age_seconds'=>$lock_age,
            'lock_stale'=>$lock_stale,
            'cron_scheduled'=>$cron_scheduled,
            'cron_next_utc'=>$cron_scheduled?gmdate('c',(int)$next):'',
            'cron_lateness_seconds'=>$cron_lateness,
            'queue_pending'=>max(0,(int)($queue['pending']??0)),
            'queue_sending'=>max(0,(int)($queue['sending']??0)),
            'queue_failed'=>max(0,(int)($queue['failed']??0)),
            'queue_retryable_failed'=>max(0,(int)($queue['retryable_failed']??0)),
            'queue_definitive_failed'=>max(0,(int)($queue['definitive_failed']??0)),
            'queue_uncertain'=>max(0,(int)($queue['uncertain']??0)),
            'queue_legacy_failed'=>max(0,(int)($queue['legacy_failed']??0)),
            'queue_sent'=>max(0,(int)($queue['sent']??0)),
            'welcome_pending'=>max(0,(int)($welcome['pending']??0)),
            'welcome_sending'=>max(0,(int)($welcome['sending']??0)),
            'welcome_sent'=>max(0,(int)($welcome['sent']??0)),
            'welcome_retryable_failed'=>max(0,(int)($welcome['retryable_failed']??0)),
            'welcome_definitive_failed'=>max(0,(int)($welcome['definitive_failed']??0)),
            'welcome_uncertain'=>max(0,(int)($welcome['uncertain']??0)),
            'welcome_sending_stale'=>max(0,(int)($welcome['sending_stale']??0)),
            'activation_pending'=>max(0,(int)($activation['pending']??0)),
            'activation_sending'=>max(0,(int)($activation['sending']??0)),
            'activation_sent'=>max(0,(int)($activation['sent']??0)),
            'activation_retryable_failed'=>max(0,(int)($activation['retryable_failed']??0)),
            'activation_definitive_failed'=>max(0,(int)($activation['definitive_failed']??0)),
            'activation_uncertain'=>max(0,(int)($activation['uncertain']??0)),
            'activation_sending_stale'=>max(0,(int)($activation['sending_stale']??0)),
            'queue_sending_lease_active'=>max(0,(int)($queue['sending_lease_active']??0)),
            'queue_sending_lease_expired'=>max(0,(int)($queue['sending_lease_expired']??0)),
            'queue_sending_unleased'=>max(0,(int)($queue['sending_unleased']??0)),
            'oldest_pending_age_seconds'=>(int)($queue['oldest_pending_age_seconds']??-1),
            'oldest_active_pending_age_seconds'=>(int)($queue['oldest_active_pending_age_seconds']??-1),
            'oldest_sending_age_seconds'=>(int)($queue['oldest_sending_age_seconds']??-1),
            'oldest_lease_overdue_seconds'=>(int)($queue['oldest_lease_overdue_seconds']??-1),
            'schema_version'=>(string)($ready['schema_version']??''),
            'schema_ready'=>(string)($ready['schema_version']??'')===QYS_Config::SCHEMA_VERSION,
            'webapp_token_configured'=>!empty($ready['webapp_token_configured']),
            'delivery_token_configured'=>!empty($ready['delivery_token_configured']),
            'birthday_enabled'=>!empty($birthday['enabled']),
            'birthday_ready'=>empty($birthday['enabled']) || (!empty($birthday['profile_schema_ready']) && !empty($birthday['birthday_log_ready']) && !empty($birthday['archive_ready'])),
            'retention_policy_version'=>(string)($retention['policy_version']??''),
            'retention_scheduled'=>!empty($retention['scheduled']),
            'retention_next_utc'=>(string)($retention['next_utc']??''),
            'retention_last_run_utc'=>(string)($retention['last_run_utc']??''),
            'retention_age_seconds'=>(int)($retention['last_run_age_seconds']??-1),
            'retention_last_result'=>(string)($retention['last_result']??'never'),
            'retention_last_duration_ms'=>max(0,(int)($retention['last_duration_ms']??0)),
            'retention_last_changed'=>max(0,(int)($retention['last_changed']??0)),
            'migration_initialized'=>!empty($migrations['initialized']),
            'migration_required'=>!empty($migrations['required']),
            'migration_ready'=>!empty($migrations['ready']),
            'migration_total'=>max(0,(int)($migrations['total']??0)),
            'migration_pending'=>max(0,(int)($migrations['pending']??0)),
            'migration_running'=>max(0,(int)($migrations['running']??0)),
            'migration_completed'=>max(0,(int)($migrations['completed']??0)),
            'migration_failed'=>max(0,(int)($migrations['failed']??0)),
            'migration_running_stale'=>!empty($migrations['running_stale']),
            'migration_last_completed_id'=>(string)($migrations['last_completed_id']??''),
            'migration_last_completed_at_utc'=>(string)($migrations['last_completed_at_utc']??''),
            'migration_current_id'=>(string)($migrations['current_id']??''),
            'upgrade_ready'=>!empty($upgrade['ready']),
            'upgrade_automatic_enabled'=>!empty($upgrade['automatic_enabled']),
            'upgrade_last_attempt_utc'=>(string)($upgrade['last_attempt_utc']??''),
            'upgrade_last_attempt_age_seconds'=>(int)($upgrade['last_attempt_age_seconds']??-1),
            'upgrade_last_result'=>(string)($upgrade['last_result']??'never'),
            'upgrade_last_ran'=>max(0,(int)($upgrade['last_ran']??0)),
            'upgrade_remaining'=>max(0,(int)($upgrade['remaining']??0)),
            'upgrade_last_duration_ms'=>max(0,(int)($upgrade['last_duration_ms']??0)),
            'module_ready'=>!empty($modules['ready']),
            'module_boot_complete'=>!empty($modules['boot_complete']),
            'module_total'=>max(0,(int)($modules['total']??0)),
            'module_booted'=>max(0,(int)($modules['booted']??0)),
            'module_failed'=>max(0,(int)($modules['failed']??0)),
            'module_blocked'=>max(0,(int)($modules['blocked']??0)),
            'module_critical_failed'=>max(0,(int)($modules['critical_failed']??0)),
            'module_health_degraded'=>max(0,(int)($modules['health_degraded']??0)),
            'module_degraded'=>max(0,(int)($modules['degraded']??0)),
            'registry_ready'=>!empty($registry['ready']),
            'registry_module_count'=>max(0,(int)($registry['module_count']??0)),
            'registry_feature_count'=>max(0,(int)($registry['feature_count']??0)),
            'incident_count'=>0,
        ];
        if($record_incidents)self::record_transition($out);
        $out['incident_count']=count(self::incidents());
        return $out;
    }

    private static function record_transition(array $snapshot): void {
        $signature=(string)$snapshot['status'].'|'.implode(',',(array)$snapshot['issues']);
        $previous=(string)get_option(self::SIGNATURE_OPTION,'');
        if($signature===$previous)return;
        update_option(self::SIGNATURE_OPTION,$signature,false);
        $events=self::incidents();
        array_unshift($events,[
            'at_utc'=>(string)$snapshot['checked_at_utc'],
            'status'=>(string)$snapshot['status'],
            'codes'=>array_values(array_map('sanitize_key',(array)$snapshot['issues'])),
        ]);
        if(count($events)>self::INCIDENT_LIMIT)$events=array_slice($events,0,self::INCIDENT_LIMIT);
        update_option(self::INCIDENTS_OPTION,$events,false);
    }

    public static function incidents(): array {
        $events=get_option(self::INCIDENTS_OPTION,[]);
        if(!is_array($events))return [];
        $safe=[];
        foreach($events as $e){
            if(!is_array($e))continue;
            $codes=[];foreach((array)($e['codes']??[]) as $code)$codes[]=sanitize_key((string)$code);
            $safe[]=['at_utc'=>(string)($e['at_utc']??''),'status'=>sanitize_key((string)($e['status']??'')),'codes'=>$codes];
            if(count($safe)>=self::INCIDENT_LIMIT)break;
        }
        return $safe;
    }

    public static function register_ability(): void {
        if(!function_exists('wp_register_ability'))return;
        wp_register_ability('qys-messenger/health',[
            'label'=>'QuranYar production health',
            'description'=>'Read-only worker, scheduler, queue, lock and readiness telemetry. Contains no PII, tokens, message text or raw network errors.',
            'category'=>'qys-messenger',
            'permission_callback'=>static function(){return QYS_Security_Service::is_global_admin();},
            'meta'=>['public'=>true,'show_in_rest'=>true,'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
            'output_schema'=>['type'=>'object','properties'=>[
                'status'=>['type'=>'string'],'checked_at_utc'=>['type'=>'string'],'issues'=>['type'=>'array','items'=>['type'=>'string']],
                'worker_last_started_utc'=>['type'=>'string'],'worker_last_finished_utc'=>['type'=>'string'],'worker_age_seconds'=>['type'=>'integer'],
                'worker_duration_ms'=>['type'=>'integer','minimum'=>0],'worker_result'=>['type'=>'string'],'worker_source'=>['type'=>'string'],
                'lock_active'=>['type'=>'boolean'],'lock_age_seconds'=>['type'=>'integer','minimum'=>0],'lock_stale'=>['type'=>'boolean'],
                'cron_scheduled'=>['type'=>'boolean'],'cron_next_utc'=>['type'=>'string'],'cron_lateness_seconds'=>['type'=>'integer'],
                'queue_pending'=>['type'=>'integer','minimum'=>0],'queue_sending'=>['type'=>'integer','minimum'=>0],'queue_failed'=>['type'=>'integer','minimum'=>0],'queue_retryable_failed'=>['type'=>'integer','minimum'=>0],'queue_definitive_failed'=>['type'=>'integer','minimum'=>0],'queue_uncertain'=>['type'=>'integer','minimum'=>0],'queue_legacy_failed'=>['type'=>'integer','minimum'=>0],'queue_sent'=>['type'=>'integer','minimum'=>0],
                'welcome_pending'=>['type'=>'integer','minimum'=>0],'welcome_sending'=>['type'=>'integer','minimum'=>0],'welcome_sent'=>['type'=>'integer','minimum'=>0],'welcome_retryable_failed'=>['type'=>'integer','minimum'=>0],'welcome_definitive_failed'=>['type'=>'integer','minimum'=>0],'welcome_uncertain'=>['type'=>'integer','minimum'=>0],'welcome_sending_stale'=>['type'=>'integer','minimum'=>0],
                'activation_pending'=>['type'=>'integer','minimum'=>0],'activation_sending'=>['type'=>'integer','minimum'=>0],'activation_sent'=>['type'=>'integer','minimum'=>0],'activation_retryable_failed'=>['type'=>'integer','minimum'=>0],'activation_definitive_failed'=>['type'=>'integer','minimum'=>0],'activation_uncertain'=>['type'=>'integer','minimum'=>0],'activation_sending_stale'=>['type'=>'integer','minimum'=>0],
                'queue_sending_lease_active'=>['type'=>'integer','minimum'=>0],'queue_sending_lease_expired'=>['type'=>'integer','minimum'=>0],'queue_sending_unleased'=>['type'=>'integer','minimum'=>0],
                'oldest_pending_age_seconds'=>['type'=>'integer'],'oldest_active_pending_age_seconds'=>['type'=>'integer'],'oldest_sending_age_seconds'=>['type'=>'integer'],'oldest_lease_overdue_seconds'=>['type'=>'integer'],
                'schema_version'=>['type'=>'string'],'schema_ready'=>['type'=>'boolean'],'webapp_token_configured'=>['type'=>'boolean'],'delivery_token_configured'=>['type'=>'boolean'],
                'birthday_enabled'=>['type'=>'boolean'],'birthday_ready'=>['type'=>'boolean'],
                'retention_policy_version'=>['type'=>'string'],'retention_scheduled'=>['type'=>'boolean'],'retention_next_utc'=>['type'=>'string'],'retention_last_run_utc'=>['type'=>'string'],'retention_age_seconds'=>['type'=>'integer'],'retention_last_result'=>['type'=>'string'],'retention_last_duration_ms'=>['type'=>'integer','minimum'=>0],'retention_last_changed'=>['type'=>'integer','minimum'=>0],
                'migration_initialized'=>['type'=>'boolean'],'migration_required'=>['type'=>'boolean'],'migration_ready'=>['type'=>'boolean'],'migration_total'=>['type'=>'integer','minimum'=>0],'migration_pending'=>['type'=>'integer','minimum'=>0],'migration_running'=>['type'=>'integer','minimum'=>0],'migration_completed'=>['type'=>'integer','minimum'=>0],'migration_failed'=>['type'=>'integer','minimum'=>0],'migration_running_stale'=>['type'=>'boolean'],'migration_last_completed_id'=>['type'=>'string'],'migration_last_completed_at_utc'=>['type'=>'string'],'migration_current_id'=>['type'=>'string'],
                'upgrade_ready'=>['type'=>'boolean'],'upgrade_automatic_enabled'=>['type'=>'boolean'],'upgrade_last_attempt_utc'=>['type'=>'string'],'upgrade_last_attempt_age_seconds'=>['type'=>'integer'],'upgrade_last_result'=>['type'=>'string'],'upgrade_last_ran'=>['type'=>'integer','minimum'=>0],'upgrade_remaining'=>['type'=>'integer','minimum'=>0],'upgrade_last_duration_ms'=>['type'=>'integer','minimum'=>0],
                'module_ready'=>['type'=>'boolean'],'module_boot_complete'=>['type'=>'boolean'],'module_total'=>['type'=>'integer','minimum'=>0],'module_booted'=>['type'=>'integer','minimum'=>0],'module_failed'=>['type'=>'integer','minimum'=>0],'module_blocked'=>['type'=>'integer','minimum'=>0],'module_critical_failed'=>['type'=>'integer','minimum'=>0],'module_health_degraded'=>['type'=>'integer','minimum'=>0],'module_degraded'=>['type'=>'integer','minimum'=>0],
                'registry_ready'=>['type'=>'boolean'],'registry_module_count'=>['type'=>'integer','minimum'=>0],'registry_feature_count'=>['type'=>'integer','minimum'=>0],
                'incident_count'=>['type'=>'integer','minimum'=>0],
            ],'additionalProperties'=>false],
            'execute_callback'=>static function(){return self::snapshot(true);},
        ]);
    }

    /** Explicit physical schema diagnostics. Never called by worker/cron/ability snapshots. */
    private static function schema_diagnostics(): array {
        $checks=[
            'members'=>QYS_Member_Repository::diagnostic_schema_ready(),
            'family_links'=>QYS_Family_Relationship_Repository::diagnostic_schema_ready(),
            'campaigns'=>QYS_Campaign_Repository::diagnostic_campaigns_ready(),
            'queue'=>QYS_Campaign_Repository::diagnostic_queue_ready(),
            'targeted'=>QYS_Campaign_Repository::diagnostic_targeted_meta_ready(),
            'universities'=>QYS_Multitenant::diagnostic_schema_ready(),
            'programs'=>QYS_Program_Repository::diagnostic_schema_ready(),
            'attendance'=>QYS_Attendance::diagnostic_schema_ready(),
            'campus_birthday'=>QYS_Campus_Birthday::diagnostic_schema_ready(),
            'campus_welcome'=>QYS_Campus_Welcome::diagnostic_schema_ready(),
            'welcome_log'=>QYS_Messenger::onboarding_log_diagnostic_ready('welcome'),
            'activation_log'=>QYS_Messenger::onboarding_log_diagnostic_ready('activation'),
            'support_tables'=>QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('birthday_log'))
                && QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('birthday_archive'))
                && QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('archive_access_log'))
                && QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('import_reports'))
                && QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('excel_followup')),
        ];
        $passed=0;foreach($checks as $ok)if($ok)$passed++;
        return ['ready'=>$passed===count($checks),'passed'=>$passed,'total'=>count($checks),'checks'=>$checks];
    }

    private static function fa_status(string $status): string {
        return $status==='healthy'?'سالم':($status==='warning'?'هشدار':'بحرانی');
    }

    private static function age_label(int $seconds): string {
        if($seconds<0)return '—';
        if($seconds<60)return $seconds.' ثانیه';
        if($seconds<3600)return (int)floor($seconds/60).' دقیقه';
        return (int)floor($seconds/3600).' ساعت و '.(int)floor(($seconds%3600)/60).' دقیقه';
    }

    private static function issue_label(string $code): string {
        $labels=[
            'schema_mismatch'=>'نسخه Schema با نسخه مورد انتظار یکسان نیست.',
            'webapp_token_missing'=>'توکن برنامک برای ارسال خصوصی تنظیم نشده است.',
            'cron_unscheduled'=>'رویداد qys_send_batch در WordPress زمان‌بندی نشده است.',
            'cron_overdue_warning'=>'اجرای Cron بیش از حد معمول عقب افتاده است.',
            'cron_overdue_critical'=>'Cron بیش از ۳ دقیقه عقب افتاده است.',
            'worker_awaiting_first_finish'=>'پس از این نسخه هنوز پایان موفق Worker ثبت نشده است.',
            'worker_not_finishing'=>'Worker شروع شده اما پایان آن بیش از ۳ دقیقه ثبت نشده است.',
            'worker_stale'=>'بیش از ۳ دقیقه از آخرین اجرای کامل Worker گذشته است.',
            'batch_lock_stale'=>'قفل پردازش صف بیش از ۱۲۰ ثانیه باقی مانده است.',
            'batch_lock_recovered'=>'یک قفل رهاشده شناسایی و در اجرای اخیر بازیابی شده است.',
            'tenant_metadata_hold'=>'صف به‌علت آماده نبودن متادیتای مجوز دانشگاهی به‌صورت امن متوقف شده است.',
            'queue_tables_not_ready'=>'جدول‌های اصلی صف/کارزار آماده نیستند.',
            'queue_rate_limited'=>'در اجرای اخیر Provider محدودیت نرخ اعمال کرد و Worker همان اجرا را متوقف کرد.',
            'queue_network_uncertain'=>'در اجرای اخیر نتیجه یک ارسال شبکه نامشخص شد و Worker برای جلوگیری از گسترش ابهام متوقف شد.',
            'queue_sending_recovered'=>'حداقل یک claim منقضی‌شده صف در اجرای اخیر به‌صورت امن بازیابی شد.',
            'queue_sending_lease_expired'=>'حداقل یک پیام با Lease منقضی هنوز در وضعیت sending مانده است.',
            'queue_sending_unleased_stale'=>'حداقل یک رکورد قدیمی sending بدون Lease بیش از ۳ دقیقه باقی مانده است.',
            'birthday_not_ready'=>'خدمت تولد فعال است اما یکی از پیش‌نیازهای ذخیره‌سازی آماده نیست.',
            'welcome_delivery_stale'=>'حداقل یک پیام خوش‌آمد در وضعیت sending رهاشده باقی مانده است.',
            'activation_delivery_stale'=>'حداقل یک پیام فعال‌سازی در وضعیت sending رهاشده باقی مانده است.',
            'retention_unscheduled'=>'پاک‌سازی دوره‌ای داده‌های قدیمی زمان‌بندی نشده است.',
            'retention_cleanup_failed'=>'آخرین اجرای پاک‌سازی داده با خطا پایان یافته است.',
            'retention_cleanup_stale'=>'بیش از ۴۸ ساعت از آخرین اجرای موفق/ثبت‌شده پاک‌سازی داده گذشته است.',
            'migration_ledger_uninitialized'=>'دفتر ثبت Migration هنوز مقداردهی اولیه نشده است.',
            'migration_pending'=>'Migration در انتظار اجرا یا در حال اجرا است.',
            'migration_failed'=>'حداقل یک Migration با خطا متوقف شده است.',
            'migration_running_stale'=>'یک Migration بیش از حد مجاز در وضعیت اجرا باقی مانده است.',
            'upgrade_gate_closed'=>'دروازه Readiness قرآن‌یار تا تکمیل Migration بسته است.',
            'upgrade_runner_failed'=>'آخرین اجرای خودکار Upgrade با خطا متوقف شده است.',
            'module_critical_failed'=>'حداقل یک ماژول حیاتی قرآن‌یار در Boot یا وابستگی خود شکست خورده است.',
            'module_degraded'=>'حداقل یک ماژول غیرحیاتی ایزوله یا در Health داخلی degraded شده است.',
        ];
        return $labels[$code]??$code;
    }

    public static function admin(): void {
        if(!QYS_Security_Service::is_global_admin() || !class_exists('QYS_Tenant_Context') || QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL)wp_die('این صفحه فقط برای مدیر کل قرآن‌یار است.');
        $h=self::snapshot(true);$events=self::incidents();
        // Capture the hot-path telemetry before any explicit physical diagnostic.
        $schema_runtime=QYS_Schema_Runtime::telemetry();
        $schema_diagnostics=self::schema_diagnostics();
        $program_performance=QYS_Program_Repository::performance_contract();
        $home_summary=QYS_Home_Summary_Service::performance_contract();
        $lazy_features=QYS_Messenger::lazy_feature_contract();
        $queue_index=QYS_Campaign_Repository::queue_dispatch_index_contract();
        $queue_throughput=QYS_Queue_Throughput::status();
        $health_snapshot=self::snapshot_schedule_status();
        $batch_export=QYS_Batch_Export::contract();
        $birthday_lookup=QYS_Member_Repository::birthday_lookup_contract();
        $portal_assets=QYS_Portal_Asset_Policy::contract();
        $membership_consent=method_exists(QYS_Messenger::class,'membership_consent_contract')?QYS_Messenger::membership_consent_contract():['ready'=>false];
        $family_attribution=method_exists(QYS_Messenger::class,'family_attribution_contract')?QYS_Messenger::family_attribution_contract():['ready'=>false];
        $mobile_otp=method_exists(QYS_Messenger::class,'mobile_otp_contract')?QYS_Messenger::mobile_otp_contract():['ready'=>false];
        $programs_gate=method_exists(QYS_Programs_Module::class,'access_gate_contract')?QYS_Programs_Module::access_gate_contract():['ready'=>false];
        $destructive_confirm=method_exists(QYS_Programs_Module::class,'destructive_confirmation_contract')?QYS_Programs_Module::destructive_confirmation_contract():['ready'=>false];
        $program_edit=method_exists(QYS_Programs_Module::class,'program_edit_contract')?QYS_Programs_Module::program_edit_contract():['ready'=>false];
        $member_withdraw=method_exists(QYS_Programs_Module::class,'member_withdraw_contract')?QYS_Programs_Module::member_withdraw_contract():['ready'=>false];
        $member_reapply=method_exists(QYS_Programs_Module::class,'member_reapply_contract')?QYS_Programs_Module::member_reapply_contract():['ready'=>false];
        $program_post_redirect=method_exists(QYS_Programs_Module::class,'post_redirect_contract')?QYS_Programs_Module::post_redirect_contract():['ready'=>false];
        $form_draft=method_exists(QYS_Programs_Module::class,'member_form_draft_contract')?QYS_Programs_Module::member_form_draft_contract():['ready'=>false];
        $field_validation=method_exists(QYS_Programs_Module::class,'field_validation_contract')?QYS_Programs_Module::field_validation_contract():['ready'=>false];
        $bulk_applicants=method_exists(QYS_Programs_Module::class,'bulk_applicant_contract')?QYS_Programs_Module::bulk_applicant_contract():['ready'=>false];
        $profile_progress=method_exists(QYS_Messenger::class,'profile_progress_contract')?QYS_Messenger::profile_progress_contract():['ready'=>false];
        $campaign_edit=method_exists(QYS_Targeted::class,'campaign_edit_contract')?QYS_Targeted::campaign_edit_contract():['ready'=>false];
        $program_notifications=class_exists(QYS_Program_Notifications::class,false)&&method_exists(QYS_Program_Notifications::class,'contract')?QYS_Program_Notifications::contract():['ready'=>false];
        $async_states=method_exists(QYS_Member_Experience::class,'async_state_contract')?QYS_Member_Experience::async_state_contract():['ready'=>false];
        $member_readability=method_exists(QYS_Member_Experience::class,'readability_contract')?QYS_Member_Experience::readability_contract():['ready'=>false];
        echo '<div class="wrap qys-admin qys-admin--health" dir="rtl"><h1>سلامت سیستم قرآن‌یار</h1>';
        echo '<div class="qys-health-banner qys-health--'.esc_attr($h['status']).'"><strong>وضعیت کلی: '.esc_html(self::fa_status($h['status'])).'</strong><span>آخرین بررسی: '.esc_html($h['checked_at_utc']).'</span></div>';
        if($h['issues']){echo '<div class="qys-admin-panel"><h2>موارد نیازمند توجه</h2><ul class="qys-health-issues">';foreach($h['issues'] as $issue)echo '<li>'.esc_html(self::issue_label($issue)).'</li>';echo '</ul></div>';}
        echo '<div class="qys-kpi-grid qys-health-kpis">';
        echo '<div class="qys-kpi"><span>آخرین اجرای کامل Worker</span><strong>'.esc_html(self::age_label((int)$h['worker_age_seconds'])).'</strong><small>'.esc_html($h['worker_last_finished_utc']?:'ثبت نشده').'</small></div>';
        echo '<div class="qys-kpi"><span>مدت اجرای اخیر</span><strong>'.(int)$h['worker_duration_ms'].' ms</strong><small>'.esc_html($h['worker_result'].' / '.$h['worker_source']).'</small></div>';
        echo '<div class="qys-kpi"><span>Pending</span><strong>'.(int)$h['queue_pending'].'</strong><small>قدیمی‌ترین فعال: '.esc_html(self::age_label((int)$h['oldest_active_pending_age_seconds'])).'</small></div>';
        echo '<div class="qys-kpi"><span>Sending</span><strong>'.(int)$h['queue_sending'].'</strong><small>Lease فعال: '.(int)$h['queue_sending_lease_active'].' | منقضی: '.(int)$h['queue_sending_lease_expired'].' | بدون Lease: '.(int)$h['queue_sending_unleased'].'</small></div>';
        echo '<div class="qys-kpi"><span>نیازمند بررسی</span><strong>'.(int)$h['queue_failed'].'</strong><small>موقت: '.(int)$h['queue_retryable_failed'].' | قطعی: '.(int)$h['queue_definitive_failed'].' | نامشخص: '.(int)$h['queue_uncertain'].' | ارسال‌شده: '.(int)$h['queue_sent'].'</small></div>';
        echo '<div class="qys-kpi"><span>خوش‌آمد / فعال‌سازی</span><strong>'.((int)$h['welcome_pending']+(int)$h['activation_pending']+(int)$h['welcome_sending']+(int)$h['activation_sending']).'</strong><small>خوش‌آمد — موقت: '.(int)$h['welcome_retryable_failed'].' | نامشخص: '.(int)$h['welcome_uncertain'].' ؛ فعال‌سازی — موقت: '.(int)$h['activation_retryable_failed'].' | نامشخص: '.(int)$h['activation_uncertain'].'</small></div>';
        echo '<div class="qys-kpi"><span>Cron lateness</span><strong>'.esc_html(self::age_label((int)$h['cron_lateness_seconds'])).'</strong><small>'.esc_html($h['cron_next_utc']?:'زمان‌بندی نشده').'</small></div>';
        echo '<div class="qys-kpi"><span>پاک‌سازی داده</span><strong>'.esc_html(self::age_label((int)$h['retention_age_seconds'])).'</strong><small>نتیجه: '.esc_html($h['retention_last_result']).' | تغییرات آخر: '.(int)$h['retention_last_changed'].'</small></div>';
        echo '</div>';
        echo '<div class="qys-admin-panel"><h2>Readiness</h2><div class="qys-health-readiness">';
        $items=[
            'Schema'=>[$h['schema_ready'],$h['schema_version']],
            'Runtime schema gate'=>[$schema_runtime['ready'],'mode: '.(string)$schema_runtime['mode'].' | probes: '.(int)$schema_runtime['diagnostic_queries']],
            'Schema diagnostics'=>[$schema_diagnostics['ready'],'Physical: '.(int)$schema_diagnostics['passed'].' / '.(int)$schema_diagnostics['total']],
            'Programs query plan'=>[!empty($program_performance['ready']),'forms: '.(string)($program_performance['forms']??'unknown').' | counts: '.(string)($program_performance['counts']??'unknown').' | answers: '.(string)($program_performance['answers']??'unknown')],
            'Home summary'=>[!empty($home_summary['ready']),'endpoint: '.(string)($home_summary['endpoint']??'unknown').' | programs: '.(string)($home_summary['programs']??'unknown').' | forms: '.(string)($home_summary['forms']??'unknown')],
            'Mini-app feature load'=>[!empty($lazy_features['ready']),'programs: '.(string)($lazy_features['programs']??'unknown').' | attendance: '.(string)($lazy_features['attendance']??'unknown').' | data: '.(string)($lazy_features['full_data']??'unknown')],
            'Queue dispatch index'=>[!empty($queue_index['ready']),'('.(string)($queue_index['columns']??'unknown').'): '.(!empty($queue_index['ready'])?'ready':'missing').' | pending scan: '.(string)($queue_index['pending_scan']??'unknown')],
            'Queue throughput'=>[!empty($queue_throughput['ready']),'mode: '.(string)($queue_throughput['mode']??'unknown').' | budget: '.(int)round(((int)($queue_throughput['budget_ms']??0))/1000).'s | max: '.(int)($queue_throughput['max_messages']??0).' | rate-limit: '.(string)($queue_throughput['rate_limit']??'unknown')],
            'Health snapshot'=>[!empty($health_snapshot['ready']) && !empty($health_snapshot['scheduled']),'heartbeat: '.(string)($health_snapshot['heartbeat']??'unknown').' | full: 15m/on-demand'],
            'Batch export'=>[!empty($batch_export['ready']),'mode: '.(string)($batch_export['mode']??'unknown').' | chunk: '.(int)($batch_export['chunk']??0).' | cursor: '.(string)($batch_export['cursor']??'unknown').' | memory: '.(string)($batch_export['memory']??'unknown')],
            'Birthday lookup'=>[!empty($birthday_lookup['ready']),'column: '.(string)($birthday_lookup['column']??'unknown').' | query: '.(string)($birthday_lookup['query']??'unknown').' | index: '.(string)($birthday_lookup['index']??'unknown')],
            'Portal assets'=>[!empty($portal_assets['ready']),'shell: '.(string)($portal_assets['shell']??'unknown').' | feature: '.(string)($portal_assets['feature']??'unknown').' | scope: '.str_replace('_','+',(string)($portal_assets['scope']??'unknown'))],
            'Membership / messaging'=>[!empty($membership_consent['ready']),'membership: '.str_replace('_','-',(string)($membership_consent['membership']??'unknown')).' | messaging: '.(string)($membership_consent['messaging']??'unknown').' | gate: '.str_replace('_','-',(string)($membership_consent['gate']??'unknown')).' | disable: '.str_replace('_','-',(string)($membership_consent['disable']??'unknown'))],
            'Family attribution'=>[!empty($family_attribution['ready']),'model: '.str_replace('_','-',(string)($family_attribution['model']??'unknown')).' | staff-family: '.(string)($family_attribution['staff_family']??'unknown').' | overlap: '.(string)($family_attribution['employee_overlap']??'unknown').' | parents: '.str_replace('_','-',(string)($family_attribution['multiple_parents']??'unknown')).' | scope: '.(string)($family_attribution['scope']??'unknown')],
            'Mobile OTP'=>[!empty($mobile_otp['ready'])&&!empty($mobile_otp['configured']),'provider: '.(string)($mobile_otp['provider']??'unknown').' | channel: '.str_replace('_','-',(string)($mobile_otp['channel']??'unknown')).' | registration: '.(string)($mobile_otp['registration']??'unknown').' | change: '.(string)($mobile_otp['mobile_change']??'unknown').' | legacy: '.str_replace('_','-',(string)($mobile_otp['legacy']??'unknown')).' | configured: '.(!empty($mobile_otp['configured'])?'yes':'no')],
            'Programs access gate'=>[!empty($programs_gate['ready']),'mode: '.(string)($programs_gate['mode']??'unknown').' | cta: '.(string)($programs_gate['cta']??'unknown').' | redirect: '.(string)($programs_gate['redirect']??'unknown')],
            'Destructive confirmation'=>[!empty($destructive_confirm['ready']),'program: complete/cancel/archive | applicant: reject | server: '.(string)($destructive_confirm['server']??'unknown')],
            'Program edit'=>[!empty($program_edit['ready']),'mode: '.str_replace('_','-',(string)($program_edit['mode']??'unknown')).' | capacity: '.str_replace('_','-',(string)($program_edit['capacity']??'unknown')).' | audience: '.str_replace('_','-',(string)($program_edit['audience']??'unknown')).' | attendance: '.str_replace('_','-',(string)($program_edit['attendance']??'unknown'))],
            'Member withdraw'=>[!empty($member_withdraw['ready']),'mode: '.str_replace('_','-',(string)($member_withdraw['mode']??'unknown')).' | application: '.(string)($member_withdraw['application']??'unknown').' | enrollment: '.(string)($member_withdraw['enrollment']??'unknown').' | confirm: '.(string)($member_withdraw['confirmation']??'unknown').' | history: '.(string)($member_withdraw['history']??'unknown')],
            'Program reapply'=>[!empty($member_reapply['ready']),'mode: '.str_replace('_','-',(string)($member_reapply['mode']??'unknown')).' | source: '.str_replace('_','-',(string)($member_reapply['source']??'unknown')).' | target: '.(string)($member_reapply['target']??'unknown').' | approval: '.str_replace('_','-',(string)($member_reapply['approval']??'unknown')).' | capacity: '.str_replace('_','-',(string)($member_reapply['capacity']??'unknown')).' | enrollment: '.str_replace('_','-',(string)($member_reapply['enrollment']??'unknown'))],
            'Program action routing'=>[!empty($program_post_redirect['ready']),'portal: '.str_replace('_','-',(string)($program_post_redirect['portal_hook']??'unknown')).' | admin: '.str_replace('_','-',(string)($program_post_redirect['admin_hook']??'unknown')).' | timing: '.str_replace('_','-',(string)($program_post_redirect['timing']??'unknown')).' | notify: '.str_replace('_','-',(string)($program_post_redirect['notification']??'unknown'))],
            'Form draft'=>[!empty($form_draft['ready']),'storage: '.(string)($form_draft['storage']??'unknown').' | scope: '.str_replace('_','+',(string)($form_draft['scope']??'unknown')).' | ttl: '.(int)($form_draft['ttl_hours']??0).'h | clear: '.str_replace('_','-',(string)($form_draft['clear']??'unknown'))],
            'Field validation'=>[!empty($field_validation['ready']),'mode: '.str_replace('_','-',(string)($field_validation['mode']??'unknown')).' | required: '.str_replace('_','+',(string)($field_validation['required']??'unknown')).' | multi: '.str_replace('_','-',(string)($field_validation['multi_choice']??'unknown')).' | error: '.(string)($field_validation['error']??'unknown')],
            'Bulk applicants'=>[!empty($bulk_applicants['ready']),'mode: '.(string)($bulk_applicants['mode']??'unknown').' | filters: '.str_replace('_','+',(string)($bulk_applicants['filters']??'unknown')).' | actions: '.str_replace('_','+',(string)($bulk_applicants['actions']??'unknown')).' | scope: '.(string)($bulk_applicants['scope']??'unknown').' | max: '.(int)($bulk_applicants['max_selected']??0)],
            'Profile progress'=>[!empty($profile_progress['ready']),'source: '.(string)($profile_progress['source']??'unknown').' | basis: '.str_replace('_','-',(string)($profile_progress['basis']??'unknown')).' | base fields: '.(int)($profile_progress['total_fields']??0).' | family: '.str_replace('_','-',(string)($profile_progress['family_attribution']??'conditional')).' | consent: '.str_replace('_','-',(string)($profile_progress['consent_gate']??'n/a')).' | 100: '.str_replace('_','-',(string)($profile_progress['hundred']??'unknown'))],
            'Message preview / edit'=>[!empty($campaign_edit['ready']),'preview: '.(int)($campaign_edit['preview_rows']??0).' | editor: '.str_replace('_','-',(string)($campaign_edit['editor']??'unknown')).' | edit: '.str_replace('_','-',(string)($campaign_edit['editable']??'unknown')).' | payload: '.str_replace('_','-',(string)($campaign_edit['payload']??'unknown')).' | worker: '.str_replace('_','-',(string)($campaign_edit['worker']??'unknown'))],
            'Program status notify'=>[!empty($program_notifications['ready']),'events: '.str_replace('_','/',(string)($program_notifications['events']??'unknown')).' | queue: '.(string)($program_notifications['queue']??'unknown').' | templates: '.str_replace('_','+',(string)($program_notifications['templates']??'unknown')).' | scope: '.str_replace('_','-',(string)($program_notifications['settings_scope']??'unknown')).' | editor: '.str_replace('_','-',(string)($program_notifications['editor']??'unknown')).' | consent: '.(string)($program_notifications['consent']??'unknown')],
            'Async states'=>[!empty($async_states['ready']),'loading: '.(string)($async_states['loading']??'unknown').' | empty: '.(string)($async_states['empty']??'unknown').' | error: '.(string)($async_states['error']??'unknown').' | retry: '.(string)($async_states['retry']??'unknown')],
            'Member app readability'=>[!empty($member_readability['ready']),'base: '.(string)($member_readability['base_text']??'unknown').' | secondary: '.str_replace('_',' ',(string)($member_readability['secondary_text']??'unknown')).' | compact: '.str_replace('_',' ',(string)($member_readability['compact_text']??'unknown')).' | contrast: '.strtoupper((string)($member_readability['contrast']??'unknown')).' | copy: '.str_replace('_','-',(string)($member_readability['copy']??'unknown')).' | technical: '.str_replace('_','-',(string)($member_readability['technical_details']??'unknown'))],
            'Upgrade gate'=>[$h['upgrade_ready'],$h['upgrade_ready']?'آماده':'بسته — '.($h['upgrade_last_result']?:'در انتظار')],
            'توکن برنامک'=>[$h['webapp_token_configured'],$h['webapp_token_configured']?'آماده':'تنظیم نشده'],
            'کلید اختیاری EitaaYar'=>[true,$h['delivery_token_configured']?'تنظیم شده':'اختیاری/تنظیم نشده'],
            'خدمت تولد'=>[$h['birthday_ready'],$h['birthday_enabled']?'فعال':'غیرفعال'],
            'Cron'=>[$h['cron_scheduled'],$h['cron_scheduled']?'زمان‌بندی‌شده':'فاقد زمان‌بندی'],
            'Batch lock'=>[!$h['lock_stale'],$h['lock_active']?'فعال؛ سن '.self::age_label((int)$h['lock_age_seconds']):'آزاد'],
            'Retention'=>[$h['retention_scheduled'],$h['retention_scheduled']?($h['retention_last_run_utc']?'آخرین اجرا: '.$h['retention_last_run_utc']:'زمان‌بندی‌شده؛ در انتظار اولین اجرا'):'فاقد زمان‌بندی'],
            'Migration ledger'=>[$h['migration_ready'],'تکمیل: '.(int)$h['migration_completed'].' / '.(int)$h['migration_total'].($h['migration_failed']?' | خطا: '.(int)$h['migration_failed']:'')],
            'Module lifecycle'=>[$h['module_ready'] && $h['module_degraded']===0,'Boot: '.(int)$h['module_booted'].' / '.(int)$h['module_total'].($h['module_degraded']?' | degraded: '.(int)$h['module_degraded']:'')],
            'Module registry'=>[$h['registry_ready'],'Modules: '.(int)$h['registry_module_count'].' | Features: '.(int)$h['registry_feature_count']],
        ];
        foreach($items as $label=>$item){$ok=(bool)$item[0];echo '<div class="qys-health-ready '.($ok?'is-ok':'is-bad').'"><span>'.esc_html($label).'</span><strong>'.esc_html((string)$item[1]).'</strong></div>';}
        echo '</div></div>';
        if(class_exists('QYS_Module_Manager')){
            $module_rows=QYS_Module_Manager::rows();
            echo '<div class="qys-admin-panel"><h2>Module Lifecycle</h2><p>هر قابلیت با قرارداد Boot / Dependency / Migration / Health مستقل اجرا می‌شود. خطای ماژول غیرحیاتی در همان قابلیت ایزوله می‌شود.</p>';
            echo '<div class="qys-table-scroll"><table class="widefat striped"><thead><tr><th>ماژول</th><th>وضعیت</th><th>حیاتی</th><th>وابستگی Boot</th><th>Migration</th><th>Feature</th><th>Health</th><th>کد خطا</th></tr></thead><tbody>';
            foreach($module_rows as $row){
                echo '<tr><td><code>'.esc_html($row['id']).'</code></td><td>'.esc_html($row['state']).'</td><td>'.(!empty($row['critical'])?'بله':'خیر').'</td><td>'.esc_html($row['dependencies']?implode('، ',$row['dependencies']):'—').'</td><td>'.(int)$row['migration_count'].'</td><td>'.(int)($row['feature_count']??0).'</td><td>'.esc_html($row['health_code']).'</td><td><code>'.esc_html($row['error_code']?:'—').'</code></td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
        if(class_exists('QYS_Migration_Ledger')){
            $migration_rows=QYS_Migration_Ledger::entries(20);
            echo '<div class="qys-admin-panel"><h2>Migration Ledger</h2><p>RC12 تا RC18 به‌صورت baseline ثبت شده‌اند و دوباره اجرا نمی‌شوند. Migrationهای جدید مستقل، ترتیبی و قابل retry/checkpoint هستند.</p>';
            if(!$migration_rows){
                echo '<p>هنوز Ledger مقداردهی اولیه نشده است.</p>';
            } else {
                echo '<div class="qys-table-scroll"><table class="widefat striped"><thead><tr><th>ID</th><th>وضعیت</th><th>تلاش</th><th>Checkpoint</th><th>نسخه</th><th>تکمیل UTC</th></tr></thead><tbody>';
                foreach($migration_rows as $row){
                    echo '<tr><td><code>'.esc_html($row['id']).'</code>'.(!empty($row['baseline'])?' <small>baseline</small>':'').'</td><td>'.esc_html($row['status']).'</td><td>'.(int)$row['attempts'].'</td><td><code>'.esc_html($row['checkpoint']?:'—').'</code></td><td>'.esc_html($row['plugin_version']?:'—').'</td><td>'.esc_html($row['completed_at_utc']?:'—').'</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div>';
        }
        if(class_exists('QYS_Retention_Service')){
            $matrix=QYS_Retention_Service::matrix();
            echo '<div class="qys-admin-panel"><h2>سیاست نگهداری داده</h2><p>پاک‌سازی به‌صورت دسته‌ای و روزانه انجام می‌شود. رکوردهای pending، sending، retryable_failed و uncertain از حذف خودکار محافظت می‌شوند.</p><div class="qys-health-readiness">';
            $retention_items=[
                'متن پیام صف'=>'کاهش داده پس از '.(int)$matrix['queue_payload_minimise'].' روز',
                'پیگیری اکسل'=>'ناشناس‌سازی پس از '.(int)$matrix['excel_followup_anonymise'].' روز',
                'آرشیو تولد'=>'ناشناس‌سازی '.(int)$matrix['birthday_archive_anonymise'].' روز / حذف '.(int)$matrix['birthday_archive_delete'].' روز',
                'گزارش تولد'=>'حذف وضعیت‌های نهایی پس از '.(int)$matrix['birthday_log_delete'].' روز',
                'کارزار/صف'=>'حذف کامل فقط پس از '.(int)$matrix['campaign_history_delete'].' روز و فقط در حالت کاملاً نهایی',
                'Audit'=>'حذف پس از '.(int)$matrix['audit_delete'].' روز',
            ];
            foreach($retention_items as $label=>$value)echo '<div class="qys-health-ready is-ok"><span>'.esc_html($label).'</span><strong>'.esc_html($value).'</strong></div>';
            echo '</div></div>';
        }
        echo '<div class="qys-admin-panel"><h2>Incident log بدون اطلاعات شخصی</h2>';
        if(!$events)echo '<p>هنوز تغییر وضعیت سلامت ثبت نشده است.</p>';else{echo '<div class="qys-table-scroll"><table class="widefat striped"><thead><tr><th>زمان UTC</th><th>وضعیت</th><th>کدهای تشخیصی</th></tr></thead><tbody>';foreach($events as $e){echo '<tr><td><code>'.esc_html($e['at_utc']).'</code></td><td>'.esc_html(self::fa_status($e['status'])).'</td><td>'.esc_html($e['codes']?implode('، ',$e['codes']):'recovered').'</td></tr>';}echo '</tbody></table></div>';}
        echo '<p class="description">این گزارش فقط وضعیت‌های عملیاتی و کدهای تشخیصی را ذخیره می‌کند و شامل نام، شماره تلفن، شناسه ایتا، متن پیام، توکن یا خطای خام شبکه نیست.</p>';
        echo '</div></div>';
    }
}
