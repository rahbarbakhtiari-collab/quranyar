<?php
if (!defined('ABSPATH')) exit;

/**
 * B6 — bounded retention / data-minimisation worker.
 *
 * The service deliberately distinguishes active/recoverable delivery state
 * from old terminal history.  It never purges pending/sending/retryable or
 * uncertain deliveries.  Old direct PII/message bodies are minimised first;
 * only fully-terminal old campaign histories are purged as a unit later.
 */
final class QYS_Retention_Service {
    public const EVENT='qys_retention_cleanup';
    public const POLICY_VERSION='2026.10.05.1';
    private const LAST_RUN_OPTION='qys_retention_last_run';
    private const INIT_OPTION='qys_retention_initialized_at';
    private const LOCK_OPTION='qys_retention_lock';
    private const LOCK_SECONDS=900;
    private const BATCH_LIMIT=250;
    private const CAMPAIGN_BATCH_LIMIT=25;

    public static function init(): void {
        add_action('init',[__CLASS__,'ensure_scheduled'],40);
        add_action(self::EVENT,[__CLASS__,'run']);
    }

    /** Explicit, conservative default policy. Values are days. */
    public static function matrix(): array {
        return [
            'queue_payload_minimise'=>90,
            'excel_followup_anonymise'=>180,
            'birthday_archive_anonymise'=>180,
            'birthday_archive_delete'=>730,
            'birthday_log_delete'=>550,
            'campaign_history_delete'=>730,
            'audit_delete'=>730,
        ];
    }

    public static function ensure_scheduled(): void {
        if(!(int)get_option(self::INIT_OPTION,0))update_option(self::INIT_OPTION,time(),false);
        if(!wp_next_scheduled(self::EVENT))wp_schedule_event(time()+300,'daily',self::EVENT);
    }

    public static function status(): array {
        $next=wp_next_scheduled(self::EVENT);
        $last=get_option(self::LAST_RUN_OPTION,[]);if(!is_array($last))$last=[];
        $finished=max(0,(int)($last['finished_epoch']??0));
        $age=$finished>0?max(0,time()-$finished):-1;
        $init=max(0,(int)get_option(self::INIT_OPTION,0));
        return [
            'policy_version'=>self::POLICY_VERSION,
            'scheduled'=>$next!==false,
            'next_utc'=>$next!==false?gmdate('c',(int)$next):'',
            'initialized_age_seconds'=>$init>0?max(0,time()-$init):-1,
            'last_run_utc'=>(string)($last['finished_at_utc']??''),
            'last_run_age_seconds'=>$age,
            'last_result'=>sanitize_key((string)($last['result']??'never')),
            'last_duration_ms'=>max(0,(int)($last['duration_ms']??0)),
            'last_changed'=>max(0,(int)($last['changed']??0)),
        ];
    }

