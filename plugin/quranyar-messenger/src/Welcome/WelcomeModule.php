<?php
if (!defined('ABSPATH')) exit;

trait QYS_Welcome_Module {
    static function welcome_default_template(){return "سلام {نام} عزیز 🌱\nبه قرآن‌یار {دانشگاه} خوش آمدید.\nعضویت شما با موفقیت ثبت شد.\nاز همراهی شما در مسیر انس با قرآن کریم خوشحالیم. 🌿";}

    static function activation_default_template(){return "سلام {نام} عزیز 🌱\nحساب شما در قرآن‌یار {دانشگاه} تأیید و فعال شد.\nاز این پس پیام‌ها و خدمات اختصاصی قرآن‌یار برای شما در دسترس است. 🌿";}

    static function welcome_format($raw){
      $s=trim(wp_strip_all_tags((string)$raw));
      if(self::chars($s)<1||self::chars($s)>1200)return '';
      preg_match_all('/\{([^{}]+)\}/u',$s,$m);
      foreach($m[1] as $token)if(!in_array($token,['نام','نام_خانوادگی','دانشگاه'],true))return '';
      if(strpbrk(preg_replace('/\{(?:نام|نام_خانوادگی|دانشگاه)\}/u','',$s),'{}')!==false)return '';
      return $s;
     }

    /** B3: onboarding delivery uses the canonical delivery-state vocabulary. */
    static function onboarding_lease_seconds(){return 180;}
    static function onboarding_max_attempts(){return 3;}
    static function onboarding_retry_window_seconds(){return DAY_IN_SECONDS;}
    static function onboarding_retry_delay($attempts){
      $attempts=max(1,(int)$attempts);
      if($attempts===1)return 60;
      if($attempts===2)return 300;
      return 0;
     }

    static function onboarding_table($kind){return self::table($kind==='activation'?'activation_log':'welcome_log');}

    static function onboarding_log_schema_ready($kind){ return QYS_Schema_Runtime::ready(); }

    static function onboarding_log_diagnostic_ready($kind){
      $kind=$kind==='activation'?'activation':'welcome';
      $t=self::onboarding_table($kind);
      $required=['id','member_id','university','status','attempts','reason','claimed_at_utc','lease_expires_at_utc','outbound_started_at_utc','next_attempt_at_utc','created_at','updated_at'];
      $columns=QYS_Table_Names::diagnostic_columns($t);
      foreach($required as $c)if(!isset($columns[$c]))return false;
      $type=(string)($columns['status']['Type']??'');
      return preg_match('/varchar\((\d+)\)/i',$type,$m)===1 && (int)$m[1]>=24;
    }

    static function welcome_log_ready(){return self::onboarding_log_schema_ready('welcome');}
    static function activation_log_ready(){return self::onboarding_log_schema_ready('activation');}

    static function ensure_onboarding_schema($kind){global $wpdb;
      $kind=$kind==='activation'?'activation':'welcome';$t=self::onboarding_table($kind);$status_index=$kind.'_status';
      require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
      dbDelta("CREATE TABLE $t (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     member_id bigint unsigned NOT NULL,
     university varchar(40) NOT NULL DEFAULT '',
     status varchar(24) NOT NULL DEFAULT 'pending',
     attempts smallint unsigned NOT NULL DEFAULT 0,
     reason varchar(100) NOT NULL DEFAULT '',
     claimed_at_utc datetime NULL DEFAULT NULL,
     lease_expires_at_utc datetime NULL DEFAULT NULL,
     outbound_started_at_utc datetime NULL DEFAULT NULL,
     next_attempt_at_utc datetime NULL DEFAULT NULL,
     created_at datetime NOT NULL,
     updated_at datetime NOT NULL,
     PRIMARY KEY  (id), UNIQUE KEY member_once (member_id), KEY {$status_index} (status), KEY retry_due (status,next_attempt_at_utc), KEY sending_lease (status,lease_expires_at_utc)
     ) $c;");
      if(!self::onboarding_log_diagnostic_ready($kind))return false;
      return self::migrate_onboarding_log($kind);
     }

    static function ensure_welcome_schema(){return self::ensure_onboarding_schema('welcome');}
    static function ensure_activation_schema(){return self::ensure_onboarding_schema('activation');}

