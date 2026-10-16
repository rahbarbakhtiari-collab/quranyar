<?php
if (!defined('ABSPATH')) exit;

trait QYS_Birthday_Module {
    static function birthday_enabled(){return get_option('qys_birthday_enabled','no')==='yes';}

    static function birthday_default_template(){return "سلام {نام} عزیز 🌷\nزادروزتان مبارک! برایتان تندرستی، آرامش و توفیق روزافزون آرزو داریم.\nقرآن‌یار";}

    static function birthday_iran_now(){return QYS_Calendar_Service::iran_now();}

    public static function jalali_from_iran_date(DateTimeInterface $dt){return QYS_Calendar_Service::jalali_from_date($dt);}

    static function birthday_log_ready(){return self::table_exists(self::table('birthday_log'));}

    static function ensure_birthday_schema(){global $wpdb;
      require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
      dbDelta('CREATE TABLE '.self::table('birthday_log')." (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     member_id bigint unsigned NOT NULL,
     jalali_year smallint unsigned NOT NULL,
     scheduled_on varchar(10) NOT NULL,
     status varchar(16) NOT NULL DEFAULT 'pending',
     attempts tinyint unsigned NOT NULL DEFAULT 0,
     reason varchar(64) NOT NULL DEFAULT '',
     created_at datetime NOT NULL,
     updated_at datetime NOT NULL,
     PRIMARY KEY  (id), UNIQUE KEY member_year (member_id,jalali_year), KEY day_status (scheduled_on,status)
     ) $c;");
      if(!QYS_Table_Names::diagnostic_exists(self::table('birthday_log')))return false;
      return true;
     }

    static function birthday_archive_ready(){return self::table_exists(self::table('birthday_archive'));}

    static function ensure_birthday_archive_schema(){global $wpdb;
      require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
      dbDelta('CREATE TABLE '.self::table('birthday_archive')." (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     member_id bigint unsigned NOT NULL,
     birthday_log_id bigint unsigned DEFAULT NULL,
     kind varchar(12) NOT NULL,
     first_name varchar(100) NOT NULL DEFAULT '',
     last_name varchar(100) NOT NULL DEFAULT '',
     university varchar(40) NOT NULL DEFAULT '',
     member_role varchar(32) NOT NULL DEFAULT '',
     eitaa_id varchar(32) NOT NULL DEFAULT '',
     national_id varchar(10) NOT NULL DEFAULT '',
     mobile varchar(11) NOT NULL DEFAULT '',
     birth_jalali varchar(10) NOT NULL DEFAULT '',
     consent_at_send tinyint unsigned NOT NULL DEFAULT 0,
     verified_at_send tinyint unsigned NOT NULL DEFAULT 0,
     birthday_opt_in_at_send tinyint unsigned NOT NULL DEFAULT 0,
     message_text longtext NOT NULL,
     iran_day varchar(10) NOT NULL,
     attempted_at datetime NOT NULL,
     gateway_status varchar(16) NOT NULL DEFAULT 'sending',
     reason varchar(100) NOT NULL DEFAULT '',
     updated_at datetime NOT NULL,
     PRIMARY KEY  (id), UNIQUE KEY automatic_log (birthday_log_id), UNIQUE KEY test_member_day (kind,member_id,iran_day), KEY archive_date (attempted_at), KEY kind_status (kind,gateway_status), KEY member_history (member_id)
     ) $c;");
      if(!QYS_Table_Names::diagnostic_exists(self::table('birthday_archive')))return false;
      return true;
     }

    static function archive_access_ready(){return self::table_exists(self::table('archive_access_log'));}

    static function ensure_archive_access_schema(){global $wpdb;
      require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
      dbDelta('CREATE TABLE '.self::table('archive_access_log')." (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     admin_user_id bigint unsigned NOT NULL,
     event varchar(20) NOT NULL,
     archive_kind varchar(12) NOT NULL DEFAULT '',
     archive_status varchar(16) NOT NULL DEFAULT '',
     day_filter_applied tinyint unsigned NOT NULL DEFAULT 0,
     search_filter_applied tinyint unsigned NOT NULL DEFAULT 0,
     row_count int unsigned NOT NULL DEFAULT 0,
     occurred_at_utc datetime NOT NULL,
     PRIMARY KEY  (id), KEY audit_date (occurred_at_utc), KEY audit_actor (admin_user_id), KEY audit_event (event)
     ) $c;");
      if(!QYS_Table_Names::diagnostic_exists(self::table('archive_access_log')))return false;
      return true;
     }