    public static function run(): array {
        $empty=self::empty_counts();
        if(class_exists('QYS_Installer')&&!QYS_Installer::ready())return ['result'=>'schema_not_ready','changed'=>0,'counts'=>$empty];

        $started=microtime(true);$now=time();
        if(!add_option(self::LOCK_OPTION,$now,'','no')){
            $existing=max(0,(int)get_option(self::LOCK_OPTION,0));
            if($existing>0 && $now-$existing<self::LOCK_SECONDS)return ['result'=>'locked','changed'=>0,'counts'=>$empty];
            delete_option(self::LOCK_OPTION);
            if(!add_option(self::LOCK_OPTION,$now,'','no'))return ['result'=>'locked','changed'=>0,'counts'=>$empty];
        }

        $counts=$empty;$result='ok';
        try {
            $counts['queue_payload_minimised']=self::minimise_queue_payloads();
            $counts['excel_followup_anonymised']=self::anonymise_excel_followup();
            $counts['birthday_archive_anonymised']=self::anonymise_birthday_archive();
            $counts['birthday_archive_deleted']=self::delete_old_birthday_archive();
            $counts['birthday_log_deleted']=self::delete_old_birthday_log();
            $counts['archive_access_deleted']=self::delete_old_audit(QYS_Table_Names::name('archive_access_log'),'occurred_at_utc',true);
            $counts['tenant_audit_deleted']=self::delete_old_audit(QYS_Table_Names::name('tenant_audit'),'created_at_utc',true);
            $counts['program_audit_deleted']=self::delete_old_audit(QYS_Table_Names::name('program_audit'),'created_at_utc',true);
            $counts['campaigns_deleted']=self::purge_old_campaigns();
        } catch(Throwable $e) {
            // Never store raw database/network errors in telemetry.
            $result='error';
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        $changed=array_sum($counts);$finished=time();
        $last=[
            'policy_version'=>self::POLICY_VERSION,
            'finished_at_utc'=>gmdate('c',$finished),
            'finished_epoch'=>$finished,
            'duration_ms'=>max(0,(int)round((microtime(true)-$started)*1000)),
            'result'=>$result,
            'changed'=>$changed,
            'counts'=>$counts,
        ];
        update_option(self::LAST_RUN_OPTION,$last,false);
        return ['result'=>$result,'changed'=>$changed,'counts'=>$counts];
    }

    private static function empty_counts(): array {
        return [
            'queue_payload_minimised'=>0,
            'excel_followup_anonymised'=>0,
            'birthday_archive_anonymised'=>0,
            'birthday_archive_deleted'=>0,
            'birthday_log_deleted'=>0,
            'archive_access_deleted'=>0,
            'tenant_audit_deleted'=>0,
            'program_audit_deleted'=>0,
            'campaigns_deleted'=>0,
        ];
    }

    private static function local_cutoff(int $days): string {
        $base=function_exists('current_time')?(int)current_time('timestamp'):time();
        return gmdate('Y-m-d H:i:s',$base-max(1,$days)*86400);
    }

    private static function utc_cutoff(int $days): string {
        return gmdate('Y-m-d H:i:s',time()-max(1,$days)*86400);
    }

    private static function safe_ids(array $ids): array {
        $out=[];foreach($ids as $id){$id=absint($id);if($id>0)$out[$id]=$id;}
        return array_values($out);
    }

    private static function id_list(array $ids): string {
        return implode(',',array_map('intval',self::safe_ids($ids)));
    }

    /** Remove rendered message bodies while preserving delivery counters/report rows. */
    private static function minimise_queue_payloads(): int { global $wpdb;
        $table=QYS_Table_Names::name('queue');if(!QYS_Table_Names::runtime_exists($table))return 0;
        $cutoff=self::local_cutoff(self::matrix()['queue_payload_minimise']);
        $ids=self::safe_ids((array)$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status IN ('sent','definitive_failed','cancelled') AND payload<>'' AND updated_at<%s ORDER BY id ASC LIMIT %d",
            $cutoff,self::BATCH_LIMIT
        )));
        if(!$ids)return 0;$list=self::id_list($ids);
        $n=$wpdb->query("UPDATE $table SET payload='' WHERE id IN ($list) AND status IN ('sent','definitive_failed','cancelled')");
        if($n===false)throw new RuntimeException('retention_write_failed');
        return max(0,(int)$n);
    }

    /** Keep reason/source-row statistics, remove direct identifiers after six months. */
    private static function anonymise_excel_followup(): int { global $wpdb;
        $table=QYS_Table_Names::name('excel_followup');if(!QYS_Table_Names::runtime_exists($table))return 0;
        $cutoff=self::local_cutoff(self::matrix()['excel_followup_anonymise']);
        $ids=self::safe_ids((array)$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE created_at<%s AND (member_id<>0 OR person_name<>'' OR national_id<>'' OR mobile<>'') ORDER BY id ASC LIMIT %d",
            $cutoff,self::BATCH_LIMIT
        )));
        if(!$ids)return 0;$list=self::id_list($ids);
        $n=$wpdb->query("UPDATE $table SET member_id=0,person_name='',national_id='',mobile='' WHERE id IN ($list)");
        if($n===false)throw new RuntimeException('retention_write_failed');
        return max(0,(int)$n);
    }

    /** Minimise direct PII/message snapshots only after the delivery is terminal. */
    private static function anonymise_birthday_archive(): int { global $wpdb;
        $table=QYS_Table_Names::name('birthday_archive');if(!QYS_Table_Names::runtime_exists($table))return 0;
        $cutoff=self::local_cutoff(self::matrix()['birthday_archive_anonymise']);
        $ids=self::safe_ids((array)$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE gateway_status IN ('accepted','failed') AND attempted_at<%s AND (first_name<>'' OR last_name<>'' OR eitaa_id<>'' OR national_id<>'' OR mobile<>'' OR birth_jalali<>'' OR message_text<>'') ORDER BY id ASC LIMIT %d",
            $cutoff,self::BATCH_LIMIT
        )));
        if(!$ids)return 0;$list=self::id_list($ids);
        $n=$wpdb->query("UPDATE $table SET first_name='',last_name='',eitaa_id='',national_id='',mobile='',birth_jalali='',message_text='' WHERE id IN ($list) AND gateway_status IN ('accepted','failed')");
        if($n===false)throw new RuntimeException('retention_write_failed');
        return max(0,(int)$n);
    }

    /** Unknown/sending birthday outcomes are protected from retention deletion. */
    private static function delete_old_birthday_archive(): int { global $wpdb;
        $table=QYS_Table_Names::name('birthday_archive');if(!QYS_Table_Names::runtime_exists($table))return 0;
        $cutoff=self::local_cutoff(self::matrix()['birthday_archive_delete']);
        $ids=self::safe_ids((array)$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE gateway_status IN ('accepted','failed') AND attempted_at<%s ORDER BY id ASC LIMIT %d",
            $cutoff,self::BATCH_LIMIT
        )));
        if(!$ids)return 0;$list=self::id_list($ids);
        $n=$wpdb->query("DELETE FROM $table WHERE id IN ($list) AND gateway_status IN ('accepted','failed')");
        if($n===false)throw new RuntimeException('retention_write_failed');
        return max(0,(int)$n);
    }

    /** Birthday dedupe only needs current/recent years; active/uncertain rows remain protected. */
    private static function delete_old_birthday_log(): int { global $wpdb;
        $table=QYS_Table_Names::name('birthday_log');if(!QYS_Table_Names::runtime_exists($table))return 0;
        $cutoff=self::local_cutoff(self::matrix()['birthday_log_delete']);
        $ids=self::safe_ids((array)$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE status IN ('sent','failed','cancelled') AND updated_at<%s ORDER BY id ASC LIMIT %d",
            $cutoff,self::BATCH_LIMIT
        )));
        if(!$ids)return 0;$list=self::id_list($ids);
        $n=$wpdb->query("DELETE FROM $table WHERE id IN ($list) AND status IN ('sent','failed','cancelled')");
        if($n===false)throw new RuntimeException('retention_write_failed');
        return max(0,(int)$n);
    }

    private static function delete_old_audit(string $table,string $column,bool $utc): int { global $wpdb;
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)||!preg_match('/^[A-Za-z0-9_]+$/D',$column)||!QYS_Table_Names::runtime_exists($table))return 0;
        $cutoff=$utc?self::utc_cutoff(self::matrix()['audit_delete']):self::local_cutoff(self::matrix()['audit_delete']);
        $ids=self::safe_ids((array)$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE $column<%s ORDER BY id ASC LIMIT %d",$cutoff,self::BATCH_LIMIT)));
        if(!$ids)return 0;$list=self::id_list($ids);
        $n=$wpdb->query("DELETE FROM $table WHERE id IN ($list)");
        if($n===false)throw new RuntimeException('retention_write_failed');
        return max(0,(int)$n);
    }

    /**
     * Purge very old campaign history only as a complete unit.  Any pending,
     * sending, retryable, uncertain or legacy-failed row protects the entire
     * campaign.  This avoids partial historical counts and unsafe recovery loss.
     */
    private static function purge_old_campaigns(): int { global $wpdb;
        $campaigns=QYS_Table_Names::name('campaigns');$queue=QYS_Table_Names::name('queue');
        if(!QYS_Table_Names::runtime_exists($campaigns)||!QYS_Table_Names::runtime_exists($queue))return 0;
        $cutoff=self::local_cutoff(self::matrix()['campaign_history_delete']);
        $sql=$wpdb->prepare(
            "SELECT c.id FROM $campaigns c WHERE c.created_at<%s AND c.state<>'scheduled' AND NOT EXISTS (SELECT 1 FROM $queue q WHERE q.campaign_id=c.id AND (q.status NOT IN ('sent','definitive_failed','cancelled') OR q.updated_at>=%s)) AND (c.state IN ('draft','cancelled') OR EXISTS (SELECT 1 FROM $queue q2 WHERE q2.campaign_id=c.id)) ORDER BY c.id ASC LIMIT %d",
            $cutoff,$cutoff,self::CAMPAIGN_BATCH_LIMIT
        );
        $ids=self::safe_ids((array)$wpdb->get_col($sql));if(!$ids)return 0;
        $follow=QYS_Table_Names::name('excel_followup');$reports=QYS_Table_Names::name('import_reports');$meta=class_exists('QYS_Targeted')?QYS_Targeted::table():QYS_Table_Names::name('target_campaign_meta');
        $follow_ready=QYS_Table_Names::runtime_exists($follow);$reports_ready=QYS_Table_Names::runtime_exists($reports);$meta_ready=QYS_Table_Names::runtime_exists($meta);
        $deleted=0;
        foreach($ids as $id){
            if($wpdb->query('START TRANSACTION')===false)break;
            try {
                if($follow_ready && $wpdb->delete($follow,['campaign_id'=>$id],['%d'])===false)throw new RuntimeException('followup');
                if($reports_ready && $wpdb->delete($reports,['campaign_id'=>$id],['%d'])===false)throw new RuntimeException('report');
                if($meta_ready && $wpdb->delete($meta,['campaign_id'=>$id],['%d'])===false)throw new RuntimeException('meta');
                if($wpdb->delete($queue,['campaign_id'=>$id],['%d'])===false)throw new RuntimeException('queue');
                $n=$wpdb->delete($campaigns,['id'=>$id],['%d']);if($n!==1)throw new RuntimeException('campaign');
                if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit');
                $deleted++;
            } catch(Throwable $e) {
                $wpdb->query('ROLLBACK');
                throw new RuntimeException('campaign_purge_failed');
            }
        }
        return $deleted;
    }
}