    /** Idempotent migration of pre-B3 accepted/failed/sending rows. */
    static function migrate_onboarding_log($kind){global $wpdb;
      $kind=$kind==='activation'?'activation':'welcome';$t=self::onboarding_table($kind);$now=gmdate('Y-m-d H:i:s');$cutoff=gmdate('Y-m-d H:i:s',time()-self::onboarding_retry_window_seconds());
      for($page=0;$page<1000;$page++){
        $rows=(array)$wpdb->get_results("SELECT id,status,attempts,reason,created_at FROM $t WHERE status IN ('accepted','failed','sending') ORDER BY id ASC LIMIT 250");
        if(!$rows)break;
        foreach($rows as $r){
          $old=(string)$r->status;$attempts=max(0,(int)$r->attempts);$reason=(string)$r->reason;$next=null;$new=QYS_Delivery_State::UNCERTAIN;
          if($old==='accepted')$new=QYS_Delivery_State::SENT;
          elseif($old==='failed'){
            $new=QYS_Delivery_State::from_failure_reason($reason);
            if($new===QYS_Delivery_State::RETRYABLE_FAILED && $attempts<self::onboarding_max_attempts() && (string)$r->created_at>=$cutoff){$delay=self::onboarding_retry_delay(max(1,$attempts));if($delay>0)$next=gmdate('Y-m-d H:i:s',time()+$delay);}
          } elseif($old==='sending'){
            // Pre-B3 did not persist a network boundary; safest state is unknown.
            $new=QYS_Delivery_State::UNCERTAIN;if($reason==='')$reason='legacy_sending_unknown';
          }
          $ok=$wpdb->update($t,['status'=>$new,'reason'=>self::clip($reason,100),'claimed_at_utc'=>null,'lease_expires_at_utc'=>null,'outbound_started_at_utc'=>null,'next_attempt_at_utc'=>$next,'updated_at'=>$now],['id'=>(int)$r->id],['%s','%s','%s','%s','%s','%s','%s'],['%d']);
          if($ok===false)return false;
        }
      }
      return true;
     }

    static function welcome_message($member){
      $uni=(string)($member->university??'');
      $text=class_exists('QYS_Campus_Welcome')?QYS_Campus_Welcome::template($uni):self::welcome_default_template();
      $text=self::welcome_format($text);
      if($text==='')$text=self::welcome_default_template();
      $map=['{نام}'=>trim((string)($member->first_name??'')),'{نام_خانوادگی}'=>trim((string)($member->last_name??'')),'{دانشگاه}'=>self::university_name($uni)];
      return trim(strtr($text,$map));
     }

    static function activation_message($member){
      $uni=(string)($member->university??'');
      $text=class_exists('QYS_Campus_Welcome')?QYS_Campus_Welcome::activation_template($uni):self::activation_default_template();
      $text=self::welcome_format($text);
      if($text==='')$text=self::activation_default_template();
      $map=['{نام}'=>trim((string)($member->first_name??'')),'{نام_خانوادگی}'=>trim((string)($member->last_name??'')),'{دانشگاه}'=>self::university_name($uni)];
      return trim(strtr($text,$map));
     }

    static function onboarding_member_eligible($kind,$member){
      if(!$member||!(int)($member->consent??0))return false;
      if($kind==='activation'&&!(int)($member->verified??0))return false;
      return preg_match('/\A[0-9]{1,20}\z/D',(string)($member->eitaa_id??''))===1;
     }

    static function onboarding_recover_member($kind,$member_id){global $wpdb;
      $t=self::onboarding_table($kind);$now=gmdate('Y-m-d H:i:s');$member_id=absint($member_id);if(!$member_id)return '';
      $row=$wpdb->get_row($wpdb->prepare("SELECT status,lease_expires_at_utc,outbound_started_at_utc FROM $t WHERE member_id=%d",$member_id));
      if(!$row||(string)$row->status!==QYS_Delivery_State::SENDING)return $row?(string)$row->status:'';
      $lease=(string)($row->lease_expires_at_utc??'');
      if($lease!==''&&$lease>$now)return QYS_Delivery_State::SENDING;
      $outbound=(string)($row->outbound_started_at_utc??'');
      $new=($lease!==''&&$outbound==='')?QYS_Delivery_State::PENDING:QYS_Delivery_State::UNCERTAIN;
      $reason=$new===QYS_Delivery_State::UNCERTAIN?'stale_onboarding_delivery_unknown':'';
      $n=$wpdb->query($wpdb->prepare("UPDATE $t SET status=%s,reason=%s,claimed_at_utc=NULL,lease_expires_at_utc=NULL,outbound_started_at_utc=NULL,next_attempt_at_utc=NULL,updated_at=%s WHERE member_id=%d AND status=%s",$new,$reason,$now,$member_id,QYS_Delivery_State::SENDING));
      return $n===1?$new:QYS_Delivery_State::SENDING;
     }

