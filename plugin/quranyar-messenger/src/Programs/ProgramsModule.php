<?php
if (!defined('ABSPATH')) exit;

/** Programs & Registration product surface. Domain rules remain in ProgramService. */
final class QYS_Programs_Module {
    private const TYPES=['course'=>'کلاس / دوره','workshop'=>'کارگاه','event'=>'رویداد','competition'=>'مسابقه','camp'=>'اردو','recurring_session'=>'جلسه تکرارشونده','other'=>'سایر'];
    private const PROGRAM_LABELS=['draft'=>'پیش‌نویس','registration_open'=>'ثبت‌نام باز','registration_closed'=>'ثبت‌نام بسته','confirmed'=>'قطعی‌شده','running'=>'در حال اجرا','completed'=>'پایان‌یافته','cancelled'=>'لغوشده','archived'=>'بایگانی'];
    private const APP_LABELS=['submitted'=>'ثبت‌شده','under_review'=>'در حال بررسی','waitlisted'=>'لیست انتظار','accepted'=>'پذیرفته‌شده','rejected'=>'ردشده','withdrawn'=>'انصراف','closed'=>'بسته‌شده','cancelled'=>'لغوشده با برنامه'];
    private const ENROLL_LABELS=['enrolled'=>'ثبت‌نام قطعی','participating'=>'در حال شرکت','completed'=>'تکمیل‌شده','withdrawn'=>'انصراف','no_show'=>'عدم شرکت','cancelled'=>'لغوشده با برنامه','closed'=>'بسته‌شده'];
    private const FIELD_LABELS=['short_text'=>'متن کوتاه','long_text'=>'متن بلند','number'=>'عدد','date'=>'تاریخ','single_choice'=>'تک‌انتخابی','multi_choice'=>'چندانتخابی','yes_no'=>'بله / خیر','notice'=>'متن توضیحی'];
    private const DESTRUCTIVE_PROGRAM_STATUSES=['completed','cancelled','archived'];
    private const DESTRUCTIVE_APPLICATION_STATUSES=['rejected'];
    private const BULK_APPLICATION_LIMIT=100;

