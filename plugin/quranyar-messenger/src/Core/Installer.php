<?php
if (!defined('ABSPATH')) exit;

/**
 * Fresh-install bootstrap for QuranYar.
 * Activation itself stays lightweight; schema creation runs on the next
 * authenticated administrator request and failures are captured instead of
 * taking down wp-admin with a fatal error.
 */
final class QYS_Installer {
    private const STATE_OPTION='qys_install_state';
    private const ERROR_OPTION='qys_install_error';
    private const OWNER_CANDIDATE_OPTION='qys_install_owner_candidate';

    public static function init(): void {
        // admin_init remains only as fresh-install/manual fallback; C2 automatic
        // upgrades are driven by QYS_Upgrade_Coordinator on ordinary requests.
        add_action('admin_init',[__CLASS__,'maybe_run'],1);
        add_action('admin_notices',[__CLASS__,'admin_notice'],1);
    }

    /** Activation must never perform long/fragile database work. */
    public static function activate(): void {
        $uid=get_current_user_id();
        if($uid>0 && user_can($uid,'manage_options')){
            update_option(self::OWNER_CANDIDATE_OPTION,$uid,false);
        }
        update_option(self::STATE_OPTION,'pending',false);
        delete_option(self::ERROR_OPTION);
    }

    public static function ready(): bool {
        $base=get_option(self::STATE_OPTION,'')==='ready'
            && (string)get_option('qys_schema_version','')===QYS_Config::SCHEMA_VERSION;
        if(!$base)return false;
        // Compatibility window for an older pre-ledger release. C2 will import
        // a proven current installation automatically; a true fresh install is
        // still handled only by the authenticated bootstrap path.
        if(!class_exists('QYS_Migration_Ledger'))return true;
        if(!QYS_Migration_Ledger::initialized())return !QYS_Migration_Ledger::required();
        return QYS_Migration_Ledger::ready();
    }

    public static function automatic_baseline_eligible(): bool {
        if(!class_exists('QYS_Migration_Ledger') || QYS_Migration_Ledger::initialized())return false;
        return get_option(self::STATE_OPTION,'')==='ready'
            && (string)get_option('qys_schema_version','')===QYS_Config::SCHEMA_VERSION
            && !QYS_Migration_Ledger::required();
    }

    public static function maybe_run(): void {
        $ledger_needs=class_exists('QYS_Migration_Ledger') && QYS_Migration_Ledger::needs_run();
        if(self::ready() && !$ledger_needs)return;
        // Once the durable ledger exists, upgrade execution no longer depends
        // on this hook. Keep an authenticated fallback for wp-admin requests.
        if(class_exists('QYS_Migration_Ledger') && QYS_Migration_Ledger::initialized()){
            if(current_user_can('manage_options'))self::run_automatic_upgrade(5,5000);
            return;
        }
        // First-install owner bootstrap still requires an authenticated admin.
        if(!is_user_logged_in() || !current_user_can('manage_options'))return;
        self::run();
    }

    /**
     * Post-C1 automatic upgrade path. Safe on public/REST/cron requests because
     * it can execute only code-defined ledger migrations and never bootstraps
     * ownership or accepts request-provided migration code.
     */
    public static function run_automatic_upgrade(int $max_migrations=1,int $budget_ms=1500): array {
        if(!class_exists('QYS_Migration_Ledger') || !QYS_Migration_Ledger::initialized()){
            return ['result'=>'fresh_install_admin_required','ran'=>0,'remaining'=>1,'ready'=>false];
        }

        // If another request already completed the upgrade, normalize state.
        if(!QYS_Migration_Ledger::needs_run() && QYS_Migration_Ledger::ready()){
            update_option('qys_schema_version',QYS_Config::SCHEMA_VERSION,false);
            update_option(self::STATE_OPTION,'ready',false);
            delete_option(self::ERROR_OPTION);
            return ['result'=>'already_ready','ran'=>0,'remaining'=>0,'ready'=>true];
        }

        update_option(self::STATE_OPTION,'installing',false);
        delete_option(self::ERROR_OPTION);
        $run=QYS_Migration_Ledger::run_pending_bounded(max(1,$max_migrations),max(100,$budget_ms));

        if(($run['result']??'')==='locked'){
            // A concurrent request owns the migration lock. This request must
            // stay gated, but the installation is not considered failed.
            return ['result'=>'locked','ran'=>0,'remaining'=>max(1,(int)($run['remaining']??1)),'ready'=>false];
        }

        if(empty($run['ok'])){
            $code=sanitize_key((string)($run['result']??'migration_failed'));
            update_option(self::ERROR_OPTION,$code,false);
            update_option(self::STATE_OPTION,'failed',false);
            return ['result'=>$code,'ran'=>max(0,(int)($run['ran']??0)),'remaining'=>max(0,(int)($run['remaining']??1)),'ready'=>false];
        }

        $ready=!empty($run['ready']) && QYS_Migration_Ledger::ready();
        if($ready){
            update_option('qys_schema_version',QYS_Config::SCHEMA_VERSION,false);
            update_option(self::STATE_OPTION,'ready',false);
            delete_option(self::ERROR_OPTION);
            delete_option(self::OWNER_CANDIDATE_OPTION);
            return ['result'=>'complete','ran'=>max(0,(int)($run['ran']??0)),'remaining'=>0,'ready'=>true];
        }

        // Bounded runner intentionally leaves the gate closed until a later
        // request advances the next migration.
        update_option(self::STATE_OPTION,'installing',false);
        return ['result'=>'bounded','ran'=>max(0,(int)($run['ran']??0)),'remaining'=>max(0,(int)($run['remaining']??0)),'ready'=>false];
    }