    static function onboarding_claim($kind,$member_id){global $wpdb;
      $t=self::onboarding_table($kind);$member_id=absint($member_id);$now=gmdate('Y-m-d H:i:s');$lease=gmdate('Y-m-d H:i:s',time()+self::onboarding_lease_seconds());$cutoff=gmdate('Y-m-d H:i:s',time()-self::onboarding_retry_window_seconds());
      $sql="UPDATE $t SET status=%s,attempts=attempts+1,reason='',claimed_at_utc=%s,lease_expires_at_utc=%s,outbound_started_at_utc=NULL,next_attempt_at_utc=NULL,updated_at=%s WHERE member_id=%d AND (status=%s OR (status=%s AND attempts<%d AND created_at>=%s AND (next_attempt_at_utc IS NULL OR next_attempt_at_utc<=%s)))";
      return $wpdb->query($wpdb->prepare($sql,QYS_Delivery_State::SENDING,$now,$lease,$now,$member_id,QYS_Delivery_State::PENDING,QYS_Delivery_State::RETRYABLE_FAILED,self::onboarding_max_attempts(),$cutoff,$now))===1;
     }

    static function onboarding_mark_outbound_started($kind,$member_id){global $wpdb;
      $t=self::onboarding_table($kind);$now=gmdate('Y-m-d H:i:s');
      return $wpdb->query($wpdb->prepare("UPDATE $t SET outbound_started_at_utc=%s,updated_at=%s WHERE member_id=%d AND status=%s AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc>=%s AND outbound_started_at_utc IS NULL",$now,$now,absint($member_id),QYS_Delivery_State::SENDING,$now))===1;
     }

    static function onboarding_finish($kind,$member_id,$result){global $wpdb;
      $t=self::onboarding_table($kind);$member_id=absint($member_id);$result=QYS_Delivery_State::normalize_result((array)$result);$state=(string)$result['state'];$reason=self::clip((string)$result['reason'],100);$now=gmdate('Y-m-d H:i:s');$row=$wpdb->get_row($wpdb->prepare("SELECT attempts,created_at FROM $t WHERE member_id=%d",$member_id));$next=null;
      if($state===QYS_Delivery_State::RETRYABLE_FAILED&&$row){$attempts=(int)$row->attempts;$within=(string)$row->created_at>=gmdate('Y-m-d H:i:s',time()-self::onboarding_retry_window_seconds());$delay=self::onboarding_retry_delay($attempts);if($attempts<self::onboarding_max_attempts()&&$within&&$delay>0)$next=gmdate('Y-m-d H:i:s',time()+$delay);}
      $n=$wpdb->query($wpdb->prepare("UPDATE $t SET status=%s,reason=%s,claimed_at_utc=NULL,lease_expires_at_utc=NULL,outbound_started_at_utc=NULL,next_attempt_at_utc=".($next===null?'NULL':'%s').",updated_at=%s WHERE member_id=%d AND status=%s",...($next===null?[$state,$reason,$now,$member_id,QYS_Delivery_State::SENDING]:[$state,$reason,$next,$now,$member_id,QYS_Delivery_State::SENDING])));
      return $n===1;
     }

    static function onboarding_cancel_sending($kind,$member_id,$reason='member_ineligible'){global $wpdb;
      $t=self::onboarding_table($kind);$now=gmdate('Y-m-d H:i:s');
      return $wpdb->query($wpdb->prepare("UPDATE $t SET status=%s,reason=%s,claimed_at_utc=NULL,lease_expires_at_utc=NULL,outbound_started_at_utc=NULL,next_attempt_at_utc=NULL,updated_at=%s WHERE member_id=%d AND status=%s",QYS_Delivery_State::CANCELLED,self::clip($reason,100),$now,absint($member_id),QYS_Delivery_State::SENDING))===1;
     }

