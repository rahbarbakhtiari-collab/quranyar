<?php
/** Independent Eitaa birthday settings for approved campus representatives. */
if (!defined('ABSPATH')) exit;

final class QYS_Campus_Birthday {
    private static $ready = null;
    public static function table(){return QYS_Table_Names::name('campus_birthday');}

    public static function init(){
        // The existing QYS minute worker executes at priority 20. Reserve only
        // birthdays due for a specific enabled campus before that worker runs.
        add_action('qys_send_batch',[__CLASS__,'schedule_today'],15);
        add_action('admin_post_qys_campus_birthday_save',[__CLASS__,'save']);
    }
    public static function schema_ready(){ return QYS_Schema_Runtime::ready(); }
    public static function diagnostic_schema_ready(){
        return QYS_Table_Names::diagnostic_has_columns(self::table(),['university','enabled','send_hour','send_minute','template','updated_by','updated_at_utc']);
    }

    public static function ensure_schema(){global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
          university varchar(40) NOT NULL,
          enabled tinyint unsigned NOT NULL DEFAULT 0,
          send_hour tinyint unsigned NOT NULL DEFAULT 9,
          send_minute tinyint unsigned NOT NULL DEFAULT 0,
          template text NOT NULL,
          updated_by bigint unsigned NOT NULL DEFAULT 0,
          updated_at_utc datetime NOT NULL,
          PRIMARY KEY  (university)
        ) $charset;");
        self::$ready=null;
        if(!self::diagnostic_schema_ready())return false;
        return true;
    }
    /** Every campus has explicit independent settings; missing rows are disabled. */
    public static function settings($uni){global $wpdb;
     $defaults=['enabled'=>false,'send_hour'=>9,'send_minute'=>0,'template'=>QYS_Messenger::birthday_default_template()];
     if(!QYS_Multitenant::university_active($uni)||!self::schema_ready())return $defaults;
     $row=$wpdb->get_row($wpdb->prepare('SELECT enabled,send_hour,send_minute,template FROM '.self::table().' WHERE university=%s',$uni),ARRAY_A);
     if(!is_array($row))return $defaults;
     return ['enabled'=>(bool)$row['enabled'],'send_hour'=>min(23,max(0,(int)$row['send_hour'])),'send_minute'=>min(59,max(0,(int)$row['send_minute'])),'template'=>(string)$row['template']];
    }
    public static function due($uni,DateTimeInterface $now){
        if(!QYS_Messenger::birthday_enabled() || !QYS_Multitenant::university_active($uni))return false;
        $s=self::settings($uni);
        return $s['enabled'] && $now>=DateTimeImmutable::createFromInterface($now)->setTime($s['send_hour'],$s['send_minute'],0);
    }
    public static function send_template($uni){
        $s=self::settings($uni);
        return QYS_Messenger::birthday_format($s['template']);
    }
    /** Deduplicated by existing birthday_log(member_id,jalali_year) index. Do
     * not create any new rows or alter old attempts when a campus is disabled. */
    public static function schedule_today(){global $wpdb;
        if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::worker_allowed())return 0;
        if(!QYS_Messenger::birthday_enabled()||!self::schema_ready()||!QYS_Messenger::birthday_log_ready()
            ||!QYS_Messenger::profile_schema_ready()||!QYS_Token_Store::secret('webapp'))return 0;
        $now=QYS_Calendar_Service::iran_now();$day=QYS_Calendar_Service::jalali_from_date($now);
        $year=(int)substr($day,0,4);$suffix=substr($day,4);
        $log=QYS_Table_Names::name('birthday_log');
        $last=0;$reserved=0;
        for($page=0;$page<100;$page++){
            $rows=QYS_Member_Repository::birthday_members_after($suffix,$last,100);
            if(!$rows)break;
            foreach($rows as $m){$last=max($last,(int)$m->id);
                if(substr((string)$m->birth_jalali,4)!==$suffix||!QYS_Messenger::profile_complete($m))continue;
                if(!self::due((string)$m->university,$now)||self::send_template((string)$m->university)==='')continue;
                $stamp=current_time('mysql');
                $ok=$wpdb->insert($log,['member_id'=>(int)$m->id,'jalali_year'=>$year,'scheduled_on'=>$day,
                    'status'=>'pending','attempts'=>0,'reason'=>'','created_at'=>$stamp,'updated_at'=>$stamp],
                    ['%d','%d','%s','%s','%d','%s','%s','%s']);
                if($ok===1)$reserved++;
            }
            if(count($rows)<100)break;
        }
        return $reserved;
    }
    public static function page(){global $wpdb;
        $uni=QYS_Tenant_Context::campus();
        if($uni===''||QYS_Permission_Service::scope('birthday')!==$uni){echo '<section class="qys-tenant-state"><h2>مجوز مدیریت تبریک تولد ندارید.</h2></section>';return;}
        $s=self::settings($uni);$today=QYS_Calendar_Service::jalali_from_date(QYS_Calendar_Service::iran_now());
        $eligible=0;$pending=0;$results=['accepted'=>0,'failed'=>0,'uncertain'=>0];
        if(QYS_Messenger::profile_schema_ready()){
            $suffix=substr($today,4);
            $eligible=QYS_Member_Repository::birthday_complete_count_for_university($uni,$suffix);
            if(QYS_Messenger::birthday_log_ready()){
                $log=QYS_Table_Names::name('birthday_log');
                $pending=QYS_Member_Repository::birthday_pending_count_for_university($log,$uni,$today);
            }
        }
        if(QYS_Messenger::birthday_archive_ready()){
            $archive=QYS_Table_Names::name('birthday_archive');
            $row=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(gateway_status='accepted'),0) AS accepted,COALESCE(SUM(gateway_status='failed'),0) AS failed,COALESCE(SUM(gateway_status='uncertain'),0) AS uncertain FROM $archive WHERE kind='automatic' AND university=%s",$uni),ARRAY_A);
            if(is_array($row))foreach($results as $k=>$_)$results[$k]=(int)($row[$k]??0);
        }
        echo '<div class="qys-admin qys-tenant-campus" dir="rtl"><section class="qys-tenant-panel"><h2>تبریک تولد خودکار دانشگاه</h2>';
        if(isset($_GET['qys_bday'])&&$_GET['qys_bday']==='saved')echo '<p class="notice notice-success" role="status">تنظیمات تبریک دانشگاه ذخیره شد.</p>';
        if(isset($_GET['qys_bday'])&&$_GET['qys_bday']==='error')echo '<p class="notice notice-error" role="alert">تنظیمات ذخیره نشد؛ متن و ساعت را بررسی کنید.</p>';
        if(!QYS_Messenger::birthday_enabled())echo '<p class="notice notice-warning">کلید سراسری تبریک تولد در مدیریت کل خاموش است؛ تنظیمات این دانشگاه ذخیره می‌شوند، اما تا فعال‌سازی مدیر کل پیامی ارسال نمی‌شود.</p>';
        echo '<p>فقط اعضای تأییدشده دانشگاه شما با رضایت تبریک تولد در روز تولد شمسی پیام ایتا می‌گیرند. زمان بر اساس ساعت ایران است.</p>';
        echo '<div class="qys-tenant-kpis"><article><span>واجد شرایط امروز</span><strong>'.(int)$eligible.'</strong></article>';
        echo '<article><span>در صف امروز</span><strong>'.(int)$pending.'</strong></article>';
        echo '<article><span>پذیرفته‌شده توسط ایتا</span><strong>'.(int)$results['accepted'].'</strong></article>';
        echo '<article><span>خطا / نامشخص</span><strong>'.(int)($results['failed']+$results['uncertain']).'</strong></article></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('qys_campus_birthday_save');
        echo '<input type="hidden" name="action" value="qys_campus_birthday_save">'.QYS_Multitenant::portal_scope_fields();
        echo '<p><label for="qys-campus-bday-time">ساعت ارسال (ایران)</label> <input id="qys-campus-bday-time" type="time" name="birthday_time" step="60" dir="ltr" required value="'.esc_attr(sprintf('%02d:%02d',$s['send_hour'],$s['send_minute'])).'"></p>';
        echo '<p><label for="qys-campus-bday-message">متن پیام؛ متغیرها: {نام} و {نام_خانوادگی}</label><br><textarea id="qys-campus-bday-message" name="birthday_template" maxlength="1200" rows="5" cols="72" required>'.esc_textarea($s['template']).'</textarea></p>';
        echo '<p><label><input type="checkbox" name="birthday_enabled" value="1" '.checked($s['enabled'],true,false).'> فعال‌سازی تبریک خودکار اعضای این دانشگاه</label></p>';
        echo '<p><button type="submit" class="button button-primary">ذخیره تنظیمات دانشگاه</button></p></form>';
        echo '<p>گزارش، تعداد پاسخ‌های پذیرفته‌شده از درگاه ایتاست؛ دریافت و خواندن پیام در ایتا باید جداگانه کنترل شود. متن و مشخصات کامل اعضا در این صفحه نمایش داده نمی‌شود.</p>';
        echo '</section></div>';
    }
    public static function save(){global $wpdb;
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_user_logged_in())wp_die('درخواست معتبر نیست.',403);
        check_admin_referer('qys_campus_birthday_save');
        $uni=QYS_Tenant_Context::campus();
        $submitted=isset($_POST['qys_portal_university'])?sanitize_key(wp_unslash($_POST['qys_portal_university'])):'';
        if($uni===''||$submitted!==$uni||QYS_Permission_Service::scope('birthday')!==$uni||!QYS_Multitenant::university_active($uni))wp_die('مجوز مدیریت تبریک دانشگاه ندارید.',403);
        if(!self::schema_ready()&&!self::ensure_schema())wp_die('جدول تنظیمات تبریک آماده نیست.',500);
        $raw=trim(QYS_Text_Normalizer::digits((string)wp_unslash($_POST['birthday_time']??'')));
        $template=QYS_Messenger::birthday_format(wp_unslash($_POST['birthday_template']??''));
        if(!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$raw)||$template===''){
            wp_safe_redirect(QYS_Multitenant::campus_url('birthday',['qys_bday'=>'error']));exit;
        }
        [$hour,$minute]=array_map('intval',explode(':',$raw));
        $enabled=isset($_POST['birthday_enabled'])&&$_POST['birthday_enabled']==='1'?1:0;
        $t=self::table();$stamp=gmdate('Y-m-d H:i:s');
        $ok=$wpdb->query($wpdb->prepare("INSERT INTO $t (university,enabled,send_hour,send_minute,template,updated_by,updated_at_utc) VALUES (%s,%d,%d,%d,%s,%d,%s) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),send_hour=VALUES(send_hour),send_minute=VALUES(send_minute),template=VALUES(template),updated_by=VALUES(updated_by),updated_at_utc=VALUES(updated_at_utc)",
            $uni,$enabled,$hour,$minute,$template,get_current_user_id(),$stamp));
        if($ok===false)wp_die('ذخیره تنظیمات تبریک ناموفق بود.',500);
        // Disabling only cancels this campus's still-pending birthday wishes.
        if(!$enabled && QYS_Messenger::birthday_log_ready()){
            $log=QYS_Table_Names::name('birthday_log');
            QYS_Member_Repository::cancel_pending_birthday_for_university($log,$uni);
        }
        wp_safe_redirect(QYS_Multitenant::campus_url('birthday',['qys_bday'=>'saved']));exit;
    }
}
