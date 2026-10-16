<?php
if (!defined('ABSPATH')) exit;

/** Canonical storage boundary for core campaigns + delivery queue. */
final class QYS_Campaign_Repository {
    private static function campaigns(): string { return QYS_Table_Names::name('campaigns'); }
    private static function queue(): string { return QYS_Table_Names::name('queue'); }
    public static function targeted_meta_table(): string { return QYS_Table_Names::name('target_campaign_meta'); }
    private static function identifier(string $table): string {
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new InvalidArgumentException('Unsafe table identifier.');
        return $table;
    }

    public static function campaigns_ready(): bool { return QYS_Table_Names::runtime_exists(self::campaigns()); }
    public static function diagnostic_campaigns_ready(): bool { return QYS_Table_Names::diagnostic_exists(self::campaigns()); }
    public static function queue_ready(): bool { return QYS_Table_Names::runtime_exists(self::queue()); }
    public static function diagnostic_queue_ready(): bool { return QYS_Table_Names::diagnostic_exists(self::queue()); }

    /**
     * D5 dispatch index contract. The hot worker query filters by status and
     * consumes the queue in id order, so the physical prefix must be (status,id).
     * Any index name is accepted if its first two columns match this contract.
     */
    public static function diagnostic_queue_status_id_index_ready(): bool {
        if(!self::diagnostic_queue_ready())return false;
        $grouped=[];
        foreach(QYS_Table_Names::diagnostic_indexes(self::queue()) as $row){
            $name=(string)($row['Key_name']??'');
            $seq=(int)($row['Seq_in_index']??0);
            $column=(string)($row['Column_name']??'');
            if($name==='' || $seq<1 || $column==='')continue;
            $grouped[$name][$seq]=$column;
        }
        foreach($grouped as $columns){
            ksort($columns,SORT_NUMERIC);
            $columns=array_values($columns);
            if(array_slice($columns,0,2)===['status','id'])return true;
        }
        return false;
    }

    /** Add the D5 queue dispatch index exactly once on existing installations. */
    public static function ensure_queue_status_id_index(): bool { global $wpdb;
        if(!self::diagnostic_queue_ready())return false;
        if(self::diagnostic_queue_status_id_index_ready())return true;
        $table=self::identifier(self::queue());
        $sql='ALTER TABLE `'.$table.'` ADD KEY `status_id` (`status`,`id`)';
        if($wpdb->query($sql)===false)return false;
        QYS_Table_Names::clear_diagnostic_cache($table);
        return self::diagnostic_queue_status_id_index_ready();
    }

    /** Health/acceptance telemetry for D5; called only from explicit diagnostics. */
    public static function queue_dispatch_index_contract(): array {
        $ready=self::diagnostic_queue_status_id_index_ready();
        return [
            'ready'=>$ready,
            'index'=>'status_id',
            'columns'=>'status,id',
            'pending_scan'=>$ready?'indexed':'missing',
        ];
    }

    /** Targeted campaign metadata storage contract used by permissions and Targeted. */
    public static function targeted_meta_ready(): bool { return QYS_Schema_Runtime::ready(); }

    public static function diagnostic_targeted_meta_ready(): bool {
        $table=self::targeted_meta_table();
        if(!QYS_Table_Names::diagnostic_has_columns($table,['campaign_id','request_key','audience','audience_groups','audience_universities','manager_university','scheduled_at_utc','scheduled_jalali','scheduled_hm','created_by','created_at_utc','launched_at_utc','cancelled_at_utc']))return false;
        foreach(QYS_Table_Names::diagnostic_indexes($table) as $index){
            if(($index['Key_name']??'')==='request_key'&&(int)($index['Non_unique']??1)===0)return true;
        }
        return false;
    }

