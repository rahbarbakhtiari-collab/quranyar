<?php
if (!defined('ABSPATH')) exit;

/**
 * Durable, ordered migration ledger for QuranYar.
 *
 * Historical RC12-RC18 work is imported as an immutable baseline on the first
 * RC19 administrator run. Future migrations are registered independently and
 * executed exactly through this ledger instead of replaying the legacy schema
 * bootstrap on every version change.
 *
 * The ledger intentionally lives in a non-autoloaded WordPress option. It is
 * small, contains no PII, and can bootstrap without requiring a migration table
 * of its own.
 */
final class QYS_Migration_Ledger {
    private const OPTION='qys_migration_ledger_v1';
    private const LOCK_OPTION='qys_migration_ledger_lock';
    private const REQUIRED_OPTION='qys_migration_ledger_required';
    private const FORMAT=1;
    private const LOCK_TTL=300;
    private const BASELINE_SCHEMA='2026.10.05.7';
    private const BUILTIN_CURRENT_ID='20261006_009_rc20_upgrade_coordinator';

    private const BASELINE_MIGRATIONS=[
        '20261005_001_rc12_tenant_boundaries'=>['label'=>'RC12 tenant/data boundaries','plugin_version'=>'1.3.1-rc12','schema_version'=>'2026.10.05.3'],
        '20261005_002_rc13_delivery_state'=>['label'=>'RC13 delivery state model','plugin_version'=>'1.3.1-rc13','schema_version'=>'2026.10.05.4'],
        '20261005_003_rc14_sending_lease'=>['label'=>'RC14 sending lease/recovery','plugin_version'=>'1.3.1-rc14','schema_version'=>'2026.10.05.5'],
        '20261005_004_rc15_onboarding_reliability'=>['label'=>'RC15 welcome/activation reliability','plugin_version'=>'1.3.1-rc15','schema_version'=>'2026.10.05.6'],
        '20261005_005_rc16_terminal_state'=>['label'=>'RC16 program terminal-state reconciliation','plugin_version'=>'1.3.1-rc16','schema_version'=>'2026.10.05.7'],
        '20261005_006_rc17_admission_policy'=>['label'=>'RC17 capacity/waitlist policy','plugin_version'=>'1.3.1-rc17','schema_version'=>'2026.10.05.7'],
        '20261005_007_rc18_retention_policy'=>['label'=>'RC18 retention policy','plugin_version'=>'1.3.1-rc18','schema_version'=>'2026.10.05.7'],
    ];

    /** @var array<string,array{callback:callable,label:string,schema_version:string}> */
    private static array $runtime_migrations=[];
    private static string $lock_token='';

    public static function register(string $id,callable $callback,array $meta=[]): bool {
        if(!preg_match('/^\d{8}_\d{3}_[a-z0-9_]+$/',$id))return false;
        if(strcmp($id,self::BUILTIN_CURRENT_ID)<=0 || isset(self::BASELINE_MIGRATIONS[$id]) || isset(self::$runtime_migrations[$id]))return false;
        self::$runtime_migrations[$id]=[
            'callback'=>$callback,
            'label'=>sanitize_text_field((string)($meta['label']??$id)),
            'schema_version'=>sanitize_text_field((string)($meta['schema_version']??'')),
        ];
        return true;
    }

    public static function initialized(): bool {
        return self::valid_ledger(get_option(self::OPTION,[]));
    }

    public static function required(): bool {
        return (int)get_option(self::REQUIRED_OPTION,0)===1;
    }

    public static function needs_run(): bool {
        if(!self::initialized())return true;
        $ledger=self::load();
        foreach(self::definitions() as $id=>$definition){
            $status=(string)($ledger['migrations'][$id]['status']??'pending');
            if($status!=='completed')return true;
        }
        return false;
    }

    public static function ready(): bool {
        if(!self::initialized())return false;
        $status=self::status();
        return !empty($status['ready']);
    }