    static function archive_access_record($event,$filters,$row_count=0){global $wpdb;
      if(!QYS_Security_Service::is_global_admin()||!in_array($event,['view','export_request'],true))return false;
      if(!self::archive_access_ready()&&!self::ensure_archive_access_schema())return false;
      $kind=in_array($filters['kind']??'',['automatic','test'],true)?$filters['kind']:'';
      $status=in_array($filters['status']??'',['accepted','failed','uncertain','sending'],true)?$filters['status']:'';
      $ok=$wpdb->insert(self::table('archive_access_log'),[
       'admin_user_id'=>(int)get_current_user_id(),'event'=>$event,
       'archive_kind'=>$kind,'archive_status'=>$status,
       'day_filter_applied'=>!empty($filters['day'])?1:0,
       'search_filter_applied'=>!empty($filters['search'])?1:0,
       'row_count'=>max(0,(int)$row_count),
       'occurred_at_utc'=>gmdate('Y-m-d H:i:s')],
       ['%d','%s','%s','%s','%d','%d','%d','%s']);
      return $ok===1;
     }
    static function archive_readiness(){global $wpdb;
     $ready=self::birthday_archive_ready();$audit=self::archive_access_ready();
     $totals=['archive_ready'=>$ready,'access_audit_ready'=>$audit,'archive_total'=>0,'automatic_total'=>0,'test_total'=>0,'accepted_total'=>0,'archive_views'=>0,'export_requests'=>0];
     if($ready){$a=self::table('birthday_archive');$r=$wpdb->get_row("SELECT COUNT(*) total,COALESCE(SUM(kind='automatic'),0) automatic_total,COALESCE(SUM(kind='test'),0) test_total,COALESCE(SUM(gateway_status='accepted'),0) accepted_total FROM $a",ARRAY_A);if(is_array($r))foreach(['total'=>'archive_total','automatic_total'=>'automatic_total','test_total'=>'test_total','accepted_total'=>'accepted_total'] as $k=>$v)$totals[$v]=(int)($r[$k]??0);}
     if($audit){$t=self::table('archive_access_log');$r=$wpdb->get_row("SELECT COALESCE(SUM(event='view'),0) views,COALESCE(SUM(event='export_request'),0) exports FROM $t",ARRAY_A);if(is_array($r)){$totals['archive_views']=(int)$r['views'];$totals['export_requests']=(int)$r['exports'];}}
     return $totals;
    }

    static function archive_access_panel(){global $wpdb;
      if(!QYS_Security_Service::is_global_admin()||!self::archive_access_ready())return;
      $rows=$wpdb->get_results('SELECT admin_user_id,event,archive_kind,archive_status,day_filter_applied,search_filter_applied,row_count,occurred_at_utc FROM '.self::table('archive_access_log').' ORDER BY id DESC LIMIT 30');
      echo '<h2>گزارش دسترسی به آرشیو</h2><p>این جدول فقط نام مدیر، زمان دسترسی و نوع عملیات را ثبت می‌کند. متن جست‌وجو، کد ملی، موبایل، متن پیام و نشانی IP در گزارش دسترسی ذخیره نمی‌شود. تعداد خروجی، تعداد ردیف‌های <strong>درخواست‌شده</strong> است؛ نه تأیید دانلود فایل.</p>';
      echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>زمان UTC</th><th>مدیر</th><th>عملیات</th><th>فیلتر نوع</th><th>فیلتر وضعیت</th><th>فیلتر تاریخ</th><th>جست‌وجو اعمال شد</th><th>تعداد نتایج هنگام درخواست</th></tr></thead><tbody>';
      foreach($rows as $r){
       $user=get_userdata((int)$r->admin_user_id);
       $name=$user?((string)$user->display_name).' (#'.(int)$r->admin_user_id.')':'#'.(int)$r->admin_user_id;
       $cols=[$r->occurred_at_utc,$name,$r->event==='view'?'مشاهده آرشیو':'درخواست خروجی CSV',$r->archive_kind?:'همه',$r->archive_status?:'همه',(int)$r->day_filter_applied?'بله':'خیر',(int)$r->search_filter_applied?'بله':'خیر',(int)$r->row_count];
       echo '<tr>';foreach($cols as $v)echo '<td>'.esc_html((string)$v).'</td>';echo '</tr>';
      }
      if(!$rows)echo '<tr><td colspan="8">هنوز دسترسی ثبت‌شده‌ای وجود ندارد.</td></tr>';
      echo '</tbody></table></div>';
     }