    public static function run(): bool {
        if(!current_user_can('manage_options'))return false;
        $pre_state=(string)get_option(self::STATE_OPTION,'');
        $pre_schema=(string)get_option('qys_schema_version','');
        $had_ledger=class_exists('QYS_Migration_Ledger') && QYS_Migration_Ledger::initialized();
        $legacy_current=$pre_state==='ready' && $pre_schema===QYS_Config::SCHEMA_VERSION;

        update_option(self::STATE_OPTION,'installing',false);
        delete_option(self::ERROR_OPTION);

        try {
            if(!$had_ledger){
                // Existing RC18 installations are imported as a baseline and do
                // not replay RC12-RC18 data migrations. Fresh/incomplete installs
                // still receive the proven legacy bootstrap once.
                if(!$legacy_current)self::run_legacy_bootstrap();
                self::require_step(QYS_Migration_Ledger::initialize_baseline($legacy_current?'existing_rc18':'fresh_current'),'migration_baseline');
            }

            self::require_step(QYS_Migration_Ledger::run_pending(),'migration_ledger');
            self::require_step(QYS_Migration_Ledger::ready(),'migration_ledger_ready');

            update_option('qys_schema_version',QYS_Config::SCHEMA_VERSION,false);
            update_option(self::STATE_OPTION,'ready',false);
            delete_option(self::ERROR_OPTION);
            delete_option(self::OWNER_CANDIDATE_OPTION);

            // Only after storage, authority and migrations are ready may public entry pages exist.
            QYS_Multitenant::ensure_portal_page();
            QYS_Multitenant::ensure_entry_pages();
            return true;
        } catch (Throwable $e) {
            $message=self::safe_error($e);
            update_option(self::ERROR_OPTION,$message,false);
            update_option(self::STATE_OPTION,'failed',false);
            // Server logs contain no tokens or PII; this is a bounded installer diagnostic.
            error_log('[QuranYar installer] '.$message);
            return false;
        }
    }

    private static function run_legacy_bootstrap(): void {
        self::create_core_schema();
        self::require_step(QYS_Messenger::ensure_welcome_schema(),'welcome_log');
        self::require_step(QYS_Messenger::ensure_activation_schema(),'activation_log');
        self::require_step(QYS_Messenger::ensure_birthday_schema(),'birthday_log');
        self::require_step(QYS_Messenger::ensure_birthday_archive_schema(),'birthday_archive');
        self::require_step(QYS_Messenger::ensure_archive_access_schema(),'archive_access_log');
        self::require_step(QYS_Multitenant::ensure_schema(),'universities');
        self::require_step(QYS_Targeted::ensure_schema(),'targeted');
        self::require_step(QYS_Program_Repository::ensure_schema(),'programs');
        self::require_step(QYS_Program_Service::backfill_tenant_snapshots(),'program_tenant_snapshots');
        self::require_step(QYS_Attendance::ensure_schema(),'attendance');
        self::require_step(QYS_Program_Service::backfill_attendance_programs(),'program_attendance_backfill');
        self::require_step(QYS_Program_Service::reconcile_terminal_programs(),'program_terminal_reconcile');
        self::require_step(QYS_Campus_Birthday::ensure_schema(),'campus_birthday');
        self::require_step(QYS_Campus_Welcome::ensure_schema(),'campus_welcome');
        self::bootstrap_owner_and_saveh();
    }