    /** Phase E / E2: Programs access explains the blocking state before any CTA. */
    public static function access_gate_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'explain',
            'cta'=>'explicit',
            'redirect'=>'none',
            'states'=>'membership_consent_profile_verification',
            'backend_authoritative'=>true,
        ];
    }

    /** Phase E / E3: irreversible Program/Application actions require an explicit confirmation. */
    public static function destructive_confirmation_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'explicit',
            'program'=>'complete_cancel_archive',
            'applicant'=>'reject',
            'client'=>'confirm_before_submit',
            'server'=>'required',
        ];
    }

    /** Phase E / E5: verified members can explicitly withdraw their own active Program relationship. */
    public static function member_withdraw_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'self_service',
            'application'=>'withdrawable',
            'enrollment'=>'withdrawable',
            'confirmation'=>'explicit',
            'history'=>'preserved',
            'server'=>'owner_required',
        ];
    }

    /** RC53: a withdrawn relationship can return to the normal review flow while registration is open. */
    public static function member_reapply_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'self_service',
            'source'=>'withdrawn_only',
            'target'=>'submitted',
            'approval'=>'normal_flow',
            'capacity'=>'rechecked',
            'enrollment'=>'reactivate_after_accept',
            'history'=>'audit_preserved',
            'confirmation'=>'explicit',
        ];
    }

    /** Phase E / E7: member application answers survive refresh/navigation as a bounded local draft. */
    public static function member_form_draft_contract(): array {
        return [
            'ready'=>true,
            'storage'=>'local',
            'scope'=>'member_program_form',
            'ttl_hours'=>24,
            'restore'=>'automatic',
            'clear'=>'successful_submit',
            'server'=>'not_persisted',
        ];
    }

    /** Phase E / E8: application fields expose inline client validation while the server stays authoritative. */
    public static function field_validation_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'field_level',
            'required'=>'client_server',
            'multi_choice'=>'one_or_more',
            'error'=>'inline',
            'focus'=>'first_invalid',
            'server'=>'authoritative',
        ];
    }

    /** Phase E / E9: tenant-scoped search/filter + selected applicant bulk management. */
    public static function bulk_applicant_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'selected',
            'filters'=>'status_role_enrollment_search',
            'actions'=>'transition_enroll',
            'scope'=>'tenant',
            'result'=>'partial_summary',
            'max_selected'=>self::BULK_APPLICATION_LIMIT,
            'destructive'=>'explicit',
        ];
    }

    /** RC54: Program POST actions are processed before template output so redirects cannot strand the portal on a blank shell. */
    public static function post_redirect_contract(): array {
        return [
            'ready'=>true,
            'portal_hook'=>'template_redirect',
            'admin_hook'=>'admin_init',
            'timing'=>'pre_render',
            'redirect'=>'canonical_program_url',
            'notification'=>'fail_soft',
            'statuses'=>'review_waitlist_accept_reject_enroll',
        ];
    }

    /** Phase E / E4: core Program details are editable only while their state makes the change safe. */
    public static function program_edit_contract(): array {
        return [
            'ready'=>true,
            'mode'=>'state_aware',
            'core'=>'title_description',
            'capacity'=>'reserved_floor',
            'audience'=>'draft_only',
            'attendance'=>'pre_link',
            'terminal'=>'read_only',
            'server'=>'required',
        ];
    }

    private static function destructive_confirmation_message(string $action,string $target): string {
        if($action==='program_status'){
            return match($target){
                'completed'=>'با پایان برنامه، درخواست‌های بلاتکلیف بسته و وضعیت ثبت‌نام‌های فعال نهایی می‌شود. این عملیات قابل بازگشت نیست. ادامه می‌دهید؟',
                'cancelled'=>'با لغو برنامه، درخواست‌ها و ثبت‌نام‌های فعال مرتبط لغو می‌شوند. این عملیات قابل بازگشت نیست. ادامه می‌دهید؟',
                'archived'=>'با بایگانی برنامه، مدیریت فعال آن پایان می‌یابد و برنامه به وضعیت بایگانی می‌رود. این عملیات قابل بازگشت نیست. ادامه می‌دهید؟',
                default=>'',
            };
        }
        if($action==='application_status'&&$target==='rejected'){
            return 'با رد متقاضی، این درخواست وارد وضعیت نهایی «ردشده» می‌شود و از همین مسیر قابل بازگشت نیست. ادامه می‌دهید؟';
        }
        if($action==='bulk_applications'&&$target==='rejected'){
            return 'درخواست‌های انتخاب‌شده که امکان ردشدن دارند، به وضعیت نهایی «ردشده» منتقل می‌شوند. این تغییر برای همان درخواست‌ها قابل بازگشت نیست. ادامه می‌دهید؟';
        }
        return '';
    }

    private static function require_destructive_confirmation(string $action,string $target): void {
        $required=($action==='program_status'&&in_array($target,self::DESTRUCTIVE_PROGRAM_STATUSES,true))
            || ($action==='application_status'&&in_array($target,self::DESTRUCTIVE_APPLICATION_STATUSES,true))
            || ($action==='bulk_applications'&&in_array($target,self::DESTRUCTIVE_APPLICATION_STATUSES,true));
        if(!$required)return;
        $confirmed=(string)wp_unslash($_POST['qys_destructive_confirmed']??'');
        if($confirmed!=='1')wp_die('برای انجام این عملیات برگشت‌ناپذیر، تأیید نهایی لازم است.',400);
    }

    public static function init(): void {
        add_action('admin_menu',[__CLASS__,'menu'],40);
        add_action('admin_enqueue_scripts',[__CLASS__,'admin_assets']);
        add_action('wp_enqueue_scripts',[__CLASS__,'public_assets']);
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('admin_post_qys_program_export',[__CLASS__,'export_csv']);
        // RC54: process state-changing Program forms before the standalone
        // portal template prints any HTML. Redirecting from the render callback
        // could leave only the shell/header visible if response headers were
        // already committed by PHP/server buffering.
        add_action('template_redirect',[__CLASS__,'route_portal_post'],8);
        add_action('admin_init',[__CLASS__,'route_admin_post'],60);
    }

    public static function route_portal_post(): void {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||empty($_POST['qys_program_action']))return;
        if(!QYS_Multitenant::is_portal_request())return;
        $section=sanitize_key(wp_unslash($_GET['section']??''));
        if($section!=='programs')return;
        self::handle_post();
    }

    public static function route_admin_post(): void {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||empty($_POST['qys_program_action']))return;
        if(!is_admin())return;
        $page=sanitize_key(wp_unslash($_GET['page']??''));
        if($page!=='qys-programs')return;
        self::handle_post();
    }

    public static function menu(): void {
        if(!QYS_Security_Service::is_global_admin()||QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL)return;
        add_submenu_page('qys-messaging','برنامه‌ها و ثبت‌نام','برنامه‌ها و ثبت‌نام','manage_options','qys-programs',[__CLASS__,'page'],35);
    }

    public static function admin_assets(): void {
        $page=sanitize_key(wp_unslash($_GET['page']??''));if($page!=='qys-programs')return;
        wp_enqueue_style('qys-fonts',plugins_url('assets/qys-fonts.css',QYS_Config::plugin_file()),[],QYS_Config::VERSION);
        wp_enqueue_style('qys-admin-ui',plugins_url('assets/qys-admin-ui.css',QYS_Config::plugin_file()),['qys-fonts'],QYS_Config::VERSION);
        wp_enqueue_style('qys-programs',plugins_url('assets/qys-programs.css',QYS_Config::plugin_file()),['qys-admin-ui'],QYS_Config::VERSION);
        wp_enqueue_script('qys-confirm',plugins_url('assets/qys-confirm.js',QYS_Config::plugin_file()),[],QYS_Config::VERSION,true);
        wp_enqueue_script('qys-programs-admin',plugins_url('assets/qys-programs-admin.js',QYS_Config::plugin_file()),[],QYS_Config::VERSION,true);
    }

    public static function public_assets(): void {
        if(class_exists('QYS_Portal_Asset_Policy',false) && QYS_Portal_Asset_Policy::should_load('programs')){
            wp_enqueue_style('qys-programs',plugins_url('assets/qys-programs.css',QYS_Config::plugin_file()),['qys-university-portal'],QYS_Config::VERSION);
            wp_enqueue_script('qys-confirm',plugins_url('assets/qys-confirm.js',QYS_Config::plugin_file()),[],QYS_Config::VERSION,true);
            wp_enqueue_script('qys-programs-admin',plugins_url('assets/qys-programs-admin.js',QYS_Config::plugin_file()),[],QYS_Config::VERSION,true);
        }
        // D4: member mini-app feature assets are lazy on first view. D10 also
        // prevents the university portal Programs stylesheet from loading on
        // unrelated /qys/ sections.
    }

    public static function public_asset_manifest(): array {
        return [
            'style'=>plugins_url('assets/qys-programs-public.css',QYS_Config::plugin_file()),
            'script'=>plugins_url('assets/qys-programs-public.js',QYS_Config::plugin_file()),
            'global'=>'QYSPrograms',
            'config'=>[
                'listEndpoint'=>rest_url('qys/v1/programs/list'),
                'applyEndpoint'=>rest_url('qys/v1/programs/apply'),
                'recordEndpoint'=>rest_url('qys/v1/programs/record'),
                'withdrawEndpoint'=>rest_url('qys/v1/programs/withdraw'),
                'reapplyEndpoint'=>rest_url('qys/v1/programs/reapply'),
            ],
        ];
    }

    public static function routes(): void {
        register_rest_route('qys/v1','/programs/list',['methods'=>'POST','callback'=>[__CLASS__,'rest_list'],'permission_callback'=>static fn($r)=>QYS_Security_Service::authorize_eitaa($r,'program_list',60,60)]);
        register_rest_route('qys/v1','/programs/apply',['methods'=>'POST','callback'=>[__CLASS__,'rest_apply'],'permission_callback'=>static fn($r)=>QYS_Security_Service::authorize_eitaa($r,'program_apply',20,60)]);
        register_rest_route('qys/v1','/programs/record',['methods'=>'POST','callback'=>[__CLASS__,'rest_record'],'permission_callback'=>static fn($r)=>QYS_Security_Service::authorize_eitaa($r,'program_record',60,60)]);
        register_rest_route('qys/v1','/programs/withdraw',['methods'=>'POST','callback'=>[__CLASS__,'rest_withdraw'],'permission_callback'=>static fn($r)=>QYS_Security_Service::authorize_eitaa($r,'program_withdraw',12,60)]);
        register_rest_route('qys/v1','/programs/reapply',['methods'=>'POST','callback'=>[__CLASS__,'rest_reapply'],'permission_callback'=>static fn($r)=>QYS_Security_Service::authorize_eitaa($r,'program_reapply',12,60)]);
    }

    private static function request_member(WP_REST_Request $req){
        $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
        $id=QYS_Member_Repository::id_by_eitaa_id((string)$user['id']);
        if(!$id)return new WP_Error('not_registered','برای استفاده از برنامه‌ها ابتدا پرونده قرآن‌یار خود را تشکیل دهید.',['status'=>403,'action'=>'complete_profile','gate'=>'membership_required','cta_label'=>'تشکیل پرونده','cta_view'=>'profile']);
        $m=QYS_Member_Repository::find_by_id((int)$id);
        if($m&&(int)($m->consent??0)!==1)return new WP_Error('consent_required','برای استفاده از برنامه‌ها، مجوز دریافت پیام قرآن‌یار باید فعال باشد.',['status'=>403,'action'=>'enable_messaging','gate'=>'consent_required','cta_label'=>'فعال‌سازی دریافت پیام','cta_view'=>'profile']);
        if(!$m||!QYS_Messenger::profile_complete($m))return new WP_Error('profile_incomplete','پرونده شما برای استفاده از برنامه‌ها کامل نیست. اطلاعات لازم را تکمیل کنید.',['status'=>403,'action'=>'complete_profile','gate'=>'profile_incomplete','cta_label'=>'تکمیل پرونده','cta_view'=>'profile']);
        if((int)$m->verified!==1)return new WP_Error('not_verified','برای ثبت‌نام و مشاهده سوابق برنامه‌ها، تأیید هویت مدیر دانشگاه لازم است.',['status'=>403,'action'=>'profile_status','gate'=>'verification_pending','cta_label'=>'مشاهده وضعیت پرونده','cta_view'=>'profile']);
        return $m;
    }

    public static function rest_list(WP_REST_Request $req){
        $m=self::request_member($req);if(is_wp_error($m))return $m;$rows=QYS_Program_Repository::list_for_member($m,100);$items=[];
        $program_ids=array_map(static fn($row)=>(int)$row->id,$rows);$field_map=QYS_Program_Repository::current_form_fields_for_programs($program_ids);
        foreach($rows as $p){$fields=[];foreach($field_map[(int)$p->id]??[] as $f){$opts=json_decode((string)$f->options_json,true);$fields[]=['key'=>(string)$f->field_key,'label'=>(string)$f->label,'type'=>(string)$f->field_type,'required'=>(bool)$f->required,'help'=>(string)$f->help_text,'options'=>is_array($opts)?array_values($opts):[]];}
            $withdraw=QYS_Program_Service::member_withdraw_state_from_statuses((string)$p->status,(string)($p->application_status??''),(string)($p->enrollment_status??''),(int)($p->enrollment_id??0)>0);
            $reapply=QYS_Program_Service::member_reapply_state_from_statuses((string)$p->status,(string)($p->application_status??''),(string)($p->enrollment_status??''),(int)($p->enrollment_id??0)>0);
            $items[]=['id'=>(int)$p->id,'title'=>(string)$p->title,'description'=>wp_strip_all_tags((string)$p->description),'type'=>(string)$p->program_type,'type_label'=>self::TYPES[(string)$p->program_type]??'برنامه','status'=>(string)$p->status,'status_label'=>self::PROGRAM_LABELS[(string)$p->status]??(string)$p->status,'capacity'=>(int)$p->capacity,'application_id'=>(int)($p->application_id??0),'application_status'=>(string)($p->application_status??''),'application_status_label'=>self::APP_LABELS[(string)($p->application_status??'')]??'','enrollment_status'=>(string)($p->enrollment_status??''),'enrollment_status_label'=>self::ENROLL_LABELS[(string)($p->enrollment_status??'')]??'','registration_open'=>((string)$p->status==='registration_open'),'can_withdraw'=>!empty($withdraw['allowed']),'withdraw_kind'=>(string)($withdraw['kind']??''),'can_reapply'=>!empty($reapply['allowed']),'reapply_reason'=>(string)($reapply['reason']??''),'fields'=>$fields];
        }
        return rest_ensure_response(['ok'=>true,'programs'=>$items]);
    }

    public static function rest_apply(WP_REST_Request $req){$m=self::request_member($req);if(is_wp_error($m))return $m;$program_id=absint($req->get_param('program_id'));$answers=$req->get_param('answers');if(!is_array($answers))$answers=[];$result=QYS_Program_Service::submit_application($program_id,(int)$m->id,$answers);if(is_wp_error($result))return $result;return rest_ensure_response(['ok'=>true,'application_id'=>(int)$result,'message'=>'درخواست شما ثبت شد. نتیجه بررسی را از همین بخش و پیام‌های قرآن‌یار دنبال کنید.']);}

    public static function rest_record(WP_REST_Request $req){
        $m=self::request_member($req);if(is_wp_error($m))return $m;
        $record=QYS_Program_Service::member_record((int)$m->id);
        $enrollment_programs=[];$enrollment_statuses=[];
        foreach(['current','history','closed_without_activity'] as $group){foreach($record[$group] as $row){$pid=(int)($row['program_id']??0);$enrollment_programs[$pid]=true;$enrollment_statuses[$pid]=(string)($row['status']??'');}}
        foreach(['applications','current','history','closed_without_activity'] as $group){
            foreach($record[$group] as &$row){
                if(isset($row['status']))$row['status_label']=self::ENROLL_LABELS[$row['status']]??self::APP_LABELS[$row['status']]??$row['status'];
                if(isset($row['program_status']))$row['program_status_label']=self::PROGRAM_LABELS[$row['program_status']]??$row['program_status'];
                if(isset($row['program_type']))$row['type_label']=self::TYPES[$row['program_type']]??'برنامه';
                $has_enrollment=$group!=='applications';
                $enrollment_status=$group==='applications'?(string)($enrollment_statuses[(int)($row['program_id']??0)]??''):($has_enrollment?(string)($row['status']??''):'');
                $has_related_enrollment=$group==='applications'?isset($enrollment_programs[(int)($row['program_id']??0)]):$has_enrollment;
                $withdraw=QYS_Program_Service::member_withdraw_state_from_statuses((string)($row['program_status']??''),$group==='applications'?(string)($row['status']??''):'',$enrollment_status,$has_related_enrollment);
                $row['can_withdraw']=!empty($withdraw['allowed']);
                $row['withdraw_kind']=(string)($withdraw['kind']??'');
                $reapply=QYS_Program_Service::member_reapply_state_from_statuses((string)($row['program_status']??''),$group==='applications'?(string)($row['status']??''):'',$enrollment_status,$has_related_enrollment);
                $row['can_reapply']=!empty($reapply['allowed']);
            }
            unset($row);
        }
        return rest_ensure_response(['ok'=>true,'record'=>$record]);
    }

    public static function rest_withdraw(WP_REST_Request $req){
        $m=self::request_member($req);if(is_wp_error($m))return $m;
        $program_id=absint($req->get_param('program_id'));
        $confirmed=(string)$req->get_param('confirmed');
        if($confirmed!=='1')return new WP_Error('confirmation_required','برای ثبت انصراف، تأیید نهایی لازم است.',['status'=>400]);
        $result=QYS_Program_Service::withdraw_member($program_id,(int)$m->id,(int)$m->id);
        if(is_wp_error($result))return $result;
        return rest_ensure_response(['ok'=>true,'withdrawal'=>$result,'message'=>'انصراف شما ثبت شد و سوابق برنامه حفظ می‌شود.']);
    }

    public static function rest_reapply(WP_REST_Request $req){
        $m=self::request_member($req);if(is_wp_error($m))return $m;
        $program_id=absint($req->get_param('program_id'));
        $confirmed=(string)$req->get_param('confirmed');
        if($confirmed!=='1')return new WP_Error('confirmation_required','برای ثبت‌نام مجدد، تأیید نهایی لازم است.',['status'=>400]);
        $result=QYS_Program_Service::reapply_member($program_id,(int)$m->id,(int)$m->id);
        if(is_wp_error($result))return $result;
        return rest_ensure_response(['ok'=>true,'reapplication'=>$result,'message'=>'درخواست ثبت‌نام مجدد شما ثبت شد و دوباره بررسی می‌شود.']);
    }

    public static function member_panel(): string {
        return '<section id="qys-view-programs" class="qys-member-view" data-qys-view="programs" hidden aria-hidden="true" aria-labelledby="qys-programs-title"><div class="qys-view-heading"><span class="qys-section-kicker">برنامه‌های من</span><h2 id="qys-programs-title">برنامه‌ها و ثبت‌نام</h2><p>برای برنامه‌ها ثبت‌نام کنید، نتیجه درخواست‌ها را ببینید و سوابق خود را مرور کنید.</p></div><div id="qys-program-access-gate" class="qys-program-access-gate-shell" hidden role="status" aria-live="polite"><span class="qys-program-gate-kicker">دسترسی به برنامه‌ها</span><strong id="qys-program-gate-title">در حال بررسی پرونده شما...</strong><p id="qys-program-gate-reason"></p><button type="button" id="qys-program-gate-cta" data-qys-program-gate-cta hidden>مشاهده پرونده</button></div><div id="qys-program-content"><div class="qys-program-member-tabs" role="tablist"><button type="button" class="is-active" data-qys-program-tab="available">ثبت‌نام‌ها</button><button type="button" data-qys-program-tab="record">سوابق من</button></div><div id="qys-program-list" class="qys-program-member-list"><div class="qys-program-loading">در حال آماده‌کردن برنامه‌ها...</div></div><div id="qys-program-record" class="qys-program-member-list" hidden><div class="qys-program-loading">در حال آماده‌کردن سوابق...</div></div><div id="qys-program-feedback" role="status" aria-live="polite"></div></div></section>';
    }

    private static function scope(): ?string {return QYS_Permission_Service::scope('programs');}
    private static function visible(int $id): bool {$s=self::scope();return $s!==null&&QYS_Program_Repository::program_visible_in_scope($id,$s);}
    private static function manageable(int $id): bool {$s=self::scope();return $s!==null&&QYS_Program_Repository::program_manageable_in_scope($id,$s);}
    private static function url(array $args=[]): string {if(QYS_Multitenant::is_portal_request())return QYS_Multitenant::campus_url('programs',$args);return add_query_arg(array_merge(['page'=>'qys-programs'],$args),admin_url('admin.php'));}
    private static function notice_redirect(string $message,int $program_id=0,array $extra=[]): void {
        $anchor='';if(isset($extra['_anchor'])){$anchor='#'.sanitize_html_class((string)$extra['_anchor']);unset($extra['_anchor']);}
        $args=array_merge(['qys_program_notice'=>substr(wp_strip_all_tags($message),0,220)],$extra);if($program_id)$args['program_id']=$program_id;
        $target=self::url($args).$anchor;
        if($target!==''&&!headers_sent()){wp_safe_redirect($target);exit;}
        // Defensive fallback only: RC54 routes normal POSTs before rendering, but
        // another plugin/theme may still emit output too early. Never leave the
        // representative with an empty portal shell after a successful change.
        echo '<section class="qys-tenant-state"><h2>عملیات انجام شد</h2><p>'.esc_html($message).'</p>'.($target!==''?'<p><a class="button button-primary" href="'.esc_url($target).'">بازگشت به برنامه</a></p>':'').'</section>';
        exit;
    }

    private static function normalize_applicant_filters(array $source): array {
        $status=sanitize_key((string)($source['qys_app_status']??''));
        if(!isset(self::APP_LABELS[$status]))$status='';
        $role=sanitize_key((string)($source['qys_app_role']??''));
        if(!isset(QYS_Messenger::member_roles()[$role]))$role='';
        $enrollment=sanitize_key((string)($source['qys_app_enrollment']??''));
        if(!in_array($enrollment,['with','without'],true))$enrollment='';
        $search=trim(sanitize_text_field((string)($source['qys_app_q']??'')));
        if(mb_strlen($search)>80)$search=mb_substr($search,0,80);
        return ['status'=>$status,'role'=>$role,'enrollment'=>$enrollment,'search'=>$search];
    }

    private static function applicant_filters_from_get(): array {
        $source=[];foreach(['qys_app_status','qys_app_role','qys_app_enrollment','qys_app_q'] as $key)$source[$key]=isset($_GET[$key])?wp_unslash($_GET[$key]):'';
        return self::normalize_applicant_filters($source);
    }

    private static function applicant_filters_from_post(): array {
        $source=[];foreach(['qys_app_status','qys_app_role','qys_app_enrollment','qys_app_q'] as $key)$source[$key]=isset($_POST[$key])?wp_unslash($_POST[$key]):'';
        return self::normalize_applicant_filters($source);
    }

    private static function applicant_filter_args(array $filters): array {
        $args=[];
        if(($filters['status']??'')!=='')$args['qys_app_status']=$filters['status'];
        if(($filters['role']??'')!=='')$args['qys_app_role']=$filters['role'];
        if(($filters['enrollment']??'')!=='')$args['qys_app_enrollment']=$filters['enrollment'];
        if(($filters['search']??'')!=='')$args['qys_app_q']=$filters['search'];
        return $args;
    }

    private static function applicant_filter_hidden(array $filters): string {
        $html='';foreach(self::applicant_filter_args($filters) as $key=>$value)$html.='<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';return $html;
    }

    private static function applicant_get_context_hidden(int $program_id): string {
        $html=QYS_Multitenant::is_portal_request()?'<input type="hidden" name="section" value="programs">':'<input type="hidden" name="page" value="qys-programs">';
        return $html.'<input type="hidden" name="program_id" value="'.(int)$program_id.'">';
    }

    private static function handle_post(): void {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||empty($_POST['qys_program_action']))return;if(self::scope()===null)wp_die('دسترسی غیرمجاز',403);check_admin_referer('qys_programs','qys_program_nonce');$action=sanitize_key(wp_unslash($_POST['qys_program_action']));$actor=get_current_user_id();
        if($action==='create'){$scope=self::scope();$universities=$scope==='*'?(array)wp_unslash($_POST['universities']??[]):[$scope];$manager=$scope==='*'?sanitize_key(wp_unslash($_POST['manager_university']??'')):$scope;$roles=(array)wp_unslash($_POST['audience_roles']??[]);$id=QYS_Program_Service::create(['title'=>wp_unslash($_POST['title']??''),'description'=>wp_unslash($_POST['description']??''),'program_type'=>sanitize_key(wp_unslash($_POST['program_type']??'course')),'status'=>'draft','registration_mode'=>'approval','capacity'=>absint($_POST['capacity']??0),'waitlist_enabled'=>!empty($_POST['waitlist_enabled']),'attendance_enabled'=>!empty($_POST['attendance_enabled']),'audience_roles'=>$roles?:'all','manager_university'=>$manager,'universities'=>$universities]);if(is_wp_error($id))self::notice_redirect($id->get_error_message());QYS_Program_Service::ensure_draft_form((int)$id,$actor);self::notice_redirect('برنامه ساخته شد. اکنون فرم پیش‌ثبت‌نام را تکمیل و منتشر کنید.',(int)$id);}
        if($action==='save_notifications'){
            $scope=(string)self::scope();$raw=[];foreach(array_keys(QYS_Program_Notifications::labels()) as $event)$raw[$event]=(string)wp_unslash($_POST['notify_'.$event]??'');
            $r=QYS_Program_Notifications::save_scope_templates($scope,$raw,$actor);
            self::notice_redirect(is_wp_error($r)?$r->get_error_message():'پیام‌های سراسری تغییر وضعیت ذخیره شد.',0,['_anchor'=>'qys-program-notifications-global']);
        }
        $pid=absint($_POST['program_id']??0);if(!$pid||!self::visible($pid))wp_die('برنامه در دامنه دسترسی شما نیست.',403);
        $definition_actions=['update_program','add_field','prepare_field_edit','update_field','remove_field','publish_form','program_status','create_attendance'];
        if(in_array($action,$definition_actions,true)&&!self::manageable($pid))wp_die('مدیریت ساختار این برنامه فقط برای دانشگاه مالک یا مدیر کل مجاز است.',403);
        if($action==='update_program'){
            $roles=(array)wp_unslash($_POST['audience_roles']??[]);
            $universities=(array)wp_unslash($_POST['universities']??[]);
            $payload=[
                'title'=>wp_unslash($_POST['title']??''),
                'description'=>wp_unslash($_POST['description']??''),
                'capacity'=>absint($_POST['capacity']??0),
                'audience_roles'=>$roles?:'all',
                'universities'=>$universities,
            ];
            if(array_key_exists('attendance_enabled',$_POST))$payload['attendance_enabled']=!empty($_POST['attendance_enabled']);
            $r=QYS_Program_Service::update_details($pid,$payload,$actor);
            self::notice_redirect(is_wp_error($r)?$r->get_error_message():'مشخصات برنامه به‌روزرسانی شد.',$pid,is_wp_error($r)?['_anchor'=>'qys-program-details']:[]);
        }
        if($action==='add_field'){$draft=QYS_Program_Service::ensure_draft_form($pid,$actor);if(is_wp_error($draft))self::notice_redirect($draft->get_error_message(),$pid);$opts=preg_split('/\r\n|\r|\n/',(string)wp_unslash($_POST['options_text']??''));$key='f_'.substr(wp_generate_password(14,false,false),0,14);$r=QYS_Program_Service::add_field((int)$draft,['field_key'=>$key,'label'=>wp_unslash($_POST['label']??''),'field_type'=>sanitize_key(wp_unslash($_POST['field_type']??'')),'required'=>!empty($_POST['required']),'help_text'=>wp_unslash($_POST['help_text']??''),'options'=>$opts,'sort_order'=>absint($_POST['sort_order']??0)]);self::notice_redirect(is_wp_error($r)?$r->get_error_message():'فیلد به پیش‌نویس فرم اضافه شد.',$pid);}
        if($action==='prepare_field_edit'){$fid=absint($_POST['field_id']??0);$r=QYS_Program_Service::prepare_field_for_edit($fid,$pid,$actor);self::notice_redirect(is_wp_error($r)?$r->get_error_message():'نسخه قابل ویرایش فرم آماده شد.',$pid,is_wp_error($r)?[]:['edit_field'=>(int)$r,'_anchor'=>'qys-program-form-edit']);}
        if($action==='update_field'){$fid=absint($_POST['field_id']??0);$opts=preg_split('/\r\n|\r|\n/',(string)wp_unslash($_POST['options_text']??''));$r=QYS_Program_Service::update_draft_field($fid,$pid,['label'=>wp_unslash($_POST['label']??''),'field_type'=>sanitize_key(wp_unslash($_POST['field_type']??'')),'required'=>!empty($_POST['required']),'help_text'=>wp_unslash($_POST['help_text']??''),'options'=>$opts,'sort_order'=>absint($_POST['sort_order']??0)],$actor);self::notice_redirect(is_wp_error($r)?$r->get_error_message():'ویرایش سؤال ذخیره شد.',$pid,is_wp_error($r)?['edit_field'=>$fid,'_anchor'=>'qys-program-form-edit']:['_anchor'=>'qys-program-form']);}
        if($action==='remove_field'){$fid=absint($_POST['field_id']??0);self::notice_redirect(QYS_Program_Service::remove_draft_field($fid,$pid)?'فیلد از پیش‌نویس حذف شد.':'حذف فیلد انجام نشد.',$pid);}
        if($action==='publish_form'){$draft=QYS_Program_Repository::draft_form($pid);$r=$draft?QYS_Program_Service::publish_form((int)$draft->id,$actor):new WP_Error('form','پیش‌نویس فرم وجود ندارد.');self::notice_redirect(is_wp_error($r)?$r->get_error_message():'نسخه جدید فرم منتشر شد.',$pid);}
        if($action==='program_status'){$to=sanitize_key(wp_unslash($_POST['to_status']??''));self::require_destructive_confirmation($action,$to);$r=QYS_Program_Service::transition_program($pid,$to,$actor);self::notice_redirect(is_wp_error($r)?$r->get_error_message():'وضعیت برنامه تغییر کرد.',$pid);}
        if($action==='application_status'){$aid=absint($_POST['application_id']??0);$app=QYS_Program_Repository::get_application($aid);if(!$app||(int)$app->program_id!==$pid)wp_die('درخواست نامعتبر است.',400);$scope=(string)self::scope();if(!QYS_Program_Repository::application_in_scope($aid,$scope))wp_die('این درخواست در دامنه دانشگاه شما نیست.',403);$to=sanitize_key(wp_unslash($_POST['to_status']??''));self::require_destructive_confirmation($action,$to);$r=QYS_Program_Service::transition_application($aid,$to,$actor,(string)wp_unslash($_POST['decision_note']??''));$return=self::applicant_filter_args(self::applicant_filters_from_post());$return['_anchor']='qys-program-applicants';$message=is_wp_error($r)?$r->get_error_message():'وضعیت درخواست به‌روز شد.';if(!is_wp_error($r)&&array_key_exists($to,QYS_Program_Notifications::labels()))$message.=QYS_Program_Notifications::summary_text(QYS_Program_Notifications::queue_for_applications_safe([$aid],$to));self::notice_redirect($message,$pid,$return);}
        if($action==='enroll'){$aid=absint($_POST['application_id']??0);$app=QYS_Program_Repository::get_application($aid);if(!$app||(int)$app->program_id!==$pid)wp_die('درخواست نامعتبر است.',400);$scope=(string)self::scope();if(!QYS_Program_Repository::application_in_scope($aid,$scope))wp_die('این درخواست در دامنه دانشگاه شما نیست.',403);$r=QYS_Program_Service::enroll_application($aid,$actor);$return=self::applicant_filter_args(self::applicant_filters_from_post());$return['_anchor']='qys-program-applicants';$message=is_wp_error($r)?$r->get_error_message():'ثبت‌نام فرد قطعی شد.';if(!is_wp_error($r))$message.=QYS_Program_Notifications::summary_text(QYS_Program_Notifications::queue_for_applications_safe([$aid],'enrolled'));self::notice_redirect($message,$pid,$return);}
        if($action==='bulk_applications'){
            $bulk=sanitize_key(wp_unslash($_POST['bulk_action']??''));$allowed=['under_review','waitlisted','accepted','rejected','enroll'];
            $return=self::applicant_filter_args(self::applicant_filters_from_post());$return['_anchor']='qys-program-applicants';
            if(!in_array($bulk,$allowed,true))self::notice_redirect('عملیات گروهی معتبر انتخاب نشده است.',$pid,$return);
            $ids=array_values(array_unique(array_filter(array_map('absint',(array)wp_unslash($_POST['application_ids']??[])))));
            if(!$ids)self::notice_redirect('برای عملیات گروهی حداقل یک متقاضی را انتخاب کنید.',$pid,$return);
            if(count($ids)>self::BULK_APPLICATION_LIMIT)self::notice_redirect('در هر عملیات گروهی حداکثر '.self::BULK_APPLICATION_LIMIT.' متقاضی قابل پردازش است.',$pid,$return);
            self::require_destructive_confirmation($action,$bulk);
            $scope=(string)self::scope();$rows=QYS_Program_Repository::applications_in_scope_by_ids($pid,$ids,$scope);
            $found=array_map(static fn($row)=>(int)$row->id,$rows);sort($found);$expected=$ids;sort($expected);
            if($found!==$expected)wp_die('حداقل یکی از درخواست‌های انتخاب‌شده در دامنه این برنامه یا دانشگاه نیست.',403);
            $program=QYS_Program_Repository::get_program($pid);if(!$program||in_array((string)$program->status,['completed','cancelled','archived'],true))self::notice_redirect('فرآیند پذیرش این برنامه بسته شده است.',$pid,$return);
            if($bulk==='waitlisted'&&(int)$program->waitlist_enabled!==1)self::notice_redirect('فهرست انتظار برای این برنامه فعال نیست.',$pid,$return);
            if($bulk==='accepted'){
                $snapshot=QYS_Program_Service::admission_snapshot($pid);
                if(is_wp_error($snapshot))self::notice_redirect($snapshot->get_error_message(),$pid,$return);
                $actionable=0;foreach($rows as $row)if(QYS_Program_Service::can_application_transition((string)$row->status,'accepted'))$actionable++;
                if(!empty($snapshot['limited'])&&$actionable>(int)$snapshot['remaining'])self::notice_redirect('ظرفیت باقی‌مانده برای پذیرش گروهی کافی نیست. ظرفیت آزاد: '.number_format_i18n((int)$snapshot['remaining']).'، درخواست قابل پذیرش انتخاب‌شده: '.number_format_i18n($actionable).'.',$pid,$return);
            }
            $ok=0;$skipped=0;$failed=0;$first_error='';$notify_ids=[];
            foreach($rows as $row){
                if($bulk==='enroll'){
                    $can_enroll=(string)$row->status==='accepted' && (empty($row->enrollment_id)||(string)($row->enrollment_status??'')==='withdrawn');
                    if(!$can_enroll){$skipped++;continue;}
                    $result=QYS_Program_Service::enroll_application((int)$row->id,$actor);
                }else{
                    if(!QYS_Program_Service::can_application_transition((string)$row->status,$bulk)){$skipped++;continue;}
                    $result=QYS_Program_Service::transition_application((int)$row->id,$bulk,$actor,'');
                }
                if(is_wp_error($result)){$failed++;if($first_error==='')$first_error=$result->get_error_message();}else{$ok++;$notify_ids[]=(int)$row->id;}
            }
            $parts=['موفق: '.number_format_i18n($ok),'نامرتبط با وضعیت فعلی: '.number_format_i18n($skipped),'ناموفق: '.number_format_i18n($failed)];
            $message='عملیات گروهی انجام شد — '.implode('، ',$parts).'.';if($first_error!=='')$message.=' اولین خطا: '.$first_error;
            if($notify_ids){$event=$bulk==='enroll'?'enrolled':$bulk;if(array_key_exists($event,QYS_Program_Notifications::labels()))$message.=QYS_Program_Notifications::summary_text(QYS_Program_Notifications::queue_for_applications_safe($notify_ids,$event));}
            self::notice_redirect($message,$pid,$return);
        }
        if($action==='create_attendance'){$p=QYS_Program_Repository::get_program($pid);if(!$p||(int)$p->attendance_enabled!==1)self::notice_redirect('حضور و غیاب برای این برنامه فعال نشده است.',$pid);if(!in_array((string)$p->status,['confirmed','running'],true))self::notice_redirect('ابتدا برنامه را قطعی کنید.',$pid);$r=QYS_Program_Attendance_Boundary::create_for_core_program($pid,$actor);self::notice_redirect(is_wp_error($r)?$r->get_error_message():'QR ثابت حضور و غیاب ساخته شد.',$pid);}
    }

    public static function page(): void {
        if(self::scope()===null){echo QYS_Portal_Experience::alert_state('دسترسی فعال نیست','مجوز مدیریت برنامه‌ها برای این حساب فعال نشده است.');return;}$program_id=absint($_GET['program_id']??0);$view=sanitize_key(wp_unslash($_GET['view']??''));$notice=trim(sanitize_text_field(wp_unslash($_GET['qys_program_notice']??'')));
        $portal=QYS_Multitenant::is_portal_request();if(!$portal)echo '<div class="wrap qys-admin qys-program-admin" dir="rtl">';if($notice!=='')echo '<div class="qys-program-notice">'.esc_html($notice).'</div>';
        if($program_id){if(!self::visible($program_id)){echo QYS_Portal_Experience::alert_state('برنامه در دسترس نیست','این برنامه در دامنه مدیریتی شما قرار ندارد.');}else self::render_detail($program_id);}elseif($view==='create')self::render_create();else self::render_index();if(!$portal)echo '</div>';
    }

    private static function render_global_notification_settings(string $scope): void {
        $templates=QYS_Program_Notifications::scope_templates($scope);$labels=QYS_Program_Notifications::labels();
        $scope_label=$scope==='*'?'پیش‌فرض سراسری همه دانشگاه‌ها':('سراسری '.QYS_Multitenant::university_label($scope));
        $description=$scope==='*'
            ?'این ۵ متن، پیش‌فرض همه برنامه‌ها و دانشگاه‌ها هستند. دانشگاهی که متن اختصاصی سراسری خود را ذخیره کند، همان متن را برای همه برنامه‌های خودش استفاده می‌کند.'
            :'این ۵ متن برای همه برنامه‌های این دانشگاه استفاده می‌شوند. داخل هر برنامه تنظیم پیام جداگانه وجود ندارد.';
        echo '<section id="qys-program-notifications-global" class="qys-program-panel qys-program-panel--notifications qys-program-panel--global-notifications"><div class="qys-program-panel-title"><div><span class="qys-program-eyebrow">اطلاع‌رسانی خودکار سراسری</span><h2>پیام‌های تغییر وضعیت برنامه‌ها</h2><p>'.esc_html($description).'</p></div><span class="qys-program-chip">'.esc_html($scope_label).'</span></div><details class="qys-program-notification-editor"><summary><span class="qys-program-notification-summary-copy"><strong>ویرایش پیام‌های سراسری وضعیت</strong><small>۵ متن مشترک برای همه برنامه‌ها: بررسی، انتظار، پذیرش، رد و ثبت‌نام قطعی</small></span><span class="qys-program-notification-summary-action"><span class="is-closed">باز کردن تنظیمات</span><span class="is-open">بستن تنظیمات</span></span></summary><form method="post" class="qys-program-notification-form">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="save_notifications"><p class="qys-program-note qys-program-notification-note">متغیرهای مجاز: <code>{نام}</code>، <code>{نام خانوادگی}</code>، <code>{برنامه}</code>، <code>{وضعیت}</code>، <code>{دانشگاه}</code>. نام برنامه هنگام ارسال به‌صورت خودکار جای‌گذاری می‌شود؛ بنابراین همین متن‌ها برای همه برنامه‌ها قابل استفاده‌اند.</p>';
        foreach($labels as $event=>$label)echo '<label><span>'.esc_html($label).'</span><textarea name="notify_'.esc_attr($event).'" rows="4" maxlength="1200" required>'.esc_textarea((string)($templates[$event]??'')).'</textarea></label>';
        echo '<div class="qys-program-actions"><button class="button button-primary" type="submit">ذخیره پیام‌های سراسری</button></div></form></details></section>';
    }

    private static function render_index(): void {$scope=(string)self::scope();$rows=QYS_Program_Repository::list_for_scope($scope,150);$program_ids=array_map(static fn($row)=>(int)$row->id,$rows);$count_map=QYS_Program_Repository::counts_for_programs($program_ids,$scope);$apps=0;$open=0;$running=0;foreach($rows as $r){if($r->status==='registration_open')$open++;if($r->status==='running')$running++;$apps+=(int)($count_map[(int)$r->id]['applications']??0);}
        echo QYS_Portal_Experience::section_head('برنامه‌ها و ثبت‌نام','مدیریت برنامه‌ها','از پیش‌ثبت‌نام تا پذیرش، حضور و سابقه فعالیت را در یک جریان واحد مدیریت کنید.',number_format_i18n(count($rows)).' برنامه');
        echo '<div class="qys-program-metrics">'.QYS_Portal_Experience::metric('کل برنامه‌ها',count($rows),'info').QYS_Portal_Experience::metric('ثبت‌نام باز',$open,'good').QYS_Portal_Experience::metric('در حال اجرا',$running,'attention').QYS_Portal_Experience::metric('درخواست‌های این دامنه',$apps,'default').'</div>';
        echo '<div class="qys-program-toolbar"><a class="qys-tenant-btn button button-primary" href="'.esc_url(self::url(['view'=>'create'])).'">+ برنامه جدید</a></div>';
        self::render_global_notification_settings($scope);
        echo '<section class="qys-program-panel"><h2>برنامه‌های شما</h2>';
        if(!$rows){echo QYS_Portal_Experience::empty_state('هنوز برنامه‌ای ایجاد نشده است','اولین برنامه را برای کلاس، دوره، کارگاه، مسابقه یا هر فعالیت دیگری ایجاد کنید.',self::url(['view'=>'create']),'ایجاد برنامه');echo '</section>';return;}
        echo '<div class="qys-program-cards">';foreach($rows as $p){$count=(int)($count_map[(int)$p->id]['applications']??0);$en=(int)($count_map[(int)$p->id]['enrollments']??0);$manageable=!empty($p->qys_manageable);echo '<article class="qys-program-card"><div><span class="qys-program-chip is-'.esc_attr($p->status).'">'.esc_html(self::PROGRAM_LABELS[$p->status]??$p->status).'</span><h3>'.esc_html($p->title).'</h3><p>'.esc_html(self::TYPES[$p->program_type]??'برنامه').' · '.($p->capacity?'ظرفیت '.number_format_i18n((int)$p->capacity):'بدون سقف ظرفیت').(!$manageable?' · برنامه مشترک':'').'</p></div><div class="qys-program-card-stats"><span>درخواست <strong>'.number_format_i18n($count).'</strong></span><span>قطعی <strong>'.number_format_i18n($en).'</strong></span></div><a class="button button-primary" href="'.esc_url(self::url(['program_id'=>(int)$p->id])).'">'.($manageable?'مدیریت':'متقاضیان دانشگاه').'</a></article>'; }echo '</div></section>';
    }

    private static function render_create(): void {$scope=self::scope();$roles=QYS_Messenger::member_roles();echo QYS_Portal_Experience::section_head('برنامه جدید','ایجاد برنامه','این موجودیت می‌تواند کلاس، دوره، مسابقه، اردو یا هر فعالیت دیگری باشد. فرم و حضور و غیاب در ادامه به همین برنامه متصل می‌شوند.');echo '<section class="qys-program-panel"><form method="post" class="qys-program-form">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="create"><label class="qys-program-wide">عنوان برنامه<input name="title" required maxlength="190" placeholder="مثلاً دوره آموزش روخوانی قرآن"></label><label class="qys-program-wide">توضیح کوتاه<textarea name="description" rows="4" placeholder="توضیحی که شرکت‌کننده قبل از ثبت‌نام می‌بیند"></textarea></label><label>نوع برنامه<select name="program_type">';foreach(self::TYPES as $k=>$v)echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';echo '</select></label><label>ظرفیت <input type="number" min="0" name="capacity" value="0"><small>صفر یعنی بدون سقف</small></label>';
        if($scope==='*'){echo '<fieldset class="qys-program-wide"><legend>دانشگاه‌های مخاطب</legend><div class="qys-program-checks">';foreach(QYS_Multitenant::universities() as $u)echo '<label><input type="checkbox" name="universities[]" value="'.esc_attr($u['code']).'"> '.esc_html($u['title']).'</label>';echo '</div></fieldset><label>دانشگاه مدیر<select name="manager_university"><option value="">انتخاب کنید</option>';foreach(QYS_Multitenant::universities() as $u)echo '<option value="'.esc_attr($u['code']).'">'.esc_html($u['title']).'</option>';echo '</select></label>';}
        echo '<fieldset class="qys-program-wide"><legend>گروه‌های مجاز <small>عدم انتخاب یعنی همه اعضای تأییدشده</small></legend><div class="qys-program-checks">';foreach($roles as $k=>$v)echo '<label><input type="checkbox" name="audience_roles[]" value="'.esc_attr($k).'"> '.esc_html($v).'</label>';echo '</div></fieldset><label class="qys-program-toggle"><input type="checkbox" name="waitlist_enabled" value="1" checked> لیست انتظار فعال باشد</label><label class="qys-program-toggle"><input type="checkbox" name="attendance_enabled" value="1"> این برنامه حضور و غیاب خواهد داشت</label><div class="qys-program-wide qys-program-actions"><button class="button button-primary button-hero" type="submit">ایجاد برنامه</button><a class="button" href="'.esc_url(self::url()).'">انصراف</a></div></form></section>';
    }

    private static function render_program_editor(int $pid,$p,array $universities,array $admission,$link,bool $manageable): void {
        $policy=QYS_Program_Service::edit_policy($p,(bool)$link);
        echo '<section id="qys-program-details" class="qys-program-panel qys-program-panel--details"><div class="qys-program-panel-title"><div><span class="qys-program-eyebrow">مشخصات اصلی</span><h2>ویرایش برنامه</h2><p>فیلدها بر اساس مرحله جاری برنامه باز یا قفل می‌شوند تا سابقه ثبت‌نام و حضور ناسازگار نشود.</p></div><span class="qys-program-edit-state">'.(!empty($policy['editable'])?'ویرایش مجاز':'فقط خواندنی').'</span></div>';
        if(!$manageable){
            echo '<p class="qys-program-note">این برنامه مشترک است؛ مشخصات اصلی فقط توسط دانشگاه مالک یا مدیر کل ویرایش می‌شود.</p></section>';
            return;
        }
        if(empty($policy['editable'])){
            echo '<div class="qys-program-readonly-summary"><strong>'.esc_html($p->title).'</strong><p>'.esc_html(wp_strip_all_tags((string)$p->description)).'</p><span>ظرفیت: '.((int)$p->capacity?number_format_i18n((int)$p->capacity):'بدون سقف').'</span><span>مخاطبان: '.esc_html(((string)$p->audience_roles==='all'||(string)$p->audience_roles==='')?'همه گروه‌ها':(string)$p->audience_roles).'</span><span>حضور: '.((int)$p->attendance_enabled===1?'فعال':'غیرفعال').'</span></div><p class="qys-program-note">برنامه‌های پایان‌یافته، لغوشده یا بایگانی‌شده برای حفظ سابقه قابل ویرایش نیستند.</p></section>';
            return;
        }
        $roles=QYS_Messenger::member_roles();$stored_roles=array_filter(explode(',',(string)$p->audience_roles));$all_roles=(string)$p->audience_roles===''||(string)$p->audience_roles==='all';
        $scope=(string)self::scope();$capacity_min=max(0,(int)($admission['used']??0));
        echo '<form method="post" class="qys-program-form qys-program-form--edit">';wp_nonce_field('qys_programs','qys_program_nonce');
        echo '<input type="hidden" name="qys_program_action" value="update_program"><input type="hidden" name="program_id" value="'.(int)$pid.'">';
        if(!empty($policy['title']))echo '<label class="qys-program-wide">عنوان برنامه<input name="title" required maxlength="190" value="'.esc_attr((string)$p->title).'"></label>';
        else echo '<input type="hidden" name="title" value="'.esc_attr((string)$p->title).'">';
        if(!empty($policy['description']))echo '<label class="qys-program-wide">توضیح کوتاه<textarea name="description" rows="4">'.esc_textarea((string)$p->description).'</textarea></label>';
        else echo '<input type="hidden" name="description" value="'.esc_attr((string)$p->description).'">';
        if(!empty($policy['capacity']))echo '<label>ظرفیت<input type="number" min="0" name="capacity" value="'.(int)$p->capacity.'"><small>صفر یعنی بدون سقف؛ ظرفیت محدود نمی‌تواند از '.number_format_i18n($capacity_min).' تعهد فعلی کمتر شود.</small></label>';
        else echo '<input type="hidden" name="capacity" value="'.(int)$p->capacity.'">';
        if(!empty($policy['audience'])){
            echo '<fieldset class="qys-program-wide"><legend>گروه‌های مجاز <small>عدم انتخاب یعنی همه اعضای تأییدشده</small></legend><div class="qys-program-checks">';
            foreach($roles as $k=>$v)echo '<label><input type="checkbox" name="audience_roles[]" value="'.esc_attr($k).'"'.checked(!$all_roles&&in_array($k,$stored_roles,true),true,false).'> '.esc_html($v).'</label>';
            echo '</div></fieldset>';
            if($scope==='*'){
                echo '<fieldset class="qys-program-wide"><legend>دانشگاه‌های مخاطب <small>فقط در پیش‌نویس قابل تغییر است؛ دانشگاه مدیر باید باقی بماند.</small></legend><div class="qys-program-checks">';
                foreach(QYS_Multitenant::universities() as $u)echo '<label><input type="checkbox" name="universities[]" value="'.esc_attr($u['code']).'"'.checked(in_array((string)$u['code'],$universities,true),true,false).'> '.esc_html($u['title']).'</label>';
                echo '</div></fieldset>';
            }else{
                foreach($universities as $u)echo '<input type="hidden" name="universities[]" value="'.esc_attr($u).'">';
            }
        }else{
            foreach($stored_roles as $role)echo '<input type="hidden" name="audience_roles[]" value="'.esc_attr($role).'">';
            foreach($universities as $u)echo '<input type="hidden" name="universities[]" value="'.esc_attr($u).'">';
            echo '<div class="qys-program-wide qys-program-lock-note">مخاطبان پس از خروج از پیش‌نویس قفل می‌شوند تا Eligibility درخواست‌های موجود تغییر نکند.</div>';
        }
        if(!empty($policy['attendance'])){
            echo '<input type="hidden" name="attendance_enabled" value="0"><label class="qys-program-toggle"><input type="checkbox" name="attendance_enabled" value="1"'.checked((int)$p->attendance_enabled,1,false).'> این برنامه حضور و غیاب خواهد داشت</label>';
        }else{
            if((int)$p->attendance_enabled===1)echo '<input type="hidden" name="attendance_enabled" value="1">';
            echo '<div class="qys-program-lock-note">تنظیم حضور در این مرحله'.($link?' به دلیل ایجاد اتصال حضور':' بر اساس وضعیت برنامه').' قفل است.</div>';
        }
        echo '<div class="qys-program-wide qys-program-actions"><button class="button button-primary" type="submit">ذخیره مشخصات</button></div></form></section>';
    }

    private static function render_detail(int $pid): void {
        $scope=(string)self::scope();$manageable=self::manageable($pid);$p=QYS_Program_Repository::get_program($pid);$universities=QYS_Program_Repository::program_universities($pid);$app_filters=self::applicant_filters_from_get();$apps=QYS_Program_Repository::applications_for_program_filtered($pid,$scope,$app_filters,500);$total_apps=QYS_Program_Repository::count_applications_for_program($pid,$scope);$enrolled=QYS_Program_Repository::count_enrollments_for_program($pid,$scope);$draft=$manageable?QYS_Program_Repository::draft_form($pid):null;$published=QYS_Program_Repository::current_form($pid);$draft_id=$draft?(int)$draft->id:0;$active_form_id=$draft_id?:($published?(int)$published->id:0);$fields=QYS_Program_Repository::form_fields($active_form_id);$link=QYS_Program_Repository::attendance_link($pid);$admission=QYS_Program_Service::admission_snapshot($pid);if(is_wp_error($admission))$admission=['capacity'=>(int)$p->capacity,'limited'=>(int)$p->capacity>0,'used'=>0,'remaining'=>(int)$p->capacity>0?(int)$p->capacity:null,'full'=>false,'waitlist_enabled'=>(int)$p->waitlist_enabled===1,'waitlisted'=>0];$capacity_full=!empty($admission['full']);
        $edit_id=$manageable?absint($_GET['edit_field']??0):0;$edit_field=$edit_id?QYS_Program_Repository::get_field_context($edit_id):null;if(!$edit_field||(int)$edit_field->program_id!==$pid||(string)$edit_field->form_status!=='draft')$edit_field=null;
        $status=(string)$p->status;$program_terminal=in_array($status,['completed','cancelled','archived'],true);$guidance=match($status){
            'draft'=>$published?'فرم آماده است؛ در صورت اطمینان ثبت‌نام را باز کنید.':'ابتدا سؤال‌های فرم را تکمیل و یک نسخه منتشر کنید.',
            'registration_open'=>'ثبت‌نام باز است؛ درخواست‌ها را دریافت و وضعیت متقاضیان را بررسی کنید.',
            'registration_closed'=>'ثبت‌نام بسته است؛ اکنون می‌توانید درخواست‌ها را نهایی و برنامه را قطعی کنید.',
            'confirmed'=>'برگزاری قطعی شده است؛ در صورت نیاز حضور و غیاب را آماده کنید.',
            'running'=>'برنامه در حال اجراست؛ حضورها و وضعیت شرکت‌کنندگان را پیگیری کنید.',
            'completed'=>'برنامه پایان یافته و سوابق شرکت قابل جمع‌بندی است.',
            'cancelled'=>'برنامه لغو شده است. برای نگهداری سوابق می‌توانید آن را بایگانی کنید.',
            'archived'=>'این برنامه بایگانی شده است.',default=>'وضعیت برنامه را بررسی کنید.'};
        echo '<div class="qys-program-detail-head"><div class="qys-program-detail-copy"><a class="qys-program-back" href="'.esc_url(self::url()).'">← بازگشت به برنامه‌ها</a><div class="qys-program-title-line"><span class="qys-program-chip is-'.esc_attr($p->status).'">'.esc_html(self::PROGRAM_LABELS[$p->status]??$p->status).'</span><span class="qys-program-type-label">'.esc_html(self::TYPES[$p->program_type]??'برنامه').'</span></div><h1>'.esc_html($p->title).'</h1><p>'.esc_html(implode('، ',array_map([QYS_Multitenant::class,'university_label'],$universities))).'</p></div><div class="qys-program-head-stats"><span>درخواست این دامنه <strong>'.number_format_i18n($total_apps).'</strong></span><span>ثبت‌نام قطعی این دامنه <strong>'.number_format_i18n($enrolled).'</strong></span><span>ظرفیت رزروشده کل <strong>'.number_format_i18n((int)$admission['used']).'</strong></span><span>فهرست انتظار کل <strong>'.number_format_i18n((int)$admission['waitlisted']).'</strong></span></div></div>';
        if(!empty($admission['limited'])){echo '<div class="qys-program-note"><strong>ظرفیت کل برنامه: '.number_format_i18n((int)$admission['capacity']).'</strong> · رزروشده/فعال: '.number_format_i18n((int)$admission['used']).' · باقی‌مانده: '.number_format_i18n((int)$admission['remaining']).(!empty($admission['waitlist_enabled'])?' · فهرست انتظار فعال':' · فهرست انتظار غیرفعال').(!empty($admission['over_capacity'])?' · هشدار: داده‌های قدیمی بیش از ظرفیت فعلی تعهد دارند':($capacity_full?' · ظرفیت تکمیل است':'')).'</div>';}else{echo '<div class="qys-program-note">این برنامه سقف ظرفیت ندارد.'.(!empty($admission['waitlist_enabled'])?' فهرست انتظار در صورت نیاز مدیریتی فعال است.':'').'</div>';}
        echo '<nav class="qys-program-quicknav" aria-label="بخش‌های مدیریت برنامه"><a href="#qys-program-details">مشخصات</a><a href="#qys-program-status">وضعیت</a><a href="#qys-program-form">فرم ثبت‌نام</a><a href="#qys-program-attendance">حضور و غیاب</a><a href="#qys-program-applicants">متقاضیان <span>'.number_format_i18n($total_apps).'</span></a></nav>';

        self::render_program_editor($pid,$p,$universities,$admission,$link,$manageable);

        echo '<section id="qys-program-status" class="qys-program-panel qys-program-panel--status"><div class="qys-program-panel-title"><div><span class="qys-program-eyebrow">مرحله جاری</span><h2>وضعیت برنامه</h2><p>'.esc_html($guidance).'</p></div><span class="qys-program-current-state qys-program-chip is-'.esc_attr($p->status).'">'.esc_html(self::PROGRAM_LABELS[$p->status]??$p->status).'</span></div><div class="qys-program-state-actions">';if($manageable){$targets=['registration_open'=>'بازکردن ثبت‌نام','registration_closed'=>'بستن ثبت‌نام','confirmed'=>'قطعی‌کردن برگزاری','running'=>'شروع برنامه','completed'=>'پایان برنامه','cancelled'=>'لغو برنامه','archived'=>'بایگانی'];foreach($targets as $to=>$label)if(QYS_Program_Service::can_program_transition((string)$p->status,$to)){$confirm=self::destructive_confirmation_message('program_status',$to);echo '<form method="post"'.($confirm!==''?' data-qys-confirm="'.esc_attr($confirm).'"':'').'>';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="program_status"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="to_status" value="'.esc_attr($to).'">'.($confirm!==''?'<input type="hidden" name="qys_destructive_confirmed" value="0">':'').'<button class="button '.($to==='registration_open'||$to==='confirmed'?'button-primary':'').'" type="submit">'.esc_html($label).'</button></form>';}}else echo '<span class="qys-program-note">این برنامه مشترک است؛ تغییر وضعیت و ساختار فقط توسط دانشگاه مالک انجام می‌شود.</span>';echo '</div></section>';

        $form_summary='هنوز فرم منتشرشده‌ای وجود ندارد.';if($published&&$draft)$form_summary='نسخه فعال '.number_format_i18n((int)$published->version).' محفوظ است و تغییرات در پیش‌نویس نسخه '.number_format_i18n((int)$draft->version).' انجام می‌شود.';elseif($published)$form_summary='نسخه فعال '.number_format_i18n((int)$published->version).' منتشر شده است. با اولین ویرایش، یک پیش‌نویس امن ساخته می‌شود.';elseif($draft)$form_summary='در حال ساخت پیش‌نویس نسخه '.number_format_i18n((int)$draft->version).' هستید.';
        echo '<section id="qys-program-form" class="qys-program-panel qys-program-panel--form"><div class="qys-program-panel-title"><div><span class="qys-program-eyebrow">فرم شرکت‌کننده</span><h2>فرم پیش‌ثبت‌نام</h2><p>'.esc_html($form_summary).'</p></div><div class="qys-program-form-badges">'.($published?'<span class="qys-program-chip is-registration_open">نسخه فعال '.number_format_i18n((int)$published->version).'</span>':'').($draft?'<span class="qys-program-chip is-under_review">پیش‌نویس '.number_format_i18n((int)$draft->version).'</span>':'').'</div></div>';
        if($fields){echo '<div class="qys-program-field-list">';$n=0;foreach($fields as $f){$n++;echo '<article class="qys-program-field-row"><div class="qys-program-field-main"><span class="qys-program-field-index">'.number_format_i18n($n).'</span><div><strong>'.esc_html($f->label).'</strong><span>'.esc_html(self::FIELD_LABELS[$f->field_type]??$f->field_type).($f->required?' · اجباری':' · اختیاری').' · ترتیب '.number_format_i18n((int)$f->sort_order).'</span>'.($f->help_text!==''?'<small>'.esc_html($f->help_text).'</small>':'').'</div></div><div class="qys-program-field-actions">';if($manageable&&$draft_id){echo '<a class="button button-small" href="'.esc_url(self::url(['program_id'=>$pid,'edit_field'=>(int)$f->id]).'#qys-program-form-edit').'">ویرایش</a><form method="post" onsubmit="return confirm(\'این سؤال از پیش‌نویس حذف شود؟\')">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="remove_field"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="field_id" value="'.(int)$f->id.'"><button class="button-link-delete" type="submit">حذف</button></form>';}elseif($manageable&&$published){echo '<form method="post">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="prepare_field_edit"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="field_id" value="'.(int)$f->id.'"><button class="button button-small" type="submit">ویرایش</button></form>';}echo '</div></article>'; }echo '</div>';}else echo '<div class="qys-program-form-empty"><strong>هنوز سؤالی اضافه نشده است.</strong><span>اطلاعات پایه عضو از پرونده قرآن‌یار خوانده می‌شود؛ فقط اطلاعات اضافه‌ای را بپرسید که واقعاً برای این برنامه لازم است.</span></div>';

        if($manageable&&$edit_field){$edit_options=json_decode((string)$edit_field->options_json,true);if(!is_array($edit_options))$edit_options=[];echo '<div id="qys-program-form-edit" class="qys-program-edit-card"><div class="qys-program-edit-head"><div><span class="qys-program-eyebrow">ویرایش سؤال</span><h3>'.esc_html($edit_field->label).'</h3><p>این تغییر فقط در پیش‌نویس ذخیره می‌شود و تا انتشار نسخه جدید برای اعضا نمایش داده نمی‌شود.</p></div><a class="button" href="'.esc_url(self::url(['program_id'=>$pid]).'#qys-program-form').'">انصراف</a></div><form method="post" class="qys-program-form qys-program-form--compact">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="update_field"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="field_id" value="'.(int)$edit_field->id.'"><label>عنوان سؤال<input name="label" maxlength="190" required value="'.esc_attr($edit_field->label).'"></label><label>نوع فیلد<select name="field_type">';foreach(self::FIELD_LABELS as $k=>$v)echo '<option value="'.esc_attr($k).'"'.selected((string)$edit_field->field_type,$k,false).'>'.esc_html($v).'</option>';echo '</select></label><label>ترتیب<input type="number" min="0" name="sort_order" value="'.(int)$edit_field->sort_order.'"></label><label class="qys-program-toggle"><input type="checkbox" name="required" value="1"'.checked((int)$edit_field->required,1,false).'> پاسخ اجباری است</label><label class="qys-program-wide">راهنمای سؤال<input name="help_text" maxlength="500" value="'.esc_attr($edit_field->help_text).'"></label><label class="qys-program-wide">گزینه‌ها <small>برای تک‌انتخابی و چندانتخابی؛ هر گزینه در یک خط</small><textarea name="options_text" rows="4">'.esc_textarea(implode("\n",$edit_options)).'</textarea></label><div class="qys-program-wide qys-program-actions"><button class="button button-primary" type="submit">ذخیره ویرایش سؤال</button><a class="button" href="'.esc_url(self::url(['program_id'=>$pid]).'#qys-program-form').'">انصراف</a></div></form></div>';}

        if($manageable){
        echo '<details class="qys-program-builder"><summary><span>＋ افزودن سؤال جدید</span><small>فرم ساز کنترل‌شده قرآن‌یار</small></summary><form method="post" class="qys-program-form qys-program-form--compact">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="add_field"><input type="hidden" name="program_id" value="'.$pid.'"><label>عنوان سؤال<input name="label" maxlength="190" required placeholder="مثلاً محل شرکت خود در دوره را انتخاب نمایید"></label><label>نوع فیلد<select name="field_type">';foreach(self::FIELD_LABELS as $k=>$v)echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';echo '</select></label><label>ترتیب<input type="number" min="0" name="sort_order" value="10"></label><label class="qys-program-toggle"><input type="checkbox" name="required" value="1"> پاسخ اجباری است</label><label class="qys-program-wide">راهنمای سؤال<input name="help_text" maxlength="500" placeholder="در صورت نیاز یک توضیح کوتاه برای عضو بنویسید"></label><label class="qys-program-wide">گزینه‌ها <small>فقط برای تک‌انتخابی و چندانتخابی؛ هر گزینه در یک خط</small><textarea name="options_text" rows="4"></textarea></label><div class="qys-program-wide qys-program-actions"><button class="button button-primary" type="submit">افزودن سؤال</button></div></form></details>';
        if($draft_id){echo '<div class="qys-program-publish-bar"><div><strong>تغییرات فرم هنوز منتشر نشده‌اند.</strong><span>پاسخ‌های نسخه‌های قبلی بدون تغییر حفظ می‌شوند.</span></div><form method="post">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="publish_form"><input type="hidden" name="program_id" value="'.$pid.'"><button class="button button-primary" type="submit" onclick="return confirm(\'نسخه فعلی فرم منتشر شود؟ پاسخ‌های قبلی دست‌نخورده می‌مانند.\')">'.($published?'انتشار تغییرات فرم':'انتشار فرم').'</button></form></div>';}
        }elseif(!$published){echo '<p class="qys-program-note">فرم این برنامه هنوز توسط دانشگاه مالک منتشر نشده است.</p>';}
        echo '</section>';

        echo '<section id="qys-program-attendance" class="qys-program-panel"><div class="qys-program-panel-title"><div><span class="qys-program-eyebrow">مرحله اجرا</span><h2>حضور و غیاب</h2><p>'.((int)$p->attendance_enabled===1?'این برنامه برای حضور و غیاب آماده اتصال است.':'برای این برنامه هنگام ایجاد، حضور و غیاب فعال نشده است.').'</p></div></div>';
        if(!$manageable){echo '<p class="qys-program-note">مدیریت QR و ساختار حضور این برنامه مشترک فقط در اختیار دانشگاه مالک است. حضور اعضای دانشگاه شما همچنان طبق قواعد برنامه ثبت می‌شود.</p>';}
        elseif($link){$att=QYS_Program_Attendance_Boundary::get_attendance_program((int)$link->attendance_program_id);echo '<div class="qys-program-attendance-linked"><div><strong>QR ثابت آماده است</strong><span>برای تمام نوبت‌های آینده همین QR استفاده می‌شود.</span></div><span>کد: <b dir="ltr">'.esc_html($att?$att->access_code:'—').'</b></span><a class="button button-primary" href="'.esc_url(QYS_Multitenant::is_portal_request()?QYS_Multitenant::campus_url('attendance',['program_id'=>(int)$link->attendance_program_id]):add_query_arg(['page'=>'qys-attendance','program_id'=>(int)$link->attendance_program_id],admin_url('admin.php'))).'">مدیریت حضور</a></div>';}
        elseif((int)$p->attendance_enabled===1){echo '<div class="qys-program-inline-action"><div><strong>هنوز QR حضور ساخته نشده است.</strong><span>پس از قطعی‌شدن برنامه، یک QR ثابت برای همه نوبت‌ها ایجاد کنید.</span></div><form method="post">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="create_attendance"><input type="hidden" name="program_id" value="'.$pid.'"><button class="button button-primary" type="submit">ایجاد QR ثابت حضور</button></form></div>';}else echo '<p class="qys-program-note">این برنامه بدون حضور و غیاب تعریف شده است.</p>';echo '</section>';

        $filter_args=self::applicant_filter_args($app_filters);$filters_active=!empty($filter_args);$filter_hidden=self::applicant_filter_hidden($app_filters);$roles=QYS_Messenger::member_roles();
        echo '<section id="qys-program-applicants" class="qys-program-panel"><div class="qys-program-panel-title"><div><span class="qys-program-eyebrow">ثبت درخواست و پذیرش</span><h2>متقاضیان و ثبت‌نام‌ها</h2><p>پیش‌ثبت‌نام با سابقه فعالیت یکی نیست؛ فقط حضور واقعی سابقه شرکت ایجاد می‌کند.</p></div><div>';if(QYS_Permission_Service::scope('programs_export')!==null)echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('qys_program_export_'.$pid,'qys_export_nonce',true,false).'<input type="hidden" name="action" value="qys_program_export"><input type="hidden" name="program_id" value="'.$pid.'"><button class="button">خروجی CSV</button></form>';echo '</div></div>';
        echo '<div class="qys-program-applicant-tools"><form method="get" action="'.esc_url(self::url()).'" class="qys-program-app-filter">'.self::applicant_get_context_hidden($pid).'<label>جست‌وجوی نام<input type="search" name="qys_app_q" maxlength="80" value="'.esc_attr((string)$app_filters['search']).'" placeholder="نام یا نام خانوادگی"></label><label>وضعیت درخواست<select name="qys_app_status"><option value="">همه وضعیت‌ها</option>';foreach(self::APP_LABELS as $key=>$label)echo '<option value="'.esc_attr($key).'"'.selected((string)$app_filters['status'],$key,false).'>'.esc_html($label).'</option>';echo '</select></label><label>گروه<select name="qys_app_role"><option value="">همه گروه‌ها</option>';foreach($roles as $key=>$label)echo '<option value="'.esc_attr($key).'"'.selected((string)$app_filters['role'],$key,false).'>'.esc_html($label).'</option>';echo '</select></label><label>ثبت‌نام قطعی<select name="qys_app_enrollment"><option value="">همه</option><option value="with"'.selected((string)$app_filters['enrollment'],'with',false).'>دارد</option><option value="without"'.selected((string)$app_filters['enrollment'],'without',false).'>ندارد</option></select></label><div class="qys-program-app-filter-actions"><button class="button" type="submit">اعمال فیلتر</button>'.($filters_active?'<a class="button" href="'.esc_url(self::url(['program_id'=>$pid]).'#qys-program-applicants').'">پاک‌کردن فیلتر</a>':'').'</div></form><div class="qys-program-app-result-count">نمایش <strong>'.number_format_i18n(count($apps)).'</strong> از <strong>'.number_format_i18n($total_apps).'</strong> درخواست این دامنه</div></div>';
        if($total_apps===0){echo QYS_Portal_Experience::empty_state('هنوز پیش‌ثبت‌نامی ثبت نشده است','پس از بازشدن ثبت‌نام، اعضای واجد شرایط می‌توانند از داخل برنامک قرآن‌یار درخواست خود را ثبت کنند.');echo '</section>';return;}
        if(!$apps){echo QYS_Portal_Experience::empty_state('موردی با این فیلتر پیدا نشد','فیلترها یا عبارت جست‌وجوی نام را تغییر دهید.',self::url(['program_id'=>$pid]).'#qys-program-applicants','نمایش همه متقاضیان');echo '</section>';return;}
        if(!$program_terminal){
            $bulk_actions=['under_review'=>'انتقال به بررسی'];if(!empty($admission['waitlist_enabled']))$bulk_actions['waitlisted']='انتقال به فهرست انتظار';if(!$capacity_full)$bulk_actions['accepted']='پذیرش';$bulk_actions['rejected']='رد درخواست';$bulk_actions['enroll']='ثبت‌نام قطعی پذیرفته‌شده‌ها';
            echo '<form id="qys-program-bulk-form" class="qys-program-bulk-bar" method="post" data-qys-applicant-bulk data-qys-bulk-max="'.self::BULK_APPLICATION_LIMIT.'" data-qys-reject-message="'.esc_attr(self::destructive_confirmation_message('bulk_applications','rejected')).'">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="bulk_applications"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="qys_destructive_confirmed" value="0">'.$filter_hidden.'<label>عملیات روی انتخاب‌شده‌ها<select name="bulk_action" required><option value="">انتخاب عملیات</option>';foreach($bulk_actions as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';echo '</select></label><button class="button button-primary" type="submit">اجرا</button><span class="qys-program-bulk-count" data-qys-selected-count>۰ انتخاب</span><small>حداکثر '.number_format_i18n(self::BULK_APPLICATION_LIMIT).' متقاضی در هر اجرا؛ هر ردیف با همان قواعد ظرفیت، صلاحیت و Audit پردازش می‌شود.</small></form>';
        }
        $answer_map=QYS_Program_Repository::answers_for_applications(array_map(static fn($row)=>(int)$row->id,$apps));
        $family_map=self::family_summaries_for_application_rows($apps);
        echo '<div class="qys-table-scroll"><table class="widefat striped qys-program-app-table"><thead><tr>'.(!$program_terminal?'<th class="qys-program-select-cell"><input type="checkbox" data-qys-app-select-all aria-label="انتخاب همه ردیف‌های نمایش‌داده‌شده"></th>':'').'<th>عضو</th><th>گروه</th><th>انتساب خانوادگی</th><th>درخواست</th><th>ثبت‌نام قطعی</th><th>پاسخ‌ها</th><th>عملیات</th></tr></thead><tbody>';foreach($apps as $a){$answers=$answer_map[(int)$a->id]??[];echo '<tr>'.(!$program_terminal?'<td class="qys-program-select-cell"><input type="checkbox" name="application_ids[]" value="'.(int)$a->id.'" form="qys-program-bulk-form" data-qys-applicant-checkbox aria-label="انتخاب '.esc_attr(trim($a->first_name.' '.$a->last_name)).'"></td>':'').'<td class="qys-program-member-cell"><strong>'.esc_html(trim($a->first_name.' '.$a->last_name)).'</strong><small>'.esc_html(QYS_Multitenant::university_label((string)$a->university)).'</small></td><td>'.esc_html($roles[$a->member_role]??$a->member_role).'</td><td>'.esc_html((string)($family_map[(int)$a->member_id]??'—')).'</td><td><span class="qys-program-chip is-'.esc_attr($a->status).'">'.esc_html(self::APP_LABELS[$a->status]??$a->status).'</span></td><td>'.($a->enrollment_status?'<span class="qys-program-chip">'.esc_html(self::ENROLL_LABELS[$a->enrollment_status]??$a->enrollment_status).'</span>':'—').'</td><td>';if($answers){echo '<details><summary>'.count($answers).' پاسخ</summary><dl class="qys-program-answers">';foreach($answers as $ans)echo '<dt>'.esc_html($ans->label).'</dt><dd>'.esc_html(self::display_answer($ans)).'</dd>';echo '</dl></details>';}else echo '—';echo '</td><td><div class="qys-program-row-actions">';if(!$program_terminal){foreach(['under_review'=>'بررسی','waitlisted'=>'انتظار','accepted'=>'پذیرش','rejected'=>'رد'] as $to=>$label){if($to==='waitlisted'&&empty($admission['waitlist_enabled']))continue;if($to==='accepted'&&$capacity_full)continue;if(QYS_Program_Service::can_application_transition((string)$a->status,$to)){$confirm=self::destructive_confirmation_message('application_status',$to);echo '<form method="post"'.($confirm!==''?' data-qys-confirm="'.esc_attr($confirm).'"':'').'>';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="application_status"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="application_id" value="'.(int)$a->id.'"><input type="hidden" name="to_status" value="'.esc_attr($to).'">'.$filter_hidden.($confirm!==''?'<input type="hidden" name="qys_destructive_confirmed" value="0">':'').'<button class="button button-small" type="submit">'.esc_html($label).'</button></form>';}}if($capacity_full&&QYS_Program_Service::can_application_transition((string)$a->status,'accepted'))echo '<span class="qys-program-note">ظرفیت تکمیل است'.(!empty($admission['waitlist_enabled'])?'؛ از فهرست انتظار استفاده کنید.':'.').'</span>';if($a->status==='accepted'&&(!$a->enrollment_id||(string)$a->enrollment_status==='withdrawn')){$reenroll=(string)$a->enrollment_status==='withdrawn';echo '<form method="post">';wp_nonce_field('qys_programs','qys_program_nonce');echo '<input type="hidden" name="qys_program_action" value="enroll"><input type="hidden" name="program_id" value="'.$pid.'"><input type="hidden" name="application_id" value="'.(int)$a->id.'">'.$filter_hidden.'<button class="button button-primary button-small" type="submit">'.($reenroll?'ثبت‌نام مجدد قطعی':'ثبت‌نام قطعی').'</button></form>';}}else echo '<span class="qys-program-note">فرآیند پذیرش این برنامه بسته شده است.</span>';echo '</div></td></tr>'; }echo '</tbody></table></div></section>';
    }

    private static function family_summaries_for_application_rows(array $rows): array {
        $groups=[];
        foreach($rows as $row){
            $member_id=(int)($row->member_id??0);$scope=sanitize_key((string)($row->university??''));
            if($member_id>0&&$scope!=='')$groups[$scope][$member_id]=true;
        }
        $out=[];
        foreach($groups as $scope=>$ids){
            foreach(QYS_Family_Relationship_Repository::summaries_for_members(array_keys($ids),(string)$scope) as $member_id=>$summary)$out[(int)$member_id]=(string)$summary;
        }
        return $out;
    }

    private static function display_answer($a): string {$v=(string)$a->answer_value;if($a->field_type==='multi_choice'){$j=json_decode($v,true);if(is_array($j))return implode('، ',$j);}if($a->field_type==='yes_no')return in_array($v,['1','yes'],true)?'بله':'خیر';return $v;}
    private static function mask(string $v): string {$v=trim($v);$n=mb_strlen($v);if($n<=4)return str_repeat('•',$n);return mb_substr($v,0,2).str_repeat('•',max(2,$n-4)).mb_substr($v,-2);}

    private static function csv_safe(string $value): string {
        $value=(string)$value;if($value!==''&&preg_match('/^[\x00-\x20]*[=+\-@]/u',$value))return "'".$value;return $value;
    }

    public static function export_csv(): void {
        $pid=absint($_POST['program_id']??0);check_admin_referer('qys_program_export_'.$pid,'qys_export_nonce');
        $scope=QYS_Permission_Service::scope('programs_export');
        if($scope===null||!QYS_Program_Repository::program_visible_in_scope($pid,(string)$scope))wp_die('دسترسی خروجی مجاز نیست.',403);
        $p=QYS_Program_Repository::get_program($pid);if(!$p)wp_die('برنامه پیدا نشد.',404);
        $fields=QYS_Program_Repository::all_form_fields($pid);
        $sensitive_scope=QYS_Permission_Service::scope('sensitive_identity');$sensitive=$sensitive_scope==='*'||($scope!=='*'&&$sensitive_scope===$scope);
        $head=['نام','نام خانوادگی','دانشگاه','گروه','انتساب خانوادگی','کد ملی','موبایل','وضعیت درخواست','وضعیت ثبت‌نام'];foreach($fields as $f)if($f->field_type!=='notice')$head[]='v'.(int)$f->version.' — '.$f->label;
        try{$out=QYS_Batch_Export::open_csv('qys-program-'.$pid.'.csv',array_map([__CLASS__,'csv_safe'],$head));}
        catch(Throwable $e){wp_die('ساخت خروجی ممکن نیست.',500);}
        $after_id=0;$size=QYS_Batch_Export::chunk_size();
        do{
            $rows=QYS_Program_Repository::applications_export_chunk($pid,(string)$scope,$after_id,$size);
            if(!$rows)break;
            $answer_map=QYS_Program_Repository::answers_for_applications(array_map(static fn($row)=>(int)$row->id,$rows));
            $family_map=self::family_summaries_for_application_rows($rows);
            foreach($rows as $r){
                $after_id=max($after_id,(int)$r->id);$ans=[];foreach($answer_map[(int)$r->id]??[] as $a)$ans[(int)$a->field_id]=self::display_answer($a);
                $line=[$r->first_name,$r->last_name,QYS_Multitenant::university_label((string)$r->university),QYS_Messenger::member_roles()[$r->member_role]??$r->member_role,(string)($family_map[(int)$r->member_id]??'—'),$sensitive?$r->national_id:self::mask((string)$r->national_id),$sensitive?$r->mobile:self::mask((string)$r->mobile),self::APP_LABELS[$r->status]??$r->status,$r->enrollment_status?(self::ENROLL_LABELS[$r->enrollment_status]??$r->enrollment_status):''];
                foreach($fields as $f)if($f->field_type!=='notice')$line[]=$ans[(int)$f->id]??'';
                QYS_Batch_Export::write_csv_row($out,array_map(static fn($v)=>self::csv_safe((string)$v),$line));
            }
            QYS_Batch_Export::flush_chunk($out);
        }while(count($rows)===$size);
        QYS_Batch_Export::close($out);exit;
    }
}
