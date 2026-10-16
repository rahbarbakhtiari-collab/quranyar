<?php
if (!defined('ABSPATH')) exit;

/**
 * QuranYar module lifecycle coordinator (Phase C / C3).
 *
 * Responsibilities are intentionally narrow:
 * - one boot contract for all runtime modules;
 * - ordered dependency-aware boot;
 * - module-owned migration registration before WordPress init;
 * - fault isolation for non-critical modules;
 * - safe, non-PII lifecycle health telemetry.
 *
 * C5 enforces one-way feature dependency boundaries. C6 moves Program-domain
 * SQL ownership behind its repository; C7 makes QYS_Module_Registry the
 * canonical owner of lifecycle modules and portal feature metadata.
 */
final class QYS_Module_Manager {
    private const C3_MIGRATION='20261006_010_rc21_module_lifecycle';
    private const C4_MIGRATION='20261006_011_rc22_core_services';
    private const C5_MIGRATION='20261006_012_rc23_dependency_cycle_break';
    private const C6_MIGRATION='20261006_013_rc24_repository_ownership';
    private const C7_MIGRATION='20261006_014_rc25_module_registry';
    private const D1_MIGRATION='20261006_015_rc26_runtime_schema_guard';
    private const D2_MIGRATION='20261006_016_rc27_program_batch_queries';
    private const D3_MIGRATION='20261006_017_rc28_home_summary';
    private const D4_MIGRATION='20261006_018_rc29_lazy_member_features';
    private const D5_MIGRATION='20261006_019_rc30_queue_status_id_index';
    private const D6_MIGRATION='20261006_020_rc31_adaptive_queue_throughput';
    private const D7_MIGRATION='20261006_021_rc32_lightweight_health_snapshot';
    private const D8_MIGRATION='20261006_022_rc33_batch_export';
    private const D9_MIGRATION='20261006_023_rc34_birthday_lookup_index';
    private const D10_MIGRATION='20261006_024_rc35_conditional_portal_assets';
    private const E1_MIGRATION='20261006_025_rc36_membership_messaging_consent';
    private const E2_MIGRATION='20261006_026_rc37_programs_access_gate';
    private const E3_MIGRATION='20261006_027_rc38_destructive_confirmations';
    private const E4_MIGRATION='20261006_028_rc39_program_edit_workflow';
    private const E5_MIGRATION='20261006_029_rc40_member_withdraw';
    private const E6_MIGRATION='20261006_030_rc41_async_member_states';
    private const E7_MIGRATION='20261006_031_rc42_member_form_draft';
    private const E8_MIGRATION='20261006_032_rc43_field_level_validation';
    private const E9_MIGRATION='20261006_033_rc44_bulk_applicant_management';
    private const E10_MIGRATION='20261006_034_rc45_profile_progress';
    private const M1_MIGRATION='20261007_035_rc46_targeted_message_edit';
    private const M2_MIGRATION='20261007_036_rc46_program_status_notifications';
    private const M3_MIGRATION='20261007_037_rc48_global_program_status_templates';
    private const M4_MIGRATION='20261007_038_rc49_required_messaging_consent';
    private const M5_MIGRATION='20261011_039_rc50_family_attribution';
    private const M6_MIGRATION='20261011_040_rc51_mobile_otp_verification';
    private const M7_MIGRATION='20261011_041_rc53_program_reapply_after_withdrawal';
    private const M8_MIGRATION='20261011_042_rc54_program_pre_render_post_redirect';

    /** @var array<string,array<string,mixed>> */
    private static array $states=[];
    private static bool $defaults_registered=false;
    private static bool $migrations_registered=false;
    private static bool $booted=false;
    private static string $plugin_file='';

    public static function normalize_id(string $id): string { return QYS_Module_Registry::normalize_id($id); }

    public static function safe_code(string $code): string {
        $code=self::normalize_id(str_replace('\\','_',$code));
        return $code!==''?substr($code,0,80):'unknown';
    }

    public static function register(QYS_Module_Contract $module): void {
        $id=self::normalize_id($module->id());
        $dependencies=[];
        foreach($module->dependencies() as $dependency){
            $dependency=self::normalize_id((string)$dependency);
            if($dependency==='' || $dependency===$id)throw new InvalidArgumentException('Invalid QuranYar module dependency');
            $dependencies[]=$dependency;
        }
        $id=QYS_Module_Registry::register($module);
        self::$states[$id]=[
            'id'=>$id,
            'state'=>'registered',
            'critical'=>$module->critical(),
            'dependencies'=>array_values(array_unique($dependencies)),
            'migration_count'=>count($module->migrations()),
            'feature_count'=>count($module->features()),
            'error_code'=>'',
        ];
    }

    public static function bootstrap(string $plugin_file): void {
        if(self::$booted)return;
        self::$plugin_file=$plugin_file;
        self::register_defaults($plugin_file);
        self::register_migrations();
        self::boot_all();
    }