    /**
     * Import the pre-ledger RC12-RC18 history without executing those old data
     * migrations again. Call only after the Installer has established that the
     * current RC18-compatible schema is already present (or has just created it).
     */
    public static function initialize_baseline(string $source): bool {
        if(self::initialized()){update_option(self::REQUIRED_OPTION,1,false);return true;}
        if(!self::acquire_lock())return false;
        try {
            if(self::initialized())return true;
            $now=gmdate('c');
            $migrations=[];
            foreach(self::BASELINE_MIGRATIONS as $id=>$meta){
                $migrations[$id]=[
                    'status'=>'completed',
                    'label'=>(string)$meta['label'],
                    'baseline'=>true,
                    'attempts'=>0,
                    'plugin_version'=>(string)$meta['plugin_version'],
                    'schema_version'=>(string)$meta['schema_version'],
                    'started_at_utc'=>'',
                    'completed_at_utc'=>$now,
                    'failed_at_utc'=>'',
                    'checkpoint'=>'baseline_imported',
                    'error_code'=>'',
                ];
            }
            foreach(self::definitions() as $id=>$definition){
                if(isset($migrations[$id]))continue;
                $migrations[$id]=self::pending_entry($definition);
            }
            $ledger=[
                'format'=>self::FORMAT,
                'baseline_schema'=>self::BASELINE_SCHEMA,
                'baseline_source'=>sanitize_key($source),
                'initialized_at_utc'=>$now,
                'updated_at_utc'=>$now,
                'migrations'=>$migrations,
            ];
            if(!add_option(self::OPTION,$ledger,'',false)){
                $ok=self::initialized();
                if($ok)update_option(self::REQUIRED_OPTION,1,false);
                return $ok;
            }
            update_option(self::REQUIRED_OPTION,1,false);
            return true;
        } finally {
            self::release_lock();
        }
    }

    /** Execute all pending migrations. Retained for explicit/admin paths. */
    public static function run_pending(): bool {
        $run=self::execute_pending(0,0);
        return !empty($run['ok']);
    }

    /**
     * C2 bounded runner used by ordinary requests. It starts at most the given
     * number of migrations and will not start another migration after the time
     * budget is exhausted. Individual migration callbacks must remain bounded
     * or checkpoint their own long work.
     */
    public static function run_pending_bounded(int $max_migrations=1,int $budget_ms=1500): array {
        return self::execute_pending(max(1,$max_migrations),max(100,$budget_ms));
    }

    private static function execute_pending(int $max_migrations,int $budget_ms): array {
        if(!self::initialized())return ['ok'=>false,'result'=>'uninitialized','ran'=>0,'remaining'=>count(self::definitions()),'ready'=>false];
        if(!self::acquire_lock()){
            $status=self::status();
            return ['ok'=>false,'result'=>'locked','ran'=>0,'remaining'=>max(0,(int)$status['pending']+(int)$status['running']+(int)$status['failed']),'ready'=>false];
        }
        $started=microtime(true);$ran=0;
        try {
            $ledger=self::load();
            self::ensure_registered_entries($ledger);
            if(!self::save($ledger))return ['ok'=>false,'result'=>'ledger_save_failed','ran'=>0,'remaining'=>1,'ready'=>false];

            foreach(self::definitions() as $id=>$definition){
                $entry=$ledger['migrations'][$id]??self::pending_entry($definition);
                if((string)($entry['status']??'')==='completed')continue;
                if($max_migrations>0 && $ran>=$max_migrations)break;
                if($budget_ms>0 && $ran>0 && ((microtime(true)-$started)*1000)>=$budget_ms)break;

                $now=gmdate('c');
                $entry['status']='running';
                $entry['attempts']=max(0,(int)($entry['attempts']??0))+1;
                $entry['plugin_version']=QYS_Config::VERSION;
                if((string)($definition['schema_version']??'')!=='')$entry['schema_version']=(string)$definition['schema_version'];
                $entry['started_at_utc']=$now;
                $entry['failed_at_utc']='';
                $entry['error_code']='';
                $ledger['migrations'][$id]=$entry;
                $ledger['updated_at_utc']=$now;
                if(!self::save($ledger))return ['ok'=>false,'result'=>'ledger_save_failed','ran'=>$ran,'remaining'=>1,'ready'=>false];

                $ran++;
                try {
                    $ok=(bool)call_user_func($definition['callback']);
                    $ledger=self::load();
                    $entry=$ledger['migrations'][$id]??$entry;
                    if(!$ok){
                        self::mark_failed($ledger,$id,$entry,'callback_false');
                        $status=self::status();
                        return ['ok'=>false,'result'=>'migration_failed','ran'=>$ran,'remaining'=>max(1,(int)$status['failed']+(int)$status['pending']+(int)$status['running']),'ready'=>false];
                    }
                    $entry['status']='completed';
                    $entry['completed_at_utc']=gmdate('c');
                    $entry['failed_at_utc']='';
                    $entry['checkpoint']='complete';
                    $entry['error_code']='';
                    $ledger['migrations'][$id]=$entry;
                    $ledger['updated_at_utc']=gmdate('c');
                    if(!self::save($ledger))return ['ok'=>false,'result'=>'ledger_save_failed','ran'=>$ran,'remaining'=>1,'ready'=>false];
                } catch(Throwable $e) {
                    $ledger=self::load();
                    $entry=$ledger['migrations'][$id]??$entry;
                    self::mark_failed($ledger,$id,$entry,self::safe_error_code($e));
                    $status=self::status();
                    return ['ok'=>false,'result'=>'migration_failed','ran'=>$ran,'remaining'=>max(1,(int)$status['failed']+(int)$status['pending']+(int)$status['running']),'ready'=>false];
                }
            }
            $status=self::status();
            $remaining=max(0,(int)$status['pending']+(int)$status['running']+(int)$status['failed']);
            return ['ok'=>true,'result'=>$remaining===0?'complete':'bounded','ran'=>$ran,'remaining'=>$remaining,'ready'=>$remaining===0];
        } finally {
            self::release_lock();
        }
    }