    public static function targeted_meta(int $campaign_id){ global $wpdb;
        if($campaign_id<=0||!self::targeted_meta_ready())return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT created_by,manager_university,audience_universities FROM '.self::targeted_meta_table().' WHERE campaign_id=%d',
            $campaign_id
        ));
    }

    public static function targeted_visible_in_scope(int $campaign_id,string $scope): bool { global $wpdb;
        if($campaign_id<=0||!self::targeted_meta_ready())return false;
        if($scope==='*')$sql=$wpdb->prepare('SELECT COUNT(*) FROM '.self::targeted_meta_table().' WHERE campaign_id=%d',$campaign_id);
        else $sql=$wpdb->prepare('SELECT COUNT(*) FROM '.self::targeted_meta_table().' WHERE campaign_id=%d AND manager_university=%s',$campaign_id,$scope);
        return (int)$wpdb->get_var($sql)>0;
    }


    /** Targeted metadata writes are owned by this repository; feature services do not issue SQL. */
    public static function insert_targeted_meta(array $meta): bool { global $wpdb;
        return $wpdb->insert(self::targeted_meta_table(),$meta,['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s'])===1;
    }
    public static function mark_targeted_launched(int $campaign_id,string $utc): bool { global $wpdb;
        return $wpdb->update(self::targeted_meta_table(),['launched_at_utc'=>$utc],['campaign_id'=>$campaign_id],['%s'],['%d'])!==false;
    }
    public static function mark_targeted_cancelled(int $campaign_id,string $utc): bool { global $wpdb;
        return $wpdb->update(self::targeted_meta_table(),['cancelled_at_utc'=>$utc],['campaign_id'=>$campaign_id],['%s'],['%d'])!==false;
    }
    public static function due_targeted_campaign_ids(string $now,int $limit=20): array { global $wpdb;
        $limit=max(1,min(100,$limit));
        $rows=(array)$wpdb->get_results($wpdb->prepare('SELECT campaign_id FROM '.self::targeted_meta_table().' WHERE scheduled_at_utc IS NOT NULL AND scheduled_at_utc<=%s ORDER BY scheduled_at_utc,campaign_id LIMIT %d',$now,$limit));
        return array_values(array_filter(array_map(static function($r){return (int)($r->campaign_id??0);},$rows)));
    }

    public static function tables_ready(): bool { return self::campaigns_ready()&&self::queue_ready(); }
    public static function begin(): bool { global $wpdb; return $wpdb->query('START TRANSACTION')!==false; }
    public static function commit(): bool { global $wpdb; return $wpdb->query('COMMIT')!==false; }
    public static function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public static function create_draft(string $label,string $template){ global $wpdb;
        $ok=$wpdb->insert(self::campaigns(),['label'=>$label,'template'=>$template,'state'=>'draft','created_at'=>current_time('mysql')],['%s','%s','%s','%s']);
        return $ok===1?(int)$wpdb->insert_id:false;
    }
    public static function enqueue(int $campaign_id,int $member_id,string $payload): bool { global $wpdb;
        return $wpdb->insert(self::queue(),['campaign_id'=>$campaign_id,'member_id'=>$member_id,'payload'=>$payload,'updated_at'=>current_time('mysql')],['%d','%d','%s','%s'])===1;
    }
    public static function enqueue_many(int $campaign_id,array $messages): bool {
        foreach($messages as $m)if(!self::enqueue($campaign_id,(int)$m['id'],(string)$m['text']))return false;
        return true;
    }
    public static function pending_count(int $campaign_id): int { global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::queue().' WHERE campaign_id=%d AND status=%s',$campaign_id,'pending'));
    }
    public static function state(int $campaign_id): string { global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SELECT state FROM '.self::campaigns().' WHERE id=%d',$campaign_id));
    }
    public static function set_state(int $campaign_id,string $from,string $to){ global $wpdb;
        return $wpdb->update(self::campaigns(),['state'=>$to],['id'=>$campaign_id,'state'=>$from],['%s'],['%d','%s']);
    }
    public static function cancel_if_prelaunch(int $campaign_id): bool { global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("UPDATE ".self::campaigns()." SET state='cancelled' WHERE id=%d AND state IN ('draft','scheduled')",$campaign_id));
        return $changed===1;
    }
    public static function cancel_pending_for_campaign(int $campaign_id){ global $wpdb;
        return $wpdb->query($wpdb->prepare("UPDATE ".self::queue()." SET status='cancelled',updated_at=%s WHERE campaign_id=%d AND status='pending'",current_time('mysql'),$campaign_id));
    }
    public static function cancel_pending_for_member(int $member_id){ global $wpdb;
        return $wpdb->update(self::queue(),['status'=>'cancelled','updated_at'=>current_time('mysql')],['member_id'=>$member_id,'status'=>'pending'],['%s','%s'],['%d','%s']);
    }
    public static function queued_payloads(int $campaign_id,int $limit=5): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare('SELECT payload FROM '.self::queue().' WHERE campaign_id=%d ORDER BY id LIMIT %d',$campaign_id,max(1,min(20,$limit))));
    }
    /** Compact one-recipient preview used by the messaging UI. */
    public static function preview_sample(int $campaign_id){ global $wpdb;
        if($campaign_id<=0)return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT id,member_id,status,payload,updated_at,last_error FROM '.self::queue().' WHERE campaign_id=%d ORDER BY id ASC LIMIT 1',
            $campaign_id
        ));
    }

    /** Re-read the canonical payload after a successful claim to avoid stale pre-claim data. */
    public static function claimed_payload(int $queue_id): ?string { global $wpdb;
        if($queue_id<=0)return null;
        $payload=$wpdb->get_var($wpdb->prepare(
            'SELECT payload FROM '.self::queue().' WHERE id=%d AND status=%s',
            $queue_id,QYS_Delivery_State::SENDING
        ));
        return is_string($payload)?$payload:null;
    }

    /** Lock one targeted campaign plus all queue rows before an approved-message edit. */
    public static function lock_targeted_edit(int $campaign_id,string $meta_table): ?array { global $wpdb;
        if($campaign_id<=0)return null;$meta_table=self::identifier($meta_table);
        $campaign=$wpdb->get_row($wpdb->prepare(
            'SELECT c.id,c.label,c.template,c.state,m.audience,m.scheduled_at_utc,m.manager_university,m.audience_universities FROM '.self::campaigns().' c JOIN '.$meta_table.' m ON m.campaign_id=c.id WHERE c.id=%d FOR UPDATE',
            $campaign_id
        ));
        if(!$campaign)return null;
        $rows=(array)$wpdb->get_results($wpdb->prepare(
            'SELECT id,member_id,status FROM '.self::queue().' WHERE campaign_id=%d ORDER BY id ASC FOR UPDATE',
            $campaign_id
        ));
        return ['campaign'=>$campaign,'rows'=>$rows];
    }

    public static function update_campaign_copy(int $campaign_id,string $label,string $template): bool { global $wpdb;
        return $wpdb->update(
            self::campaigns(),
            ['label'=>$label,'template'=>$template],
            ['id'=>$campaign_id],
            ['%s','%s'],
            ['%d']
        )!==false;
    }

    public static function update_pending_payload(int $queue_id,int $campaign_id,string $payload): bool { global $wpdb;
        return $wpdb->update(
            self::queue(),
            ['payload'=>$payload,'updated_at'=>current_time('mysql')],
            ['id'=>$queue_id,'campaign_id'=>$campaign_id,'status'=>QYS_Delivery_State::PENDING],
            ['%s','%s'],
            ['%d','%d','%s']
        )!==false;
    }

    /** Full targeted row for compact preview/edit cards. */
    public static function targeted_detail(int $campaign_id,string $meta_table){ global $wpdb;
        $meta_table=self::identifier($meta_table);
        return $wpdb->get_row($wpdb->prepare(
            'SELECT c.*,m.request_key,m.audience,m.audience_groups,m.audience_universities,m.manager_university,m.scheduled_at_utc,m.scheduled_jalali,m.scheduled_hm,m.created_by,m.created_at_utc,m.launched_at_utc,m.cancelled_at_utc,'.
            '(SELECT COUNT(*) FROM '.self::queue().' q WHERE q.campaign_id=c.id) recipients,'.
            '(SELECT COUNT(*) FROM '.self::queue()." q WHERE q.campaign_id=c.id AND q.status='pending') pending,".
            '(SELECT COUNT(*) FROM '.self::queue()." q WHERE q.campaign_id=c.id AND q.status='sending') sending,".
            '(SELECT COUNT(*) FROM '.self::queue()." q WHERE q.campaign_id=c.id AND q.status='sent') sent,".
            '(SELECT COUNT(*) FROM '.self::queue()." q WHERE q.campaign_id=c.id AND q.status IN ('failed','retryable_failed','definitive_failed','uncertain')) failed,".
            '(SELECT COUNT(*) FROM '.self::queue()." q WHERE q.campaign_id=c.id AND q.status='cancelled') cancelled ".
            'FROM '.self::campaigns().' c JOIN '.$meta_table.' m ON m.campaign_id=c.id WHERE c.id=%d',
            $campaign_id
        ));
    }
    public static function pending_batch(int $limit=5): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT q.id,q.campaign_id,q.member_id,q.payload FROM ".self::queue()." q JOIN ".self::campaigns()." c ON c.id=q.campaign_id WHERE q.status='pending' AND c.state='active' ORDER BY q.id LIMIT %d",max(1,min(50,$limit))));
    }
    public static function claim(int $queue_id,int $lease_seconds=180): bool { global $wpdb;
        $lease_seconds=max(60,min(900,$lease_seconds));
        $now=time();
        $claimed=gmdate('Y-m-d H:i:s',$now);
        $lease=gmdate('Y-m-d H:i:s',$now+$lease_seconds);
        $sql=$wpdb->prepare(
            'UPDATE '.self::queue()." SET status=%s,attempts=LEAST(attempts+1,255),last_error='',claimed_at_utc=%s,lease_expires_at_utc=%s,outbound_started_at_utc=NULL,updated_at=%s WHERE id=%d AND status=%s",
            QYS_Delivery_State::SENDING,$claimed,$lease,current_time('mysql'),$queue_id,QYS_Delivery_State::PENDING
        );
        return $wpdb->query($sql)===1;
    }

    /**
     * Mark the last safe boundary before the HTTP request is allowed to begin.
     * A stale claim with no outbound_started_at_utc can be safely returned to
     * pending; after this marker it must become uncertain rather than resend.
     */
    public static function mark_outbound_started(int $queue_id,int $lease_seconds=180): bool { global $wpdb;
        $lease_seconds=max(60,min(900,$lease_seconds));
        $now=time();
        $started=gmdate('Y-m-d H:i:s',$now);
        $lease=gmdate('Y-m-d H:i:s',$now+$lease_seconds);
        $sql=$wpdb->prepare(
            'UPDATE '.self::queue().' SET outbound_started_at_utc=%s,lease_expires_at_utc=%s,updated_at=%s WHERE id=%d AND status=%s AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc>=%s',
            $started,$lease,current_time('mysql'),$queue_id,QYS_Delivery_State::SENDING,$started
        );
        return $wpdb->query($sql)===1;
    }

    /**
     * Recover expired sending claims conservatively.
     *
     * - New B2 claim with no outbound marker: safe to put back in pending.
     * - Any claim whose outbound marker exists: delivery result is uncertain.
     * - Pre-B2/unleased sending rows: uncertain; the old worker did not record
     *   whether the network boundary had already been crossed.
     */
    public static function recover_expired_sending(int $limit=50,int $legacy_stale_seconds=180): array { global $wpdb;
        $limit=max(1,min(500,$limit));
        $legacy_stale_seconds=max(60,min(3600,$legacy_stale_seconds));
        $now=time();
        $now_utc=gmdate('Y-m-d H:i:s',$now);
        $legacy_cutoff_local=function_exists('current_time')
            ?gmdate('Y-m-d H:i:s',(int)current_time('timestamp')-$legacy_stale_seconds)
            :gmdate('Y-m-d H:i:s',$now-$legacy_stale_seconds);
        $sql=$wpdb->prepare(
            'SELECT id,claimed_at_utc,lease_expires_at_utc,outbound_started_at_utc,updated_at FROM '.self::queue().' WHERE status=%s AND ((lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc<%s) OR (lease_expires_at_utc IS NULL AND updated_at<%s)) ORDER BY id LIMIT %d',
            QYS_Delivery_State::SENDING,$now_utc,$legacy_cutoff_local,$limit
        );
        $rows=(array)$wpdb->get_results($sql);
        $out=['requeued'=>0,'uncertain'=>0,'checked'=>count($rows)];
        foreach($rows as $row){
            $id=(int)$row->id;
            $leased=!empty($row->lease_expires_at_utc);
            $outbound=!empty($row->outbound_started_at_utc);
            if($leased && !$outbound){
                $changed=$wpdb->update(
                    self::queue(),
                    ['status'=>QYS_Delivery_State::PENDING,'last_error'=>'recovered_preflight_lease_expired','claimed_at_utc'=>null,'lease_expires_at_utc'=>null,'outbound_started_at_utc'=>null,'updated_at'=>current_time('mysql')],
                    ['id'=>$id,'status'=>QYS_Delivery_State::SENDING,'lease_expires_at_utc'=>(string)$row->lease_expires_at_utc],
                    ['%s','%s','%s','%s','%s','%s'],
                    ['%d','%s','%s']
                );
                if($changed===1)$out['requeued']++;
                continue;
            }
            $reason=$leased?'lease_expired_after_outbound_started':'legacy_sending_recovered_uncertain';
            $where=['id'=>$id,'status'=>QYS_Delivery_State::SENDING];
            $where_formats=['%d','%s'];
            if($leased){$where['lease_expires_at_utc']=(string)$row->lease_expires_at_utc;$where_formats[]='%s';}
            $changed=$wpdb->update(
                self::queue(),
                ['status'=>QYS_Delivery_State::UNCERTAIN,'last_error'=>$reason,'lease_expires_at_utc'=>null,'updated_at'=>current_time('mysql')],
                $where,
                ['%s','%s','%s','%s'],
                $where_formats
            );
            if($changed===1)$out['uncertain']++;
        }
        return $out;
    }

    /**
     * Existing sending rows from pre-B2 have no lease/outbound marker. Give
     * them a temporary conservative lease and mark them as outbound-started so
     * a later expiry can never cause an automatic resend.
     */
    public static function migrate_sending_leases(int $lease_seconds=180): bool { global $wpdb;
        if(!self::diagnostic_queue_ready())return false;
        $lease_seconds=max(60,min(900,$lease_seconds));
        $now=time();$claimed=gmdate('Y-m-d H:i:s',$now);$lease=gmdate('Y-m-d H:i:s',$now+$lease_seconds);
        $sql=$wpdb->prepare(
            'UPDATE '.self::queue().' SET claimed_at_utc=%s,lease_expires_at_utc=%s,outbound_started_at_utc=%s WHERE status=%s AND lease_expires_at_utc IS NULL',
            $claimed,$lease,$claimed,QYS_Delivery_State::SENDING
        );
        return $wpdb->query($sql)!==false;
    }
    public static function finish_delivery(int $queue_id,string $state,string $reason=''): bool { global $wpdb;
        if(!in_array($state,QYS_Delivery_State::attempt_outcomes(),true))return false;
        $error=$state===QYS_Delivery_State::SENT?'':QYS_Text_Normalizer::clip($reason,250);
        return $wpdb->update(self::queue(),['status'=>$state,'last_error'=>$error,'lease_expires_at_utc'=>null,'updated_at'=>current_time('mysql')],['id'=>$queue_id,'status'=>QYS_Delivery_State::SENDING],['%s','%s','%s','%s'],['%d','%s'])===1;
    }
    /** Backward-compatible internal wrapper; new code should persist the explicit delivery state. */
    public static function finish(int $queue_id,bool $ok,string $reason=''): bool {
        $state=$ok?QYS_Delivery_State::SENT:QYS_Delivery_State::from_failure_reason($reason);
        return self::finish_delivery($queue_id,$state,$reason);
    }
    public static function cancel_queue_row(int $queue_id,string $from,string $reason): bool { global $wpdb;
        return $wpdb->update(self::queue(),['status'=>QYS_Delivery_State::CANCELLED,'last_error'=>QYS_Text_Normalizer::clip($reason,250),'lease_expires_at_utc'=>null,'updated_at'=>current_time('mysql')],['id'=>$queue_id,'status'=>$from],['%s','%s','%s','%s'],['%d','%s'])===1;
    }

    /**
     * One-time compatibility migration for pre-B1 `failed` rows. Unknown legacy
     * failures become `uncertain` rather than being guessed as definitely unsent.
     */
    public static function migrate_delivery_states(): bool { global $wpdb;
        if(!self::diagnostic_queue_ready())return false;
        for($page=0;$page<10000;$page++){
            $rows=(array)$wpdb->get_results("SELECT id,last_error FROM ".self::queue()." WHERE status='failed' ORDER BY id LIMIT 500");
            if(!$rows)return true;
            foreach($rows as $row){
                $state=QYS_Delivery_State::from_failure_reason((string)$row->last_error);
                $changed=$wpdb->update(self::queue(),['status'=>$state],['id'=>(int)$row->id,'status'=>QYS_Delivery_State::LEGACY_FAILED],['%s'],['%d','%s']);
                if($changed===false)return false;
            }
            if(count($rows)<500)return true;
        }
        return false;
    }
    public static function health_metrics(): array { global $wpdb;
        $out=['pending'=>0,'sending'=>0,'sent'=>0,'failed'=>0,'retryable_failed'=>0,'definitive_failed'=>0,'uncertain'=>0,'legacy_failed'=>0,'sending_lease_active'=>0,'sending_lease_expired'=>0,'sending_unleased'=>0,'oldest_pending_age_seconds'=>-1,'oldest_active_pending_age_seconds'=>-1,'oldest_sending_age_seconds'=>-1,'oldest_lease_overdue_seconds'=>-1];
        if(!self::queue_ready())return $out;
        // updated_at is site-local; lease timestamps are explicitly UTC.
        $now_local=function_exists('current_time')?(int)current_time('timestamp'):time();
        $now_utc=time();$now_utc_sql=gmdate('Y-m-d H:i:s',$now_utc);
        $counts=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='sending'),0) sending,COALESCE(SUM(status='sent'),0) sent,COALESCE(SUM(status='retryable_failed'),0) retryable_failed,COALESCE(SUM(status='definitive_failed'),0) definitive_failed,COALESCE(SUM(status='uncertain'),0) uncertain,COALESCE(SUM(status='failed'),0) legacy_failed,COALESCE(SUM(status='sending' AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc>=%s),0) sending_lease_active,COALESCE(SUM(status='sending' AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc<%s),0) sending_lease_expired,COALESCE(SUM(status='sending' AND lease_expires_at_utc IS NULL),0) sending_unleased,MIN(CASE WHEN status='pending' THEN updated_at END) oldest_pending,MIN(CASE WHEN status='sending' THEN updated_at END) oldest_sending,MIN(CASE WHEN status='sending' AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc<%s THEN lease_expires_at_utc END) oldest_expired_lease FROM ".self::queue(),$now_utc_sql,$now_utc_sql,$now_utc_sql),ARRAY_A);
        if(is_array($counts)){
            foreach(['pending','sending','sent','retryable_failed','definitive_failed','uncertain','legacy_failed','sending_lease_active','sending_lease_expired','sending_unleased'] as $k)$out[$k]=max(0,(int)($counts[$k]??0));
            $out['failed']=$out['retryable_failed']+$out['definitive_failed']+$out['uncertain']+$out['legacy_failed'];
            foreach(['oldest_pending'=>'oldest_pending_age_seconds','oldest_sending'=>'oldest_sending_age_seconds'] as $src=>$dst){
                $ts=!empty($counts[$src])?strtotime((string)$counts[$src]):false;
                if($ts!==false)$out[$dst]=max(0,$now_local-$ts);
            }
            $lease_ts=!empty($counts['oldest_expired_lease'])?strtotime((string)$counts['oldest_expired_lease'].' UTC'):false;
            if($lease_ts!==false)$out['oldest_lease_overdue_seconds']=max(0,$now_utc-$lease_ts);
        }
        if(self::campaigns_ready()){
            $oldest=$wpdb->get_var("SELECT MIN(q.updated_at) FROM ".self::queue()." q JOIN ".self::campaigns()." c ON c.id=q.campaign_id WHERE q.status='pending' AND c.state='active'");
            $ts=$oldest?strtotime((string)$oldest):false;
            if($ts!==false)$out['oldest_active_pending_age_seconds']=max(0,$now_local-$ts);
        }
        return $out;
    }

    public static function readiness_counts(): array { global $wpdb;
        $out=['draft'=>0,'active'=>0,'pending'=>0,'sending'=>0,'sent'=>0,'failed'=>0,'retryable_failed'=>0,'definitive_failed'=>0,'uncertain'=>0,'legacy_failed'=>0,'cancelled'=>0];
        if(self::campaigns_ready()){
            $r=$wpdb->get_row("SELECT COALESCE(SUM(state='draft'),0) draft,COALESCE(SUM(state='active'),0) active FROM ".self::campaigns(),ARRAY_A);
            if(is_array($r)){$out['draft']=(int)($r['draft']??0);$out['active']=(int)($r['active']??0);}
        }
        if(self::queue_ready()){
            $r=$wpdb->get_row("SELECT COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='sending'),0) sending,COALESCE(SUM(status='sent'),0) sent,COALESCE(SUM(status='retryable_failed'),0) retryable_failed,COALESCE(SUM(status='definitive_failed'),0) definitive_failed,COALESCE(SUM(status='uncertain'),0) uncertain,COALESCE(SUM(status='failed'),0) legacy_failed,COALESCE(SUM(status='cancelled'),0) cancelled FROM ".self::queue(),ARRAY_A);
            if(is_array($r)){
                foreach(['pending','sending','sent','retryable_failed','definitive_failed','uncertain','legacy_failed','cancelled'] as $k)$out[$k]=(int)($r[$k]??0);
                $out['failed']=$out['retryable_failed']+$out['definitive_failed']+$out['uncertain']+$out['legacy_failed'];
            }
        }
        return $out;
    }
    /** Storage read model used by Targeted; meta table is supplied by the owning feature. */
    public static function targeted_state(int $campaign_id,string $meta_table){ global $wpdb;
        $meta_table=self::identifier($meta_table);
        return $wpdb->get_row($wpdb->prepare('SELECT c.id,c.state,m.scheduled_at_utc FROM '.self::campaigns().' c JOIN '.$meta_table.' m ON m.campaign_id=c.id WHERE c.id=%d',$campaign_id));
    }
    public static function targeted_readiness(string $meta_table,string $manager_university): array { global $wpdb;
        $meta_table=self::identifier($meta_table);
        $out=['scheduled'=>0,'draft'=>0,'active'=>0,'cancelled'=>0,'due_now'=>0,'recipient_queue_pending'=>0];
        $where=" AND m.audience<>'program_status'";
        if($manager_university!=='')$where.=$wpdb->prepare(' AND m.manager_university=%s',$manager_university);
        $rows=$wpdb->get_results('SELECT c.state,COUNT(*) AS n FROM '.self::campaigns()." c JOIN $meta_table m ON m.campaign_id=c.id WHERE 1=1 $where GROUP BY c.state",ARRAY_A);
        foreach((array)$rows as $r)if(array_key_exists($r['state'],$out))$out[$r['state']]=(int)$r['n'];
        $out['due_now']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::campaigns()." c JOIN $meta_table m ON m.campaign_id=c.id WHERE c.state='scheduled' AND m.scheduled_at_utc<=%s $where",gmdate('Y-m-d H:i:s')));
        $out['recipient_queue_pending']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::queue()." q JOIN $meta_table m ON m.campaign_id=q.campaign_id WHERE q.status='pending' $where");
        return $out;
    }
    public static function targeted_rows(string $meta_table,string $manager_university,int $limit=100): array { global $wpdb;
        $meta_table=self::identifier($meta_table);
        $where=" WHERE m.audience<>'program_status'";
        if($manager_university!=='')$where.=$wpdb->prepare(' AND m.manager_university=%s',$manager_university);
        return (array)$wpdb->get_results($wpdb->prepare('SELECT c.*,m.request_key,m.audience,m.audience_groups,m.audience_universities,m.manager_university,m.scheduled_at_utc,m.scheduled_jalali,m.scheduled_hm,m.created_by,m.created_at_utc,m.launched_at_utc,m.cancelled_at_utc,(SELECT COUNT(*) FROM '.self::queue().' q WHERE q.campaign_id=c.id) recipients,(SELECT COUNT(*) FROM '.self::queue()." q WHERE q.campaign_id=c.id AND q.status='pending') pending,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status='sending') sending,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status='sent') sent,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status IN ('failed','retryable_failed','definitive_failed','uncertain')) failed,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status='retryable_failed') retryable_failed,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status='definitive_failed') definitive_failed,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status='uncertain') uncertain,(SELECT COUNT(*) FROM ".self::queue()." q WHERE q.campaign_id=c.id AND q.status='cancelled') cancelled FROM ".self::campaigns()." c JOIN $meta_table m ON m.campaign_id=c.id $where ORDER BY c.id DESC LIMIT %d",max(1,min(500,$limit))));
    }
    public static function excel_preview(int $campaign_id,string $report_table){ global $wpdb;
        $report_table=self::identifier($report_table);
        return $wpdb->get_row($wpdb->prepare('SELECT c.label,c.state,r.* FROM '.self::campaigns()." c JOIN $report_table r ON r.campaign_id=c.id WHERE c.id=%d",$campaign_id));
    }
    public static function recipient_count(int $campaign_id): int { global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::queue().' WHERE campaign_id=%d',$campaign_id));
    }
    public static function delivery_rows(int $campaign_id,int $limit=200,int $offset=0): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare('SELECT member_id,status,payload,updated_at,last_error FROM '.self::queue().' WHERE campaign_id=%d ORDER BY id LIMIT %d OFFSET %d',$campaign_id,max(1,min(500,$limit)),max(0,$offset)));
    }
    public static function non_sent_rows(int $campaign_id,int $limit=5000): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT member_id,status,updated_at,last_error FROM ".self::queue()." WHERE campaign_id=%d AND status<>'sent' ORDER BY id LIMIT %d",$campaign_id,max(1,min(5000,$limit))));
    }

    /** Phase D / D8: bounded keyset chunk for follow-up CSV export. */
    public static function non_sent_rows_after(int $campaign_id,int $after_id=0,int $limit=250): array { global $wpdb;
        if($campaign_id<=0)return [];
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT id,member_id,status,updated_at,last_error FROM ".self::queue()." WHERE campaign_id=%d AND status<>'sent' AND id>%d ORDER BY id ASC LIMIT %d",
            $campaign_id,max(0,$after_id),max(1,min(500,$limit))
        ));
    }
    public static function status_counts_for_campaign(int $campaign_id): array { global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='sending'),0) sending,COALESCE(SUM(status='sent'),0) sent,COALESCE(SUM(status='retryable_failed'),0) retryable_failed,COALESCE(SUM(status='definitive_failed'),0) definitive_failed,COALESCE(SUM(status='uncertain'),0) uncertain,COALESCE(SUM(status='failed'),0) legacy_failed,COALESCE(SUM(status='cancelled'),0) cancelled FROM ".self::queue()." WHERE campaign_id=%d",$campaign_id),ARRAY_A);
        $out=['pending'=>0,'sending'=>0,'sent'=>0,'failed'=>0,'retryable_failed'=>0,'definitive_failed'=>0,'uncertain'=>0,'legacy_failed'=>0,'cancelled'=>0];
        if(is_array($row)){
            foreach(['pending','sending','sent','retryable_failed','definitive_failed','uncertain','legacy_failed','cancelled'] as $k)$out[$k]=max(0,(int)($row[$k]??0));
            $out['failed']=$out['retryable_failed']+$out['definitive_failed']+$out['uncertain']+$out['legacy_failed'];
        }
        return $out;
    }

}