    static function onboarding_send_once($kind,$member_id){global $wpdb;
      $kind=$kind==='activation'?'activation':'welcome';$member_id=absint($member_id);if(!$member_id)return ['attempted'=>false,'sent'=>false,'status'=>'invalid_member'];
      $ready=$kind==='activation'?self::activation_log_ready():self::welcome_log_ready();
      if(!$ready && !self::ensure_onboarding_schema($kind))return ['attempted'=>false,'sent'=>false,'status'=>'log_unavailable'];
      $t=self::onboarding_table($kind);$member=QYS_Member_Repository::find_by_id($member_id);
      if(!self::onboarding_member_eligible($kind,$member))return ['attempted'=>false,'sent'=>false,'status'=>'ineligible'];
      self::onboarding_recover_member($kind,$member_id);
      $row=$wpdb->get_row($wpdb->prepare("SELECT status,attempts,next_attempt_at_utc,university FROM $t WHERE member_id=%d",$member_id));
      if(!$row){$now=gmdate('Y-m-d H:i:s');$created=$wpdb->insert($t,['member_id'=>$member_id,'university'=>(string)$member->university,'status'=>QYS_Delivery_State::PENDING,'attempts'=>0,'reason'=>'','created_at'=>$now,'updated_at'=>$now],['%d','%s','%s','%d','%s','%s','%s']);if($created!==1)$row=$wpdb->get_row($wpdb->prepare("SELECT status,attempts,next_attempt_at_utc,university FROM $t WHERE member_id=%d",$member_id));}
      $expected_uni=$row?sanitize_key((string)($row->university??'')):sanitize_key((string)$member->university);
      if($row){$status=(string)$row->status;
        if($status===QYS_Delivery_State::SENT||$status==='accepted')return ['attempted'=>false,'sent'=>true,'status'=>QYS_Delivery_State::SENT];
        if(in_array($status,[QYS_Delivery_State::PENDING,QYS_Delivery_State::RETRYABLE_FAILED],true) && sanitize_key((string)($row->university??''))!==sanitize_key((string)$member->university)){
          $wpdb->update($t,['status'=>QYS_Delivery_State::CANCELLED,'reason'=>'member_scope_changed','next_attempt_at_utc'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')],['member_id'=>$member_id],['%s','%s','%s','%s'],['%d']);
          return ['attempted'=>false,'sent'=>false,'status'=>QYS_Delivery_State::CANCELLED];
        }
        if(in_array($status,[QYS_Delivery_State::UNCERTAIN,QYS_Delivery_State::DEFINITIVE_FAILED,QYS_Delivery_State::CANCELLED],true))return ['attempted'=>false,'sent'=>false,'status'=>$status];
        if($status===QYS_Delivery_State::SENDING)return ['attempted'=>false,'sent'=>false,'status'=>$status];
        if($status===QYS_Delivery_State::RETRYABLE_FAILED && ((int)$row->attempts>=self::onboarding_max_attempts() || ((string)($row->next_attempt_at_utc??'')!==''&&(string)$row->next_attempt_at_utc>gmdate('Y-m-d H:i:s'))))return ['attempted'=>false,'sent'=>false,'status'=>$status];
      }
      if(self::secret('webapp')===''){
        $status=$row?(string)$row->status:QYS_Delivery_State::PENDING;
        return ['attempted'=>false,'sent'=>false,'status'=>$status];
      }
      if(!self::onboarding_claim($kind,$member_id)){$row=$wpdb->get_row($wpdb->prepare("SELECT status FROM $t WHERE member_id=%d",$member_id));$status=$row?(string)$row->status:'claim_failed';return ['attempted'=>false,'sent'=>$status===QYS_Delivery_State::SENT,'status'=>$status];}
      // Re-read eligibility after the atomic claim; consent/verification may have
      // changed while this event was waiting for retry.
      $member=QYS_Member_Repository::find_by_id($member_id);
      if(!self::onboarding_member_eligible($kind,$member) || sanitize_key((string)($member->university??''))!==$expected_uni){self::onboarding_cancel_sending($kind,$member_id,'member_ineligible_or_scope_changed');return ['attempted'=>false,'sent'=>false,'status'=>QYS_Delivery_State::CANCELLED];}
      if(!self::onboarding_mark_outbound_started($kind,$member_id))return ['attempted'=>false,'sent'=>false,'status'=>'claim_lost'];
      $message=$kind==='activation'?self::activation_message($member):self::welcome_message($member);
      try{$result=QYS_Queue_Service::deliver_claimed((string)$member->eitaa_id,$message);}catch(Throwable $e){$result=['ok'=>false,'state'=>QYS_Delivery_State::UNCERTAIN,'reason'=>'network_failure'];}
      $result=QYS_Delivery_State::normalize_result($result);
      self::onboarding_finish($kind,$member_id,$result);
      return ['attempted'=>true,'sent'=>!empty($result['ok']),'status'=>(string)$result['state']];
     }

    static function welcome_send_once($member_id){return self::onboarding_send_once('welcome',$member_id);}
    static function activation_send_once($member_id){return self::onboarding_send_once('activation',$member_id);}

    /** B3 cron recovery: only pending/due-retry rows are attempted. */
    static function welcome_activation_worker(){global $wpdb;
      if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::worker_allowed())return;
      foreach(['welcome','activation'] as $kind){
        $ready=$kind==='activation'?self::activation_log_ready():self::welcome_log_ready();if(!$ready)continue;
        $t=self::onboarding_table($kind);$now=gmdate('Y-m-d H:i:s');
        // Recover stale claims in bounded batches. Any unleased/legacy sending is
        // quarantined, because its network history cannot be proven.
        $stale=(array)$wpdb->get_results($wpdb->prepare("SELECT member_id FROM $t WHERE status=%s AND (lease_expires_at_utc IS NULL OR lease_expires_at_utc<=%s) ORDER BY id ASC LIMIT 20",QYS_Delivery_State::SENDING,$now));
        foreach($stale as $r)self::onboarding_recover_member($kind,(int)$r->member_id);
        $cutoff=gmdate('Y-m-d H:i:s',time()-self::onboarding_retry_window_seconds());
        $due=(array)$wpdb->get_results($wpdb->prepare("SELECT member_id FROM $t WHERE status=%s OR (status=%s AND attempts<%d AND created_at>=%s AND (next_attempt_at_utc IS NULL OR next_attempt_at_utc<=%s)) ORDER BY id ASC LIMIT 2",QYS_Delivery_State::PENDING,QYS_Delivery_State::RETRYABLE_FAILED,self::onboarding_max_attempts(),$cutoff,$now));
        foreach($due as $r)self::onboarding_send_once($kind,(int)$r->member_id);
      }
     }

    static function onboarding_delivery_counts($kind){global $wpdb;
      $kind=$kind==='activation'?'activation':'welcome';$ready=$kind==='activation'?self::activation_log_ready():self::welcome_log_ready();
      $out=['pending'=>0,'sending'=>0,'sent'=>0,'retryable_failed'=>0,'definitive_failed'=>0,'uncertain'=>0,'cancelled'=>0,'legacy_accepted'=>0,'legacy_failed'=>0,'sending_stale'=>0];if(!$ready)return $out;
      $t=self::onboarding_table($kind);$now=gmdate('Y-m-d H:i:s');
      $row=$wpdb->get_row("SELECT COUNT(*) total,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='sending'),0) sending,COALESCE(SUM(status='sent'),0) sent,COALESCE(SUM(status='retryable_failed'),0) retryable_failed,COALESCE(SUM(status='definitive_failed'),0) definitive_failed,COALESCE(SUM(status='uncertain'),0) uncertain,COALESCE(SUM(status='cancelled'),0) cancelled,COALESCE(SUM(status='accepted'),0) legacy_accepted,COALESCE(SUM(status='failed'),0) legacy_failed,COALESCE(SUM(status='sending' AND (lease_expires_at_utc IS NULL OR lease_expires_at_utc<='$now')),0) sending_stale FROM $t",ARRAY_A);
      if(is_array($row))foreach($out as $k=>$v)$out[$k]=max(0,(int)($row[$k]??0));return $out;
     }

    static function activation_delivery_note($result){
      if(!empty($result['sent']))return ' پیام فعال‌سازی نیز در ایتا ارسال شد.';
      $s=(string)($result['status']??'');
      if($s==='ineligible')return ' دریافت پیام عضو غیرفعال است؛ پیام فعال‌سازی ارسال نشد.';
      if($s===QYS_Delivery_State::RETRYABLE_FAILED)return ' ارسال پیام فعال‌سازی موقتاً ناموفق بود و در بازه ایمن دوباره تلاش می‌شود.';
      if($s===QYS_Delivery_State::UNCERTAIN)return ' نتیجه ارسال پیام فعال‌سازی نامشخص است؛ برای جلوگیری از پیام تکراری، ارسال خودکار تکرار نمی‌شود.';
      if($s===QYS_Delivery_State::DEFINITIVE_FAILED)return ' ارسال پیام فعال‌سازی به‌صورت قطعی ناموفق بود؛ وضعیت مخاطب/درگاه را بررسی کنید.';
      if($s===QYS_Delivery_State::PENDING)return ' پیام فعال‌سازی در صف امن قرار دارد و پس از آماده بودن درگاه ارسال می‌شود.';
      if($s===QYS_Delivery_State::SENDING)return ' ارسال پیام فعال‌سازی در حال پردازش است.';
      return ' ارسال پیام فعال‌سازی انجام نشد؛ وضعیت درگاه را بررسی کنید.';
     }
}