    /** Durable checkpoint for long future migrations. No PII should be stored. */
    public static function checkpoint(string $id,string $checkpoint): bool {
        if(self::$lock_token==='' || !self::owns_lock())return false;
        $ledger=self::load();
        if(!isset($ledger['migrations'][$id]) || (string)($ledger['migrations'][$id]['status']??'')!=='running')return false;
        $ledger['migrations'][$id]['checkpoint']=sanitize_key($checkpoint);
        $ledger['updated_at_utc']=gmdate('c');
        return self::save($ledger);
    }

    public static function checkpoint_of(string $id): string {
        $ledger=self::load();
        return sanitize_key((string)($ledger['migrations'][$id]['checkpoint']??''));
    }

    public static function status(): array {
        $ledger=self::load();
        $initialized=self::valid_ledger($ledger);
        $counts=['pending'=>0,'running'=>0,'completed'=>0,'failed'=>0];
        $failed=[];$running=[];$last_completed='';$last_completed_at='';

        if($initialized){
            self::ensure_registered_entries($ledger,false);
            foreach((array)$ledger['migrations'] as $id=>$entry){
                $status=(string)($entry['status']??'pending');
                if(!array_key_exists($status,$counts))$status='pending';
                $counts[$status]++;
                if($status==='failed')$failed[]=sanitize_key((string)$id);
                if($status==='running')$running[]=sanitize_key((string)$id);
                $completed_at=(string)($entry['completed_at_utc']??'');
                if($status==='completed' && $completed_at!=='' && ($last_completed_at==='' || strcmp($completed_at,$last_completed_at)>0)){
                    $last_completed=sanitize_key((string)$id);
                    $last_completed_at=$completed_at;
                }
            }
        } else {
            $counts['pending']=count(self::definitions());
        }

        $running_stale=false;
        if($initialized){
            $now=time();
            foreach($running as $id){
                $started=(string)($ledger['migrations'][$id]['started_at_utc']??'');
                $epoch=$started!==''?strtotime($started):false;
                if($epoch!==false && ($now-$epoch)>self::LOCK_TTL){$running_stale=true;break;}
            }
        }

        return [
            'initialized'=>$initialized,
            'required'=>self::required(),
            'ready'=>$initialized && $counts['pending']===0 && $counts['running']===0 && $counts['failed']===0,
            'format'=>$initialized?(int)($ledger['format']??0):0,
            'baseline_schema'=>$initialized?(string)($ledger['baseline_schema']??''):'',
            'baseline_source'=>$initialized?sanitize_key((string)($ledger['baseline_source']??'')):'',
            'total'=>array_sum($counts),
            'pending'=>$counts['pending'],
            'running'=>$counts['running'],
            'completed'=>$counts['completed'],
            'failed'=>$counts['failed'],
            'failed_ids'=>$failed,
            'running_ids'=>$running,
            'running_stale'=>$running_stale,
            'last_completed_id'=>$last_completed,
            'last_completed_at_utc'=>$last_completed_at,
            'current_id'=>self::latest_definition_id(),
        ];
    }

