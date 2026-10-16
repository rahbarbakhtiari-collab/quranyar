<?php
/**
 * QuranYar targeted immediate/scheduled private messaging.
 * Recipient sets and rendered messages are snapshotted into the EXISTING queue.
 * Existing file-based campaigns and the birthday worker are not changed.
 */
if (!defined('ABSPATH')) exit;

final class QYS_Targeted {
    const MAX_RECIPIENTS = 5000;
    const MAX_SELECTED = 500;
    private static $plugin_file = '';
    private static $admin_hook = '';

    public static function table() { return QYS_Campaign_Repository::targeted_meta_table(); }
    public static function init($plugin_file) {
        self::$plugin_file = $plugin_file;
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'portal_assets']);
        add_action('wp_ajax_qys_target_member_search', [__CLASS__, 'member_search']);
        // This runs before the pre-existing queue worker (priority 10).
        add_action('qys_send_batch', [__CLASS__, 'activate_due'], 1);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_ability']);
    }

    public static function schema_ready() {
        return QYS_Campaign_Repository::targeted_meta_ready();
    }

    /** Maintenance RC46: compact preview + safe edit of an approved message before first delivery. */
    public static function campaign_edit_contract(): array {
        return [
            'ready'=>true,
            'preview_rows'=>1,
            'editable'=>'draft_scheduled_active_before_delivery',
            'queue'=>'pending_only',
            'payload'=>'rebuild_transactional',
            'worker'=>'reload_after_claim',
            'editor'=>'collapsed_disclosure',
        ];
    }
    public static function portal_assets(): void {
        if(!class_exists('QYS_Portal_Asset_Policy',false) || !QYS_Portal_Asset_Policy::should_load('targeted'))return;
        $base=self::$plugin_file!==''?self::$plugin_file:QYS_Config::plugin_file();
        wp_enqueue_script('qys-targeted',plugins_url('assets/targeted-admin.js',$base),['qys-university-portal'],QYS_Config::VERSION,true);
        $uni=QYS_Tenant_Context::campus();
        wp_localize_script('qys-targeted','QYSTarget',[
            'url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('qys_target_search_portal|'.$uni),
            'portal'=>true,'university'=>$uni
        ]);
    }

    public static function ensure_schema(){global $wpdb;
     require_once ABSPATH.'wp-admin/includes/upgrade.php';$table=self::table();$c=$wpdb->get_charset_collate();
     dbDelta("CREATE TABLE $table (
     campaign_id bigint unsigned NOT NULL,
     request_key varchar(40) NOT NULL,
     audience varchar(24) NOT NULL,
     audience_groups varchar(255) NOT NULL DEFAULT '',
     audience_universities varchar(2048) NOT NULL DEFAULT '',
     manager_university varchar(40) NOT NULL DEFAULT '',
     scheduled_at_utc datetime DEFAULT NULL,
     scheduled_jalali varchar(10) NOT NULL DEFAULT '',
     scheduled_hm varchar(5) NOT NULL DEFAULT '',
     created_by bigint unsigned NOT NULL,
     created_at_utc datetime NOT NULL,
     launched_at_utc datetime DEFAULT NULL,
     cancelled_at_utc datetime DEFAULT NULL,
     PRIMARY KEY  (campaign_id), UNIQUE KEY request_key (request_key), KEY due (scheduled_at_utc)
     ) $c;");
     return QYS_Campaign_Repository::diagnostic_targeted_meta_ready();
    }

    /** Strict Jalali YYYY/MM/DD + hour/minute. Times are interpreted as Asia/Tehran. */
    public static function parse_schedule($day, $hour, $minute, $now = null) {
        $day = trim(QYS_Text_Normalizer::digits((string)$day));
        $hour = QYS_Text_Normalizer::digits((string)$hour);
        $minute = QYS_Text_Normalizer::digits((string)$minute);
        if (!preg_match('~\A(1[34][0-9]{2}|15[0-9]{2})/(0?[1-9]|1[0-2])/(0?[1-9]|[12][0-9]|3[01])\z~D', $day, $m)) return null;
        if (!preg_match('/\A(?:[01]?[0-9]|2[0-3])\z/D', $hour) || !preg_match('/\A(?:[0-5]?[0-9])\z/D', $minute)) return null;
        $jy = (int)$m[1]; $jm = (int)$m[2]; $jd = (int)$m[3];
        $max_day = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : (QYS_Calendar_Service::jal_cal($jy)['leap'] === 0 ? 30 : 29));
        if ($jd > $max_day) return null;
        $jdn = QYS_Calendar_Service::jalali_jdn($jy, $jm, $jd);
        $gregorian = gmdate('Y-m-d', ($jdn - 2440588) * 86400);
        $wall = sprintf('%s %02d:%02d:00', $gregorian, (int)$hour, (int)$minute);
        $tehran = new DateTimeZone('Asia/Tehran');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, $tehran);
        if (!$date || $date->format('Y-m-d H:i:s') !== $wall) return null;
        if ($date->getTimestamp() <= ($now ?? time())) return null;
        return [
            'utc' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'jalali' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd),
            'hm' => sprintf('%02d:%02d', (int)$hour, (int)$minute),
            'timestamp' => $date->getTimestamp(),
        ];
    }

    public static function menu() {
        // WordPress derives the hook suffix from the translated parent menu title.
        // It is NOT reliably 'qys-messaging_page_qys-targeted'.
        if(QYS_Permission_Service::scope('send_messages')===null)return;
        if(QYS_Permission_Service::scope('send_messages')==='*')self::$admin_hook = add_submenu_page('qys-messaging','پیام فوری و زمان‌بندی‌شده','ارسال سراسری/هدفمند','manage_options','qys-targeted',[__CLASS__,'admin'],20);
        else self::$admin_hook = add_submenu_page('qys-campus','پیام فوری و زمان‌بندی‌شده','ارسال هدفمند','read','qys-targeted',[__CLASS__,'admin']);
    }
    public static function enqueue($hook) {
        if (!$hook || $hook !== self::$admin_hook) return;
        wp_enqueue_script('qys-targeted', plugins_url('assets/targeted-admin.js', self::$plugin_file), [], QYS_Config::VERSION, true);
        wp_localize_script('qys-targeted', 'QYSTarget', ['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('qys_target_search_global'),'portal'=>false,'university'=>'']);
    }

    /** POST only, limited disclosure, never emit full identity identifiers to browser suggestions. */
    public static function member_search() {
        // The same WordPress account may be both global owner and a campus
        // representative. Bind the AJAX nonce to the intended context so a
        // portal request cannot become global merely by dropping/tampering the
        // hidden campus fields.
        $portal_flag=sanitize_key(wp_unslash($_POST['qys_portal_scope']??''));
        if($portal_flag==='1'){
            $submitted=sanitize_key(wp_unslash($_POST['qys_portal_university']??''));
            $campus=QYS_Multitenant::campus_university();
            if($campus===''||$submitted===''||!hash_equals($campus,$submitted))wp_send_json_error('دامنه دانشگاه معتبر نیست.',403);
            check_ajax_referer('qys_target_search_portal|'.$campus,'nonce');
            QYS_Tenant_Context::reset();
            if(QYS_Tenant_Context::campus()!==$campus||QYS_Permission_Service::scope('send_messages')!==$campus)wp_send_json_error('دسترسی مجاز نیست.',403);
        }else{
            check_ajax_referer('qys_target_search_global','nonce');
            QYS_Tenant_Context::reset();
            if(!QYS_Security_Service::is_global_admin()||QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL)wp_send_json_error('دسترسی مجاز نیست.',403);
        }
        nocache_headers();
        $raw = wp_unslash($_POST['search'] ?? '');
        if (!is_string($raw)) wp_send_json_error('عبارت نامعتبر است.',400);
        $query = trim(sanitize_text_field($raw));
        if (QYS_Text_Normalizer::chars($query) < 2 || QYS_Text_Normalizer::chars($query) > 80) wp_send_json_success([]);
        $uni_raw=wp_unslash($_POST['university_codes']??[]);
        $university_mode=sanitize_key(wp_unslash($_POST['university_mode']??'all'));
        $allowed=QYS_Permission_Service::universities('send_messages',$university_mode==='selected'?$uni_raw:'all');
        if(!$allowed)wp_send_json_success([]);
        $sensitive_scope=QYS_Permission_Service::scope('sensitive_identity');
        $allow_sensitive=$sensitive_scope==='*' || (is_string($sensitive_scope) && count($allowed)===1 && $allowed[0]===$sensitive_scope);
        $rows=QYS_Member_Repository::targeted_search($query,$allowed,12,$allow_sensitive);
        $out = [];
        foreach ($rows as $m) {
            $name = trim((string)$m->first_name . ' ' . (string)$m->last_name);
            $role = QYS_Messenger::member_roles()[$m->member_role] ?? 'عضو';
            $campus=' | '.QYS_Multitenant::university_label($m->university);
            $phone = $allow_sensitive && !empty($m->mobile) ? ' | موبایل: ****' . substr($m->mobile,-4) : '';
            $out[] = ['id'=>(int)$m->id, 'label'=>$name . ' (' . $role . ')' . $campus . $phone];
        }
        wp_send_json_success($out);
    }

    /** Only current, server-defined role keys are accepted; preserve their canonical order. */
    public static function normalize_groups($raw) {
        if (!is_array($raw) || !$raw) return null;
        $valid = QYS_Messenger::member_roles();
        $found = [];
        foreach ($raw as $value) {
            if (!is_string($value) || !isset($valid[$value])) return null;
            $found[$value] = true;
        }
        $groups = array_values(array_filter(array_keys($valid), static function($key) use ($found) { return isset($found[$key]); }));
        if (!$groups || strlen(implode(',', $groups)) > 255) return null;
        return $groups;
    }

    public static function target_ids($audience, $chosen = [], $role_groups = [], $allowed_universities = null) {
        if($allowed_universities===null)$allowed_universities=QYS_Permission_Service::universities('send_messages');
        if(!$allowed_universities||!is_array($allowed_universities))return null;
        if($audience==='selected' && (!is_array($chosen)||!$chosen||count($chosen)>self::MAX_SELECTED))return null;
        $groups=$audience==='groups'?self::normalize_groups($role_groups):$role_groups;
        if($audience==='groups'&&$groups===null)return null;
        return QYS_Member_Repository::targeted_members($audience,is_array($chosen)?$chosen:[],is_array($groups)?$groups:[],$allowed_universities,self::MAX_SELECTED,self::MAX_RECIPIENTS);
    }

    /** Preview creates only DRAFT rows; activation always requires an explicit second POST. */
    public static function create($raw) {
        if (!self::schema_ready() && !self::ensure_schema()) return 'جدول زمان‌بندی آماده نیست؛ پیش‌نویس ایجاد نشد.';
        $label = QYS_Text_Normalizer::clip(sanitize_text_field($raw['label'] ?? ''),190);
        $template = sanitize_textarea_field($raw['template'] ?? '');
        $audience = sanitize_key($raw['audience'] ?? '');
        $request_key = sanitize_text_field($raw['request_key'] ?? '');
        $mode = sanitize_key($raw['mode'] ?? '');
        if (!$label || !$template || QYS_Text_Normalizer::chars($template)>3500) return 'عنوان یا متن پیام نامعتبر است.';
        if ($audience!=='all' && $audience!=='selected' && $audience!=='groups' && !isset(QYS_Messenger::member_roles()[$audience])) return 'گروه گیرندگان نامعتبر است.';
        $groups = $audience==='groups' ? self::normalize_groups($raw['role_groups']??[]) : [];
        if ($groups===null) return 'حداقل یک گروه معتبر را انتخاب کنید؛ گزینه‌های نامعتبر پذیرفته نمی‌شوند.';
        if (!in_array($mode,['immediate','scheduled'],true)) return 'نوع زمان‌بندی نامعتبر است.';
        if (!preg_match('/\A[a-f0-9\-]{36}\z/D',$request_key)) return 'شناسه درخواست نامعتبر است؛ صفحه را دوباره باز کنید.';
        $schedule = null;
        if ($mode==='scheduled') {
            $schedule=self::parse_schedule($raw['send_day'] ?? '', $raw['send_hour'] ?? '', $raw['send_minute'] ?? '');
            if (!$schedule) return 'تاریخ شمسی یا ساعت نامعتبر/گذشته است؛ یک زمان آینده انتخاب کنید.';
        }
        $scope=QYS_Permission_Service::scope('send_messages');
        if($scope===null)return 'برای این دانشگاه مجوز ارسال پیام ندارید.';
        $mode_universities=sanitize_key($raw['university_mode']??'all');
        if(!in_array($mode_universities,['all','selected'],true))return 'روش انتخاب دانشگاه‌ها معتبر نیست.';
        $selected_universities=$mode_universities==='selected'?($raw['university_codes']??[]):'all';
        $campuses=QYS_Permission_Service::universities('send_messages',$selected_universities);
        if(!$campuses)return 'هیچ دانشگاه فعال و مجازی انتخاب نشده است.';
        $targets=self::target_ids($audience,$raw['selected_ids']??[],$groups,$campuses);
        if ($targets===null) return 'گروه یا انتخاب مخاطبان نامعتبر است؛ همه افراد باید تأیید و دارای مجوز پیام باشند.';
        if (!$targets) return 'هیچ مخاطب واجد شرایطی در گروه انتخاب‌شده وجود ندارد.';
        $messages=[];
        foreach ($targets as $m) {
            $msg=QYS_Text_Normalizer::render_template($template, ['نام'=>$m->first_name,'نام خانوادگی'=>$m->last_name]);
            if ($msg===null) return 'متغیر ناشناخته یا متن بیش از حد طولانی است؛ پیش‌نویس ساخته نشد.';
            $messages[]=['id'=>(int)$m->id,'text'=>$msg];
        }
        if (!QYS_Campaign_Repository::begin()) return 'امکان شروع تراکنش وجود ندارد؛ پیش‌نویس ساخته نشد.';
        $ok=false;$cid=0;
        try {
            $cid=QYS_Campaign_Repository::create_draft($label,$template);
            if($cid===false)throw new RuntimeException('campaign');
            $meta=[
                'campaign_id'=>$cid,'request_key'=>$request_key,'audience'=>$audience,
                'audience_groups'=>implode(',', $groups),
                'audience_universities'=>implode(',',$campuses),
                'manager_university'=>$scope==='*'?'':$scope,
                'scheduled_at_utc'=>$schedule['utc']??null,
                'scheduled_jalali'=>$schedule['jalali']??'',
                'scheduled_hm'=>$schedule['hm']??'',
                'created_by'=>get_current_user_id(),'created_at_utc'=>gmdate('Y-m-d H:i:s')];
            if (!QYS_Campaign_Repository::insert_targeted_meta($meta)) throw new RuntimeException('meta');
            if(!QYS_Campaign_Repository::enqueue_many((int)$cid,$messages))throw new RuntimeException('queue');
            if (!QYS_Campaign_Repository::commit()) throw new RuntimeException('commit');
            $ok=true;
        } catch (Throwable $e) {
            QYS_Campaign_Repository::rollback();
        }
        if (!$ok) return 'ثبت پیش‌نویس ناموفق بود یا درخواست تکراری بود؛ هیچ ارسالی فعال نشد.';
        return sprintf('پیش‌نویس شماره %d با %d گیرنده ساخته شد. متن هر گیرنده در صف ثبت شده است؛ برای ارسال، پیش‌نمایش را بررسی و تأیید کنید.', $cid,count($messages));
    }

    /**
     * Edit a human-created campaign even after approval, but only while every
     * recipient row is still pending. Queue rows and the campaign are locked
     * together; the worker re-reads payload after claim, so no stale text can
     * cross the network during an edit race.
     */
    public static function edit($cid,$raw) {
        $cid=absint($cid);
        if(!$cid||!QYS_Permission_Service::campaign_visible($cid))return 'این کارزار در اختیار شما نیست.';
        $label=QYS_Text_Normalizer::clip(sanitize_text_field($raw['label']??''),190);
        $template=sanitize_textarea_field($raw['template']??'');
        if($label===''||$template===''||QYS_Text_Normalizer::chars($template)>3500)return 'عنوان یا متن پیام نامعتبر است.';
        if(!QYS_Campaign_Repository::begin())return 'ویرایش پیام آغاز نشد.';
        try{
            $locked=QYS_Campaign_Repository::lock_targeted_edit($cid,self::table());
            if(!$locked){QYS_Campaign_Repository::rollback();return 'پیام پیدا نشد.';}
            $campaign=$locked['campaign'];$rows=(array)$locked['rows'];
            if((string)($campaign->audience??'')==='program_status'){
                QYS_Campaign_Repository::rollback();return 'این پیام سیستمی از صفحه ارسال هدفمند قابل ویرایش نیست.';
            }
            if(!in_array((string)$campaign->state,['draft','scheduled','active'],true)){
                QYS_Campaign_Repository::rollback();return 'این پیام دیگر در وضعیت قابل ویرایش نیست.';
            }
            if(!$rows){QYS_Campaign_Repository::rollback();return 'این پیام گیرنده‌ای در صف ندارد.';}
            foreach($rows as $row){
                if((string)$row->status!==QYS_Delivery_State::PENDING){
                    QYS_Campaign_Repository::rollback();
                    return 'ارسال این پیام آغاز شده است؛ برای جلوگیری از دریافت متن‌های متفاوت، ویرایش قفل شد.';
                }
            }
            $ids=array_map(static fn($r)=>(int)$r->member_id,$rows);
            $names=QYS_Member_Repository::names_by_ids($ids);
            $payloads=[];
            foreach($rows as $row){
                $person=$names[(int)$row->member_id]??null;
                if(!$person){QYS_Campaign_Repository::rollback();return 'اطلاعات یکی از گیرندگان برای بازسازی متن در دسترس نیست.';}
                $rendered=QYS_Text_Normalizer::render_template($template,[
                    'نام'=>(string)$person->first_name,
                    'نام خانوادگی'=>(string)$person->last_name,
                ]);
                if($rendered===null){QYS_Campaign_Repository::rollback();return 'متغیر ناشناخته یا متن بیش از حد طولانی است؛ ویرایش ذخیره نشد.';}
                $payloads[(int)$row->id]=$rendered;
            }
            if(!QYS_Campaign_Repository::update_campaign_copy($cid,$label,$template)){
                QYS_Campaign_Repository::rollback();return 'ویرایش متن پیام ذخیره نشد.';
            }
            foreach($payloads as $queue_id=>$payload){
                if(!QYS_Campaign_Repository::update_pending_payload((int)$queue_id,$cid,$payload)){
                    QYS_Campaign_Repository::rollback();return 'بازسازی متن گیرندگان کامل نشد؛ هیچ تغییری ذخیره نشد.';
                }
            }
            if(!QYS_Campaign_Repository::commit()){QYS_Campaign_Repository::rollback();return 'ثبت نهایی ویرایش انجام نشد.';}
            return (string)$campaign->state==='draft'
                ? 'پیش‌نویس و پیش‌نمایش به‌روزرسانی شد.'
                : 'متن پیام تأییدشده به‌روزرسانی شد؛ همه گیرندگانِ هنوز ارسال‌نشده متن جدید را دریافت خواهند کرد.';
        }catch(Throwable $e){
            QYS_Campaign_Repository::rollback();
            return 'ویرایش پیام به‌صورت امن انجام نشد.';
        }
    }

    public static function launch($cid) {
        if(!QYS_Permission_Service::campaign_visible($cid))return 'این کارزار در اختیار شما نیست.';
        if (!self::schema_ready()) return 'جدول زمان‌بندی آماده نیست.';
        $row=QYS_Campaign_Repository::targeted_state((int)$cid,self::table());
        if (!$row || $row->state!=='draft') return 'فقط پیش‌نویس ارسال‌نشده قابل فعال‌سازی است.';
        if (!QYS_Campaign_Repository::pending_count((int)$cid)) return 'این پیش‌نویس گیرنده‌ای در صف ندارد.';
        $scheduled=!empty($row->scheduled_at_utc);
        if ($scheduled && $row->scheduled_at_utc<=gmdate('Y-m-d H:i:s')) return 'زمان انتخاب‌شده سپری شده است. پیام را با زمان آینده در پیش‌نویس جدید ثبت کنید.';
        if (!QYS_Campaign_Repository::begin()) return 'خطای ثبت فعال‌سازی.';
        $changed=QYS_Campaign_Repository::set_state((int)$cid,'draft',$scheduled?'scheduled':'active');
        if ($changed!==1) { QYS_Campaign_Repository::rollback();return 'پیش‌نویس قبلاً فعال یا لغو شده است.'; }
        if (!$scheduled) {
            $saved=QYS_Campaign_Repository::mark_targeted_launched((int)$cid,gmdate('Y-m-d H:i:s'));
            if (!$saved){QYS_Campaign_Repository::rollback();return 'ثبت زمان فعال‌سازی ناموفق بود.';}
        }
        if (!QYS_Campaign_Repository::commit()) { QYS_Campaign_Repository::rollback();return 'فعال‌سازی تأیید نشد.'; }
        return $scheduled?'پیام زمان‌بندی شد؛ در موعد انتخاب‌شده به صف ارسال فعال منتقل می‌شود.':'پیام به صف ارسال فوری منتقل شد؛ اجرای زمان‌بند ممکن است تا حدود یک دقیقه تأخیر داشته باشد.';
    }

    /** Allowed ONLY while draft/scheduled; cannot revoke a campaign already being delivered. */
    public static function cancel($cid) {
        if(!QYS_Permission_Service::campaign_visible($cid))return 'این کارزار در اختیار شما نیست.';
        if (!QYS_Campaign_Repository::begin()) return 'لغو ثبت نشد.';
        if(!QYS_Campaign_Repository::cancel_if_prelaunch((int)$cid)){QYS_Campaign_Repository::rollback();return 'لغو فقط پیش از آغاز ارسال امکان‌پذیر است.';}
        $q=QYS_Campaign_Repository::cancel_pending_for_campaign((int)$cid);
        $m=QYS_Campaign_Repository::mark_targeted_cancelled((int)$cid,gmdate('Y-m-d H:i:s'));
        if ($q===false||!$m||!QYS_Campaign_Repository::commit()) {QYS_Campaign_Repository::rollback();return 'لغو ثبت نشد؛ وضعیت کارزار را دوباره بررسی کنید.';}
        return 'کارزار لغو شد؛ هیچ پیام در صف آن ارسال نخواهد شد.';
    }

    /** Runs at 60-second cron. The normal existing send_batch processes activated rows. */
    public static function activate_due() {
        if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::worker_allowed())return;
        if (!self::schema_ready()) return;
        $now=gmdate('Y-m-d H:i:s');
        // Meta owns schedule timestamps; all persistence crosses the repository boundary.
        $due=QYS_Campaign_Repository::due_targeted_campaign_ids($now,20);
        foreach ($due as $cid) {
            $cid=(int)$cid;
            if(QYS_Campaign_Repository::state($cid)!=='scheduled')continue;
            if(!QYS_Campaign_Repository::begin())continue;
            $updated=QYS_Campaign_Repository::set_state($cid,'scheduled','active');
            if($updated!==1){QYS_Campaign_Repository::rollback();continue;}
            $marked=QYS_Campaign_Repository::mark_targeted_launched($cid,$now);
            if(!$marked||!QYS_Campaign_Repository::commit())QYS_Campaign_Repository::rollback();
        }
    }

    public static function readiness() {
        $ready=self::schema_ready();$out=['ready'=>$ready,'scheduled'=>0,'draft'=>0,'active'=>0,'cancelled'=>0,'due_now'=>0,'recipient_queue_pending'=>0];
        if (!$ready) return $out;
        $scope=QYS_Permission_Service::scope('send_messages');
        if($scope===null)return $out;
        $manager=$scope==='*'?'':$scope;
        return ['ready'=>true]+QYS_Campaign_Repository::targeted_readiness(self::table(),$manager);
    }

    public static function register_ability() {
        if (!function_exists('wp_register_ability')) return;
        wp_register_ability('qys-messenger/targeted-readiness',[
            'label'=>'Targeted schedule readiness',
            'description'=>'Read-only aggregate targeted campaign and due-job status; no PII, tokens or message bodies.',
            'category'=>'qys-messenger',
            'permission_callback'=>static function(){return QYS_Security_Service::is_global_admin();},
            'meta'=>['public'=>true,'show_in_rest'=>true,'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
            'output_schema'=>['type'=>'object','properties'=>[
                'ready'=>['type'=>'boolean'],
                'scheduled'=>['type'=>'integer'],'draft'=>['type'=>'integer'],
                'active'=>['type'=>'integer'],'cancelled'=>['type'=>'integer'],
                'due_now'=>['type'=>'integer'],'recipient_queue_pending'=>['type'=>'integer']],
                'additionalProperties'=>false],
            'execute_callback'=>[__CLASS__,'readiness'],
        ]);
    }

    /** This page is wp-admin ONLY. Never direct Eitaa members to the technical website. */
    public static function admin() {
        if (QYS_Permission_Service::scope('send_messages')===null) wp_die('دسترسی مجاز نیست.');
        nocache_headers();
        $notice='';$detail_id=0;
        if (!self::schema_ready() && !self::ensure_schema()) $notice='جدول زمان‌بندی آماده نیست؛ از پایگاه داده نسخه پشتیبان تهیه کنید و مجوز ایجاد جدول را بررسی کنید.';
        if (!empty($_POST['qys_target_action'])) {
            check_admin_referer('qys_target_admin');
            $action=sanitize_key(wp_unslash($_POST['qys_target_action']));
            if ($action==='create') {
                $notice=self::create(wp_unslash($_POST));
            } elseif(in_array($action,['launch','cancel','view','edit'],true)) {
                $id=absint($_POST['campaign_id']??0);
                if ($action==='launch') $notice=self::launch($id);
                if ($action==='cancel') $notice=self::cancel($id);
                if ($action==='edit') {$notice=self::edit($id,wp_unslash($_POST));$detail_id=$id;}
                if ($action==='view') {if(QYS_Permission_Service::campaign_visible($id)){$detail_id=$id;}else $notice='مشاهده این کارزار مجاز نیست.';}
            }
        }
        $scope=QYS_Permission_Service::scope('send_messages');
        $portal=QYS_Multitenant::is_portal_request() && $scope!=='*';
        echo '<div class="wrap qys-admin qys-admin--targeted'.($portal?' qys-targeted-portal qys-tenant-screen':'').'" dir="rtl">';
        if($portal){
            $stats=QYS_Member_Repository::university_counts((string)$scope);
            echo QYS_Portal_Experience::section_head('پیام‌رسانی دانشگاه','ارسال پیام','پیام را برای اعضای واجد شرایط همین دانشگاه بسازید؛ ابتدا پیش‌نویس ایجاد می‌شود و ارسال فقط پس از تأیید شما آغاز خواهد شد.',number_format_i18n((int)($stats['eligible']??0)).' گیرنده آماده');
            echo '<div class="qys-targeted-safety-note"><span aria-hidden="true">✓</span><div><strong>ارسال کنترل‌شده و خصوصی</strong><p>فقط اعضای تأییدشده و دارای رضایت دریافت پیام وارد صف می‌شوند؛ هر کارزار پیش از فعال‌سازی قابل بازبینی است.</p></div></div>';
            echo '<div class="qys-targeted-quick-action"><div><strong>ارسال از فایل Excel / CSV</strong><span>برای فهرست آماده یا پیام‌های شخصی‌سازی‌شده از فایل استفاده کنید.</span></div><a class="qys-tenant-btn qys-tenant-btn--secondary" href="'.esc_url(QYS_Multitenant::campus_url('excel')).'">باز کردن ارسال از فایل</a></div>';
        }else{
            echo '<h1>ارسال هدفمند قرآن‌یار</h1><p>پیام فقط به افراد تأییدشده‌ای که دریافت پیام را فعال نگه داشته‌اند، از طریق درگاه خصوصی برنامک ایتا ارسال می‌شود. هیچ کاربری برای ارسال یا دریافت به وب‌سایت هدایت نمی‌شود.</p>';
        }
        if ($notice) echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        if (!self::schema_ready()) {echo '<div class="notice notice-error"><p>ایجاد پیام تا تکمیل جدول جدید متوقف است.</p></div></div>';return;}
        $ready=self::readiness();
        if($portal){
            echo '<div class="qys-tenant-metric-grid qys-tenant-metric-grid--compact qys-targeted-metrics">'
                .QYS_Portal_Experience::metric('پیش‌نویس',(int)$ready['draft'],(int)$ready['draft']>0?'attention':'muted')
                .QYS_Portal_Experience::metric('زمان‌بندی‌شده',(int)$ready['scheduled'],(int)$ready['scheduled']>0?'info':'muted')
                .QYS_Portal_Experience::metric('در حال ارسال',(int)$ready['active'],(int)$ready['active']>0?'good':'muted')
                .QYS_Portal_Experience::metric('گیرنده آماده',(int)($stats['eligible']??0),'good').'</div>';
            echo '<section class="qys-targeted-compose qys-tenant-panel"><div class="qys-targeted-card-head"><div><span>پیام جدید</span><h2>ساخت پیش‌نویس</h2><p>مخاطبان، متن و زمان ارسال را مشخص کنید. در پایان فقط پیش‌نویس ساخته می‌شود.</p></div><strong>۳ گام کوتاه</strong></div>';
        }else{
            echo '<p><strong>پیش‌نویس:</strong> '.(int)$ready['draft'].' | <strong>زمان‌بندی‌شده:</strong> '.(int)$ready['scheduled'].' | <strong>فعال:</strong> '.(int)$ready['active'].'</p><hr><h2>ساخت پیام و پیش‌نمایش گیرندگان</h2>';
        }
        echo '<form id="qys-targeted-create"'.($portal?' class="qys-targeted-form"':'').' method="post" autocomplete="off">';
        if($portal)echo '<div class="qys-targeted-scope"><span>دامنه ارسال</span><strong>'.esc_html(QYS_Multitenant::university_label((string)$scope)).'</strong><small>مخاطبان سایر دانشگاه‌ها در دسترس این پنل نیستند.</small></div>';
        elseif($scope==='*')echo '<p><strong>محیط مدیر کل:</strong> ارسال به همه دانشگاه‌ها یا چند دانشگاه دلخواه. اعضای تکراری فقط یک بار در صف ثبت می‌شوند.</p>';
        else echo '<p><strong>دانشگاه شما:</strong> '.esc_html(QYS_Multitenant::university_label($scope)).'؛ مخاطبان دیگر دانشگاه‌ها در دسترس نیستند.</p>';
        wp_nonce_field('qys_target_admin');
        echo '<input type="hidden" name="qys_target_action" value="create"><input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'">';
        if($portal)echo '<section class="qys-targeted-step"><div class="qys-targeted-step-head"><span>۱</span><div><h3>مخاطبان</h3><p>برای شناسایی این ارسال یک عنوان داخلی بنویسید و گروه گیرندگان را مشخص کنید.</p></div></div>';
        echo '<p class="qys-targeted-field"><label>عنوان داخلی پیام<br><input name="label" class="regular-text" required maxlength="190" placeholder="مثلاً یادآوری ثبت‌نام جشنواره"></label></p>';
        if($scope==='*'){
            echo '<fieldset id="qys-target-universities" style="background:#fff;border:1px solid #dcdcde;padding:14px;margin:12px 0;max-width:760px"><legend><strong>دانشگاه‌های گیرنده</strong></legend>';
            echo '<label><input type="radio" name="university_mode" value="all" checked> تمام دانشگاه‌های فعال</label> &nbsp; ';
            echo '<label><input type="radio" name="university_mode" value="selected"> چند دانشگاه انتخابی</label><div style="margin-top:10px">';
            foreach(QYS_Multitenant::universities() as $u)echo '<label style="display:inline-block;margin:6px 12px"><input type="checkbox" name="university_codes[]" value="'.esc_attr($u['code']).'"> '.esc_html($u['title']).'</label>';
            echo '</div></fieldset>';
        }else echo '<input type="hidden" name="university_mode" value="all">';
        echo '<p><label>گروه مخاطبان<br><select name="audience" id="qys-target-audience">';
        echo '<option value="all">تمام اعضای تأییدشده و دارای مجوز</option>';
        foreach (QYS_Messenger::member_roles() as $key=>$label) echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
        echo '<option value="groups">انتخاب هم‌زمان چند گروه</option>';
        echo '<option value="selected">انتخاب دستی چند نفر</option></select></label></p>';
        // Always visible without JS; script hides it only when another audience is selected.
        echo '<fieldset id="qys-target-groups" style="display:block;max-width:600px;background:#fff;border:1px solid #dcdcde;padding:15px;margin:8px 0">';
        echo '<legend><strong>گروه‌های انتخابی (فقط در حالت «انتخاب هم‌زمان چند گروه»)</strong></legend>';
        foreach (QYS_Messenger::member_roles() as $key=>$label) {
            echo '<label style="display:inline-block;margin:4px 12px"><input type="checkbox" name="role_groups[]" value="'.esc_attr($key).'"> '.esc_html($label).'</label>';
        }
        echo '<p class="description">هر عضو واجد شرایط، حتی اگر در چند گروه قرار بگیرد، فقط یک پیام دریافت می‌کند. تعداد گیرندگان یکتا در پیش‌نویس نشان داده می‌شود.</p></fieldset>';
        echo '<div id="qys-target-chooser" style="display:none;max-width:600px;background:#fff;border:1px solid #dcdcde;padding:15px;margin:8px 0">';
        $sensitive_scope=QYS_Permission_Service::scope('sensitive_identity');
        $can_search_sensitive=$sensitive_scope==='*'||(is_string($sensitive_scope)&&$sensitive_scope===$scope);
        echo '<label for="qys-target-search">'.esc_html($can_search_sensitive?'جست‌وجوی نام، نام خانوادگی، کد ملی یا موبایل':'جست‌وجوی نام و نام خانوادگی').'</label><br>';
        echo '<input type="search" id="qys-target-search" class="regular-text" maxlength="80" autocomplete="off" placeholder="حداقل دو نویسه">';
        echo '<p class="description">'.esc_html($can_search_sensitive?'شناسه کامل نمایش داده نمی‌شود و فقط چهار رقم آخر موبایل در پیشنهادها دیده می‌شود.':'بدون مجوز اطلاعات حساس، جست‌وجو با کد ملی و موبایل غیرفعال است.').'</p>';
        echo '<div id="qys-target-search-results" aria-live="polite"></div><div id="qys-target-selected" aria-live="polite"></div></div>';
        if($portal)echo '</section><section class="qys-targeted-step"><div class="qys-targeted-step-head"><span>۲</span><div><h3>متن پیام</h3><p>متن نهایی را بنویسید؛ نام و نام خانوادگی هر مخاطب در زمان ساخت پیش‌نویس جایگزین می‌شود.</p></div></div>';
        if($portal){
            echo '<div class="qys-targeted-message-field"><label for="qys-target-template">متن پیام</label><div class="qys-targeted-variable-row"><span>متغیرهای مجاز</span><code>{نام}</code><code>{نام خانوادگی}</code></div><textarea id="qys-target-template" name="template" rows="7" maxlength="3500" required placeholder="متن پیام را اینجا بنویسید…"></textarea><div class="qys-targeted-message-meta"><small>پیش از فعال‌سازی، متن نهایی هر گیرنده در پیش‌نمایش قابل بررسی است.</small><span id="qys-target-char-count" aria-live="polite">۰ / ۳۵۰۰</span></div></div></section><section class="qys-targeted-step"><div class="qys-targeted-step-head"><span>۳</span><div><h3>زمان ارسال</h3><p>ارسال فوری پس از تأیید وارد صف می‌شود؛ برای زمان آینده گزینه زمان‌بندی را انتخاب کنید.</p></div></div>';
            echo '<fieldset class="qys-targeted-mode"><legend class="screen-reader-text">روش ارسال</legend><div class="qys-targeted-mode-options"><label><input type="radio" name="mode" value="immediate" checked><span><strong>ارسال فوری</strong><small>پس از تأیید کارزار وارد صف می‌شود.</small></span></label><label><input type="radio" name="mode" value="scheduled"><span><strong>زمان‌بندی‌شده</strong><small>در تاریخ و ساعت انتخابی وارد صف می‌شود.</small></span></label></div></fieldset>';
        }else{
            echo '<p><label>متن پیام (متغیرهای مجاز: {نام} و {نام خانوادگی})<br><textarea id="qys-target-template" name="template" rows="5" cols="95" maxlength="3500" required></textarea></label></p>';
            echo '<fieldset><legend><strong>زمان ارسال</strong></legend><label><input type="radio" name="mode" value="immediate" checked> فوری (ورود به صف پس از تأیید)</label> &nbsp;';
            echo '<label><input type="radio" name="mode" value="scheduled"> زمان‌بندی‌شده</label></fieldset>';
        }
        // ALWAYS visible, including when JS or WordPress's localized admin script fails.
        // Only a scheduled message uses these values; immediate messages ignore them.
        $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Tehran')));
        $suggested_day = QYS_Calendar_Service::jalali_from_date($tomorrow);
        echo '<div id="qys-target-schedule"'.($portal?' class="qys-targeted-schedule"':' style="display:block;margin:12px 0;padding:12px;border:1px solid #dcdcde;background:#fff"').'>';
        echo '<h3'.($portal?'':' style="margin:0 0 10px"').'>تنظیم تاریخ و ساعت ارسال زمان‌بندی‌شده</h3>';
        echo '<p id="qys-target-time-hint" class="description">برای ارسال فوری، تاریخ و ساعت زیر نادیده گرفته می‌شود. برای زمان‌بندی، آن‌ها را تنظیم کنید.</p>';
        echo '<p><label for="qys-target-day"><strong>تاریخ شمسی</strong></label><br><input type="text" name="send_day" id="qys-target-day" inputmode="numeric" dir="ltr" class="regular-text" style="max-width:180px" value="'.esc_attr($suggested_day).'" placeholder="۱۴۰۵/۰۷/۱۵" maxlength="10" pattern="[۰-۹٠-٩0-9]{4}/[۰-۹٠-٩0-9]{1,2}/[۰-۹٠-٩0-9]{1,2}" aria-describedby="qys-target-time-hint"> <span class="description">نمونه: ۱۴۰۵/۰۷/۱۵</span></p>';
        echo '<p><label for="qys-target-hour"><strong>ساعت</strong></label> <select name="send_hour" id="qys-target-hour">';for($h=0;$h<24;$h++)echo '<option value="'.sprintf('%02d',$h).'"'.selected($h,9,false).'>'.sprintf('%02d',$h).'</option>';echo '</select> : ';
        echo '<label for="qys-target-minute"><strong>دقیقه</strong></label> <select name="send_minute" id="qys-target-minute">';for($i=0;$i<60;$i++)echo '<option value="'.sprintf('%02d',$i).'">'.sprintf('%02d',$i).'</option>';echo '</select></p>';
        echo '<p class="description">پیش‌فرض: فردا ساعت ۰۹:۰۰. همه ساعات ۰۰:۰۰ تا ۲۳:۵۹ به وقت ایران مجازند. تاریخ و ساعت باید در آینده باشد.</p></div>';
        if($portal){
            echo '</section><div class="qys-targeted-submit-bar"><div><strong>هیچ پیامی در این مرحله ارسال نمی‌شود.</strong><span>ابتدا پیش‌نویس ساخته می‌شود؛ سپس می‌توانید متن و گیرندگان را بازبینی و فعال کنید.</span></div><button type="submit" class="button button-primary qys-targeted-primary-action">ساخت پیش‌نویس و پیش‌نمایش</button></div></form></section>';
        }else echo '<p><button type="submit" class="button button-primary">ایجاد پیش‌نویس بدون ارسال</button></p></form>';
        $scope=QYS_Permission_Service::scope('send_messages');
        $manager=$scope==='*'?'':$scope;
        $list=QYS_Campaign_Repository::targeted_rows(self::table(),$manager,40);
        if($portal)echo '<section class="qys-targeted-history qys-tenant-panel"><div class="qys-targeted-card-head qys-targeted-card-head--history"><div><span>پیگیری ارسال‌ها</span><h2>پیش‌نویس‌ها و آرشیو</h2><p>وضعیت هر کارزار، تعداد ارسال‌شده‌ها و خطاها را یک‌جا ببینید.</p></div><strong>'.number_format_i18n(count((array)$list)).' کارزار</strong></div>';
        else echo '<hr><h2>پیش‌نویس‌ها، پیام‌های زمان‌بندی‌شده و آرشیو ارسال</h2>';
        echo '<div class="qys-targeted-table-wrap"><table class="widefat striped qys-targeted-history-table"><thead><tr><th>عنوان</th><th>گروه</th><th>زمان</th><th>وضعیت</th><th>گیرندگان</th><th>در صف</th><th>ارسال‌شده</th><th>خطا / نامشخص</th><th>عملیات</th></tr></thead><tbody>';
        $labels=QYS_Messenger::member_roles()+['all'=>'همه','selected'=>'انتخاب دستی','groups'=>'چند گروه','excel'=>'اکسل اختصاصی'];
        foreach ((array)$list as $c) {
            echo '<tr><td data-label="عنوان"><strong>'.esc_html($c->label).'</strong> <small>#'.(int)$c->id.'</small></td>';
            $audience_label = $labels[$c->audience] ?? '—';
            if ($c->audience==='groups') {
                $group_keys=explode(',',(string)$c->audience_groups);
                $group_names=[];
                foreach ($group_keys as $key) if (isset($labels[$key])) $group_names[]=$labels[$key];
                $audience_label=$group_names ? implode('، ',$group_names) : 'چند گروه';
            }
            $unilabel=[];foreach(explode(',',(string)$c->audience_universities) as $code){$title=QYS_Multitenant::university_label($code);if($title!=='—')$unilabel[]=$title;}
            $audience_label.=' | '.implode('، ',$unilabel);
            echo '<td data-label="گروه">'.esc_html($audience_label).'</td>';
            echo '<td data-label="زمان">'.(!empty($c->scheduled_jalali)?esc_html($c->scheduled_jalali.' '.$c->scheduled_hm):'فوری').'</td>';
            $status_label=['draft'=>'پیش‌نویس','scheduled'=>'زمان‌بندی‌شده','active'=>'فعال / درحال ارسال','cancelled'=>'لغوشده'][$c->state]??$c->state;
            if($c->state==='active' && ((int)$c->pending+(int)$c->sending)===0){
                $status_label=(int)$c->failed>0?'پایان‌یافته با خطا':'تکمیل‌شده';
            }
            if($portal){
                $tone='neutral';
                if(str_contains($status_label,'تکمیل'))$tone='good';
                elseif(str_contains($status_label,'خطا'))$tone='attention';
                elseif($c->state==='scheduled')$tone='info';
                elseif($c->state==='active')$tone='good';
                echo '<td data-label="وضعیت">'.QYS_Portal_Experience::status_chip($status_label,$tone).'</td>';
            }else echo '<td data-label="وضعیت">'.esc_html($status_label).'</td>';
            echo '<td data-label="گیرندگان">'.(int)$c->recipients.'</td><td data-label="در صف">'.(int)$c->pending.'</td><td data-label="ارسال‌شده">'.(int)$c->sent.'</td><td data-label="خطا / نامشخص">'.(int)$c->failed.'</td><td data-label="عملیات" class="qys-targeted-actions">';
            echo '<form method="post" style="display:inline">';wp_nonce_field('qys_target_admin');echo '<input type="hidden" name="campaign_id" value="'.(int)$c->id.'">';
            echo '<button type="submit" name="qys_target_action" value="view" class="button">پیش‌نمایش</button> ';
            if ($c->state==='draft') echo '<button type="submit" name="qys_target_action" value="launch" class="button button-primary" onclick="return confirm(\'پس از بررسی پیش‌نمایش، فعال‌سازی این پیام را تأیید می‌کنید؟\')">تأیید و فعال‌سازی</button> ';
            if (in_array($c->state,['draft','scheduled'],true)) echo '<button type="submit" name="qys_target_action" value="cancel" class="button" onclick="return confirm(\'این کارزار و پیام‌های ارسال‌نشده لغو شوند؟\')">لغو</button>';
            echo '</form></td></tr>';
        }
        if (!$list) echo '<tr class="qys-tenant-empty-row"><td colspan="9"><span class="qys-tenant-state-icon" aria-hidden="true">○</span><strong>هنوز پیامی ساخته نشده است</strong><span>اولین پیش‌نویس را از فرم بالا بسازید؛ تا قبل از تأیید شما چیزی ارسال نمی‌شود.</span></td></tr>';
        echo '</tbody></table></div>';
        if($portal)echo '</section>';
        if ($detail_id && QYS_Permission_Service::campaign_visible($detail_id)) {
            $campaign=QYS_Campaign_Repository::targeted_detail((int)$detail_id,self::table());
            $sample=QYS_Campaign_Repository::preview_sample((int)$detail_id);
            if($campaign && (string)$campaign->audience!=='program_status'){
                $sample_person=$sample?QYS_Member_Repository::names_by_ids([(int)$sample->member_id]):[];
                $person=$sample?($sample_person[(int)$sample->member_id]??null):null;
                $sample_name=$person?trim((string)$person->first_name.' '.(string)$person->last_name):'نمونه مخاطب';
                $total=(int)$campaign->recipients;
                $all_pending=$total>0 && (int)$campaign->pending===$total && (int)$campaign->sending===0 && (int)$campaign->sent===0 && (int)$campaign->failed===0 && (int)$campaign->cancelled===0;
                $editable=in_array((string)$campaign->state,['draft','scheduled','active'],true)&&$all_pending;
                $approved=in_array((string)$campaign->state,['scheduled','active'],true);
                $status_label=['draft'=>'پیش‌نویس','scheduled'=>'تأییدشده / زمان‌بندی‌شده','active'=>'تأییدشده / آماده ارسال','cancelled'=>'لغوشده'][(string)$campaign->state]??(string)$campaign->state;
                if((string)$campaign->state==='active'&&!$all_pending)$status_label=((int)$campaign->pending+(int)$campaign->sending)>0?'در حال ارسال':'ارسال پایان یافته';
                echo '<section id="qys-targeted-preview" class="qys-targeted-preview qys-tenant-panel"><div class="qys-targeted-card-head qys-targeted-card-head--preview"><div><span>پیش‌نمایش پیام</span><h2>'.esc_html((string)$campaign->label).'</h2><p>برای جلوگیری از شلوغی، فقط یک نمونه از متن نهایی نمایش داده می‌شود.</p></div><strong>'.number_format_i18n($total).' گیرنده</strong></div>';
                echo '<div class="qys-targeted-preview-summary"><span>'.esc_html($status_label).'</span><span>در صف: '.number_format_i18n((int)$campaign->pending).'</span><span>ارسال‌شده: '.number_format_i18n((int)$campaign->sent).'</span><span>خطا/نامشخص: '.number_format_i18n((int)$campaign->failed).'</span></div>';
                if($sample){
                    echo '<article class="qys-targeted-preview-sample"><div class="qys-targeted-preview-sample-head"><div><span>نمونه مخاطب</span><strong>'.esc_html($sample_name).'</strong></div><small>۱ نمونه از '.number_format_i18n($total).' گیرنده</small></div><pre>'.esc_html((string)$sample->payload).'</pre></article>';
                }else{
                    echo '<div class="qys-targeted-preview-empty">نمونه‌ای برای این کارزار در صف وجود ندارد.</div>';
                }
                if($editable){
                    $edit_title=$approved?'ویرایش پیام تأییدشده':'ویرایش پیش‌نویس';
                    $edit_hint=$approved?'ارسال هنوز شروع نشده؛ فقط در صورت نیاز فرم ویرایش را باز کنید.':'برای تغییر عنوان یا متن، فرم ویرایش را باز کنید.';
                    echo '<details class="qys-targeted-edit"><summary><span class="qys-targeted-edit-summary-copy"><strong>'.esc_html($edit_title).'</strong><small>'.esc_html($edit_hint).'</small></span><span class="qys-targeted-edit-summary-action"><span class="is-closed">باز کردن ویرایش</span><span class="is-open">بستن ویرایش</span></span></summary><form method="post" class="qys-targeted-edit-form">';wp_nonce_field('qys_target_admin');
                    echo '<input type="hidden" name="campaign_id" value="'.(int)$detail_id.'"><input type="hidden" name="qys_target_action" value="edit">';
                    echo '<label>عنوان داخلی پیام<input type="text" name="label" maxlength="190" required value="'.esc_attr((string)$campaign->label).'"></label>';
                    echo '<label>متن پیام <small>متغیرهای مجاز: <code>{نام}</code> و <code>{نام خانوادگی}</code></small><textarea name="template" maxlength="3500" rows="7" required>'.esc_textarea((string)$campaign->template).'</textarea></label>';
                    echo '<div class="qys-targeted-edit-note">'.($approved?'این پیام تأیید شده است اما ارسال هنوز شروع نشده؛ با ذخیره، متن همه گیرندگانِ در صف به‌صورت تراکنشی بازسازی می‌شود.':'تغییر متن، پیش‌نمایش همه گیرندگان را بازسازی می‌کند.').'</div>';
                    echo '<button class="button button-primary" type="submit">ذخیره و بازسازی پیش‌نمایش</button></form></details>';
                }elseif(in_array((string)$campaign->state,['scheduled','active'],true)){
                    echo '<div class="qys-targeted-edit-locked"><strong>ویرایش قفل شده است.</strong><span>حداقل یک ارسال آغاز شده یا از حالت pending خارج شده است؛ برای جلوگیری از دریافت نسخه‌های متفاوت، متن این کارزار دیگر تغییر نمی‌کند.</span></div>';
                }
                echo '<div class="qys-targeted-preview-actions">';
                if((string)$campaign->state==='draft'){
                    echo '<form method="post">';wp_nonce_field('qys_target_admin');echo '<input type="hidden" name="campaign_id" value="'.(int)$detail_id.'"><button type="submit" name="qys_target_action" value="launch" class="button button-primary" onclick="return confirm(\'پس از بررسی همین نمونه، فعال‌سازی پیام را تأیید می‌کنید؟\')">تأیید و فعال‌سازی</button></form>';
                }
                if(in_array((string)$campaign->state,['draft','scheduled'],true)){
                    echo '<form method="post">';wp_nonce_field('qys_target_admin');echo '<input type="hidden" name="campaign_id" value="'.(int)$detail_id.'"><button type="submit" name="qys_target_action" value="cancel" class="button" onclick="return confirm(\'این کارزار و پیام‌های ارسال‌نشده لغو شوند؟\')">لغو کارزار</button></form>';
                }
                echo '</div></section>';
                if(class_exists('QYS_Excel') && !in_array((string)$campaign->state,['draft','scheduled'],true))QYS_Excel::render_followup_report((int)$detail_id);
            }
        }
        echo '<p class="description">تعداد مخاطبان در لحظه ایجاد پیش‌نویس ثابت می‌شود؛ هنگام ارسال، مجوز پیام و تأیید هویت دوباره بررسی می‌شوند. وضعیت «ارسال‌شده» یعنی API ایتا پیام را پذیرفته، نه اینکه مخاطب آن را خوانده باشد.</p></div>';
    }
}
