<?php
/**
 * QuranYar multiversity control plane.
 * Global administration lives in wp-admin for the single central owner.
 * University representatives use the standalone /qys/ page only.
 */
if (!defined('ABSPATH')) exit;

final class QYS_Multitenant {
    // Backward-compatible aliases; canonical values live in QYS_Config from C5.
    const OWNER_OPTION=QYS_Config::OWNER_OPTION;
    const PORTAL_PAGE_OPTION=QYS_Config::PORTAL_PAGE_OPTION;
    const HOME_PAGE_OPTION=QYS_Config::HOME_PAGE_OPTION;
    const MINIAPP_PAGE_OPTION=QYS_Config::MINIAPP_PAGE_OPTION;
    const HOME_SLUG=QYS_Config::HOME_SLUG;
    const MINIAPP_SLUG=QYS_Config::MINIAPP_SLUG;
    const PORTAL_SLUG=QYS_Config::PORTAL_SLUG;
    const PERMISSIONS=QYS_Config::PERMISSIONS;

    public static function audit_table(){return QYS_Table_Names::name('tenant_audit');}

    public static function init(){
        if(!get_role('qys_university_representative'))add_role('qys_university_representative','نماینده دانشگاه قرآن‌یار',['read'=>true]);
        add_shortcode('qys_university_portal',[__CLASS__,'portal_shortcode']);
        add_action('admin_post_qys_tenant_action',[__CLASS__,'post']);
        add_action('admin_post_nopriv_qys_portal_login',[__CLASS__,'portal_login']);
        add_action('admin_post_qys_portal_login',[__CLASS__,'portal_login']);
        add_action('admin_init',[__CLASS__,'redirect_representative_admin'],50);
        add_action('init',[__CLASS__,'maybe_create_portal_page'],30);
        add_action('template_redirect',[__CLASS__,'protect_portal'],1);
        add_filter('template_include',[__CLASS__,'portal_template'],99);
        add_filter('login_redirect',[__CLASS__,'login_redirect'],20,3);
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue_portal_assets']);
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue_global_assets']);
        add_action('admin_notices',[__CLASS__,'portal_admin_notice']);
        add_action('admin_menu',[__CLASS__,'admin_menu'],20);
    }

    public static function schema_ready(){ return QYS_Schema_Runtime::ready(); }
    public static function diagnostic_schema_ready(){
        return QYS_University_Repository::diagnostic_schema_ready()
            && QYS_Table_Names::diagnostic_has_columns(self::audit_table(),['id','actor_id','action','university','created_at_utc']);
    }

    public static function ensure_schema(){global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
        $tables=QYS_University_Repository::schema_tables();$u=$tables['universities'];$r=$tables['representatives'];$a=self::audit_table();
        dbDelta("CREATE TABLE $u (
            code varchar(40) NOT NULL,
            title varchar(180) NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'active',
            created_by bigint unsigned NOT NULL DEFAULT 0,
            created_at_utc datetime NOT NULL,
            PRIMARY KEY  (code),
            KEY university_status (status)
        ) $c;");
        dbDelta("CREATE TABLE $r (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            university varchar(40) NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'pending',
            permissions varchar(255) NOT NULL DEFAULT '',
            approved_by bigint unsigned NOT NULL DEFAULT 0,
            approved_at_utc datetime DEFAULT NULL,
            updated_at_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_university (user_id,university),
            KEY university_status (university,status),
            KEY user_status (user_id,status)
        ) $c;");
        dbDelta("CREATE TABLE $a (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint unsigned NOT NULL,
            action varchar(35) NOT NULL,
            university varchar(40) NOT NULL DEFAULT '',
            subject_user_id bigint unsigned NOT NULL DEFAULT 0,
            created_at_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY audit_actor (actor_id),
            KEY audit_date (created_at_utc)
        ) $c;");
        if(!self::diagnostic_schema_ready())return false;
        if(!QYS_University_Repository::ensure_saveh(0))return false;
        return true;
    }


    /** Create the dedicated short campus URL /qys/. Never overwrite user content. */
    public static function ensure_portal_page(){
        if(!current_user_can('manage_options') || (self::owner_id()>0&&!QYS_Security_Service::is_global_admin()))return 0;
        $existing=get_page_by_path(self::PORTAL_SLUG,OBJECT,'page');
        if($existing){
            if($existing->post_status==='publish' && has_shortcode($existing->post_content,'qys_university_portal')){
                update_option(self::PORTAL_PAGE_OPTION,(int)$existing->ID,false);
                return (int)$existing->ID;
            }
            return 0;
        }
        $id=wp_insert_post([
            'post_type'=>'page','post_status'=>'publish',
            'post_title'=>'پنل دانشگاه قرآن‌یار',
            'post_name'=>self::PORTAL_SLUG,
            'post_content'=>'[qys_university_portal]',
            'comment_status'=>'closed','ping_status'=>'closed',
            'meta_input'=>['_qys_university_portal'=>'1']
        ],true);
        if(is_wp_error($id)||!(int)$id)return 0;
        update_option(self::PORTAL_PAGE_OPTION,(int)$id,false);
        return (int)$id;
    }

    public static function maybe_create_portal_page(){
        if(class_exists('QYS_Installer')&&!QYS_Installer::ready())return;
        $setup_allowed=self::owner_id()===0?current_user_can('manage_options'):QYS_Security_Service::is_global_admin();
        if($setup_allowed){
            $id=(int)get_option(self::PORTAL_PAGE_OPTION,0);
            $post=$id?get_post($id):null;
            if(!$post || $post->post_name!==self::PORTAL_SLUG || $post->post_status!=='publish' || !has_shortcode($post->post_content,'qys_university_portal'))self::ensure_portal_page();
            self::ensure_entry_pages();
        }
    }

    public static function portal_url(){
        $id=(int)get_option(self::PORTAL_PAGE_OPTION,0);
        $post=$id?get_post($id):null;
        if(!$post || $post->post_type!=='page' || $post->post_status!=='publish' || $post->post_name!==self::PORTAL_SLUG || !has_shortcode($post->post_content,'qys_university_portal')){
            $post=get_page_by_path(self::PORTAL_SLUG,OBJECT,'page');
            if(!$post || $post->post_status!=='publish' || !has_shortcode($post->post_content,'qys_university_portal'))return '';
            update_option(self::PORTAL_PAGE_OPTION,(int)$post->ID,false);
        }
        return (string)get_permalink($post->ID);
    }

    /** Both /qys/ and the explicitly chosen WordPress static homepage can serve the same campus UI. */
    public static function is_portal_request(){
        if(!function_exists('is_page'))return false;
        $id=(int)get_option(self::PORTAL_PAGE_OPTION,0);
        if($id>0 && is_page($id))return true;
        $home=(int)get_option(self::HOME_PAGE_OPTION,0);
        return $home>0 && get_option('show_on_front')==='page'
            && (int)get_option('page_on_front')===$home
            && is_front_page() && is_page($home);
    }

    /** Create only new pages; do not overwrite any pre-existing WordPress page. */
    public static function ensure_entry_pages(){
        if(!current_user_can('manage_options') || (self::owner_id()>0&&!QYS_Security_Service::is_global_admin()))return;
        self::ensure_one_page(self::HOME_SLUG,'ورود دانشگاه‌ها | قرآن‌یار','[qys_university_portal]',self::HOME_PAGE_OPTION);
        self::ensure_one_page(self::MINIAPP_SLUG,'برنامک اعضای قرآن‌یار','[quranyar_miniapp]',self::MINIAPP_PAGE_OPTION);
    }