    /** Safe non-PII rows for Health Center. */
    public static function entries(int $limit=20): array {
        $ledger=self::load();
        if(!self::valid_ledger($ledger))return [];
        self::ensure_registered_entries($ledger,false);
        $rows=[];
        foreach((array)$ledger['migrations'] as $id=>$entry){
            $rows[]=[
                'id'=>sanitize_key((string)$id),
                'status'=>sanitize_key((string)($entry['status']??'pending')),
                'baseline'=>!empty($entry['baseline']),
                'attempts'=>max(0,(int)($entry['attempts']??0)),
                'checkpoint'=>sanitize_key((string)($entry['checkpoint']??'')),
                'plugin_version'=>sanitize_text_field((string)($entry['plugin_version']??'')),
                'completed_at_utc'=>sanitize_text_field((string)($entry['completed_at_utc']??'')),
                'error_code'=>sanitize_key((string)($entry['error_code']??'')),
            ];
        }
        usort($rows,static fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
        if($limit>0 && count($rows)>$limit)$rows=array_slice($rows,-$limit);
        return $rows;
    }

    private static function definitions(): array {
        $definitions=[
            '20261005_008_rc19_migration_ledger'=>[
                'callback'=>[__CLASS__,'migration_c1'],
                'label'=>'RC19 migration ledger bootstrap',
                'schema_version'=>self::BASELINE_SCHEMA,
            ],
            self::BUILTIN_CURRENT_ID=>[
                'callback'=>[__CLASS__,'migration_c2'],
                'label'=>'RC20 automatic upgrade coordinator/readiness gate',
                'schema_version'=>self::BASELINE_SCHEMA,
            ],
        ];
        foreach(self::$runtime_migrations as $id=>$definition)$definitions[$id]=$definition;
        ksort($definitions,SORT_STRING);
        return $definitions;
    }

    private static function latest_definition_id(): string {
        $definitions=self::definitions();
        if(!$definitions)return self::BUILTIN_CURRENT_ID;
        $ids=array_keys($definitions);
        return (string)end($ids);
    }

    private static function migration_c1(): bool {
        // Infrastructure-only migration: the durable ledger itself is the change.
        return true;
    }

    private static function migration_c2(): bool {
        // Infrastructure-only migration. Runtime coordinator/gates are provided
        // by RC20 code; no domain data or schema is rewritten here.
        return true;
    }

    private static function pending_entry(array $definition): array {
        return [
            'status'=>'pending',
            'label'=>sanitize_text_field((string)($definition['label']??'')),
            'baseline'=>false,
            'attempts'=>0,
            'plugin_version'=>'',
            'schema_version'=>sanitize_text_field((string)($definition['schema_version']??'')),
            'started_at_utc'=>'',
            'completed_at_utc'=>'',
            'failed_at_utc'=>'',
            'checkpoint'=>'',
            'error_code'=>'',
        ];
    }

    private static function ensure_registered_entries(array &$ledger,bool $persist=true): void {
        if(!isset($ledger['migrations']) || !is_array($ledger['migrations']))$ledger['migrations']=[];
        $changed=false;
        foreach(self::definitions() as $id=>$definition){
            if(isset($ledger['migrations'][$id]))continue;
            $ledger['migrations'][$id]=self::pending_entry($definition);
            $changed=true;
        }
        if($changed){
            $ledger['updated_at_utc']=gmdate('c');
            if($persist)self::save($ledger);
        }
    }

    private static function mark_failed(array &$ledger,string $id,array $entry,string $code): void {
        $entry['status']='failed';
        $entry['failed_at_utc']=gmdate('c');
        $entry['completed_at_utc']='';
        $entry['error_code']=sanitize_key($code);
        $ledger['migrations'][$id]=$entry;
        $ledger['updated_at_utc']=gmdate('c');
        self::save($ledger);
    }

    private static function load(): array {
        $ledger=get_option(self::OPTION,[]);
        return is_array($ledger)?$ledger:[];
    }

    private static function save(array $ledger): bool {
        $ledger['format']=self::FORMAT;
        $ledger['updated_at_utc']=gmdate('c');
        return update_option(self::OPTION,$ledger,false)!==false || self::load()===$ledger;
    }

    private static function valid_ledger($ledger): bool {
        return is_array($ledger)
            && (int)($ledger['format']??0)===self::FORMAT
            && isset($ledger['migrations'])
            && is_array($ledger['migrations']);
    }

    private static function acquire_lock(): bool {
        if(self::$lock_token!=='' && self::owns_lock())return true;
        $token=function_exists('wp_generate_uuid4')?wp_generate_uuid4():uniqid('qys_mig_',true);
        $value=['token'=>$token,'started_epoch'=>time()];
        if(add_option(self::LOCK_OPTION,$value,'',false)){
            self::$lock_token=$token;
            return true;
        }
        $existing=get_option(self::LOCK_OPTION,[]);
        $started=is_array($existing)?max(0,(int)($existing['started_epoch']??0)):0;
        if($started>0 && (time()-$started)>self::LOCK_TTL){
            delete_option(self::LOCK_OPTION);
            if(add_option(self::LOCK_OPTION,$value,'',false)){
                self::$lock_token=$token;
                return true;
            }
        }
        return false;
    }

    private static function owns_lock(): bool {
        if(self::$lock_token==='')return false;
        $existing=get_option(self::LOCK_OPTION,[]);
        return is_array($existing) && hash_equals(self::$lock_token,(string)($existing['token']??''));
    }

    private static function release_lock(): void {
        if(self::owns_lock())delete_option(self::LOCK_OPTION);
        self::$lock_token='';
    }

    private static function safe_error_code(Throwable $e): string {
        $raw=strtolower(get_class($e).'_'.$e->getMessage());
        $raw=preg_replace('/[^a-z0-9_]+/','_',trim($raw));
        $raw=trim((string)$raw,'_');
        return substr($raw!==''?$raw:'migration_exception',0,96);
    }
}