    private static function create_core_schema(): void { global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        dbDelta('CREATE TABLE '.QYS_Table_Names::name('members')." (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        eitaa_id varchar(32) NOT NULL,
        first_name varchar(100) NOT NULL DEFAULT '',
        last_name varchar(100) NOT NULL DEFAULT '',
        national_id varchar(10) DEFAULT NULL,
        university varchar(40) NOT NULL DEFAULT '',
        pending_university varchar(40) NOT NULL DEFAULT '',
        transfer_requested_at_utc datetime NULL DEFAULT NULL,
        member_role varchar(32) NOT NULL DEFAULT '',
        mobile varchar(11) DEFAULT NULL,
        mobile_verified_at_utc datetime NULL DEFAULT NULL,
        mobile_verification_required tinyint unsigned NOT NULL DEFAULT 1,
        birth_jalali varchar(10) NOT NULL DEFAULT '',
        birth_md char(5) NOT NULL DEFAULT '',
        birthday_opt_in tinyint unsigned NOT NULL DEFAULT 0,
        verified tinyint unsigned NOT NULL DEFAULT 0,
        consent tinyint unsigned NOT NULL DEFAULT 0,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id), UNIQUE KEY eitaa_id (eitaa_id), UNIQUE KEY mobile (mobile), UNIQUE KEY national_id (national_id), KEY university_state (university,verified,consent), KEY pending_transfer (pending_university,transfer_requested_at_utc), KEY birthday_lookup (birth_md,birthday_opt_in,verified,consent,id)
        ) $c;");
        dbDelta('CREATE TABLE '.QYS_Table_Names::name('family_links')." (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        member_id bigint unsigned NOT NULL,
        staff_member_id bigint unsigned NOT NULL,
        relation_type varchar(16) NOT NULL,
        university varchar(40) NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id), UNIQUE KEY member_staff_scope (member_id,staff_member_id,university), KEY member_scope (member_id,university), KEY staff_scope (staff_member_id,university), KEY relation_scope (relation_type,university)
        ) $c;");
        dbDelta('CREATE TABLE '.QYS_Table_Names::name('campaigns')." (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        label varchar(190) NOT NULL,
        template longtext NOT NULL,
        state varchar(16) NOT NULL DEFAULT 'draft',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id), KEY campaign_state (state)
        ) $c;");
        dbDelta('CREATE TABLE '.QYS_Table_Names::name('queue')." (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        campaign_id bigint unsigned NOT NULL,
        member_id bigint unsigned NOT NULL,
        payload longtext NOT NULL,
        status varchar(24) NOT NULL DEFAULT 'pending',
        attempts tinyint unsigned NOT NULL DEFAULT 0,
        last_error varchar(250) NOT NULL DEFAULT '',
        claimed_at_utc datetime NULL DEFAULT NULL,
        lease_expires_at_utc datetime NULL DEFAULT NULL,
        outbound_started_at_utc datetime NULL DEFAULT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id), UNIQUE KEY campaign_member (campaign_id,member_id), KEY campaign_status (campaign_id,status), KEY member_status (member_id,status), KEY status_lease (status,lease_expires_at_utc), KEY status_id (status,id)
        ) $c;");
        dbDelta('CREATE TABLE '.QYS_Table_Names::name('import_reports')." (
        campaign_id bigint unsigned NOT NULL,
        input_rows int unsigned NOT NULL DEFAULT 0,
        readable_rows int unsigned NOT NULL DEFAULT 0,
        queued_rows int unsigned NOT NULL DEFAULT 0,
        invalid_id int unsigned NOT NULL DEFAULT 0,
        duplicate_id int unsigned NOT NULL DEFAULT 0,
        unmatched_id int unsigned NOT NULL DEFAULT 0,
        ineligible_member int unsigned NOT NULL DEFAULT 0,
        ambiguous_match int unsigned NOT NULL DEFAULT 0,
        missing_variables int unsigned NOT NULL DEFAULT 0,
        queue_error int unsigned NOT NULL DEFAULT 0,
        malformed_csv int unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (campaign_id)
        ) $c;");
        dbDelta('CREATE TABLE '.QYS_Table_Names::name('excel_followup')." (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        campaign_id bigint unsigned NOT NULL,
        source_row int unsigned NOT NULL DEFAULT 0,
        member_id bigint unsigned NOT NULL DEFAULT 0,
        person_name varchar(190) NOT NULL DEFAULT '',
        national_id varchar(32) NOT NULL DEFAULT '',
        mobile varchar(20) NOT NULL DEFAULT '',
        reason varchar(32) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY campaign_row_reason (campaign_id,source_row,reason),
        KEY campaign_reason (campaign_id,reason),
        KEY member_id (member_id)
        ) $c;");

        self::require_step(QYS_Member_Repository::diagnostic_table_exists(),'members');
        self::require_step(QYS_Campaign_Repository::diagnostic_campaigns_ready(),'campaigns');
        self::require_step(QYS_Campaign_Repository::diagnostic_queue_ready(),'queue');
        self::require_step(QYS_Campaign_Repository::migrate_delivery_states(),'queue_delivery_states');
        self::require_step(QYS_Campaign_Repository::migrate_sending_leases(),'queue_sending_leases');
        self::require_step(QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('import_reports')),'import_reports');
        self::require_step(QYS_Table_Names::diagnostic_exists(QYS_Table_Names::name('excel_followup')),'excel_followup');
    }

    private static function bootstrap_owner_and_saveh(): void { global $wpdb;
        $owner=QYS_Multitenant::owner_id();
        if($owner<=0){
            $candidate=(int)get_option(self::OWNER_CANDIDATE_OPTION,0);
            if($candidate<=0 || !user_can($candidate,'manage_options'))$candidate=get_current_user_id();
            if($candidate<=0 || !user_can($candidate,'manage_options'))throw new RuntimeException('owner_candidate_unavailable');
            if(!add_option(QYS_Multitenant::OWNER_OPTION,$candidate,'',false)){
                $owner=QYS_Multitenant::owner_id();
                if($owner<=0)throw new RuntimeException('owner_option_write_failed');
            } else $owner=$candidate;
        }
        if(!user_can($owner,'manage_options'))throw new RuntimeException('owner_capability_missing');
        if(!QYS_University_Repository::ensure_saveh($owner))throw new RuntimeException('saveh_seed_failed');
        $now=gmdate('Y-m-d H:i:s');
        $permissions=implode(',',array_keys(QYS_Config::PERMISSIONS));
        if(QYS_University_Repository::upsert_owner_assignment($owner,'saveh_ums',$permissions,$owner,$now)===false){
            throw new RuntimeException('owner_saveh_assignment_failed');
        }
        $audit=QYS_Multitenant::audit_table();
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $audit WHERE actor_id=%d AND action=%s AND university=%s",$owner,'owner_bootstrapped','saveh_ums'));
        if(!$exists){
            $ok=$wpdb->insert($audit,['actor_id'=>$owner,'action'=>'owner_bootstrapped','university'=>'saveh_ums','subject_user_id'=>$owner,'created_at_utc'=>$now],['%d','%s','%s','%d','%s']);
            if($ok!==1)throw new RuntimeException('owner_audit_failed');
        }
    }

    private static function require_step(bool $ok,string $step): void {
        if(!$ok)throw new RuntimeException('schema_step_failed:'.$step);
    }

    private static function safe_error(Throwable $e): string {
        $raw=get_class($e).': '.$e->getMessage();
        $raw=preg_replace('/[\r\n\t]+/',' ',(string)$raw);
        $raw=preg_replace('/[^A-Za-z0-9_:\-\. ]/','?',(string)$raw);
        return substr($raw,0,240);
    }

    public static function admin_notice(): void {
        if(!current_user_can('manage_options'))return;
        $state=(string)get_option(self::STATE_OPTION,'');
        if($state==='failed'){
            $error=(string)get_option(self::ERROR_OPTION,'unknown');
            echo '<div class="notice notice-error"><p><strong>قرآن‌یار:</strong> راه‌اندازی اولیه کامل نشد. کد تشخیصی: <code>'.esc_html($error).'</code></p></div>';
        } elseif($state==='pending'||$state==='installing'){
            echo '<div class="notice notice-info"><p><strong>قرآن‌یار:</strong> راه‌اندازی اولیه در حال تکمیل است.</p></div>';
        }
    }
}
