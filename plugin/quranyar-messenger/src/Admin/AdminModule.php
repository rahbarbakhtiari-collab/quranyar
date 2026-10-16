<?php
if (!defined('ABSPATH')) exit;

trait QYS_Admin_Module {
    static function admin_full_identifier($value){
      if(!QYS_Security_Service::is_global_admin())return '—';
      if(!is_string($value)&&!is_numeric($value))return '—';
      $text=trim((string)$value);
      if($text==='')return '—';
      return '<code dir="ltr" style="direction:ltr;unicode-bidi:isolate;white-space:nowrap;user-select:all">'.esc_html($text).'</code>';
    }

    static function enqueue_admin_ui($hook){
      $page=isset($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
      if(!in_array($page,['qys-messaging','qys-targeted','qys-birthday-archive','qys-health'],true))return;
      wp_enqueue_style('qys-fonts',plugins_url('assets/qys-fonts.css',self::PLUGIN_FILE),[],self::VERSION);
      wp_enqueue_style('qys-admin-ui',plugins_url('assets/qys-admin-ui.css',self::PLUGIN_FILE),['qys-fonts'],self::VERSION);
      wp_enqueue_script('qys-admin-ui',plugins_url('assets/qys-admin-ui.js',self::PLUGIN_FILE),[],self::VERSION,true);
    }

    static function menu(){
      // A true campus context never exposes the central administration menus.
      if(!QYS_Security_Service::is_global_admin() || !class_exists('QYS_Tenant_Context') || QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL)return;
      add_menu_page('مدیریت کل قرآن‌یار','قرآن‌یار','manage_options','qys-messaging',[__CLASS__,'admin'],'dashicons-email-alt2',26);
      // Give the parent page a normal submenu label instead of duplicating the product name.
      add_submenu_page('qys-messaging','مدیریت کل قرآن‌یار','نمای کلی','manage_options','qys-messaging',[__CLASS__,'admin'],1);
      add_submenu_page('qys-messaging','آرشیو تبریک تولد','آرشیو تبریک تولد','manage_options','qys-birthday-archive',[__CLASS__,'birthday_archive_admin'],50);
    }

    private static function admin_tab_url($tab,array $args=[]){
      $url=add_query_arg(['page'=>'qys-messaging','tab'=>$tab],admin_url('admin.php'));
      return $args?add_query_arg($args,$url):$url;
    }

    private static function admin_tabs($active){
      $tabs=['overview'=>'نمای کلی','members'=>'اعضا و پرونده‌ها','settings'=>'تنظیمات و اتصال'];
      echo '<nav class="qys-section-tabs qys-section-tabs--links" aria-label="بخش‌های مدیریت کل قرآن‌یار">';
      foreach($tabs as $id=>$label){
        $current=$active===$id?' is-active':'';
        echo '<a class="qys-section-tab'.$current.'" '.($active===$id?'aria-current="page" ':'').'href="'.esc_url(self::admin_tab_url($id)).'">'.esc_html($label).'</a>';
      }
      echo '</nav>';
    }

    private static function admin_member_filters($campus,$status,$query){
      echo '<form method="get" class="qys-admin-member-filters">';
      echo '<input type="hidden" name="page" value="qys-messaging"><input type="hidden" name="tab" value="members">';
      echo '<label>دانشگاه <select name="qys_university"><option value="">همه دانشگاه‌ها</option>';
      foreach(QYS_Multitenant::universities() as $u)echo '<option value="'.esc_attr($u['code']).'" '.selected($campus,$u['code'],false).'>'.esc_html($u['title']).'</option>';
      echo '</select></label>';
      $statuses=['all'=>'همه وضعیت‌ها','eligible'=>'آماده پیام','stopped'=>'دریافت پیام متوقف','pending'=>'در انتظار تأیید','verified'=>'هویت تأییدشده','transfer'=>'انتقال دانشگاه در انتظار'];
      echo '<label>وضعیت <select name="member_status">';
      foreach($statuses as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($status,$key,false).'>'.esc_html($label).'</option>';
      echo '</select></label>';
      echo '<label class="qys-admin-member-search">جست‌وجو <input type="search" name="member_search" value="'.esc_attr($query).'" placeholder="نام، کد ملی یا موبایل"></label>';
      echo '<button class="button button-primary" type="submit">اعمال فیلتر</button>';
      if($campus!==''||$status!=='all'||$query!=='')echo '<a class="button" href="'.esc_url(self::admin_tab_url('members')).'">پاک‌کردن فیلتر</a>';
      echo '</form>';
    }

    private static function admin_member_table($members){
      $family_summaries=QYS_Family_Relationship_Repository::summaries_for_members(array_map(static fn($m)=>(int)$m->id,(array)$members));
      echo '<div class="qys-table-scroll"><table class="widefat striped qys-global-members"><thead><tr><th>شناسه</th><th>عضو</th><th>دانشگاه</th><th>انتساب خانوادگی</th><th>کد ملی</th><th>موبایل</th><th>هویت</th><th>دریافت پیام</th><th>عملیات</th></tr></thead><tbody>';
      foreach($members as $m){
        $verified=(int)$m->verified===1;$enabled=(int)$m->consent===1;
        echo '<tr'.(!$enabled?' class="is-messaging-stopped"':'').'>';
        echo '<td><code>'.(int)$m->id.'</code></td>';
        echo '<td><strong>'.esc_html(trim($m->first_name.' '.$m->last_name)).'</strong></td>';
        $uni_text=self::university_name($m->university);if(!empty($m->pending_university))$uni_text.=' ← در انتظار '.self::university_name($m->pending_university);
        echo '<td>'.esc_html($uni_text).'</td>';
        echo '<td>'.esc_html((string)($family_summaries[(int)$m->id]??'—')).'</td>';
        echo '<td>'.self::admin_full_identifier((string)$m->national_id).'</td>';
        $otp_label=!empty($m->mobile_verified_at_utc)?'OTP تأییدشده':((int)($m->mobile_verification_required??0)===0?'ثبت قدیمی':'OTP لازم');
        echo '<td>'.self::admin_full_identifier((string)$m->mobile).'<br><small>'.esc_html($otp_label).'</small></td>';
        echo '<td><span class="qys-admin-status '.($verified?'is-good':'is-warn').'">'.($verified?'تأییدشده':'در انتظار').'</span></td>';
        echo '<td><span class="qys-admin-status '.($enabled?'is-good':'is-off').'">'.($enabled?'فعال':'متوقف').'</span></td>';
        echo '<td class="qys-admin-member-actions"><form method="post">';wp_nonce_field('qys_admin');
        echo '<input type="hidden" name="member_id" value="'.(int)$m->id.'">';
        if(!$verified)echo '<button class="button button-primary" name="qys_action" value="verify">تأیید هویت</button> ';
        if(!empty($m->pending_university))echo '<button class="button button-primary" name="qys_action" value="approve_transfer" onclick="return confirm(\'انتقال این عضو به دانشگاه مقصد تأیید شود؟\')">تأیید انتقال</button> ';
        if($enabled){
          echo '<button class="button" name="qys_action" value="revoke" onclick="return confirm(\'دریافت پیام این عضو در قرآن‌یار متوقف شود؟ پرونده او تا فعال‌سازی مجدد ناقص/غیرفعال تلقی می‌شود و پیام‌های معلق نیز لغو می‌شوند.\')">توقف دریافت پیام</button>';
        }else{
          echo '<button class="button button-primary" name="qys_action" value="resume" onclick="return confirm(\'دریافت پیام این عضو دوباره فعال شود؟ پیام‌های لغوشده گذشته خودکار برنمی‌گردند.\')">فعال‌سازی مجدد</button>';
        }
        echo '</form></td></tr>';
      }
      if(!$members)echo '<tr><td colspan="9">عضوی مطابق فیلتر فعلی یافت نشد.</td></tr>';
      echo '</tbody></table></div>';
    }

    static function admin(){
      if(!QYS_Security_Service::is_global_admin()||!class_exists('QYS_Tenant_Context')||QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL)wp_die('این صفحه فقط برای مدیر کل قرآن‌یار است.');
      global $wpdb;$notice='';$bot_result=null;
      $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'overview';
      if(!in_array($tab,['overview','members','settings'],true))$tab='overview';

      if(!empty($_POST['qys_action'])){
        check_admin_referer('qys_admin');$action=sanitize_key(wp_unslash($_POST['qys_action']));
        if(in_array($action,['save_webapp_token','clear_webapp_token','save_delivery_token','clear_delivery_token'],true))$notice=self::save_token_setting($action);
        elseif(in_array($action,['save_otp_settings','clear_ippanel_token'],true))$notice=QYS_Mobile_OTP::save_admin_settings($action);
        elseif($action==='save_birthday_settings')$notice=self::save_birthday_settings();
        elseif($action==='verify'){
          $id=absint($_POST['member_id']??0);$m=QYS_Member_Repository::find_by_id($id);
          if(!self::profile_complete($m))$notice='پرونده عضو کامل نیست.';
          else{$ok=QYS_Member_Repository::verify($id);if($ok===false)$notice='تأیید انجام نشد.';elseif((int)$ok!==1)$notice='این عضو قبلاً تأیید شده است.';else{$activation=self::activation_send_once($id);$notice='هویت عضو تأیید شد.'.self::activation_delivery_note($activation);}}
        }
        elseif($action==='approve_transfer'){$id=absint($_POST['member_id']??0);$m=QYS_Member_Repository::find_by_id($id);$dest=$m?sanitize_key((string)($m->pending_university??'')):'';$r=QYS_Multitenant::approve_member_transfer($id,$dest);$notice=is_wp_error($r)?$r->get_error_message():'انتقال عضو به «'.QYS_Multitenant::university_label((string)$r['to']).'» تأیید شد و پیام‌های معلق دامنه قبلی لغو شدند.';}
        elseif($action==='revoke'){$notice=self::stop_member(absint($_POST['member_id']??0))?'دریافت پیام این عضو متوقف شد؛ پرونده تا فعال‌سازی مجدد از حالت کامل خارج می‌شود و صف‌های معلق او لغو شدند.':'توقف انجام نشد.';}
        elseif($action==='resume'){$notice=self::resume_member(absint($_POST['member_id']??0))?'دریافت پیام این عضو دوباره فعال شد. پیام‌های لغوشده گذشته خودکار ارسال نمی‌شوند.':'فعال‌سازی مجدد انجام نشد.';}
        elseif($action==='check_bot'){$bot_result=self::check_bot();$notice='بررسی کلید اختیاری ایتایار انجام شد.';}
        elseif($action==='test_private'){$id=absint($_POST['target_member']??0);$m=QYS_Member_Repository::eligible_delivery_member($id);if(!$m)$notice='یک عضو تأییدشده و دارای دریافت پیام فعال انتخاب کنید.';else{$r=QYS_Queue_Service::deliver_claimed($m->eitaa_id,'سلام؛ این یک پیام آزمایشی از قرآن‌یار است.');$notice=$r['ok']?'API برنامک پیام آزمایشی را پذیرفت.':'ارسال ناموفق: '.self::safe_failure_label($r['reason']);}}
      }

      $r=self::readiness();
      echo '<div class="wrap qys-admin qys-admin--main" data-qys-server-tabs="1" dir="rtl">';
      echo '<h1>مدیریت کل قرآن‌یار</h1>';
      echo '<div class="qys-admin-intro"><span class="qys-admin-eyebrow">کنترل سراسری</span><p>مدیریت دانشگاه‌ها، ارسال‌ها و حضور در صفحات تخصصی خود انجام می‌شود؛ این صفحه فقط نمای کلی، پرونده‌های اعضا و تنظیمات مرکزی را نگه می‌دارد.</p></div>';
      if($notice)echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
      self::admin_tabs($tab);

      if($tab==='overview'){
        echo '<section class="qys-admin-panel"><h2>نمای کلی سامانه</h2>';
        echo '<div class="qys-kpi-grid qys-kpi-grid--four">';
        echo '<div class="qys-kpi"><span class="qys-kpi-caption">کل اعضا</span><strong>'.(int)$r['members_total'].'</strong></div>';
        echo '<div class="qys-kpi"><span class="qys-kpi-caption">هویت تأییدشده</span><strong>'.(int)$r['members_verified'].'</strong></div>';
        echo '<div class="qys-kpi"><span class="qys-kpi-caption">آماده دریافت پیام</span><strong>'.(int)$r['members_eligible'].'</strong></div>';
        echo '<div class="qys-kpi"><span class="qys-kpi-caption">دریافت پیام متوقف</span><strong>'.(int)$r['members_disabled'].'</strong></div>';
        echo '</div>';
        echo '<div class="qys-admin-quick-links"><a class="button button-primary" href="'.esc_url(self::admin_tab_url('members')).'">مدیریت اعضا</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=qys-universities')).'">دانشگاه‌ها و نمایندگان</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=qys-targeted')).'">ارسال سراسری/هدفمند</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=qys-attendance')).'">حضور و غیاب</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=qys-birthday-archive')).'">آرشیو تبریک تولد</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=qys-health')).'">سلامت سیستم</a></div>';
        if((int)$r['members_disabled']>0)echo '<p class="qys-section-note">'.(int)$r['members_disabled'].' عضو دریافت پیامشان متوقف است. برای بررسی یا فعال‌سازی مجدد به بخش «اعضا و پرونده‌ها» بروید.</p>';
        echo '</section>';
      }

      if($tab==='members'){
        $campus=sanitize_key(wp_unslash($_GET['qys_university']??''));if($campus!==''&&!QYS_Multitenant::university_active($campus))$campus='';
        $status=sanitize_key(wp_unslash($_GET['member_status']??'all'));if(!in_array($status,['all','eligible','stopped','pending','verified','transfer'],true))$status='all';
        $query=trim(sanitize_text_field(wp_unslash($_GET['member_search']??'')));
        if(self::chars($query)>80)$query=self::clip($query,80);
        $members=QYS_Member_Repository::admin_search($campus,$status,$query,200);
        echo '<section class="qys-admin-panel"><h2>اعضا و پرونده‌ها</h2><p>وضعیت هویت و مجوز دریافت پیام جدا نمایش داده می‌شود؛ اما مجوز پیام شرط فعال‌بودن و کامل‌بودن پرونده است. توقف مدیریتی آن قابل بازگشت است و فعال‌سازی مجدد، پیام‌های لغوشده قبلی را دوباره وارد صف نمی‌کند.</p>';
        self::admin_member_filters($campus,$status,$query);
        echo '<p class="qys-admin-result-count">'.number_format_i18n(count($members)).' عضو نمایش داده می‌شود.</p>';
        self::admin_member_table($members);
        echo '</section>';
      }

      if($tab==='settings'){
        echo '<section class="qys-admin-panel"><h2>تنظیمات درگاه و اتصال</h2>';
        $labels=['admin'=>'ذخیره رمزگذاری‌شده','config'=>'wp-config.php','unreadable'=>'غیرقابل بازیابی','none'=>'تنظیم نشده'];
        foreach(['webapp','delivery'] as $kind){
          $src=self::token_source($kind);$isweb=$kind==='webapp';$title=$isweb?'توکن اختصاصی برنامک ایتا':'کلید اختیاری EitaaYar برای بررسی اتصال';$name=$isweb?'qys_webapp_token':'qys_delivery_token';$save=$isweb?'save_webapp_token':'save_delivery_token';$clear=$isweb?'clear_webapp_token':'clear_delivery_token';
          echo '<form method="post" autocomplete="off">';wp_nonce_field('qys_admin');
          echo '<p><strong>'.esc_html($title).'</strong> — '.esc_html($labels[$src]??$src).'</p><input type="password" name="'.esc_attr($name).'" class="regular-text" maxlength="2048" autocomplete="new-password"> <button class="button button-primary" name="qys_action" value="'.esc_attr($save).'">ذخیره</button>';
          if($src==='admin'||$src==='unreadable')echo ' <button class="button" name="qys_action" value="'.esc_attr($clear).'" onclick="return confirm(\'حذف شود؟\')">حذف</button>';
          echo '</form>';
        }

        $otp=QYS_Mobile_OTP::settings();$otp_src=self::token_source('ippanel');
        echo '<hr><h3>تأیید شماره موبایل با OTP آی‌پی‌پنل</h3><p>برای ثبت‌نام جدید و تغییر شماره، مالکیت موبایل با کد یک‌بارمصرف پیامکی بررسی می‌شود. از یک الگوی تأییدشده IPPanel با متغیر کد استفاده کنید.</p>';
        echo '<form method="post" autocomplete="off">';wp_nonce_field('qys_admin');
        echo '<p><label><strong>کلید API IPPanel</strong> — '.esc_html($labels[$otp_src]??$otp_src).'<br><input type="password" name="qys_ippanel_token" class="regular-text" maxlength="2048" autocomplete="new-password" placeholder="برای حفظ کلید فعلی خالی بگذارید"></label></p>';
        echo '<p><label><strong>شماره ارسال‌کننده</strong><br><input type="text" name="qys_ippanel_sender" class="regular-text" dir="ltr" value="'.esc_attr($otp['sender']).'" placeholder="+983000505"></label></p>';
        echo '<p><label><strong>کد الگوی OTP</strong><br><input type="text" name="qys_ippanel_pattern_code" class="regular-text" dir="ltr" value="'.esc_attr($otp['pattern_code']).'"></label></p>';
        echo '<p><label><strong>نام متغیر کد در الگو</strong><br><input type="text" name="qys_ippanel_pattern_param" class="regular-text" dir="ltr" value="'.esc_attr($otp['param']).'" placeholder="code"></label></p>';
        echo '<p><button class="button button-primary" name="qys_action" value="save_otp_settings">ذخیره تنظیمات OTP</button>';
        if($otp_src==='admin'||$otp_src==='unreadable')echo ' <button class="button" name="qys_action" value="clear_ippanel_token" onclick="return confirm(\'کلید API آی‌پی‌پنل حذف شود؟\')">حذف کلید API</button>';
        echo '</p></form>';

        echo '<form method="post">';wp_nonce_field('qys_admin');echo '<p><button class="button" name="qys_action" value="check_bot">بررسی اتصال EitaaYar</button></p></form>';
        if(is_array($bot_result))echo '<p>نتیجه: '.esc_html($bot_result['result']).' / HTTP '.(int)$bot_result['http_status'].'</p>';
        echo '</section>';
        self::birthday_admin_panel();
        echo '<section class="qys-admin-panel"><h2>آزمایش ارسال خصوصی</h2><p>فقط برای عیب‌یابی درگاه استفاده شود. شناسه داخلی عضو را از جدول اعضا انتخاب کنید.</p><form method="post">';wp_nonce_field('qys_admin');echo '<input type="number" min="1" name="target_member" placeholder="شناسه داخلی عضو"> <button class="button" name="qys_action" value="test_private">ارسال یک پیام آزمایشی</button></form></section>';
      }
      echo '</div>';
    }
}