    static function birthday_archive_start($member,$kind,$message,$log_id=null){global $wpdb;
      if(!in_array($kind,['automatic','test'],true)||!$member||$message==='')return 0;
      if(!self::birthday_archive_ready()&&!self::ensure_birthday_archive_schema())return 0;
      $now=self::birthday_iran_now();$stamp=$now->format('Y-m-d H:i:s');
      $data=[
       'member_id'=>(int)$member->id,'birthday_log_id'=>$log_id===null?null:(int)$log_id,'kind'=>$kind,
       'first_name'=>(string)$member->first_name,'last_name'=>(string)$member->last_name,
       'university'=>(string)$member->university,'member_role'=>(string)$member->member_role,
       'eitaa_id'=>(string)$member->eitaa_id,'national_id'=>(string)$member->national_id,
       'mobile'=>(string)$member->mobile,'birth_jalali'=>(string)$member->birth_jalali,
       'consent_at_send'=>(int)$member->consent,'verified_at_send'=>(int)$member->verified,
       'birthday_opt_in_at_send'=>(int)$member->birthday_opt_in,
       'message_text'=>(string)$message,'iran_day'=>self::jalali_from_iran_date($now),
       'attempted_at'=>$stamp,'gateway_status'=>'sending','reason'=>'','updated_at'=>$stamp];
      $ok=$wpdb->insert(self::table('birthday_archive'),$data,
       ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s','%s','%s','%s','%s']);
      return $ok===1?(int)$wpdb->insert_id:0;
     }

    static function birthday_archive_finish($archive_id,$outcome){global $wpdb;
      if((int)$archive_id<1)return false;
      $ok=!empty($outcome['ok']);$reason=$ok?'':self::archive_safe_reason((string)($outcome['reason']??'uncertain'));
      $status=$ok?'accepted':($reason==='uncertain'||$reason==='network_failure'?'uncertain':'failed');
      return $wpdb->update(self::table('birthday_archive'),['gateway_status'=>$status,'reason'=>$reason,'updated_at'=>self::birthday_iran_now()->format('Y-m-d H:i:s')],
       ['id'=>(int)$archive_id],['%s','%s','%s'],['%d'])===1;
     }

    static function archive_safe_reason($reason){
      $allow=['network_failure','uncertain','app_token_not_configured','invalid_private_message','archive_write_failed'];
      if(in_array($reason,$allow,true))return $reason;
      if(preg_match('/\AHTTP [1-5][0-9]{2}; reason=[a-z_]{1,30}(?:; code=[0-9]{1,6})?\z/D',$reason))return $reason;
      return 'unknown_failure';
     }

    static function birthday_format($raw){
      $s=trim(wp_strip_all_tags((string)$raw));
      if(self::chars($s)<1||self::chars($s)>1200)return '';
      preg_match_all('/\{([^{}]+)\}/u',$s,$m);
      foreach($m[1] as $token)if(!in_array($token,['نام','نام_خانوادگی'],true))return '';
      if(strpbrk(preg_replace('/\{(?:نام|نام_خانوادگی)\}/u','',$s),'{}')!==false)return '';
      return $s;
     }

    static function birthday_text($template,$member){
      $text=strtr($template,['{نام}'=>(string)$member->first_name,'{نام_خانوادگی}'=>(string)$member->last_name]);
      return trim($text);
     }
    static function birthday_readiness(){global $wpdb;
     $today=self::jalali_from_iran_date(self::birthday_iran_now());$eligible=0;
     if(self::profile_schema_ready()&&class_exists('QYS_Campus_Birthday')){
      $suffix=substr($today,4);
      $eligible=QYS_Member_Repository::birthday_count_enabled_campuses(QYS_Campus_Birthday::table(),$suffix);
     }
     $out=['enabled'=>self::birthday_enabled(),'profile_schema_ready'=>self::profile_schema_ready(),'birthday_log_ready'=>self::birthday_log_ready(),'archive_ready'=>self::birthday_archive_ready(),'cron_scheduled'=>(bool)wp_next_scheduled('qys_send_batch'),'archive_total'=>0,'eligible_today'=>$eligible,'pending'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0,'uncertain'=>0];
     if(self::birthday_archive_ready())$out['archive_total']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table('birthday_archive'));
     if(self::birthday_log_ready()){$r=$wpdb->get_row("SELECT COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='sent'),0) sent,COALESCE(SUM(status='failed'),0) failed,COALESCE(SUM(status='cancelled'),0) cancelled,COALESCE(SUM(status='sending'),0) uncertain FROM ".self::table('birthday_log'),ARRAY_A);if(is_array($r))foreach(['pending','sent','failed','cancelled','uncertain'] as $k)$out[$k]=(int)$r[$k];}
     return $out;
    }
    static function birthday_admin_panel(){
     echo '<section class="qys-admin-panel"><h2>کلید سراسری تبریک تولد</h2><p>متن و ساعت هر دانشگاه فقط در پنل همان دانشگاه تنظیم می‌شود. این کلید صرفاً توقف اضطراری کل سرویس است.</p>';
     echo '<form method="post">';wp_nonce_field('qys_admin');
     echo '<label><input type="checkbox" name="birthday_enabled" value="1"'.checked(self::birthday_enabled(),true,false).'> اجازه اجرای سرویس تبریک تولد دانشگاه‌ها</label> ';
     echo '<button class="button button-primary" name="qys_action" value="save_birthday_settings">ذخیره</button></form></section>';
    }
    static function save_birthday_settings(){
     if(!QYS_Security_Service::is_global_admin())return 'دسترسی کافی ندارید.';
     update_option('qys_birthday_enabled',isset($_POST['birthday_enabled'])?'yes':'no',false);
     return 'وضعیت سراسری سرویس تبریک تولد ذخیره شد.';
    }