    public static function register_defaults(string $plugin_file): void {
        if(self::$defaults_registered)return;
        self::$defaults_registered=true;
        self::$plugin_file=$plugin_file;
        $noop=static function(): void {};

        $feature=static function(string $id,string $section,string $label,string $permission,$handler,string $group,int $order,string $docs,array $tests): array {
            return [
                'id'=>$id,'section'=>$section,'label'=>$label,'permission'=>$permission,
                'handler'=>$handler,'nav_group'=>$group,'nav_order'=>$order,'docs'=>$docs,'tests'=>$tests,
            ];
        };

        // Infrastructure. Migrations are registered before the automatic runner.
        self::register(new QYS_Callback_Module('lifecycle',$noop,[],true,[
            [
                'id'=>self::C3_MIGRATION,
                'callback'=>[__CLASS__,'migration_c3'],
                'meta'=>['label'=>'RC21 module lifecycle contract','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::C5_MIGRATION,
                'callback'=>[__CLASS__,'migration_c5'],
                'meta'=>['label'=>'RC23 dependency cycle break','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ]));
        self::register(new QYS_Callback_Module('installer',[QYS_Installer::class,'init'],['lifecycle'],true));
        self::register(new QYS_Callback_Module('upgrade',[QYS_Upgrade_Coordinator::class,'init'],['installer'],true));
        self::register(new QYS_Callback_Module('security',[QYS_Security_Service::class,'init'],['installer'],true));
        self::register(new QYS_Callback_Module('core',[QYS_Messenger::class,'init'],['upgrade','security'],true,[
            [
                'id'=>self::C4_MIGRATION,
                'callback'=>[__CLASS__,'migration_c4'],
                'meta'=>['label'=>'RC22 core service extraction','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::D1_MIGRATION,
                'callback'=>[__CLASS__,'migration_d1'],
                'meta'=>['label'=>'RC26 runtime schema introspection guard','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::D8_MIGRATION,
                'callback'=>[__CLASS__,'migration_d8'],
                'meta'=>['label'=>'RC33 bounded streaming batch export','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],[QYS_Bootstrap_Facade::class,'health']));
        self::register(new QYS_Callback_Module('health',[QYS_Health_Center::class,'init'],['core','upgrade'],true,[
            [
                'id'=>self::D7_MIGRATION,
                'callback'=>[__CLASS__,'migration_d7'],
                'meta'=>['label'=>'RC32 lightweight health snapshot','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ]));
        self::register(new QYS_Callback_Module('retention',[QYS_Retention_Service::class,'init'],['installer'],false));

        // Tenant boundary is security-critical. Portal capabilities now belong to
        // their lifecycle module and are registered with it, not by a separate
        // FeatureRegistry bootstrap pass.
        self::register(new QYS_Callback_Module('universities',[QYS_Multitenant::class,'init'],['core','security','upgrade'],true,[
            [
                'id'=>self::D10_MIGRATION,
                'callback'=>[__CLASS__,'migration_d10'],
                'meta'=>['label'=>'RC35 conditional university portal assets','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],null,[
            $feature('home','home','خانه','',[QYS_Multitenant::class,'render_home'],'primary',10,'portal/home',['tenant-home']),
            $feature('more','more','بیشتر','',[QYS_Multitenant::class,'render_more'],'primary',50,'portal/more',['tenant-more']),
        ]));
        self::register(new QYS_Callback_Module('members',$noop,['core','universities'],true,[
            [
                'id'=>self::D3_MIGRATION,
                'callback'=>[__CLASS__,'migration_d3'],
                'meta'=>['label'=>'RC28 lightweight Home summary endpoint','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::D4_MIGRATION,
                'callback'=>[__CLASS__,'migration_d4'],
                'meta'=>['label'=>'RC29 lazy Programs/Attendance mini-app features','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E1_MIGRATION,
                'callback'=>[__CLASS__,'migration_e1'],
                'meta'=>['label'=>'RC36 membership independent from messaging consent','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E6_MIGRATION,
                'callback'=>[__CLASS__,'migration_e6'],
                'meta'=>['label'=>'RC41 distinct loading empty error member states','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E10_MIGRATION,
                'callback'=>[__CLASS__,'migration_e10'],
                'meta'=>['label'=>'RC45 server-authoritative profile progress','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M4_MIGRATION,
                'callback'=>[__CLASS__,'migration_m4'],
                'meta'=>['label'=>'RC49 messaging consent required before profile completion','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M5_MIGRATION,
                'callback'=>[__CLASS__,'migration_m5'],
                'meta'=>['label'=>'RC50 family attribution graph for staff households','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M6_MIGRATION,
                'callback'=>[__CLASS__,'migration_m6'],
                'meta'=>['label'=>'RC51 IPPanel OTP mobile ownership verification','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],null,[
            $feature('members','members','اعضا','view_members',[QYS_Multitenant::class,'render_members'],'primary',20,'members',['tenant-members','tenant-isolation']),
        ]));
        self::register(new QYS_Callback_Module('messaging',$noop,['core','universities'],true,[
            [
                'id'=>self::D5_MIGRATION,
                'callback'=>[__CLASS__,'migration_d5'],
                'meta'=>['label'=>'RC30 queue dispatch index (status,id)','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::D6_MIGRATION,
                'callback'=>[__CLASS__,'migration_d6'],
                'meta'=>['label'=>'RC31 adaptive queue throughput','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],null,[
            $feature('messages','messages','پیام‌ها','send_messages',[QYS_Multitenant::class,'render_messages_hub'],'primary',30,'messages',['message-hub','message-scope']),
        ]));
        self::register(new QYS_Callback_Module('birthday',[QYS_Campus_Birthday::class,'init'],['core','universities'],false,[
            [
                'id'=>self::D9_MIGRATION,
                'callback'=>[__CLASS__,'migration_d9'],
                'meta'=>['label'=>'RC34 indexed birthday month/day lookup','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],null,[
            $feature('birthday','birthday','تبریک تولد','birthday',[QYS_Campus_Birthday::class,'page'],'messages',130,'messages/birthday',['birthday-scope','birthday-dedupe']),
        ]));
        self::register(new QYS_Callback_Module('welcome',[QYS_Campus_Welcome::class,'init'],['core','universities'],false,[],null,[
            $feature('welcome','welcome','پیام‌های عضویت','welcome',[QYS_Campus_Welcome::class,'page'],'messages',140,'messages/welcome',['welcome-scope','welcome-once']),
        ]));
        self::register(new QYS_Callback_Module('targetedmessaging',static function() use ($plugin_file): void { QYS_Targeted::init($plugin_file); },['core','universities'],false,[
            [
                'id'=>self::M1_MIGRATION,
                'callback'=>[__CLASS__,'migration_m1'],
                'meta'=>['label'=>'RC46 compact preview and safe approved message edit','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],null,[
            $feature('targeted','targeted','ارسال پیام','send_messages',[QYS_Targeted::class,'admin'],'messages',110,'messages/targeted',['message-scope','queue-dispatch']),
        ]));
        self::register(new QYS_Callback_Module('programs',[QYS_Programs_Module::class,'init'],['core','universities'],false,[
            [
                'id'=>self::C6_MIGRATION,
                'callback'=>[__CLASS__,'migration_c6'],
                'meta'=>['label'=>'RC24 repository ownership','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::D2_MIGRATION,
                'callback'=>[__CLASS__,'migration_d2'],
                'meta'=>['label'=>'RC27 Programs batched query plan','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E2_MIGRATION,
                'callback'=>[__CLASS__,'migration_e2'],
                'meta'=>['label'=>'RC37 explicit Programs access gate','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E3_MIGRATION,
                'callback'=>[__CLASS__,'migration_e3'],
                'meta'=>['label'=>'RC38 destructive action confirmations','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E4_MIGRATION,
                'callback'=>[__CLASS__,'migration_e4'],
                'meta'=>['label'=>'RC39 state-aware Program edit workflow','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E5_MIGRATION,
                'callback'=>[__CLASS__,'migration_e5'],
                'meta'=>['label'=>'RC40 member self-service Program withdrawal','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E7_MIGRATION,
                'callback'=>[__CLASS__,'migration_e7'],
                'meta'=>['label'=>'RC42 bounded member Program form draft','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E8_MIGRATION,
                'callback'=>[__CLASS__,'migration_e8'],
                'meta'=>['label'=>'RC43 field-level Program form validation','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::E9_MIGRATION,
                'callback'=>[__CLASS__,'migration_e9'],
                'meta'=>['label'=>'RC44 tenant-scoped bulk applicant management','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M2_MIGRATION,
                'callback'=>[__CLASS__,'migration_m2'],
                'meta'=>['label'=>'RC46 Program applicant status notifications','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M3_MIGRATION,
                'callback'=>[__CLASS__,'migration_m3'],
                'meta'=>['label'=>'RC48 global Program status-message templates','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M7_MIGRATION,
                'callback'=>[__CLASS__,'migration_m7'],
                'meta'=>['label'=>'RC53 member Program re-application after withdrawal','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
            [
                'id'=>self::M8_MIGRATION,
                'callback'=>[__CLASS__,'migration_m8'],
                'meta'=>['label'=>'RC54 pre-render Program POST routing and redirect safety','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],null,[
            $feature('programs','programs','برنامه‌ها','programs',[QYS_Programs_Module::class,'page'],'primary',35,'programs',['program-scope','program-registration']),
        ]));
        self::register(new QYS_Callback_Module('attendance',static function() use ($plugin_file): void {
            QYS_Program_Attendance_Boundary::register(new QYS_Attendance_Program_Adapter());
            QYS_Attendance::init($plugin_file);
        },['core','universities','programs'],false,[],[QYS_Program_Attendance_Boundary::class,'health'],[
            $feature('attendance','attendance','حضور','attendance',[QYS_Attendance::class,'admin_page'],'primary',40,'attendance',['attendance-scope','attendance-code']),
        ]));
        self::register(new QYS_Callback_Module('spreadsheetmessaging',static function() use ($plugin_file): void { QYS_Excel::init($plugin_file); },['core','universities','targetedmessaging'],false,[],null,[
            $feature('excel','excel','ارسال از فایل','send_messages',[QYS_Excel::class,'page'],'messages',120,'messages/excel',['excel-scope','excel-draft']),
        ]));
        self::register(new QYS_Callback_Module('module-registry',$noop,['universities'],true,[
            [
                'id'=>self::C7_MIGRATION,
                'callback'=>[__CLASS__,'migration_c7'],
                'meta'=>['label'=>'RC25 canonical module registry','schema_version'=>QYS_Config::SCHEMA_VERSION],
            ],
        ],[QYS_Module_Registry::class,'health']));
    }

    public static function register_migrations(): void {
        if(self::$migrations_registered)return;
        foreach(QYS_Module_Registry::modules() as $id=>$module){
            foreach($module->migrations() as $definition){
                if(!is_array($definition))throw new RuntimeException('Invalid migration definition for module '.$id);
                $migration_id=(string)($definition['id']??'');
                $callback=$definition['callback']??null;
                $meta=is_array($definition['meta']??null)?$definition['meta']:[];
                if($migration_id==='' || !is_callable($callback) || !QYS_Migration_Ledger::register($migration_id,$callback,$meta)){
                    throw new RuntimeException('Unable to register migration for module '.$id);
                }
            }
            self::$states[$id]['state']='migrations_registered';
        }
        self::$migrations_registered=true;
    }

    public static function boot_all(): void {
        if(self::$booted)return;
        $remaining=QYS_Module_Registry::module_ids();
        $guard=count($remaining)+2;

        while($remaining && $guard-- > 0){
            $progress=false;
            foreach($remaining as $index=>$id){
                $dependency_state=self::dependency_state($id);
                if($dependency_state==='waiting')continue;
                if($dependency_state!=='ready'){
                    self::mark_blocked($id,$dependency_state);
                    unset($remaining[$index]);
                    $progress=true;
                    continue;
                }
                self::boot_one($id);
                unset($remaining[$index]);
                $progress=true;
            }
            $remaining=array_values($remaining);
            if(!$progress)break;
        }

        // Any unresolved set is a dependency cycle. Optional modules are
        // quarantined; a critical cycle still fails closed.
        foreach($remaining as $id)self::mark_blocked($id,'dependency_cycle');
        self::$booted=true;
    }

    private static function dependency_state(string $id): string {
        foreach((array)self::$states[$id]['dependencies'] as $dependency){
            if(!isset(self::$states[$dependency]))return 'missing_dependency';
            $state=(string)(self::$states[$dependency]['state']??'registered');
            if(in_array($state,['registered','migrations_registered','booting'],true))return 'waiting';
            if($state!=='booted')return 'dependency_unavailable';
        }
        return 'ready';
    }

    private static function boot_one(string $id): void {
        self::$states[$id]['state']='booting';
        try {
            $module=QYS_Module_Registry::module($id);
            if(!$module)throw new RuntimeException('QuranYar module disappeared from registry: '.$id);
            $module->boot();
            self::$states[$id]['state']='booted';
            self::$states[$id]['error_code']='';
        } catch(Throwable $e) {
            self::$states[$id]['state']='failed';
            self::$states[$id]['error_code']='boot_'.self::safe_code(get_class($e));
            if(!empty(self::$states[$id]['critical']))throw $e;
        }
    }

    private static function mark_blocked(string $id,string $reason): void {
        self::$states[$id]['state']='blocked';
        self::$states[$id]['error_code']=self::safe_code($reason);
        if(!empty(self::$states[$id]['critical']))throw new RuntimeException('Critical QuranYar module dependency failure: '.$id);
    }

    /**
     * Feature Registry compatibility bridge. The old navigation registry stays
     * in place in C3, but it may no longer expose a module that failed boot.
     */
    public static function module_available(string $module): bool {
        $id=self::normalize_id($module);
        if($id==='')return true;
        if(!isset(self::$states[$id]))return false;
        return (string)(self::$states[$id]['state']??'')==='booted';
    }

    /** @deprecated C7: use module_available(). */
    public static function feature_available(string $module): bool { return self::module_available($module); }

    public static function status(): array {
        $counts=['registered'=>0,'migrations_registered'=>0,'booting'=>0,'booted'=>0,'failed'=>0,'blocked'=>0];
        $critical_failed=0;$health_degraded=0;
        foreach(self::$states as $id=>$row){
            $state=(string)($row['state']??'registered');
            if(!isset($counts[$state]))$state='registered';
            $counts[$state]++;
            if(!empty($row['critical']) && in_array($state,['failed','blocked'],true))$critical_failed++;
            if($state==='booted'){
                try {
                    $module=QYS_Module_Registry::module($id);
                    if(!$module)throw new RuntimeException('QuranYar module missing from registry');
                    $health=$module->health();
                    if(empty($health['ready']))$health_degraded++;
                } catch(Throwable $e) {
                    $health_degraded++;
                }
            }
        }
        $degraded=$counts['failed']+$counts['blocked']+$health_degraded;
        return [
            'ready'=>self::$booted && $critical_failed===0,
            'boot_complete'=>self::$booted,
            'total'=>count(self::$states),
            'booted'=>$counts['booted'],
            'failed'=>$counts['failed'],
            'blocked'=>$counts['blocked'],
            'critical_failed'=>$critical_failed,
            'health_degraded'=>$health_degraded,
            'degraded'=>$degraded,
        ];
    }

    /** Safe per-request rows for Health Center. No exception message is exposed. */
    public static function rows(): array {
        $rows=[];
        foreach(self::$states as $id=>$state){
            $health_ready=null;$health_code='not_booted';
            if((string)($state['state']??'')==='booted'){
                try {
                    $module=QYS_Module_Registry::module($id);
                    if(!$module)throw new RuntimeException('QuranYar module missing from registry');
                    $health=$module->health();
                    $health_ready=!empty($health['ready']);
                    $health_code=self::safe_code((string)($health['code']??($health_ready?'ok':'not_ready')));
                } catch(Throwable $e) {
                    $health_ready=false;
                    $health_code='health_'.self::safe_code(get_class($e));
                }
            }
            $rows[]=[
                'id'=>$id,
                'state'=>self::safe_code((string)($state['state']??'registered')),
                'critical'=>!empty($state['critical']),
                'dependencies'=>array_values(array_map([__CLASS__,'normalize_id'],(array)($state['dependencies']??[]))),
                'migration_count'=>max(0,(int)($state['migration_count']??0)),
                'feature_count'=>max(0,(int)($state['feature_count']??0)),
                'health_ready'=>$health_ready,
                'health_code'=>$health_code,
                'error_code'=>self::safe_code((string)($state['error_code']??'')),
            ];
        }
        return $rows;
    }

    public static function migration_c3(): bool {
        // Infrastructure-only migration: the runtime lifecycle contract is in
        // RC21 code. No domain row or physical schema is rewritten.
        return true;
    }

    public static function migration_c4(): bool {
        // Infrastructure-only migration: shared services are code boundaries.
        // No domain row or physical schema is rewritten.
        $health=QYS_Bootstrap_Facade::health();
        return !empty($health['ready']);
    }


    public static function migration_c5(): bool {
        // Infrastructure-only migration: dependency direction is a code contract.
        // No domain row or physical schema is rewritten.
        if(!class_exists(QYS_Program_Attendance_Boundary::class,false) || !QYS_Program_Attendance_Boundary::registered())return false;
        if(!defined('QYS_C5_BOUNDARIES_VERIFIED'))define('QYS_C5_BOUNDARIES_VERIFIED',true);
        return true;
    }

    public static function migration_c6(): bool {
        // Infrastructure-only migration: runtime persistence in repository-backed
        // domains must cross the canonical repository owner. Feature-owned schema
        // DDL remains with its owning installer/module. No domain row is rewritten.
        $contracts=[
            QYS_Program_Repository::class=>['begin','commit','rollback','admission_counts','lock_program','lock_application','insert_application','insert_enrollment','member_record_rows','backfill_tenant_snapshots'],
            QYS_Member_Repository::class=>['cancel_pending_birthday_for_member'],
            QYS_Campaign_Repository::class=>['insert_targeted_meta','mark_targeted_launched','mark_targeted_cancelled','due_targeted_campaign_ids'],
            QYS_University_Repository::class=>['begin','commit','rollback','insert_audit'],
        ];
        foreach($contracts as $class=>$methods){
            if(!class_exists($class))return false;
            foreach($methods as $method)if(!method_exists($class,$method))return false;
        }
        if(!defined('QYS_C6_REPOSITORY_OWNERSHIP_VERIFIED'))define('QYS_C6_REPOSITORY_OWNERSHIP_VERIFIED',true);
        return true;
    }

    public static function migration_d1(): bool {
        // Performance-only code contract. Runtime readiness must be backed by
        // durable version/ledger state while physical probes remain explicit.
        return class_exists(QYS_Schema_Runtime::class,false)
            && method_exists(QYS_Schema_Runtime::class,'ready')
            && method_exists(QYS_Table_Names::class,'runtime_exists')
            && method_exists(QYS_Table_Names::class,'diagnostic_columns');
    }



    public static function migration_d3(): bool {
        // Performance-only contract: Home uses a compact endpoint and never
        // needs Program form/field hydration. No schema/data changes.
        if(!class_exists(QYS_Home_Summary_Service::class,false)
            || !method_exists(QYS_Home_Summary_Service::class,'performance_contract')
            || !method_exists(QYS_Program_Repository::class,'home_summaries_for_member'))return false;
        $contract=QYS_Home_Summary_Service::performance_contract();
        return !empty($contract['ready'])
            && ($contract['endpoint']??'')==='lightweight'
            && ($contract['programs']??'')==='compact'
            && ($contract['forms']??'')==='deferred'
            && (int)($contract['program_limit']??0)===3;
    }

    public static function migration_d4(): bool {
        // Performance-only contract: feature-specific JS/CSS and full data are
        // deferred until the member opens Programs or Attendance. Home keeps
        // only the compact D3 Program summary in the base shell.
        if(!method_exists(QYS_Messenger::class,'lazy_feature_contract')
            || !method_exists(QYS_Programs_Module::class,'public_asset_manifest')
            || !method_exists(QYS_Attendance::class,'public_asset_manifest'))return false;
        $contract=QYS_Messenger::lazy_feature_contract();
        return !empty($contract['ready'])
            && ($contract['programs']??'')==='lazy'
            && ($contract['attendance']??'')==='lazy'
            && ($contract['full_data']??'')==='on_view'
            && ($contract['home_programs']??'')==='compact_summary';
    }

    public static function migration_d5(): bool {
        // Physical performance migration: existing queue tables receive the
        // composite dispatch index used by pending_batch(status + id order).
        if(!method_exists(QYS_Campaign_Repository::class,'ensure_queue_status_id_index'))return false;
        if(!QYS_Campaign_Repository::ensure_queue_status_id_index())return false;
        $contract=QYS_Campaign_Repository::queue_dispatch_index_contract();
        return !empty($contract['ready'])
            && ($contract['columns']??'')==='status,id'
            && ($contract['pending_scan']??'')==='indexed';
    }

    public static function migration_d6(): bool {
        // Performance-only contract. D6 changes worker policy and telemetry,
        // not delivery-state semantics or physical schema.
        if(!class_exists(QYS_Queue_Throughput::class,false)
            || !method_exists(QYS_Queue_Throughput::class,'contract')
            || !method_exists(QYS_Queue_Throughput::class,'plan'))return false;
        $contract=QYS_Queue_Throughput::contract();
        $plan=QYS_Queue_Throughput::plan();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='adaptive'
            && (int)($contract['budget_ms']??0)===20000
            && (int)($contract['max_messages']??0)===30
            && ($contract['rate_limit']??'')==='stop'
            && ($contract['uncertain']??'')==='stop'
            && (int)($plan['cap']??0)>=1
            && (int)($plan['cap']??0)<=30;
    }


    public static function migration_d7(): bool {
        // Performance-only contract: the minute worker heartbeat must remain
        // lightweight; the expensive full snapshot runs on its own 15-minute
        // schedule or explicitly on demand from Health/Ability surfaces.
        if(!method_exists(QYS_Health_Center::class,'snapshot_contract')
            || !method_exists(QYS_Health_Center::class,'scheduled_snapshot')
            || !method_exists(QYS_Health_Center::class,'snapshot_schedule_status'))return false;
        $contract=QYS_Health_Center::snapshot_contract();
        return !empty($contract['ready'])
            && ($contract['heartbeat']??'')==='lightweight'
            && ($contract['full']??'')==='periodic_on_demand'
            && (int)($contract['interval_seconds']??0)===900
            && empty($contract['worker_full_snapshot']);
    }

    public static function migration_d8(): bool {
        // Performance-only contract: CSV exports must stream bounded keyset
        // chunks instead of loading a whole report (or silently truncating it).
        if(!class_exists(QYS_Batch_Export::class,false)
            || !method_exists(QYS_Batch_Export::class,'contract')
            || !method_exists(QYS_Program_Repository::class,'applications_export_chunk')
            || !method_exists(QYS_Campaign_Repository::class,'non_sent_rows_after')
            || !method_exists(QYS_Member_Repository::class,'attendance_export_chunk_after'))return false;
        $contract=QYS_Batch_Export::contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='streaming'
            && (int)($contract['chunk']??0)===250
            && ($contract['cursor']??'')==='keyset'
            && ($contract['memory']??'')==='bounded';
    }

    public static function migration_d9(): bool {
        // Physical performance migration: materialize Jalali MM/DD once and use
        // an exact-match composite index instead of leading-wildcard scans.
        if(!method_exists(QYS_Member_Repository::class,'ensure_birthday_lookup_index')
            || !method_exists(QYS_Member_Repository::class,'birthday_lookup_contract'))return false;
        if(!QYS_Member_Repository::ensure_birthday_lookup_index())return false;
        $contract=QYS_Member_Repository::birthday_lookup_contract();
        return !empty($contract['ready'])
            && ($contract['column']??'')==='birth_md'
            && ($contract['query']??'')==='exact'
            && ($contract['index']??'')==='ready'
            && ($contract['legacy_like']??'')==='removed';
    }

    public static function migration_d10(): bool {
        // Performance-only contract: the university portal shell may load only
        // shared shell assets eagerly. Feature bundles are section/permission
        // scoped and stay owned by their lifecycle feature module.
        if(!class_exists(QYS_Portal_Asset_Policy::class,false)
            || !method_exists(QYS_Portal_Asset_Policy::class,'contract')
            || !method_exists(QYS_Portal_Asset_Policy::class,'should_load'))return false;
        $contract=QYS_Portal_Asset_Policy::contract();
        return !empty($contract['ready'])
            && ($contract['shell']??'')==='base'
            && ($contract['feature']??'')==='conditional'
            && ($contract['scope']??'')==='section_permission'
            && (int)($contract['eager_feature_bundles']??-1)===0;
    }

    public static function migration_e2(): bool {
        // UX contract only: Programs access must explain why access is blocked
        // and offer an explicit profile CTA; client navigation may not silently
        // redirect the user away from the Programs view. Backend authorization
        // remains authoritative.
        if(!method_exists(QYS_Programs_Module::class,'access_gate_contract'))return false;
        $contract=QYS_Programs_Module::access_gate_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='explain'
            && ($contract['cta']??'')==='explicit'
            && ($contract['redirect']??'')==='none'
            && in_array(($contract['states']??''),['membership_profile_verification','membership_consent_profile_verification'],true)
            && !empty($contract['backend_authoritative']);
    }

    public static function migration_e3(): bool {
        // UX safety contract only: terminal Program transitions and applicant
        // rejection require an explicit client confirmation plus a server-side
        // confirmation marker before the existing domain transition executes.
        if(!method_exists(QYS_Programs_Module::class,'destructive_confirmation_contract'))return false;
        $contract=QYS_Programs_Module::destructive_confirmation_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='explicit'
            && ($contract['program']??'')==='complete_cancel_archive'
            && ($contract['applicant']??'')==='reject'
            && ($contract['client']??'')==='confirm_before_submit'
            && ($contract['server']??'')==='required';
    }

    public static function migration_e4(): bool {
        // UX/workflow contract: Program metadata editing is state-aware, keeps
        // capacity above current commitments, freezes audience after draft and
        // freezes attendance configuration after a real attendance link exists.
        if(!method_exists(QYS_Programs_Module::class,'program_edit_contract')
            || !method_exists(QYS_Program_Service::class,'edit_policy')
            || !method_exists(QYS_Program_Service::class,'update_details'))return false;
        $contract=QYS_Programs_Module::program_edit_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='state_aware'
            && ($contract['core']??'')==='title_description'
            && ($contract['capacity']??'')==='reserved_floor'
            && ($contract['audience']??'')==='draft_only'
            && ($contract['attendance']??'')==='pre_link'
            && ($contract['terminal']??'')==='read_only'
            && ($contract['server']??'')==='required';
    }

    public static function migration_e5(): bool {
        // UX/workflow contract: a verified member can withdraw only their own
        // active application/enrollment, with explicit confirmation and no
        // deletion of historical Program/Attendance records.
        if(!method_exists(QYS_Programs_Module::class,'member_withdraw_contract')
            || !method_exists(QYS_Program_Service::class,'member_withdraw_state')
            || !method_exists(QYS_Program_Service::class,'withdraw_member'))return false;
        $contract=QYS_Programs_Module::member_withdraw_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='self_service'
            && ($contract['application']??'')==='withdrawable'
            && ($contract['enrollment']??'')==='withdrawable'
            && ($contract['confirmation']??'')==='explicit'
            && ($contract['history']??'')==='preserved'
            && ($contract['server']??'')==='owner_required';
    }

    public static function migration_e6(): bool {
        // UX contract only: async member surfaces must expose loading, empty and
        // error as distinct states, and recoverable errors need an explicit retry.
        if(!method_exists(QYS_Member_Experience::class,'async_state_contract'))return false;
        $contract=QYS_Member_Experience::async_state_contract();
        return !empty($contract['ready'])
            && ($contract['loading']??'')==='visible'
            && ($contract['empty']??'')==='distinct'
            && ($contract['error']??'')==='visible'
            && ($contract['retry']??'')==='explicit'
            && ($contract['scopes']??'')==='home_programs_feature_loads';
    }

    public static function migration_e7(): bool {
        // UX workflow contract only: member Program form answers are preserved
        // locally across refresh/navigation, scoped to member+program+form and
        // cleared after successful submission. No server-side draft table exists.
        if(!method_exists(QYS_Programs_Module::class,'member_form_draft_contract'))return false;
        $contract=QYS_Programs_Module::member_form_draft_contract();
        return !empty($contract['ready'])
            && ($contract['storage']??'')==='local'
            && ($contract['scope']??'')==='member_program_form'
            && (int)($contract['ttl_hours']??0)===24
            && ($contract['restore']??'')==='automatic'
            && ($contract['clear']??'')==='successful_submit'
            && ($contract['server']??'')==='not_persisted';
    }

    public static function migration_e8(): bool {
        // UX validation contract only: the client exposes field-level errors,
        // including required multi-choice groups, while backend validation remains authoritative.
        if(!method_exists(QYS_Programs_Module::class,'field_validation_contract'))return false;
        $contract=QYS_Programs_Module::field_validation_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='field_level'
            && ($contract['required']??'')==='client_server'
            && ($contract['multi_choice']??'')==='one_or_more'
            && ($contract['error']??'')==='inline'
            && ($contract['focus']??'')==='first_invalid'
            && ($contract['server']??'')==='authoritative';
    }

    public static function migration_e9(): bool {
        // UX/workflow contract only: filters and selected bulk actions are scoped
        // to the current tenant; every selected row still passes the canonical
        // per-application transition/enrollment policies and returns a summary.
        if(!method_exists(QYS_Programs_Module::class,'bulk_applicant_contract'))return false;
        $contract=QYS_Programs_Module::bulk_applicant_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='selected'
            && ($contract['filters']??'')==='status_role_enrollment_search'
            && ($contract['actions']??'')==='transition_enroll'
            && ($contract['scope']??'')==='tenant'
            && ($contract['result']??'')==='partial_summary'
            && (int)($contract['max_selected']??0)===100
            && ($contract['destructive']??'')==='explicit';
    }

    public static function migration_m1(): bool {
        // Maintenance contract: the portal renders one representative preview,
        // approved-but-undelivered campaigns stay editable transactionally and
        // workers reload the canonical payload after claiming a queue row.
        if(!method_exists(QYS_Targeted::class,'campaign_edit_contract'))return false;
        foreach(['preview_sample','lock_targeted_edit','update_campaign_copy','update_pending_payload','claimed_payload'] as $method){
            if(!method_exists(QYS_Campaign_Repository::class,$method))return false;
        }
        $contract=QYS_Targeted::campaign_edit_contract();
        return !empty($contract['ready'])
            && (int)($contract['preview_rows']??0)===1
            && ($contract['editable']??'')==='draft_scheduled_active_before_delivery'
            && ($contract['queue']??'')==='pending_only'
            && ($contract['payload']??'')==='rebuild_transactional'
            && ($contract['worker']??'')==='reload_after_claim';
    }

    public static function migration_m2(): bool {
        // Maintenance contract: applicant status changes enqueue notification
        // messages immediately through the canonical safe queue. Copy is editable
        // per Program + campus and delivery still respects member consent.
        if(!class_exists(QYS_Program_Notifications::class,false)||!method_exists(QYS_Program_Notifications::class,'contract'))return false;
        $contract=QYS_Program_Notifications::contract();
        return !empty($contract['ready'])
            && ($contract['events']??'')==='review_waitlist_accept_reject_enroll'
            && ($contract['queue']??'')==='canonical'
            && ($contract['timing']??'')==='immediate_enqueue'
            && in_array(($contract['templates']??''),['program_campus_editable','university_global_editable'],true)
            && ($contract['variables']??'')==='member_program_status_university'
            && ($contract['consent']??'')==='required';
    }

    public static function migration_m3(): bool {
        // RC48 product correction: message copy is configured once per global
        // messaging scope and applies to all Programs; Program-specific option
        // keys are preserved only as legacy data and are no longer consulted.
        if(!class_exists(QYS_Program_Notifications::class,false)||!method_exists(QYS_Program_Notifications::class,'contract'))return false;
        foreach(['scope_templates','save_scope_templates','legacy_storage_policy'] as $method)if(!method_exists(QYS_Program_Notifications::class,$method))return false;
        $contract=QYS_Program_Notifications::contract();$legacy=QYS_Program_Notifications::legacy_storage_policy();
        return !empty($contract['ready'])
            && ($contract['templates']??'')==='university_global_editable'
            && ($contract['settings_scope']??'')==='all_programs'
            && ($contract['editor']??'')==='collapsed_disclosure'
            && ($legacy['read']??'')==='ignored'
            && ($legacy['write']??'')==='disabled'
            && ($legacy['delete']??'')==='preserved';
    }

    public static function migration_m7(): bool {
        // RC53 maintenance: a member who previously withdrew may explicitly
        // re-submit while registration is open. The renewed request returns to
        // normal review; a withdrawn enrollment is only reactivated after a new
        // acceptance/enrollment action, and capacity is reserved again safely.
        if(!method_exists(QYS_Programs_Module::class,'member_reapply_contract'))return false;
        foreach(['member_reapply_state_from_statuses','reapply_member','enroll_application'] as $method)if(!method_exists(QYS_Program_Service::class,$method))return false;
        if(!method_exists(QYS_Program_Repository::class,'reactivate_withdrawn_enrollment'))return false;
        $contract=QYS_Programs_Module::member_reapply_contract();
        return !empty($contract['ready'])
            && ($contract['mode']??'')==='self_service'
            && ($contract['source']??'')==='withdrawn_only'
            && ($contract['target']??'')==='submitted'
            && ($contract['approval']??'')==='normal_flow'
            && ($contract['capacity']??'')==='rechecked'
            && ($contract['enrollment']??'')==='reactivate_after_accept'
            && ($contract['history']??'')==='audit_preserved'
            && ($contract['confirmation']??'')==='explicit';
    }

    public static function migration_m8(): bool {
        // RC54 maintenance: all Program state-changing forms are handled before
        // template output, so PRG redirects remain reliable in the standalone
        // university portal. Notification enqueue failures are fail-soft and may
        // not strand a successful domain transition on an empty shell.
        if(!method_exists(QYS_Programs_Module::class,'post_redirect_contract'))return false;
        if(!method_exists(QYS_Program_Notifications::class,'queue_for_applications_safe'))return false;
        $contract=QYS_Programs_Module::post_redirect_contract();
        return !empty($contract['ready'])
            && ($contract['portal_hook']??'')==='template_redirect'
            && ($contract['admin_hook']??'')==='admin_init'
            && ($contract['timing']??'')==='pre_render'
            && ($contract['redirect']??'')==='canonical_program_url'
            && ($contract['notification']??'')==='fail_soft'
            && ($contract['statuses']??'')==='review_waitlist_accept_reject_enroll';
    }

    public static function migration_e10(): bool {
        // Historical E10 contract remains valid after RC49: progress is still
        // server-authoritative and 100% means complete. RC49 extends the basis
        // from seven canonical identity/profile fields to those fields + consent.
        if(!method_exists(QYS_Messenger::class,'profile_progress_contract'))return false;
        $contract=QYS_Messenger::profile_progress_contract();
        return !empty($contract['ready'])
            && ($contract['source']??'')==='server'
            && in_array(($contract['basis']??''),['canonical_required','canonical_required_plus_consent','canonical_required_plus_consent_plus_mobile_otp'],true)
            && in_array((int)($contract['total_fields']??0),[7,8,9],true)
            && ($contract['hundred']??'')==='complete_only'
            && ($contract['ui']??'')==='incomplete_only';
    }

    public static function migration_e1(): bool {
        // E1's former optional-consent product decision is historical. RC49
        // deliberately supersedes it; accept either contract here so old ledger
        // history remains replay-safe while M4 asserts the current product rule.
        if(!method_exists(QYS_Messenger::class,'membership_consent_contract'))return false;
        $contract=QYS_Messenger::membership_consent_contract();
        if(empty($contract['ready']))return false;
        $old=($contract['membership']??'')==='independent'&&($contract['messaging']??'')==='optional'&&empty($contract['profile_requires_consent']);
        $new=($contract['membership']??'')==='requires_messaging'&&($contract['messaging']??'')==='required'&&!empty($contract['profile_requires_consent']);
        return $old||$new;
    }

    public static function migration_m4(): bool {
        // RC49 product correction: messaging permission is a hard prerequisite
        // for creating/completing the member profile and member self-disable is blocked.
        if(!method_exists(QYS_Messenger::class,'membership_consent_contract')||!method_exists(QYS_Messenger::class,'profile_progress_contract'))return false;
        $contract=QYS_Messenger::membership_consent_contract();$progress=QYS_Messenger::profile_progress_contract();
        return !empty($contract['ready'])
            && ($contract['membership']??'')==='requires_messaging'
            && ($contract['messaging']??'')==='required'
            && ($contract['new_member_default']??'')==='permission_first'
            && !empty($contract['profile_requires_consent'])
            && ($contract['gate']??'')==='before_profile'
            && ($contract['disable']??'')==='blocked_for_member'
            && !empty($progress['ready'])
            && in_array(($progress['basis']??''),['canonical_required_plus_consent','canonical_required_plus_consent_plus_mobile_otp'],true)
            && in_array((int)($progress['total_fields']??0),[8,9],true)
            && ($progress['consent_gate']??'')==='required'
            && method_exists(QYS_Programs_Module::class,'access_gate_contract')
            && ((QYS_Programs_Module::access_gate_contract()['states']??'')==='membership_consent_profile_verification');
    }

    public static function migration_m5(): bool {
        // RC50 product maintenance: family attribution is a relation graph, not
        // a replacement for member_role. This supports two employed parents and
        // members who are themselves employees while also being a child/spouse.
        if(!class_exists(QYS_Family_Relationship_Repository::class,false)
            || !method_exists(QYS_Family_Relationship_Repository::class,'ensure_schema')
            || !method_exists(QYS_Messenger::class,'family_attribution_contract'))return false;
        if(!QYS_Family_Relationship_Repository::ensure_schema())return false;
        $contract=QYS_Messenger::family_attribution_contract();
        return QYS_Family_Relationship_Repository::diagnostic_schema_ready()
            && ($contract['model']??'')==='relation_graph'
            && !empty($contract['role_independent'])
            && ($contract['staff_family']??'')==='required'
            && ($contract['employee_overlap']??'')==='allowed'
            && ($contract['multiple_parents']??'')==='allowed'
            && ($contract['types']??'')==='spouse_child'
            && ($contract['scope']??'')==='university'
            && ($contract['privacy']??'')==='exact_lookup_no_directory';
    }

    public static function migration_m6(): bool {
        // RC51: new registrations and mobile changes require a verified OTP.
        // Legacy numbers are not falsely marked verified; they remain usable
        // until the member changes the stored mobile number.
        if(!class_exists(QYS_Mobile_OTP::class,false)||!method_exists(QYS_Member_Repository::class,'ensure_mobile_verification_schema'))return false;
        if(!QYS_Member_Repository::ensure_mobile_verification_schema())return false;
        if(!method_exists(QYS_Messenger::class,'mobile_otp_contract'))return false;
        $contract=QYS_Messenger::mobile_otp_contract();$progress=QYS_Messenger::profile_progress_contract();
        return QYS_Table_Names::diagnostic_has_columns(QYS_Table_Names::name('members'),['mobile_verified_at_utc','mobile_verification_required'])
            && !empty($contract['ready'])
            && ($contract['provider']??'')==='ippanel'
            && ($contract['channel']??'')==='pattern_sms'
            && ($contract['registration']??'')==='required'
            && ($contract['mobile_change']??'')==='required'
            && ($contract['legacy']??'')==='grandfathered_until_change'
            && ($progress['basis']??'')==='canonical_required_plus_consent_plus_mobile_otp'
            && (int)($progress['total_fields']??0)===9
            && ($progress['mobile_otp']??'')==='registration_and_change';
    }

    public static function migration_d2(): bool {
        // Performance-only contract: Program list/form/count/answer read models
        // must expose bounded batch/grouped query paths. No schema/data changes.
        foreach(['current_form_fields_for_programs','counts_for_programs','answers_for_applications','performance_contract'] as $method){
            if(!method_exists(QYS_Program_Repository::class,$method))return false;
        }
        $contract=QYS_Program_Repository::performance_contract();
        return !empty($contract['ready'])
            && ($contract['forms']??'')==='batch'
            && ($contract['counts']??'')==='grouped'
            && ($contract['answers']??'')==='batch';
    }

    public static function migration_c7(): bool {
        // Infrastructure-only migration: module ownership and portal feature
        // metadata are code contracts. No domain row or physical schema changes.
        $health=QYS_Module_Registry::health();
        if(empty($health['ready']) || (int)($health['feature_count']??0)!==10)return false;
        foreach(QYS_Module_Registry::features() as $feature){
            $module=(string)($feature['module']??'');
            if($module==='' || QYS_Module_Registry::module($module)===null)return false;
        }
        if(!defined('QYS_C7_MODULE_REGISTRY_VERIFIED'))define('QYS_C7_MODULE_REGISTRY_VERIFIED',true);
        return true;
    }


}

