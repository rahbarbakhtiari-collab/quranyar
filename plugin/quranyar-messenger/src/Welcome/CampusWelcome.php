<?php
/** Per-university customizable automatic membership messages. */
if (!defined('ABSPATH')) exit;

final class QYS_Campus_Welcome {
    private static $ready=null;

    public static function table(){return QYS_Table_Names::name('campus_welcome');}

    public static function init(){
        add_action('admin_post_qys_campus_welcome_save',[__CLASS__,'save']);
    }

    public static function schema_ready(){ return QYS_Schema_Runtime::ready(); }
    public static function diagnostic_schema_ready(){
        return QYS_Table_Names::diagnostic_has_columns(self::table(),['university','template','activation_template','updated_by','updated_at_utc']);
    }

    public static function ensure_schema(){global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
          university varchar(40) NOT NULL,
          template text NOT NULL,
          activation_template text NULL,
          updated_by bigint unsigned NOT NULL DEFAULT 0,
          updated_at_utc datetime NOT NULL,
          PRIMARY KEY  (university)
        ) $charset;");
        self::$ready=null;
        return self::diagnostic_schema_ready();
    }

    /** Return campus-specific initial welcome template or the safe built-in default. */
    public static function template($uni){global $wpdb;
        $uni=sanitize_key((string)$uni);
        if($uni===''||!self::schema_ready())return QYS_Messenger::welcome_default_template();
        $raw=$wpdb->get_var($wpdb->prepare('SELECT template FROM '.self::table().' WHERE university=%s',$uni));
        $text=QYS_Messenger::welcome_format((string)$raw);
        return $text!==''?$text:QYS_Messenger::welcome_default_template();
    }

    /** Return campus-specific account-activation template or the safe built-in default. */
    public static function activation_template($uni){global $wpdb;
        $uni=sanitize_key((string)$uni);
        if($uni===''||!self::schema_ready())return QYS_Messenger::activation_default_template();
        $raw=$wpdb->get_var($wpdb->prepare('SELECT activation_template FROM '.self::table().' WHERE university=%s',$uni));
        $text=QYS_Messenger::welcome_format((string)$raw);
        return $text!==''?$text:QYS_Messenger::activation_default_template();
    }

    public static function customized($uni){global $wpdb;
        if(!self::schema_ready())return false;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE university=%s',sanitize_key((string)$uni)));
    }

    public static function page(){
        $uni=QYS_Tenant_Context::campus();
        if($uni===''||QYS_Permission_Service::scope('welcome')!==$uni){
            echo '<section class="qys-tenant-state"><h2>مجوز تنظیم پیام‌های عضویت را ندارید.</h2></section>';return;
        }
        if(!self::schema_ready()&&!self::ensure_schema()){
            echo '<section class="qys-tenant-state"><h2>تنظیمات پیام‌های عضویت آماده نیست.</h2></section>';return;
        }
        $welcome=self::template($uni);$activation=self::activation_template($uni);$custom=self::customized($uni);
        echo '<div class="qys-admin qys-tenant-campus" dir="rtl"><section class="qys-tenant-panel"><h2>پیام‌های خودکار عضویت</h2>';
        if(isset($_GET['qys_welcome'])&&$_GET['qys_welcome']==='saved')echo '<p class="notice notice-success" role="status">متن پیام‌های عضویت دانشگاه ذخیره شد.</p>';
        if(isset($_GET['qys_welcome'])&&$_GET['qys_welcome']==='error')echo '<p class="notice notice-error" role="alert">متن ذخیره نشد؛ طول متن یا متغیرها را بررسی کنید.</p>';
        echo '<p>دو پیام مستقل قابل تنظیم است: پیام نخست پس از عضویت اولیه و پیام دوم بلافاصله پس از تأیید و فعال‌شدن حساب توسط مدیر مجاز ارسال می‌شود.</p>';
        if(!$custom)echo '<p class="notice notice-info">این دانشگاه هنوز متن اختصاصی ذخیره نکرده است؛ متن‌های پیش‌فرض قرآن‌یار استفاده می‌شوند. با ذخیره فرم، متن مستقل این دانشگاه فعال می‌شود.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('qys_campus_welcome_save');
        echo '<input type="hidden" name="action" value="qys_campus_welcome_save">'.QYS_Multitenant::portal_scope_fields();
        echo '<p><label for="qys-campus-welcome-message"><strong>۱. پیام پس از عضویت اولیه</strong></label><br>';
        echo '<textarea id="qys-campus-welcome-message" name="welcome_template" maxlength="1200" rows="7" cols="72" required>'.esc_textarea($welcome).'</textarea></p>';
        echo '<p><label for="qys-campus-activation-message"><strong>۲. پیام پس از تأیید و فعال‌شدن حساب</strong></label><br>';
        echo '<textarea id="qys-campus-activation-message" name="activation_template" maxlength="1200" rows="7" cols="72" required>'.esc_textarea($activation).'</textarea></p>';
        echo '<p>متغیرهای مجاز در هر دو پیام: <code>{نام}</code>، <code>{نام_خانوادگی}</code> و <code>{دانشگاه}</code>. حداکثر ۱۲۰۰ نویسه برای هر پیام.</p>';
        echo '<p><button type="submit" class="button button-primary">ذخیره پیام‌های عضویت</button></p></form>';
        echo '<p>پیام فعال‌سازی فقط هنگام تغییر وضعیت عضو از «در انتظار» به «تأییدشده» ارسال می‌شود و برای هر عضو تکرار نمی‌شود. اگر دریافت پیام عضو غیرفعال باشد، ارسال انجام نخواهد شد.</p>';
        echo '</section></div>';
    }

    public static function save(){global $wpdb;
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_user_logged_in())wp_die('درخواست معتبر نیست.',403);
        check_admin_referer('qys_campus_welcome_save');
        $uni=QYS_Tenant_Context::campus();
        $submitted=isset($_POST['qys_portal_university'])?sanitize_key(wp_unslash($_POST['qys_portal_university'])):'';
        if($uni===''||$submitted!==$uni||QYS_Permission_Service::scope('welcome')!==$uni||!QYS_Multitenant::university_active($uni))wp_die('مجوز تنظیم پیام‌های عضویت این دانشگاه را ندارید.',403);
        if(!self::schema_ready()&&!self::ensure_schema())wp_die('جدول تنظیمات پیام‌های عضویت آماده نیست.',500);
        $welcome=QYS_Messenger::welcome_format(wp_unslash($_POST['welcome_template']??''));
        $activation=QYS_Messenger::welcome_format(wp_unslash($_POST['activation_template']??''));
        if($welcome===''||$activation===''){
            wp_safe_redirect(QYS_Multitenant::campus_url('welcome',['qys_welcome'=>'error']));exit;
        }
        $t=self::table();$stamp=gmdate('Y-m-d H:i:s');
        $ok=$wpdb->query($wpdb->prepare("INSERT INTO $t (university,template,activation_template,updated_by,updated_at_utc) VALUES (%s,%s,%s,%d,%s) ON DUPLICATE KEY UPDATE template=VALUES(template),activation_template=VALUES(activation_template),updated_by=VALUES(updated_by),updated_at_utc=VALUES(updated_at_utc)",
            $uni,$welcome,$activation,get_current_user_id(),$stamp));
        if($ok===false)wp_die('ذخیره متن پیام‌های عضویت ناموفق بود.',500);
        wp_safe_redirect(QYS_Multitenant::campus_url('welcome',['qys_welcome'=>'saved']));exit;
    }
}