    static function birthday_schedule_today(){return class_exists('QYS_Campus_Birthday')?QYS_Campus_Birthday::schedule_today():0;}

    static function birthday_worker(){global $wpdb;
      if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::worker_allowed())return;
      if(!self::birthday_enabled()||!self::birthday_log_ready()||!self::profile_schema_ready()||!class_exists('QYS_Campus_Birthday'))return;
      $now=self::birthday_iran_now();$today=self::jalali_from_iran_date($now);
      $log=self::table('birthday_log');
      // Expired wishes cannot spill into the next local calendar day.
      $wpdb->query($wpdb->prepare("UPDATE $log SET status='cancelled',reason='expired',updated_at=%s WHERE status='pending' AND scheduled_on<>%s",current_time('mysql'),$today));
      // Scan across campuses; a future-time campus at a low id cannot block later ids.
      $last=0;$sent_this_batch=0;
      for($page=0;$page<100 && $sent_this_batch<5;$page++){
       $rows=QYS_Member_Repository::birthday_pending_batch($log,$today,$last,100);
       if(!$rows)break;
       foreach($rows as $item){$last=max($last,(int)$item->id);
        if(!QYS_Campus_Birthday::due((string)$item->university,$now))continue;
        $template=QYS_Campus_Birthday::send_template((string)$item->university);
        if($template==='')continue;
        $taken=$wpdb->update($log,['status'=>'sending','attempts'=>1,'updated_at'=>current_time('mysql')],['id'=>$item->id,'status'=>'pending'],['%s','%d','%s'],['%d','%s']);
        if($taken!==1)continue;
        $sent_this_batch++;
        // Re-read current campus and consent AFTER claim, before archiving or network.
        $m=QYS_Member_Repository::find_by_id((int)$item->member_id);
        if(!$m||!$m->consent||!$m->verified||!$m->birthday_opt_in||!self::profile_complete($m)
          ||$item->scheduled_on!==$today||substr((string)$m->birth_jalali,4)!==substr($today,4)
          ||!QYS_Campus_Birthday::due((string)$m->university,$now)){
         $wpdb->update($log,['status'=>'cancelled','reason'=>'not_eligible','updated_at'=>current_time('mysql')],['id'=>$item->id],['%s','%s','%s'],['%d']);continue;
        }
        $template=QYS_Campus_Birthday::send_template((string)$m->university);
        if($template===''){
         $wpdb->update($log,['status'=>'cancelled','reason'=>'invalid_template','updated_at'=>current_time('mysql')],['id'=>$item->id],['%s','%s','%s'],['%d']);continue;
        }
        $text=self::birthday_text($template,$m);
        $archive_id=self::birthday_archive_start($m,'automatic',$text,(int)$item->id);
        if(!$archive_id){
         $wpdb->update($log,['status'=>'failed','reason'=>'archive_write_failed','updated_at'=>current_time('mysql')],['id'=>$item->id],['%s','%s','%s'],['%d']);continue;
        }
        try{$result=QYS_Queue_Service::deliver_claimed($m->eitaa_id,$text);}
        catch(Throwable $e){$result=['ok'=>false,'reason'=>'uncertain'];}
        self::birthday_archive_finish($archive_id,$result);
        $wpdb->update($log,['status'=>$result['ok']?'sent':'failed','reason'=>$result['ok']?'':self::clip(self::archive_safe_reason((string)($result['reason']??'uncertain')),64),'updated_at'=>current_time('mysql')],['id'=>$item->id],['%s','%s','%s'],['%d']);
        if($sent_this_batch>=5)break;
       }
       if(count($rows)<100)break;
      }
     }

    static function birthday_archive_filters($raw){
      $type=(string)($raw['archive_kind']??'');$status=(string)($raw['archive_status']??'');
      $day=self::normalize_digits((string)($raw['archive_day']??''));
      $search=trim((string)($raw['archive_search']??''));
      return [
       'kind'=>in_array($type,['automatic','test'],true)?$type:'',
       'status'=>in_array($status,['accepted','failed','uncertain','sending'],true)?$status:'',
       'day'=>preg_match('~\A[0-9]{4}/(?:0[1-9]|1[0-2])/(?:0[1-9]|[12][0-9]|3[01])\z~D',$day)?$day:'',
       'search'=>self::clip($search,80)];
     }

    static function birthday_archive_where($filters){global $wpdb;
      $sql=' WHERE 1=1';$args=[];
      if($filters['kind']!==''){$sql.=' AND kind=%s';$args[]=$filters['kind'];}
      if($filters['status']!==''){$sql.=' AND gateway_status=%s';$args[]=$filters['status'];}
      if($filters['day']!==''){$sql.=' AND iran_day=%s';$args[]=$filters['day'];}
      if($filters['search']!==''){
       $sql.=" AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT_WS(' ',first_name,last_name) LIKE %s OR national_id=%s OR mobile=%s OR eitaa_id=%s)";
       $like='%'.$wpdb->esc_like($filters['search']).'%';
       array_push($args,$like,$like,$like,$filters['search'],$filters['search'],$filters['search']);
      }
      return [$sql,$args];
     }

    static function birthday_archive_rows($filters,$limit,$offset=0){global $wpdb;
      if(!QYS_Security_Service::is_global_admin()||!self::birthday_archive_ready())return [];
      [$where,$args]=self::birthday_archive_where($filters);
      $limit=max(1,min(1000,(int)$limit));$offset=max(0,(int)$offset);
      $sql='SELECT * FROM '.self::table('birthday_archive').$where.' ORDER BY id DESC LIMIT %d OFFSET %d';
      $args[]=$limit;$args[]=$offset;
      return $wpdb->get_results($wpdb->prepare($sql,...$args));
     }

    /** Phase D / D8: descending keyset chunk for bounded archive export. */
    static function birthday_archive_export_chunk($filters,$before_id,$limit){global $wpdb;
      if(!QYS_Security_Service::is_global_admin()||!self::birthday_archive_ready())return [];
      [$where,$args]=self::birthday_archive_where($filters);
      $before_id=max(0,(int)$before_id);$limit=max(1,min(500,(int)$limit));
      if($before_id>0){$where.=' AND id<%d';$args[]=$before_id;}
      $sql='SELECT * FROM '.self::table('birthday_archive').$where.' ORDER BY id DESC LIMIT %d';
      $args[]=$limit;
      return $wpdb->get_results($wpdb->prepare($sql,...$args));
     }

    static function birthday_archive_count($filters){global $wpdb;
      if(!QYS_Security_Service::is_global_admin()||!self::birthday_archive_ready())return 0;
      [$where,$args]=self::birthday_archive_where($filters);
      $sql='SELECT COUNT(*) FROM '.self::table('birthday_archive').$where;
      return (int)$wpdb->get_var($args?$wpdb->prepare($sql,...$args):$sql);
     }

    static function birthday_archive_labels(){return [
      'automatic'=>'خودکار','test'=>'آزمایشی','accepted'=>'پذیرفته‌شده توسط API (دریافت قطعی تأیید نشده)',
      'failed'=>'ناموفق','uncertain'=>'نامشخص؛ تکرار خودکار ممنوع','sending'=>'در حال ارسال / نتیجه هنوز ثبت نشده'];}

    static function birthday_archive_admin(){
      if(!class_exists('QYS_Multitenant')||QYS_Permission_Service::scope('view_members')!=='*')wp_die('این آرشیو در مرحله فعلی فقط در محیط مدیریت کل در دسترس است.');
      if(!QYS_Security_Service::is_global_admin())wp_die('Forbidden');
      if(function_exists('nocache_headers'))nocache_headers();
      $verified=isset($_POST['qys_archive_nonce'])&&wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['qys_archive_nonce'])),'qys_birthday_archive');
      $raw=$verified?wp_unslash($_POST):[];
      $filters=self::birthday_archive_filters($raw);
      $page=max(1,min(100000,absint($raw['archive_page']??1)));$limit=25;
      $ready=self::birthday_archive_ready();$total=$ready?self::birthday_archive_count($filters):0;
      // Fail closed: an unaudited screen must never render full identifiers or stored messages.
      if($ready&&!self::archive_access_record('view',$filters,$total)){
       echo '<div class="wrap"><h1>آرشیو تبریک تولد</h1><div class="notice notice-error"><p>ثبت دسترسی مدیریتی امکان‌پذیر نیست؛ برای حفاظت از اطلاعات، مشاهده آرشیو تا رفع مشکل متوقف شده است. جدول ممیزی یا مجوز پایگاه داده را بررسی کنید.</p></div></div>';
       return;
      }
      $rows=$ready?self::birthday_archive_rows($filters,$limit,($page-1)*$limit):[];
      $labels=self::birthday_archive_labels();
      echo '<div class="wrap qys-admin qys-admin--archive" dir="rtl"><h1>آرشیو تبریک تولد قرآن‌یار</h1>';
      echo '<p>فقط مدیر مجاز به سوابق دسترسی دارد. اطلاعات این جدول، تصویر ثبت‌شده در زمان تلاش ارسال است؛ پس از ویرایش پرونده عضو تغییر نمی‌کند. پذیرش پیام توسط API لزوماً به معنای خوانده‌شدن آن در ایتا نیست.</p>';
      $coverage=self::archive_readiness();
      echo '<p><strong>پوشش آرشیو:</strong> '.(int)$coverage['archive_total'].' رکورد، '.(int)$coverage['automatic_total'].' خودکار؛ <strong>ممیزی دسترسی:</strong> '.($coverage['access_audit_ready']?'فعال':'آماده نیست').'.</p>';
      if(!$ready){echo '<div class="notice notice-error"><p>جدول آرشیو آماده نیست. از پایگاه داده پشتیبان بگیرید و مجوز ایجاد جدول را بررسی کنید.</p></div></div>';return;}
      echo '<form method="post" action="">';wp_nonce_field('qys_birthday_archive','qys_archive_nonce');
      echo '<label>نوع <select name="archive_kind"><option value="">همه</option>';
      foreach(['automatic','test'] as $v)echo '<option value="'.esc_attr($v).'"'.selected($filters['kind'],$v,false).'>'.esc_html($labels[$v]).'</option>';
      echo '</select></label> <label>وضعیت <select name="archive_status"><option value="">همه</option>';
      foreach(['accepted','failed','uncertain','sending'] as $v)echo '<option value="'.esc_attr($v).'"'.selected($filters['status'],$v,false).'>'.esc_html($labels[$v]).'</option>';
      echo '</select></label> <label>تاریخ شمسی <input name="archive_day" dir="ltr" placeholder="۱۴۰۵/۰۷/۰۶" value="'.esc_attr($filters['day']).'" maxlength="10"></label>';
      echo '<p><label>جست‌وجوی نام، نام خانوادگی، کد ملی کامل، موبایل کامل یا شناسه ایتا: <input type="search" name="archive_search" class="regular-text" value="'.esc_attr($filters['search']).'" maxlength="80" autocomplete="off"></label></p>';
      echo '<button type="submit" class="button button-primary">جست‌وجو</button>';
      echo '<p>تعداد مطابق: '.(int)$total.' | صفحه '.(int)$page.'</p>';
      if($page>1)echo '<button class="button" type="submit" name="archive_page" value="'.($page-1).'">صفحه قبل</button> ';
      if($page*$limit<$total)echo '<button class="button" type="submit" name="archive_page" value="'.($page+1).'">صفحه بعد</button>';
      echo '</form>';
      // CSV export is a separate nonce-protected POST. No identifier is placed in a URL.
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
      wp_nonce_field('qys_birthday_archive_export','qys_export_nonce');
      echo '<input type="hidden" name="action" value="qys_birthday_archive_export">';
      foreach(['kind'=>'archive_kind','status'=>'archive_status','day'=>'archive_day','search'=>'archive_search'] as $key=>$post)
       echo '<input type="hidden" name="'.esc_attr($post).'" value="'.esc_attr($filters[$key]).'">';
      echo '<button type="submit" class="button">خروجی CSV از نتایج فیلترشده (حداکثر ۵۰۰۰ رکورد)</button></form>';
      echo '<div style="overflow-x:auto;max-width:100%"><table class="widefat striped" style="min-width:1900px"><thead><tr>';
      foreach(['شناسه آرشیو','نوع','تاریخ و ساعت ایران','تاریخ شمسی','وضعیت','نام','نام خانوادگی','دانشگاه','عنوان عضویت','کد ملی کامل','شماره موبایل کامل','شناسه عددی ایتا','تاریخ تولد شمسی','مجوز پیام هنگام ارسال','تأیید هویت هنگام ارسال','رضایت تبریک هنگام ارسال','متن کامل ارسالی','دلیل خطا','شناسه داخلی عضو','شناسه گزارش خودکار'] as $head)echo '<th>'.esc_html($head).'</th>';
      echo '</tr></thead><tbody>';
      foreach($rows as $r){
       $role=self::member_roles()[$r->member_role]??'—';
       $values=[(int)$r->id,$labels[$r->kind]??$r->kind,$r->attempted_at,$r->iran_day,$labels[$r->gateway_status]??$r->gateway_status,$r->first_name,$r->last_name,self::university_name((string)$r->university),$role];
       echo '<tr>';foreach($values as $v)echo '<td>'.esc_html((string)$v).'</td>';
       foreach(['national_id','mobile','eitaa_id'] as $key)echo '<td>'.self::admin_full_identifier((string)$r->$key).'</td>';
       echo '<td>'.esc_html($r->birth_jalali).'</td>';
       foreach(['consent_at_send','verified_at_send','birthday_opt_in_at_send'] as $key)echo '<td>'.((int)$r->$key===1?'بله':'خیر').'</td>';
       echo '<td><details><summary>نمایش متن پیام</summary><pre style="white-space:pre-wrap;max-width:500px">'.esc_html($r->message_text).'</pre></details></td>';
       echo '<td>'.esc_html($r->reason?self::safe_failure_label($r->reason):'—').'</td><td>'.(int)$r->member_id.'</td><td>'.($r->birthday_log_id===null?'—':(int)$r->birthday_log_id).'</td></tr>';
      }
      if(!$rows)echo '<tr><td colspan="20">رکوردی مطابق جست‌وجو یافت نشد.</td></tr>';
      echo '</tbody></table></div>';
      self::archive_access_panel();
      echo '</div>';
     }

    static function birthday_archive_csv_safe($value,$identifier=false){
      $value=(string)$value;
      $value=str_replace(["\x00"],[''],$value);
      if($identifier&&preg_match('/\A[0-9]+\z/D',$value))return "\t".$value;
      if(preg_match('/\A[\s\p{Z}]*[=+\-@]/u',$value))return "'".$value;
      return $value;
     }

    static function birthday_archive_export(){
      if(!class_exists('QYS_Multitenant')||QYS_Permission_Service::scope('view_members')!=='*')wp_die('دسترسی خروجی آرشیو مجاز نیست.');
      if(!QYS_Security_Service::is_global_admin())wp_die('Forbidden');
      check_admin_referer('qys_birthday_archive_export','qys_export_nonce');
      if(!self::birthday_archive_ready())wp_die('آرشیو آماده نیست.');
      $filters=self::birthday_archive_filters(wp_unslash($_POST));
      $requested=min(5000,self::birthday_archive_count($filters));
      if(!self::archive_access_record('export_request',$filters,$requested))wp_die('ثبت ممیزی خروجی امکان‌پذیر نیست؛ برای حفاظت از اطلاعات، خروجی صادر نشد.');
      try{$h=QYS_Batch_Export::open_csv('quranyar-birthday-archive-'.gmdate('Ymd-His').'.csv',['شناسه','نوع','زمان ایران','تاریخ شمسی','وضعیت API','نام','نام خانوادگی','دانشگاه','عنوان','کد ملی','موبایل','شناسه ایتا','تولد شمسی','مجوز پیام هنگام ارسال','تأیید هویت هنگام ارسال','رضایت تبریک هنگام ارسال','متن دقیق پیام','دلیل خطا','شناسه عضو','شناسه گزارش خودکار']);}
      catch(Throwable $e){wp_die('ساخت خروجی ممکن نیست.',500);}
      $before_id=0;$written=0;$size=QYS_Batch_Export::chunk_size();
      do{
       $remaining=max(0,5000-$written);if($remaining===0)break;
       $rows=self::birthday_archive_export_chunk($filters,$before_id,min($size,$remaining));
       if(!$rows)break;
       foreach($rows as $r){
        $before_id=(int)$r->id;$written++;
        $role=self::member_roles()[$r->member_role]??'—';$labels=self::birthday_archive_labels();
        $fields=[$r->id,$labels[$r->kind]??$r->kind,$r->attempted_at,$r->iran_day,$labels[$r->gateway_status]??$r->gateway_status,
         $r->first_name,$r->last_name,self::university_name($r->university),$role,$r->national_id,$r->mobile,$r->eitaa_id,$r->birth_jalali,
         ((int)$r->consent_at_send===1?'بله':'خیر'),((int)$r->verified_at_send===1?'بله':'خیر'),((int)$r->birthday_opt_in_at_send===1?'بله':'خیر'),
         $r->message_text,$r->reason?self::safe_failure_label($r->reason):'',$r->member_id,$r->birthday_log_id??''];
        foreach($fields as $i=>$v)$fields[$i]=self::birthday_archive_csv_safe($v,in_array($i,[9,10,11],true));
        QYS_Batch_Export::write_csv_row($h,$fields);
       }
       QYS_Batch_Export::flush_chunk($h);
      }while(count($rows)===$size && $written<5000);
      QYS_Batch_Export::close($h);exit;
     }

}
