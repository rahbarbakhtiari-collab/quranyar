<?php
if (!defined('ABSPATH')) exit;

/**
 * Single message-dispatch boundary. Feature modules may claim their own durable
 * rows, but only this service can cross the network through QYS_Message_Gateway.
 */
final class QYS_Queue_Service {
    private const CLAIM_LEASE_SECONDS=180;

    public static function deliver_claimed(string $eitaa_id,string $text): array {
        return QYS_Delivery_State::normalize_result(QYS_Message_Gateway::send_private($eitaa_id,$text));
    }

    public static function process_targeted_batch(): string {
        $started=microtime(true);
        $plan=QYS_Queue_Throughput::plan();
        $stats=[
            'cap'=>(int)$plan['cap'],'scanned'=>0,'attempted'=>0,'sent'=>0,
            'retryable_failed'=>0,'definitive_failed'=>0,'uncertain'=>0,
            'cancelled'=>0,'stop'=>'complete','duration_ms'=>0,
        ];

        // New delivery-state values require the B1 queue schema; never write a
        // 17-character state into the pre-B1 varchar(16) column during upgrade.
        if(class_exists('QYS_Installer')&&!QYS_Installer::ready()){
            $stats['stop']='schema_not_ready';
            self::record_throughput($stats,$started);
            return 'schema_not_ready';
        }
        if(!QYS_Token_Store::secret('webapp')){
            $stats['stop']='missing_token';
            self::record_throughput($stats,$started);
            return 'missing_webapp_token';
        }
        $lock='qys_batch_lock';
        $recovered_stale_lock=false;
        if(!add_option($lock,time(),'','no')){
            if(time()-(int)get_option($lock)<120){
                $stats['stop']='locked';
                self::record_throughput($stats,$started);
                return 'locked';
            }
            delete_option($lock);
            if(!add_option($lock,time(),'','no')){
                $stats['stop']='locked';
                self::record_throughput($stats,$started);
                return 'locked';
            }
            $recovered_stale_lock=true;
        }

        $worker_result='ok';
        try{
            if(!QYS_Campaign_Repository::tables_ready()||!QYS_Member_Repository::table_exists()){
                $stats['stop']='tables_not_ready';
                return 'tables_not_ready';
            }

            // B2: recover only expired sending claims. A claim that never crossed
            // the outbound marker is safe to requeue; after that marker it is
            // quarantined as uncertain and is never resent automatically.
            $recovery=QYS_Campaign_Repository::recover_expired_sending(50,self::CLAIM_LEASE_SECONDS);
            $recovered_sending=((int)($recovery['requeued']??0)+(int)($recovery['uncertain']??0))>0;

            $rows=QYS_Campaign_Repository::pending_batch((int)$plan['cap']);
            if(!$rows){
                $stats['stop']=$recovered_sending?'recovered_stale_sending':($recovered_stale_lock?'recovered_stale_lock':'empty');
                if($recovered_sending)return 'recovered_stale_sending';
                return $recovered_stale_lock?'recovered_stale_lock':'ok';
            }

            $last_send_started=0.0;
            foreach($rows as $row){
                if(!QYS_Queue_Throughput::within_budget($started)){
                    $stats['stop']='budget';
                    $worker_result='budget_exhausted';
                    break;
                }
                $stats['scanned']++;
                $dispatch=QYS_Permission_Service::can_dispatch_targeted((int)$row->campaign_id,(int)$row->member_id);
                if($dispatch===null){
                    $stats['stop']='tenant_hold';
                    return 'tenant_metadata_hold'; // Hold queue while tenant metadata is unavailable.
                }
                if($dispatch===false){
                    if(QYS_Campaign_Repository::cancel_queue_row((int)$row->id,'pending','tenant_authorization_revoked'))$stats['cancelled']++;
                    continue;
                }
                if(!QYS_Campaign_Repository::claim((int)$row->id,self::CLAIM_LEASE_SECONDS))continue;
                // Maintenance RC46: an approved campaign can still be edited
                // until the first delivery begins. Re-read the payload after
                // claim so a worker that selected the row just before an edit
                // never sends the stale pre-edit text.
                $fresh_payload=QYS_Campaign_Repository::claimed_payload((int)$row->id);
                if($fresh_payload===null){
                    QYS_Campaign_Repository::cancel_queue_row((int)$row->id,'sending','payload_unavailable');
                    $stats['cancelled']++;
                    continue;
                }
                $member=QYS_Member_Repository::eligible_delivery_member((int)$row->member_id);
                if(!$member){
                    if(QYS_Campaign_Repository::cancel_queue_row((int)$row->id,'sending','member_ineligible'))$stats['cancelled']++;
                    continue;
                }
                // This write is the last safe boundary before network I/O. If it
                // fails (for example because another worker recovered an expired
                // claim), this worker must not send the message.
                if(!QYS_Campaign_Repository::mark_outbound_started((int)$row->id,self::CLAIM_LEASE_SECONDS))continue;

                // D6: provider-friendly pacing plus a bounded adaptive run. We do
                // not assume a vendor-specific messages/second limit; a real 429
                // stops the run immediately and is preserved as retryable_failed.
                QYS_Queue_Throughput::pace($last_send_started);
                $last_send_started=microtime(true);
                try{$result=self::deliver_claimed((string)$member->eitaa_id,$fresh_payload);}catch(Throwable $e){$result=['ok'=>false,'state'=>QYS_Delivery_State::UNCERTAIN,'reason'=>'network_failure'];}
                $stats['attempted']++;
                $result=QYS_Delivery_State::normalize_result($result);
                $state=(string)$result['state'];
                if($state===QYS_Delivery_State::SENT)$stats['sent']++;
                elseif($state===QYS_Delivery_State::RETRYABLE_FAILED)$stats['retryable_failed']++;
                elseif($state===QYS_Delivery_State::DEFINITIVE_FAILED)$stats['definitive_failed']++;
                elseif($state===QYS_Delivery_State::UNCERTAIN)$stats['uncertain']++;
                QYS_Campaign_Repository::finish_delivery((int)$row->id,$state,(string)$result['reason']);

                // Do not fan out a provider throttle or ambiguous network event
                // across the remaining queue. The next cron gets a fresh budget.
                if($state===QYS_Delivery_State::RETRYABLE_FAILED){
                    $stats['stop']='rate_limited';
                    $worker_result='rate_limited';
                    break;
                }
                if($state===QYS_Delivery_State::UNCERTAIN){
                    $stats['stop']='uncertain';
                    $worker_result='network_uncertain';
                    break;
                }
            }

            if($worker_result==='ok'){
                if($recovered_sending){$stats['stop']='recovered_stale_sending';return 'recovered_stale_sending';}
                if($recovered_stale_lock){$stats['stop']='recovered_stale_lock';return 'recovered_stale_lock';}
            }
            return $worker_result;
        }finally{
            delete_option($lock);
            self::record_throughput($stats,$started);
        }
    }

    private static function record_throughput(array $stats,float $started): void {
        $stats['duration_ms']=QYS_Queue_Throughput::elapsed_ms($started);
        QYS_Queue_Throughput::record($stats);
    }
}