    private static function ensure_one_page($slug,$title,$shortcode,$option){
        $page=get_page_by_path($slug,OBJECT,'page');
        if($page){
            if($page->post_status==='publish' && has_shortcode($page->post_content,trim($shortcode,'[]'))){
                update_option($option,(int)$page->ID,false);
                return (int)$page->ID;
            }
            return 0; // Respect an existing page using this slug.
        }
        $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$title,
            'post_name'=>$slug,'post_content'=>$shortcode,'comment_status'=>'closed','ping_status'=>'closed'],true);
        if(is_wp_error($id) || !(int)$id)return 0;
        update_option($option,(int)$id,false);
        return (int)$id;
    }

    public static function miniapp_url(){
        $id=(int)get_option(self::MINIAPP_PAGE_OPTION,0);
        $page=$id?get_post($id):null;
        if(!$page || $page->post_status!=='publish' || !has_shortcode($page->post_content,'quranyar_miniapp'))return '';
        return (string)get_permalink($id);
    }

    public static function is_campus_home(){
        $id=(int)get_option(self::HOME_PAGE_OPTION,0);
        $page=$id?get_post($id):null;
        return $id>0 && $page && $page->post_status==='publish'
            && has_shortcode($page->post_content,'qys_university_portal')
            && get_option('show_on_front')==='page' && (int)get_option('page_on_front')===$id;
    }

    public static function campus_entry_url(){
        return self::is_campus_home()?home_url('/'):self::portal_url();
    }


    public static function protect_portal(){
        if(!self::is_portal_request())return;
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive',true);
        header('Referrer-Policy: no-referrer',true);
        add_filter('show_admin_bar','__return_false');
    }

    public static function enqueue_portal_assets(){
        if(!self::is_portal_request())return;
        $base=QYS_Config::plugin_file();
        wp_enqueue_style('qys-portal-fonts',plugins_url('assets/qys-fonts.css',$base),[],QYS_Config::VERSION);
        wp_enqueue_style('qys-admin-ui',plugins_url('assets/qys-admin-ui.css',$base),['qys-portal-fonts'],QYS_Config::VERSION);
        wp_enqueue_style('qys-university-portal',plugins_url('assets/qys-university-portal.css',$base),['qys-admin-ui'],QYS_Config::VERSION);
        wp_enqueue_script('qys-university-portal',plugins_url('assets/qys-university-portal.js',$base),[],QYS_Config::VERSION,true);
        // D10: the portal shell owns only shared assets. Attendance, targeted,
        // Programs and Excel enqueue their own bundles only on their authorized
        // section through QYS_Portal_Asset_Policy.
    }

    public static function enqueue_global_assets(){
        $page=isset($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
        if($page!=='qys-universities')return;
        $base=QYS_Config::plugin_file();
        wp_enqueue_style('qys-global-fonts',plugins_url('assets/qys-fonts.css',$base),[],QYS_Config::VERSION);
        wp_enqueue_style('qys-global-admin',plugins_url('assets/qys-admin-ui.css',$base),['qys-global-fonts'],QYS_Config::VERSION);
    }

    public static function portal_template($template){
        if(!self::is_portal_request())return $template;
        return QYS_Config::plugin_dir().'/templates/qys-university-portal.php';
    }

    public static function portal_admin_notice(){
        if(!current_user_can('manage_options') || (self::owner_id()>0&&!QYS_Security_Service::is_global_admin()))return;
        if(self::is_campus_home() && self::miniapp_url()==='')echo '<div class="notice notice-error"><p>قرآن‌یار: صفحه مستقل برنامک اعضا در مسیر /miniapp/ یافت نشد؛ پیش از تغییر نشانی ایتا، این مورد را اصلاح کنید.</p></div>';
        if(self::portal_url()!=='')return;
        echo '<div class="notice notice-warning"><p>برگه دانشگاهی قرآن‌یار با نشانی کوتاه «/'.esc_html(self::PORTAL_SLUG).'/» ایجاد نشده است. اگر برگه‌ای با این نامک وجود دارد، تداخل را برطرف کنید؛ هیچ برگه موجودی خودکار بازنویسی نمی‌شود.</p></div>';
    }

    public static function admin_menu(){
        if(!current_user_can('manage_options'))return;
        if(self::owner_id()===0){
            add_menu_page('راه‌اندازی مدیریت کل قرآن‌یار','قرآن‌یار','manage_options','qys-universities',[__CLASS__,'owner_page'],'dashicons-networking',26);
            return;
        }
        if(!QYS_Security_Service::is_global_admin())return;
        add_submenu_page('qys-messaging','دانشگاه‌ها و نمایندگان','دانشگاه‌ها و نمایندگان','manage_options','qys-universities',[__CLASS__,'owner_page'],10);
    }

    public static function login_redirect($redirect_to,$requested,$user){
        if(!$user || is_wp_error($user))return $redirect_to;
        if(QYS_Security_Service::is_global_admin($user->ID))return $redirect_to;
        if(self::has_approved_assignment($user->ID)){
            $url=self::campus_entry_url();
            if($url)return $url;
        }
        return $redirect_to;
    }

    public static function redirect_representative_admin(){
        if(!is_user_logged_in() || QYS_Security_Service::is_global_admin() || !self::has_approved_assignment() || wp_doing_ajax())return;
        $script=basename((string)($_SERVER['PHP_SELF']??''));
        if(in_array($script,['admin-post.php','async-upload.php'],true))return;
        $url=self::campus_entry_url();
        if($url){wp_safe_redirect($url);exit;}
    }

    public static function owner_id(){return max(0,(int)get_option(self::OWNER_OPTION,0));}
    public static function is_owner($id=null){$id=$id===null?get_current_user_id():$id;return self::owner_id()>0 && (int)$id===self::owner_id();}

    public static function university_active($code){
        return is_string($code)&&preg_match('/\A[a-z][a-z0-9_\-]{2,39}\z/D',$code)&&self::schema_ready()&&QYS_University_Repository::active($code);
    }

    public static function universities($active_only=true){
        return self::schema_ready()?QYS_University_Repository::list((bool)$active_only):[];
    }
    public static function university_codes(){return array_column(self::universities(),'code');}
    public static function university_label($code){foreach(self::universities(false) as $u)if($u['code']===$code)return $u['title'];return '—';}

    public static function has_approved_assignment($user_id=null){
        $user_id=$user_id??get_current_user_id();
        return (int)$user_id>0&&self::schema_ready()&&count(QYS_University_Repository::approved_assignments((int)$user_id))>0;
    }

    public static function assignments($user_id=null){
        $user_id=$user_id??get_current_user_id();if(!(int)$user_id||!self::schema_ready())return [];
        return QYS_University_Repository::approved_assignments((int)$user_id);
    }

    /** Every university representative has one approved campus. */
    public static function campus_university($user_id=null){
        $items=self::assignments($user_id);
        return count($items)===1?(string)$items[0]['university']:'';
    }

    private static function audit($action,$uni='',$target=0){
        return QYS_University_Repository::insert_audit(self::audit_table(),[
            'actor_id'=>get_current_user_id(),'action'=>$action,'university'=>$uni,
            'subject_user_id'=>(int)$target,'created_at_utc'=>gmdate('Y-m-d H:i:s')
        ]);
    }

    /** Destination approval is the only boundary that changes a member's active university. */
    public static function approve_member_transfer(int $member_id,string $destination=''){
        $member=QYS_Member_Repository::find_by_id($member_id);if(!$member)return new WP_Error('member','پرونده عضو پیدا نشد.',['status'=>404]);
        $target=sanitize_key($destination!==''?$destination:(string)($member->pending_university??''));
        if($target===''||$target===(string)$member->university||(string)($member->pending_university??'')!==$target)return new WP_Error('transfer','درخواست انتقال فعالی برای این دانشگاه وجود ندارد.',['status'=>409]);
        if(!self::university_active($target))return new WP_Error('university','دانشگاه مقصد فعال نیست.',['status'=>409]);
        $scope=QYS_Permission_Service::scope('verify_members');
        if($scope===null||($scope!=='*'&&$scope!==$target))return new WP_Error('forbidden','مجوز تأیید انتقال به این دانشگاه را ندارید.',['status'=>403]);
        if(!QYS_Messenger::profile_identity_complete($member))return new WP_Error('profile','اطلاعات هویتی پرونده عضو کامل نیست و انتقال قابل تأیید نیست.',['status'=>409]);
        if(class_exists('QYS_Attendance')&&QYS_Attendance::schema_ready()&&QYS_Attendance::open_record_count_for_member($member_id)>0)return new WP_Error('open_attendance','این عضو حضور باز دارد. ابتدا خروج جلسه قبلی را ثبت کنید و سپس انتقال دانشگاه را تأیید کنید.',['status'=>409]);
        if(!QYS_University_Repository::begin())return new WP_Error('database','آغاز تراکنش انتقال انجام نشد.',['status'=>500]);
        $locked=QYS_Member_Repository::lock_by_id($member_id);
        if(!$locked||(string)($locked->pending_university??'')!==$target||(string)$locked->university===$target){QYS_University_Repository::rollback();return new WP_Error('conflict','درخواست انتقال هم‌زمان تغییر کرده است.',['status'=>409]);}
        $from=(string)$locked->university;
        $changed=QYS_Member_Repository::approve_transfer_locked($member_id,$from,$target);
        if($changed!==1){QYS_University_Repository::rollback();return new WP_Error('conflict','انتقال دانشگاه ثبت نشد؛ دوباره وضعیت پرونده را بررسی کنید.',['status'=>409]);}
        if(!QYS_Family_Relationship_Repository::prune_member_scopes($member_id,[$target])){ QYS_University_Repository::rollback();return new WP_Error('database','به‌روزرسانی انتساب خانوادگی پس از انتقال انجام نشد.',['status'=>500]); }
        if(!QYS_Messenger::cancel_pending_after_scope_change($member_id)){ QYS_University_Repository::rollback();return new WP_Error('database','لغو پیام‌های معلق دامنه قبلی انجام نشد.',['status'=>500]); }
        if(!self::audit('member_transfer_approved',$target,$member_id)){ QYS_University_Repository::rollback();return new WP_Error('database','ثبت ممیزی انتقال انجام نشد.',['status'=>500]); }
        if(!QYS_University_Repository::commit())return new WP_Error('database','نتیجه تراکنش انتقال نامشخص است.',['status'=>500]);
        return ['from'=>$from,'to'=>$target];
    }

    private static function form_start($action){
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="qys_tenant_action"><input type="hidden" name="qys_tenant_action" value="'.esc_attr($action).'">';
        wp_nonce_field('qys_tenant_action');
        $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'';
        if(in_array($tab,['universities','representatives'],true))echo '<input type="hidden" name="redirect_tab" value="'.esc_attr($tab).'">';
    }
    private static function form_end($caption){echo '<button type="submit" class="button button-primary">'.esc_html($caption).'</button></form>';}

    /** Central control plane: wp-admin only. */
    public static function owner_page(){
        if(!current_user_can('manage_options') || (self::owner_id()>0&&!self::is_owner()))wp_die('دسترسی مجاز نیست.');
        $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'universities';
        if(!in_array($tab,['universities','representatives'],true))$tab='universities';
        echo '<div class="wrap qys-admin qys-tenant-owner" dir="rtl"><span class="qys-admin-eyebrow">مدیریت کل قرآن‌یار</span><h1>دانشگاه‌ها و نمایندگان</h1><p class="qys-tenant-lead">مدیر کل در وردپرس به همه دانشگاه‌ها دسترسی دارد. پنل هر دانشگاه، از جمله ساوه، فقط از برگه کوتاه /qys/ استفاده می‌کند.</p>';
        if(self::owner_id()===0){
            echo '<div class="notice notice-warning"><p>پیش از فعال‌شدن چنددانشگاهی، مالکیت مرکزی را با حساب مدیر اصلی تثبیت کنید.</p></div>';
            self::form_start('claim');self::form_end('تثبیت این حساب به‌عنوان مدیر کل');echo '</div>';return;
        }
        echo '<p><strong>مدیر کل:</strong> '.esc_html(wp_get_current_user()->display_name).' | <strong>پنل دانشگاه‌ها:</strong> <code>'.esc_html(self::portal_url()).'</code></p>';
        $miniapp_url=self::miniapp_url();$home_id=(int)get_option(self::HOME_PAGE_OPTION,0);
        echo '<section class="qys-tenant-panel"><h2>راه‌اندازی صفحه اصلی دانشگاه‌ها</h2>';
        echo '<p>صفحه اختصاصی ورود نمایندگان با نام کاربری و رمز عبور آماده است. برای جلوگیری از قطع دسترسی کاربران ایتا، ابتدا نشانی برنامک را در تنظیمات ایتا تغییر دهید؛ سپس صفحه اصلی را تغییر دهید.</p>';
        echo '<p><strong>نشانی جدید برنامک اعضا:</strong> '.($miniapp_url?'<a href="'.esc_url($miniapp_url).'" target="_blank" rel="noopener">'.esc_html($miniapp_url).'</a>':'<strong>ساخته نشده یا نامک /miniapp/ اشغال است</strong>').'</p>';
        echo '<p><strong>برگه آماده برای صفحه اصلی:</strong> '.($home_id?'<a href="'.esc_url(get_edit_post_link($home_id)).'">ورود دانشگاه‌ها (شناسه '.(int)$home_id.')</a>':'ساخته نشده یا نامک /university-login/ اشغال است').'</p>';
        echo '<p><strong>وضعیت صفحه اصلی:</strong> '.(self::is_campus_home()?'پنل دانشگاه‌ها فعال است':'هنوز تغییر نکرده است').'</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('options-reading.php')).'">تنظیمات خواندن / انتخاب برگه صفحه اصلی</a></p></section>';
        $base=admin_url('admin.php?page=qys-universities');
        echo '<nav class="nav-tab-wrapper"><a class="nav-tab '.($tab==='universities'?'nav-tab-active':'').'" href="'.esc_url(add_query_arg('tab','universities',$base)).'">دانشگاه‌ها</a><a class="nav-tab '.($tab==='representatives'?'nav-tab-active':'').'" href="'.esc_url(add_query_arg('tab','representatives',$base)).'">نمایندگان و مجوزها</a></nav>';
        if($tab==='universities')self::owner_universities_panel();
        else self::owner_representatives_panel();
        echo '</div>';
    }

    private static function owner_universities_panel(){
        echo '<section class="qys-tenant-panel"><h2>دانشگاه‌های ثبت‌شده</h2><div class="qys-tenant-table-scroll"><table class="widefat striped"><thead><tr><th>نام</th><th>کد</th><th>وضعیت</th></tr></thead><tbody>';
        foreach(self::universities(false) as $uni)echo '<tr><td>'.esc_html($uni['title']).'</td><td><code>'.esc_html($uni['code']).'</code></td><td>'.esc_html($uni['status']).'</td></tr>';
        echo '</tbody></table></div></section><section class="qys-tenant-panel">';
        self::form_start('university');
        echo '<h3>افزودن دانشگاه</h3><p><label>عنوان <input name="title" required maxlength="180" class="regular-text"></label> &nbsp; <label>شناسه انگلیسی ثابت <input name="code" required maxlength="40" pattern="[a-z][a-z0-9_-]{2,39}" placeholder="tehran_ums" dir="ltr"></label></p>';
        self::form_end('ایجاد دانشگاه');echo '</section>';
    }

    private static function owner_representatives_panel(){
        echo '<section class="qys-tenant-panel"><h2>افزودن نماینده</h2><p>هر حساب نماینده فقط می‌تواند یک دانشگاه تأییدشده داشته باشد. هیچ دسترسی عملیاتی پیش از تأیید مدیر کل فعال نمی‌شود.</p>';
        echo '<h3>ایجاد حساب جدید</h3>';self::form_start('create_rep');
        echo '<p><label>نام کاربری <input name="login" required maxlength="60" autocomplete="off" dir="ltr"></label> &nbsp;<label>ایمیل <input type="email" name="email" required maxlength="100" autocomplete="off" dir="ltr"></label> &nbsp;<label>دانشگاه <select name="university">';
        foreach(self::universities() as $u)echo '<option value="'.esc_attr($u['code']).'">'.esc_html($u['title']).'</option>';
        echo '</select></label></p>';self::form_end('ایجاد حساب در انتظار تأیید');
        echo '<h3>معرفی حساب موجود</h3>';self::form_start('nominate');echo '<p><label>نام کاربری وردپرس <input required name="login" class="regular-text" autocomplete="off"></label> &nbsp; <label>دانشگاه <select name="university">';
        foreach(self::universities() as $u)echo '<option value="'.esc_attr($u['code']).'">'.esc_html($u['title']).'</option>';
        echo '</select></label></p>';self::form_end('ثبت نماینده در انتظار تأیید');
        $rows=QYS_University_Repository::representatives_for_admin(150);
        echo '<h2>تأیید و مجوز نمایندگان</h2></section><section class="qys-tenant-panel">';
        foreach((array)$rows as $r){$u=get_userdata((int)$r->user_id);
            echo '<section style="background:#fff;padding:16px;margin:12px 0;border:1px solid #dcdcde;border-radius:12px"><h3>'.esc_html($u?$u->display_name:'کاربر حذف‌شده').' — '.esc_html(self::university_label($r->university)).'</h3><p>وضعیت: '.esc_html($r->status).' | شناسه کاربری: '.(int)$r->user_id.'</p>';
            self::form_start('approve');echo '<input type="hidden" name="rep_id" value="'.(int)$r->id.'"><div style="display:flex;flex-wrap:wrap;gap:12px">';
            foreach(self::PERMISSIONS as $key=>$title)echo '<label><input type="checkbox" name="permissions[]" value="'.esc_attr($key).'" '.checked(in_array($key,explode(',',(string)$r->permissions),true),true,false).'> '.esc_html($title).'</label>';
            echo '</div><p>';self::form_end('تأیید / ویرایش دسترسی');
            if($r->status==='approved'){self::form_start('suspend');echo '<input type="hidden" name="rep_id" value="'.(int)$r->id.'">';self::form_end('تعلیق فوری');}
            echo '</section>';
        }
        echo '</section>';
    }

    public static function post(){
        if(!is_user_logged_in())wp_die('دسترسی مجاز نیست.');check_admin_referer('qys_tenant_action');
        $action=sanitize_key(wp_unslash($_POST['qys_tenant_action']??''));
        if($action==='claim'){
            if(self::owner_id() || !current_user_can('manage_options') || !self::schema_ready())wp_die('اجازه تثبیت مالکیت ندارید.');
            if(!add_option(self::OWNER_OPTION,(int)get_current_user_id(),'',false))wp_die('مالکیت پیشتر تعیین شده است.');
            $now=gmdate('Y-m-d H:i:s');
            if(!QYS_University_Repository::begin()){delete_option(self::OWNER_OPTION);wp_die('امکان آغاز تراکنش ثبت مالکیت وجود ندارد.');}
            $owner_assignment=QYS_University_Repository::upsert_owner_assignment(
                get_current_user_id(),'saveh_ums',implode(',',array_keys(self::PERMISSIONS)),get_current_user_id(),$now);
            if($owner_assignment===false || !self::audit('owner_claimed','saveh_ums',get_current_user_id())){QYS_University_Repository::rollback();delete_option(self::OWNER_OPTION);wp_die('انتساب ساوه یا ثبت ممیزی انجام نشد؛ مالکیت تثبیت نشد.');}
            if(!QYS_University_Repository::commit())wp_die('نتیجه تراکنش تثبیت مالکیت نامشخص است.');
        } else {
            if(!QYS_Security_Service::is_global_admin())wp_die('این اقدام فقط در اختیار مدیر کل است.');
            if($action==='university'){
                $code=sanitize_key(wp_unslash($_POST['code']??''));$title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));
                if(!preg_match('/\A[a-z][a-z0-9_\-]{2,39}\z/D',$code)||$title===''||QYS_Text_Normalizer::chars($title)>180)wp_die('شناسه یا عنوان نامعتبر است.');
                if(!QYS_University_Repository::create_university($code,$title,get_current_user_id()))wp_die('دانشگاه تکراری است یا ثبت ناموفق بود.');
                if(!self::audit('university_created',$code))wp_die('دانشگاه ایجاد شد؛ ثبت گزارش ممیزی ناموفق بود.');
            } elseif($action==='create_rep'){
                $login=trim(sanitize_user(wp_unslash($_POST['login']??'')));$email=sanitize_email(wp_unslash($_POST['email']??''));$code=sanitize_key(wp_unslash($_POST['university']??''));
                if(!validate_username($login)||strlen($login)<3||strlen($login)>60||!is_email($email)||username_exists($login)||email_exists($email)||!self::university_active($code))wp_die('نام کاربری، ایمیل یا دانشگاه نامعتبر یا تکراری است.');
                $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$email,'role'=>'subscriber']);
                if(is_wp_error($uid))wp_die('حساب نماینده ایجاد نشد؛ ورودی‌ها را بررسی کنید.');
                $now=gmdate('Y-m-d H:i:s');
                $created=QYS_University_Repository::create_nomination((int)$uid,$code);
                if(!$created || !self::audit('rep_nominated',$code,(int)$uid))wp_die('حساب کاربری بدون مجوز ساخته شد ولی نامزدی کامل نشد.');
            } elseif($action==='nominate'){
                $login=trim(sanitize_user(wp_unslash($_POST['login']??'')));$code=sanitize_key(wp_unslash($_POST['university']??''));$u=get_user_by('login',$login);
                if(!$u||!self::university_active($code))wp_die('نام کاربری یا دانشگاه معتبر نیست.');
                if(user_can($u,'manage_options') && !self::is_owner($u->ID))wp_die('حساب مدیر وردپرس دیگری را به‌عنوان نماینده منصوب نکنید.');
                $now=gmdate('Y-m-d H:i:s');
                $ok=QYS_University_Repository::upsert_pending((int)$u->ID,$code);
                if($ok===false || !self::audit('rep_nominated',$code,$u->ID))wp_die('درخواست نمایندگی ثبت نشد یا ممیزی خطا داشت.');
            } elseif(in_array($action,['approve','suspend'],true)){
                $id=absint($_POST['rep_id']??0);$r=QYS_University_Repository::representative($id);
                if(!$r||!self::university_active($r->university))wp_die('نماینده یا دانشگاه معتبر نیست.');
                if($action==='approve'){
                    $subject=get_userdata((int)$r->user_id);if(!$subject || (user_can($subject,'manage_options') && !self::is_owner($subject->ID)))wp_die('حساب کاربری نامعتبر یا دارای نقش مدیریتی سراسری است.');
                    $raw=wp_unslash($_POST['permissions']??[]);if(!is_array($raw)||!$raw)wp_die('دست‌کم یک مجوز را انتخاب کنید.');
                    $seen=[];foreach($raw as $key){if(!is_string($key)||!isset(self::PERMISSIONS[$key]))wp_die('مجوز نامعتبر است.');$seen[$key]=true;}
                    $perms=implode(',',array_keys($seen));$now=gmdate('Y-m-d H:i:s');
                    if(!QYS_University_Repository::begin())wp_die('آغاز تراکنش تأیید نماینده ناموفق بود.');
                    // Serialize approvals for this user so two concurrent requests cannot approve two campuses.
                    if(!QYS_University_Repository::lock_user_assignments((int)$r->user_id)){QYS_University_Repository::rollback();wp_die('قفل بررسی نمایندگی ایجاد نشد؛ مجوزی اعمال نشد.');}
                    $locked=QYS_University_Repository::representative($id);
                    if(!$locked || (int)$locked->user_id!==(int)$r->user_id || !self::university_active((string)$locked->university)){QYS_University_Repository::rollback();wp_die('وضعیت نماینده هنگام تأیید تغییر کرده است؛ دوباره تلاش کنید.');}
                    $other=QYS_University_Repository::approved_other_count((int)$locked->user_id,$id);
                    if($other>0){QYS_University_Repository::rollback();wp_die('این حساب قبلاً نماینده تأییدشده دانشگاه دیگری است. هر نماینده فقط یک دانشگاه فعال دارد.');}
                    $updated=QYS_University_Repository::approve($id,$perms,get_current_user_id(),$now);
                    if($updated===false || !self::audit('rep_approved',(string)$locked->university,(int)$locked->user_id)){QYS_University_Repository::rollback();wp_die('تأیید یا ثبت گزارش ممیزی ناموفق بود؛ مجوزی اعمال نشد.');}
                    if(!QYS_University_Repository::commit())wp_die('نتیجه تراکنش نامشخص است.');
                    if(!user_can($subject,'manage_options'))$subject->add_role('qys_university_representative');
                } else {
                    if(!QYS_University_Repository::begin())wp_die('آغاز تراکنش تعلیق ناموفق بود.');
                    $updated=QYS_University_Repository::suspend($id,gmdate('Y-m-d H:i:s'));
                    if($updated===false || !self::audit('rep_suspended',$r->university,(int)$r->user_id)){QYS_University_Repository::rollback();wp_die('تعلیق یا ثبت ممیزی انجام نشد.');}
                    if(!QYS_University_Repository::commit())wp_die('نتیجه تراکنش نامشخص است.');
                }
            } else wp_die('عملیات نامعتبر است.');
        }
        $tab=isset($_POST['redirect_tab'])?sanitize_key(wp_unslash($_POST['redirect_tab'])):'';
        $url=admin_url('admin.php?page=qys-universities');
        if(in_array($tab,['universities','representatives'],true))$url=add_query_arg('tab',$tab,$url);
        elseif(in_array($action,['approve','suspend','nominate','create_rep'],true))$url=add_query_arg('tab','representatives',$url);
        wp_safe_redirect($url);exit;
    }

    /** Dedicated login uses WordPress authentication/session cookies; credentials are never stored by QuranYar. */
    public static function login_form_html(){
        $err=isset($_GET['qys_login'])?sanitize_key(wp_unslash($_GET['qys_login'])):'';
        $message=['invalid'=>'نام کاربری یا رمز عبور صحیح نیست.','limited'=>'تلاش‌های ناموفق زیاد بوده است؛ کمی بعد دوباره امتحان کنید.',
            'security'=>'اعتبار فرم منقضی شده است؛ صفحه را دوباره باز کنید.'][$err]??'';
        $out='<section class="qys-tenant-login" aria-labelledby="qys-login-title"><div class="qys-login-icon" aria-hidden="true">ق</div>'
            .'<span class="qys-tenant-eyebrow">شبکه دانشگاهی قرآن‌یار</span><h1 id="qys-login-title">ورود نمایندگان دانشگاه‌ها</h1>'
            .'<p>با نام کاربری و رمز عبور حساب تأییدشده خود وارد شوید. دانشگاه شما به‌طور خودکار شناسایی می‌شود.</p>';
        if($message!=='')$out.='<p role="alert" class="qys-login-error">'.esc_html($message).'</p>';
        $out.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" autocomplete="on" class="qys-login-form">'
            .'<input type="hidden" name="action" value="qys_portal_login">'
            .wp_nonce_field('qys_portal_login','qys_login_nonce',true,false)
            .'<label for="qys-login-user">نام کاربری<input id="qys-login-user" name="log" type="text" required maxlength="60" autocomplete="username" dir="ltr" spellcheck="false"></label>'
            .'<label for="qys-login-password">رمز عبور<input id="qys-login-password" name="pwd" type="password" required autocomplete="current-password" dir="ltr"></label>'
            .'<label class="qys-login-remember"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label>'
            .'<button type="submit" class="qys-tenant-btn qys-login-submit">ورود به پنل دانشگاه</button>'
            .'</form><a class="qys-login-reset" href="'.esc_url(wp_lostpassword_url(self::campus_entry_url())).'">رمز عبور را فراموش کرده‌اید؟</a>'
            .'</section>';
        return $out;
    }

    public static function portal_login(){
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('درخواست نامعتبر است.',403);
        $entry=self::campus_entry_url();if(!$entry)$entry=home_url('/qys/');
        $nonce=isset($_POST['qys_login_nonce'])?sanitize_text_field(wp_unslash($_POST['qys_login_nonce'])):'';
        if(!wp_verify_nonce($nonce,'qys_portal_login')){
            wp_safe_redirect(add_query_arg('qys_login','security',$entry));exit;
        }
        $username=isset($_POST['log'])&&is_string($_POST['log'])?sanitize_user(wp_unslash($_POST['log'])):'';
        $password=isset($_POST['pwd'])&&is_string($_POST['pwd'])?wp_unslash($_POST['pwd']):'';
        if($username===''||$password===''||strlen($password)>4096){
            wp_safe_redirect(add_query_arg('qys_login','invalid',$entry));exit;
        }
        // Avoid retaining IP or username as transient values. Keys are salted hashes only.
        $remote=isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):'';
        $pair_key='qys_login_pair_'.substr(hash_hmac('sha256',$remote.'|'.$username,wp_salt('auth')),0,32);
        $ip_key='qys_login_ip_'.substr(hash_hmac('sha256',$remote,wp_salt('auth')),0,32);
        $pair_failed=(int)get_transient($pair_key);$ip_failed=(int)get_transient($ip_key);
        if($pair_failed>=5||$ip_failed>=30){wp_safe_redirect(add_query_arg('qys_login','limited',$entry));exit;}
        $user=wp_signon(['user_login'=>$username,'user_password'=>$password,'remember'=>!empty($_POST['remember'])],is_ssl());
        unset($password);
        if(is_wp_error($user)){
            set_transient($pair_key,$pair_failed+1,15*MINUTE_IN_SECONDS);
            set_transient($ip_key,$ip_failed+1,15*MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('qys_login',($pair_failed>=4||$ip_failed>=29)?'limited':'invalid',$entry));exit;
        }
        delete_transient($pair_key);
        wp_safe_redirect($entry);exit;
    }

    /** Campus portal router. Global management is intentionally never rendered here. */
    public static function portal_shortcode(){
        if(!self::is_portal_request())return '';
        if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::ready())return QYS_Upgrade_Coordinator::unavailable_markup();
        if(!is_user_logged_in())return self::login_form_html();
        if(!self::schema_ready())return '<section class="qys-tenant-state"><h2>سامانه در حال آماده‌سازی است</h2><p>مدیر کل باید وضعیت نصب را بررسی کند.</p></section>';
        $assignments=self::assignments();
        if(!$assignments)return '<section class="qys-tenant-state"><h2>در انتظار تأیید نمایندگی</h2><p>هنوز نمایندگی این حساب توسط مدیر کل تأیید نشده است.</p></section>';
        if(count($assignments)!==1)return '<section class="qys-tenant-state"><h2>نیاز به اصلاح دسترسی</h2><p>برای این حساب بیش از یک دانشگاه فعال ثبت شده است. مدیر کل باید فقط یک نمایندگی را فعال نگه دارد.</p></section>';
        $section=sanitize_key(wp_unslash($_GET['section']??'home'));
        if(!QYS_Module_Registry::feature_by_section($section))$section='home';
        ob_start();
        QYS_Portal_Feature_Service::dispatch($section);
        return (string)ob_get_clean();
    }

    public static function campus_url($section='home',$args=[]){
        $url=self::portal_url();if(!$url)return '';
        $params=['section'=>$section];foreach($args as $k=>$v){if($k==='section')continue;$params[$k]=$v;}
        return add_query_arg($params,$url);
    }

    public static function portal_scope_fields(){
        $uni=self::campus_university();
        if($uni==='')return '';
        return '<input type="hidden" name="qys_portal_scope" value="1"><input type="hidden" name="qys_portal_university" value="'.esc_attr($uni).'">';
    }

    public static function render_home(){self::campus_page('home');}
    public static function render_members(){self::campus_page('members');}

    public static function render_messages_hub(){
        $uni=self::campus_university();
        if($uni===''){echo '<section class="qys-tenant-state"><h2>پنل دانشگاه در دسترس نیست</h2></section>';return;}
        $items=QYS_Portal_Feature_Service::visible_group('messages');
        $descriptions=[
            'targeted'=>'پیام فوری یا زمان‌بندی‌شده برای اعضای واجد شرایط دانشگاه',
            'excel'=>'ساخت پیام‌های شخصی‌سازی‌شده از فایل CSV یا Excel',
            'birthday'=>'تنظیم تبریک تولد خودکار و مشاهده وضعیت ارسال‌ها',
            'welcome'=>'مدیریت پیام خوش‌آمدگویی اعضای جدید دانشگاه',
        ];
        $icons=['targeted'=>'✦','excel'=>'⇩','birthday'=>'🎂','welcome'=>'👋'];
        $stats=QYS_Member_Repository::university_counts($uni);
        echo '<div class="qys-admin qys-tenant-hub qys-tenant-screen" dir="rtl">';
        echo QYS_Portal_Experience::section_head('پیام‌رسانی دانشگاه','پیام‌ها','ارسال‌ها بر اساس دسترسی همین دانشگاه و فقط برای اعضای مجاز انجام می‌شود.',count($items).' ابزار فعال');
        echo '<div class="qys-tenant-metric-grid qys-tenant-metric-grid--compact">';
        echo QYS_Portal_Experience::metric('ابزارهای فعال',count($items),'default');
        echo QYS_Portal_Experience::metric('گیرندگان آماده',(int)($stats['eligible']??0),'good');
        echo QYS_Portal_Experience::metric('نیازمند تأیید',(int)($stats['pending']??0),((int)($stats['pending']??0)>0?'attention':'muted'));
        echo '</div>';
        echo '<div class="qys-tenant-action-grid qys-tenant-action-grid--polished">';
        foreach($items as $item){
            $id=$item['id'];
            $desc=$descriptions[$id]??'مدیریت این قابلیت برای دانشگاه شما';
            $icon=$icons[$id]??'•';
            echo '<a class="qys-tenant-action-card" href="'.esc_url(self::campus_url($item['section'])).'">'
                .'<span class="qys-tenant-action-icon" aria-hidden="true">'.esc_html($icon).'</span>'
                .'<span><strong>'.esc_html($item['label']).'</strong><small>'.esc_html($desc).'</small></span>'
                .QYS_Portal_Experience::status_chip('فعال','good').'<b aria-hidden="true">‹</b></a>';
        }
        if(!$items)echo QYS_Portal_Experience::empty_state('ابزار پیام‌رسانی فعالی ندارید','مدیر کل باید دسترسی پیام‌رسانی این دانشگاه را فعال کند.');
        echo '</div></div>';
    }

    public static function render_more(){
        $uni=self::campus_university();
        if($uni===''){echo '<section class="qys-tenant-state"><h2>پنل دانشگاه در دسترس نیست</h2></section>';return;}
        $user=wp_get_current_user();
        $permissions=[];
        foreach(self::PERMISSIONS as $key=>$label)if(QYS_Permission_Service::scope($key)!==null)$permissions[]=$label;
        echo '<div class="qys-admin qys-tenant-more qys-tenant-screen" dir="rtl">';
        echo QYS_Portal_Experience::section_head('حساب و دسترسی','بیشتر','اطلاعات نمایندگی و سطح دسترسی این حساب را یک‌جا ببینید.',count($permissions).' دسترسی فعال');
        echo '<section class="qys-tenant-panel qys-tenant-profile-card qys-tenant-profile-card--polished">'
            .'<div class="qys-tenant-profile-avatar" aria-hidden="true">'.esc_html(mb_substr((string)$user->display_name,0,1)).'</div>'
            .'<div class="qys-tenant-profile-copy"><span>نماینده دانشگاه</span><h2>'.esc_html($user->display_name).'</h2><p>'.esc_html(self::university_label($uni)).'</p></div>'
            .QYS_Portal_Experience::status_chip('نمایندگی فعال','good').'</section>';
        echo '<section class="qys-tenant-panel"><div class="qys-tenant-panel-title"><div><h2>دسترسی‌های فعال</h2><p>فقط قابلیت‌هایی که مدیر کل برای این نمایندگی تأیید کرده در پنل قابل استفاده‌اند.</p></div><strong>'.number_format_i18n(count($permissions)).'</strong></div><div class="qys-tenant-chip-list">';
        foreach($permissions as $label)echo '<span>'.esc_html($label).'</span>';
        if(!$permissions)echo '<span class="is-muted">دسترسی عملیاتی فعالی ثبت نشده است.</span>';
        echo '</div></section>';
        echo '<section class="qys-tenant-panel qys-tenant-account-actions"><div><h2>حساب کاربری</h2><p>پس از پایان کار در دستگاه عمومی از حساب خود خارج شوید.</p></div><a class="qys-tenant-btn qys-tenant-btn--danger" href="'.esc_url(wp_logout_url(self::campus_entry_url())).'">خروج از پنل دانشگاه</a></section></div>';
    }

    public static function campus_page($section='home'){
        $uni=self::campus_university();if($uni===''){echo '<section class="qys-tenant-state"><h2>پنل دانشگاه در دسترس نیست</h2></section>';return;}
        $scope=QYS_Permission_Service::scope('view_members');
        $verify=QYS_Permission_Service::scope('verify_members')===$uni;
        echo '<div class="qys-admin qys-tenant-campus qys-tenant-screen" dir="rtl">';
        if($section==='home'){
            $stats=QYS_Member_Repository::university_counts($uni);
            $members=(int)($stats['total']??0);$verified=(int)($stats['verified']??0);$pending=(int)($stats['pending']??0);$eligible=(int)($stats['eligible']??0);$transfers=QYS_Member_Repository::pending_transfer_count($uni);
            echo '<div class="qys-tenant-campus-home qys-tenant-dashboard">';
            echo '<div class="qys-tenant-metric-grid">'
                .QYS_Portal_Experience::metric('کل اعضا',$members,'default')
                .QYS_Portal_Experience::metric('تأییدشده',$verified,'good')
                .QYS_Portal_Experience::metric('در انتظار',$pending,$pending>0?'attention':'muted')
                .QYS_Portal_Experience::metric('آماده پیام',$eligible,'info')
                .'</div>';
            echo '<div class="qys-tenant-home-heading"><div><h2>کارهای امروز</h2><p>مواردی که احتمالاً به اقدام شما نیاز دارند.</p></div></div><div class="qys-tenant-dashboard-stack">';
            if($transfers>0 && $verify){
                echo QYS_Portal_Experience::dashboard_notice($transfers.' درخواست انتقال به این دانشگاه','دانشگاه عضو تا زمان تأیید شما تغییر نمی‌کند. درخواست‌ها را در بخش اعضا بررسی کنید.',self::campus_url('members',['transfers'=>'1']),'بررسی انتقال‌ها');
            }elseif($transfers>0){
                echo QYS_Portal_Experience::dashboard_notice($transfers.' درخواست انتقال در انتظار است','برای تأیید انتقال، مجوز تأیید هویت اعضا لازم است.');
            }
            if($pending>0 && $verify){
                echo QYS_Portal_Experience::dashboard_notice($pending.' پرونده در انتظار تأیید است','پرونده‌های کامل را بررسی و هویت اعضا را تأیید کنید.',self::campus_url('members'),'بررسی اعضا');
            }elseif($pending>0){
                echo QYS_Portal_Experience::dashboard_notice($pending.' پرونده هنوز تأیید نشده است','مجوز تأیید هویت برای این حساب فعال نیست.','', '');
            }else{
                echo QYS_Portal_Experience::dashboard_notice('صف تأیید خالی است','در حال حاضر پرونده‌ای منتظر تأیید این دانشگاه نیست.');
            }
            if(QYS_Permission_Service::scope('send_messages')!==null){
                echo QYS_Portal_Experience::dashboard_notice(number_format_i18n($eligible).' عضو آماده دریافت پیام است','ارسال‌های دانشگاه فقط برای اعضای تأییدشده و دارای رضایت انجام می‌شود.',self::campus_url('messages'),'باز کردن پیام‌ها');
            }
            if(QYS_Permission_Service::scope('attendance')!==null){
                echo QYS_Portal_Experience::dashboard_notice('حضور و غیاب آماده است','ساخت جلسه، QR ثابت، ثبت ورود و خروج و گزارش‌ها در بخش حضور قرار دارد.',self::campus_url('attendance'),'مدیریت حضور');
            }
            if($scope===null && QYS_Permission_Service::scope('send_messages')===null && QYS_Permission_Service::scope('attendance')===null){
                echo QYS_Portal_Experience::dashboard_notice('دسترسی عملیاتی محدود است','مدیر کل هنوز قابلیت عملیاتی برای این نمایندگی فعال نکرده است.');
            }
            echo '</div></div></div>';return;
        }
        if(!$scope){echo '<section class="qys-tenant-state"><h2>دسترسی مشاهده اعضا فعال نیست</h2><p>مدیر کل باید مجوز مشاهده پرونده‌های این دانشگاه را برای نماینده فعال کند.</p></section></div>';return;}
        if(!empty($_POST['qys_campus_verify'])){
            check_admin_referer('qys_campus_verify');$id=absint($_POST['member_id']??0);if(!$verify)wp_die('مجوز تأیید هویت ندارید.');
            $m=QYS_Member_Repository::find_by_id_in_university($id,$uni);
            if(!$m||!QYS_Messenger::profile_complete($m))echo '<div class="notice notice-error"><p>پرونده عضو کامل نیست یا متعلق به این دانشگاه نیست.</p></div>';
            else{if(!QYS_University_Repository::begin())wp_die('آغاز تراکنش تأیید عضو ناموفق بود.');$n=QYS_Member_Repository::verify_in_university($id,$uni);
                if($n===false){QYS_University_Repository::rollback();wp_die('تأیید عضو ناموفق بود؛ تغییر ثبت نشد.');}
                if((int)$n!==1){QYS_University_Repository::rollback();echo '<div class="notice notice-info"><p>این پرونده قبلاً تأیید شده است.</p></div>';}
                else{
                    if(!self::audit('member_verified',$uni,$id)){QYS_University_Repository::rollback();wp_die('ثبت ممیزی ناموفق بود؛ تغییر تأیید ذخیره نشد.');}
                    if(!QYS_University_Repository::commit())wp_die('نتیجه تراکنش نامشخص است.');
                    $activation=QYS_Messenger::activation_send_once($id);
                    $activation_note=QYS_Messenger::activation_delivery_note($activation);
                    echo '<div class="notice notice-success"><p>پرونده تأیید شد.'.esc_html($activation_note).'</p></div>';
                }}
        }
        if(!empty($_POST['qys_campus_transfer_approve'])){
            check_admin_referer('qys_campus_transfer_approve');$id=absint($_POST['member_id']??0);if(!$verify)wp_die('مجوز تأیید انتقال ندارید.');
            $r=self::approve_member_transfer($id,$uni);
            if(is_wp_error($r))echo '<div class="notice notice-error"><p>'.esc_html($r->get_error_message()).'</p></div>';
            else echo '<div class="notice notice-success"><p>انتقال عضو از «'.esc_html(self::university_label((string)$r['from'])).'» به «'.esc_html(self::university_label((string)$r['to'])).'» تأیید شد. پیام‌های معلق دامنه قبلی لغو شدند.</p></div>';
        }
        $stats=QYS_Member_Repository::university_counts($uni);
        $query=sanitize_text_field(wp_unslash($_GET['member_search']??''));
        $sensitive=QYS_Permission_Service::scope('sensitive_identity')===$uni;
        $rows=QYS_Member_Repository::university_search($uni,$query,100,$sensitive);
        $transfer_rows=QYS_Member_Repository::pending_transfer_search($uni,$query,100,$sensitive);
        $family_ids=[];foreach(array_merge((array)$rows,(array)$transfer_rows) as $family_member)$family_ids[]=(int)$family_member->id;
        $family_summaries=QYS_Family_Relationship_Repository::summaries_for_members($family_ids,$uni);
        $placeholder=$sensitive?'جست‌وجوی نام، کد ملی یا موبایل':'جست‌وجوی نام و نام خانوادگی';
        $member_meta=$query!=='' ? count($rows).' نتیجه برای «'.$query.'»' : count($rows).' عضو نمایش داده می‌شود';
        echo QYS_Portal_Experience::section_head('اعضا و پرونده‌ها','اعضا','جست‌وجو، بررسی وضعیت و تأیید پرونده‌های همین دانشگاه؛ اطلاعات حساس فقط در محدوده مجوز شما نمایش داده می‌شود.',$member_meta);
        echo '<div class="qys-tenant-metric-grid qys-tenant-metric-grid--compact qys-tenant-member-metrics">'
            .QYS_Portal_Experience::metric('کل اعضا',(int)($stats['total']??0),'default')
            .QYS_Portal_Experience::metric('تأییدشده',(int)($stats['verified']??0),'good')
            .QYS_Portal_Experience::metric('در انتظار',(int)($stats['pending']??0),((int)($stats['pending']??0)>0?'attention':'muted'))
            .QYS_Portal_Experience::metric('آماده پیام',(int)($stats['eligible']??0),'info')
            .'</div>';
        echo '<section class="qys-tenant-panel qys-tenant-member-tools"><div class="qys-tenant-search-head"><div><h2>جست‌وجوی اعضا</h2><p>نام یا شناسه مجاز را وارد کنید؛ نتیجه‌ها فقط از همین دانشگاه هستند.</p></div>'.QYS_Portal_Experience::status_chip($sensitive?'جست‌وجوی حساس فعال':'حریم خصوصی محدود','info').'</div><form method="get" class="qys-tenant-search-form"><input type="hidden" name="section" value="members"><label class="screen-reader-text" for="qys-member-search">جست‌وجوی اعضا</label><div class="qys-tenant-search-row"><input id="qys-member-search" type="search" name="member_search" value="'.esc_attr($query).'" placeholder="'.esc_attr($placeholder).'"><button class="qys-tenant-btn" type="submit">جست‌وجو</button>';
        if($query!=='')echo '<a class="qys-tenant-search-clear" href="'.esc_url(self::campus_url('members')).'">نمایش همه</a>';
        echo '</div><small class="qys-tenant-privacy-note">'.($sensitive?'به‌دلیل مجوز ویژه، جست‌وجوی کد ملی و موبایل برای این حساب فعال است.':'برای این حساب، جست‌وجوی کد ملی و موبایل غیرفعال است و مقادیر به‌صورت ماسک‌شده نمایش داده می‌شوند.').'</small></form></section>';
        if($transfer_rows){
            echo '<section class="qys-tenant-panel qys-tenant-transfer-panel"><div class="qys-tenant-panel-title"><div><h2>درخواست‌های انتقال به این دانشگاه</h2><p>تا زمان تأیید، دانشگاه فعلی عضو تغییر نمی‌کند. تأیید اینجا هم‌زمان انتقال و تأیید هویت در دانشگاه مقصد است.</p></div><strong>'.number_format_i18n(count($transfer_rows)).'</strong></div>';
            echo '<div class="qys-tenant-table-scroll qys-tenant-member-table"><table class="widefat striped"><thead><tr><th>نام</th><th>دانشگاه فعلی</th><th>گروه</th><th>انتساب خانوادگی</th><th>موبایل</th><th>کد ملی</th><th>عملیات</th></tr></thead><tbody>';
            foreach($transfer_rows as $m){$mobile=$sensitive?$m->mobile:('*******'.substr((string)$m->mobile,-4));$nid=$sensitive?$m->national_id:('******'.substr((string)$m->national_id,-4));echo '<tr><td data-label="نام"><strong>'.esc_html($m->first_name.' '.$m->last_name).'</strong></td><td data-label="دانشگاه فعلی">'.esc_html(self::university_label((string)$m->university)).'</td><td data-label="گروه">'.esc_html(QYS_Messenger::member_roles()[$m->member_role]??$m->member_role).'</td><td data-label="انتساب خانوادگی">'.esc_html((string)($family_summaries[(int)$m->id]??'—')).'</td><td data-label="موبایل">'.esc_html($mobile).'</td><td data-label="کد ملی">'.esc_html($nid).'</td><td data-label="عملیات">';if($verify){echo '<form method="post" onsubmit="return confirm(\'انتقال این عضو به دانشگاه شما تأیید شود؟ دانشگاه عملیاتی او تغییر می‌کند و پیام‌های معلق دامنه قبلی لغو می‌شوند.\')">';wp_nonce_field('qys_campus_transfer_approve');echo '<input type="hidden" name="qys_campus_transfer_approve" value="1"><input type="hidden" name="member_id" value="'.(int)$m->id.'"><button class="button button-primary">تأیید انتقال</button></form>';}else echo '<span class="qys-tenant-row-done">فقط مشاهده</span>';echo '</td></tr>';}
            echo '</tbody></table></div></section>';
        }
        echo '<div class="qys-tenant-table-scroll qys-tenant-member-table"><table class="widefat striped"><thead><tr><th>نام</th><th>گروه</th><th>انتساب خانوادگی</th><th>موبایل</th><th>کد ملی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach((array)$rows as $m){
            $mobile=$sensitive?$m->mobile:('*******'.substr((string)$m->mobile,-4));$nid=$sensitive?$m->national_id:('******'.substr((string)$m->national_id,-4));
            $is_verified=(int)$m->verified===1;$messaging_enabled=(int)$m->consent===1;
            echo '<tr class="'.($is_verified?'is-verified':'is-pending').(!$messaging_enabled?' is-messaging-stopped':'').'">'
                .'<td data-label="نام"><strong>'.esc_html($m->first_name.' '.$m->last_name).'</strong></td>'
                .'<td data-label="گروه">'.esc_html(QYS_Messenger::member_roles()[$m->member_role]??$m->member_role).'</td>'
                .'<td data-label="انتساب خانوادگی">'.esc_html((string)($family_summaries[(int)$m->id]??'—')).'</td>'
                .'<td data-label="موبایل">'.esc_html($mobile).'</td>'
                .'<td data-label="کد ملی">'.esc_html($nid).'</td>'
                .'<td data-label="وضعیت"><span class="qys-tenant-status-stack">'.QYS_Portal_Experience::status_chip($is_verified?'تأییدشده':'در انتظار',$is_verified?'good':'attention').QYS_Portal_Experience::status_chip($messaging_enabled?'پیام فعال':'پیام متوقف',$messaging_enabled?'info':'muted').'</span></td><td data-label="عملیات">';
            if($verify && !$is_verified){echo '<form method="post">';wp_nonce_field('qys_campus_verify');echo '<input type="hidden" name="qys_campus_verify" value="1"><input type="hidden" name="member_id" value="'.(int)$m->id.'"><button class="button button-primary">تأیید هویت</button></form>';}
            elseif($is_verified)echo '<span class="qys-tenant-row-done">نیاز به اقدام ندارد</span>';
            else echo '<span class="qys-tenant-row-done">فقط مشاهده</span>';
            echo '</td></tr>';
        }
        if(!$rows)echo '<tr class="qys-tenant-empty-row"><td colspan="7"><span class="qys-tenant-state-icon" aria-hidden="true">○</span><strong>'.($query!==''?'عضوی مطابق جست‌وجو یافت نشد':'هنوز عضوی در این دانشگاه ثبت نشده است').'</strong><span>'.($query!==''?'عبارت جست‌وجو را تغییر دهید یا فهرست کامل اعضا را ببینید.':'پس از عضویت اولین فرد، پرونده او در همین بخش نمایش داده می‌شود.').'</span>'.($query!==''?'<a href="'.esc_url(self::campus_url('members')).'">نمایش همه اعضا</a>':'').'</td></tr>';
        echo '</tbody></table></div></div>';
    }

}
