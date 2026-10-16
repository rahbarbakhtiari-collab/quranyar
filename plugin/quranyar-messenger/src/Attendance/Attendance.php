<?php
/**
 * QuranYar attendance — dated sessions plus reusable floating attendance programs.
 * Legacy dated sessions remain supported; recurring programs create occurrences on first scan.
 */
if (!defined('ABSPATH')) exit;

final class QYS_Attendance {
    const MAX_TITLE = 180;
    const EXIT_GRACE_SECONDS = 7200; // Early exit is always allowed; late exit has two-hour grace.

    public static function sessions_table(){ return QYS_Table_Names::name('attendance_sessions'); }
    public static function records_table(){ return QYS_Table_Names::name('attendance_records'); }
    /** Canonical attendance-domain read used by transfer safety checks. */
    public static function open_record_count_for_member(int $member_id): int { global $wpdb;
        if($member_id<=0)return 0;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::records_table()." WHERE member_id=%d AND status='present' AND checked_out_at_utc IS NULL",$member_id));
    }
    public static function audit_table(){ return QYS_Table_Names::name('attendance_corrections'); }

    public static function init($plugin_file){
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('admin_menu',[__CLASS__,'menu'],20);
        add_action('admin_post_qys_attendance_export',[__CLASS__,'export_csv']);
        add_action('admin_enqueue_scripts',[__CLASS__,'admin_assets']);
        add_action('wp_enqueue_scripts',[__CLASS__,'portal_assets']);
    }

    private static function table_columns($table){
        $out=[];foreach(QYS_Table_Names::diagnostic_columns((string)$table) as $field=>$row)$out[(string)$field]=true;return $out;
    }
    public static function schema_ready(){ return QYS_Schema_Runtime::ready(); }
    public static function diagnostic_schema_ready(){
        foreach([
            self::sessions_table()=>['id','university','qr_secret','qr_version','program_id','session_mode'],
            self::records_table()=>['id','checked_in_at_utc','checked_out_at_utc','checked_out_at_iran','exit_method','status'],
            self::audit_table()=>['id','session_id','member_id','actor_id','reason','old_entry_utc','new_entry_utc','old_exit_utc','new_exit_utc','created_at_utc']
        ] as $t=>$required){$cols=self::table_columns($t);foreach($required as $c)if(!isset($cols[$c]))return false;}
        return class_exists('QYS_Attendance_Programs')&&QYS_Attendance_Programs::diagnostic_schema_ready();
    }

    public static function ensure_schema(){global $wpdb;
     require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();$s=self::sessions_table();$r=self::records_table();$a=self::audit_table();
     dbDelta("CREATE TABLE $s (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     title varchar(180) NOT NULL,
     university varchar(40) NOT NULL DEFAULT '',
     jalali_day varchar(10) NOT NULL,
     start_hm char(5) NOT NULL,
     end_hm char(5) NOT NULL,
     role_groups varchar(255) NOT NULL DEFAULT 'all',
     access_code varchar(10) NOT NULL,
     status varchar(12) NOT NULL DEFAULT 'open',
     created_by bigint unsigned NOT NULL DEFAULT 0,
     created_at datetime NOT NULL,
     updated_at datetime NOT NULL,
     qr_secret varchar(64) NOT NULL DEFAULT '',
     qr_version int unsigned NOT NULL DEFAULT 1,
     program_id bigint unsigned NOT NULL DEFAULT 0,
     session_mode varchar(16) NOT NULL DEFAULT 'dated',
     PRIMARY KEY  (id), UNIQUE KEY access_code (access_code), KEY university_day (university,jalali_day,status), KEY program_mode (program_id,session_mode,status)
     ) $c;");
     dbDelta("CREATE TABLE $r (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     session_id bigint unsigned NOT NULL,
     member_id bigint unsigned NOT NULL,
     method varchar(16) NOT NULL DEFAULT 'code',
     checked_in_at_utc datetime NOT NULL,
     checked_in_at_iran datetime NOT NULL,
     created_by bigint unsigned NOT NULL DEFAULT 0,
     note varchar(190) NOT NULL DEFAULT '',
     checked_out_at_utc datetime NULL DEFAULT NULL,
     checked_out_at_iran datetime NULL DEFAULT NULL,
     exit_method varchar(16) NOT NULL DEFAULT '',
     status varchar(10) NOT NULL DEFAULT 'present',
     PRIMARY KEY  (id), UNIQUE KEY session_member (session_id,member_id), KEY session_time (session_id,checked_in_at_iran), KEY member_history (member_id,id)
     ) $c;");
     dbDelta("CREATE TABLE $a (
     id bigint unsigned NOT NULL AUTO_INCREMENT,
     session_id bigint unsigned NOT NULL,
     member_id bigint unsigned NOT NULL,
     actor_id bigint unsigned NOT NULL,
     action varchar(20) NOT NULL,
     reason varchar(400) NOT NULL,
     old_entry_utc datetime NULL DEFAULT NULL,
     new_entry_utc datetime NULL DEFAULT NULL,
     old_exit_utc datetime NULL DEFAULT NULL,
     new_exit_utc datetime NULL DEFAULT NULL,
     created_at_utc datetime NOT NULL,
     PRIMARY KEY  (id), KEY session_member (session_id,member_id), KEY created_at_utc (created_at_utc)
     ) $c;");
     if(class_exists('QYS_Attendance_Programs'))QYS_Attendance_Programs::ensure_schema();
     return self::diagnostic_schema_ready();
    }

    public static function menu(){
        if(QYS_Permission_Service::scope('attendance')===null)return;
        if(QYS_Permission_Service::scope('attendance')==='*')add_submenu_page('qys-messaging','حضور و غیاب','حضور و غیاب','manage_options','qys-attendance',[__CLASS__,'admin_page'],30);
        else add_submenu_page('qys-campus','حضور و غیاب','حضور و غیاب','read','qys-attendance',[__CLASS__,'admin_page']);
    }
    public static function admin_assets(){
        $page=isset($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
        if($page!=='qys-attendance')return;
        wp_enqueue_style('qys-fonts',plugins_url('assets/qys-fonts.css',QYS_Config::plugin_file()),[],QYS_Config::VERSION);
        wp_enqueue_style('qys-admin-ui',plugins_url('assets/qys-admin-ui.css',QYS_Config::plugin_file()),['qys-fonts'],QYS_Config::VERSION);
        wp_enqueue_style('qys-attendance-admin',plugins_url('assets/attendance-admin.css',QYS_Config::plugin_file()),['qys-admin-ui'],QYS_Config::VERSION);
        wp_enqueue_script('qys-qr-vendor',plugins_url('assets/attendance-qr-vendor.js',QYS_Config::plugin_file()),[],QYS_Config::VERSION,true);
        wp_enqueue_script('qys-attendance-admin',plugins_url('assets/attendance-admin.js',QYS_Config::plugin_file()),['qys-qr-vendor'],QYS_Config::VERSION,true);
    }
    public static function portal_assets(): void {
        if(!class_exists('QYS_Portal_Asset_Policy',false) || !QYS_Portal_Asset_Policy::should_load('attendance'))return;
        $base=QYS_Config::plugin_file();
        wp_enqueue_style('qys-attendance-admin',plugins_url('assets/attendance-admin.css',$base),['qys-university-portal'],QYS_Config::VERSION);
        wp_enqueue_script('qys-attendance-qr',plugins_url('assets/attendance-qr-vendor.js',$base),['qys-university-portal'],QYS_Config::VERSION,true);
        wp_enqueue_script('qys-attendance-admin',plugins_url('assets/attendance-admin.js',$base),['qys-university-portal','qys-attendance-qr'],QYS_Config::VERSION,true);
    }
    public static function enqueue_public(){
        // Compatibility path for integrations that explicitly request the old
        // eager enqueue. The QuranYar member shell uses public_asset_manifest()
        // and loads this feature only when the Attendance view is opened.
        $manifest=self::public_asset_manifest();
        wp_enqueue_style('qys-attendance-public',$manifest['style'],['qys-public-ui'],QYS_Config::VERSION);
        wp_enqueue_script('qys-attendance-public',$manifest['script'],['qys-miniapp'],QYS_Config::VERSION,true);
        wp_localize_script('qys-attendance-public',$manifest['global'],$manifest['config']);
    }
    public static function public_asset_manifest(): array {
        return [
            'style'=>plugins_url('assets/attendance-public.css',QYS_Config::plugin_file()),
            'script'=>plugins_url('assets/attendance-public.js',QYS_Config::plugin_file()),
            'global'=>'QYSAttendance',
            'config'=>[
                'sessionsEndpoint'=>rest_url('qys/v1/attendance/sessions'),
                'scanEndpoint'=>rest_url('qys/v1/attendance/scan'),
                'historyEndpoint'=>rest_url('qys/v1/attendance/history'),
            ],
        ];
    }
    public static function routes(){
        register_rest_route('qys/v1','/attendance/sessions',['methods'=>'POST','callback'=>[__CLASS__,'rest_sessions'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'attendance_sessions',120,60)]);
        register_rest_route('qys/v1','/attendance/scan',['methods'=>'POST','callback'=>[__CLASS__,'rest_scan'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'attendance_scan',60,300)]);
        register_rest_route('qys/v1','/attendance/history',['methods'=>'POST','callback'=>[__CLASS__,'rest_history'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'attendance_history',60,60)]);
    }
    private static function iran_now(){return new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));}
    private static function today_jalali(){return QYS_Calendar_Service::jalali_from_date(self::iran_now());}
    private static function valid_hm($raw){
        $s=trim(QYS_Text_Normalizer::digits((string)$raw));
        return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$s)?$s:'';
    }
    private static function valid_jalali_day($raw){
        $s=trim(QYS_Text_Normalizer::digits((string)$raw));
        if(!preg_match('~\A(1[34][0-9]{2})[/\-](0?[1-9]|1[0-2])[/\-](0?[1-9]|[12][0-9]|3[01])\z~D',$s,$m))return '';
        $y=(int)$m[1];$mo=(int)$m[2];$d=(int)$m[3];
        $max=$mo<=6?31:($mo<=11?30:(QYS_Calendar_Service::jal_cal($y)['leap']===0?30:29));
        return $d<=$max?sprintf('%04d/%02d/%02d',$y,$mo,$d):'';
    }
    public static function normalize_groups($raw){
        if(!is_array($raw)||!$raw)return 'all';
        $roles=QYS_Messenger::member_roles();$seen=[];
        foreach($raw as $v){$v=sanitize_key($v);if(isset($roles[$v]))$seen[$v]=true;}
        if(!$seen)return 'all';
        $ordered=[];foreach(array_keys($roles) as $r)if(isset($seen[$r]))$ordered[]=$r;
        return implode(',',$ordered);
    }
    private static function groups_array($stored){
        if($stored===''||$stored==='all')return [];
        $roles=QYS_Messenger::member_roles();$out=[];
        foreach(explode(',',(string)$stored) as $r)if(isset($roles[$r]))$out[]=$r;
        return $out;
    }
    private static function role_allowed($stored,$role){$groups=self::groups_array($stored);return !$groups||in_array((string)$role,$groups,true);}
    private static function member_from_request(WP_REST_Request $req){
        $user=QYS_Security_Service::request_user($req);
        if(is_wp_error($user))return $user;
        $m=QYS_Member_Repository::find_by_eitaa_id((string)$user['id']);
        if(!$m)return new WP_Error('not_registered','ابتدا پرونده قرآن‌یار را تشکیل دهید.',['status'=>404]);
        if(!QYS_Messenger::profile_complete($m))return new WP_Error('profile_incomplete','برای ثبت حضور، ابتدا پرونده قرآن‌یار و انتساب خانوادگی لازم را تکمیل کنید.',['status'=>403]);
        if(!(int)$m->verified)return new WP_Error('not_verified','برای ثبت حضور، تأیید هویت مدیر لازم است.',['status'=>403]);
        return $m;
    }
    /** Convert the session Jalali day and wall-clock times into Iran-local Unix timestamps. */
    private static function time_range($s){
        if(!preg_match('~\A([0-9]{4})/([0-9]{2})/([0-9]{2})\z~',$s->jalali_day,$m))return null;
        $jdn=QYS_Calendar_Service::jalali_jdn((int)$m[1],(int)$m[2],(int)$m[3]);
        $greg=gmdate('Y-m-d',($jdn-2440588)*86400);
        $tz=new DateTimeZone('Asia/Tehran');
        $start=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$greg.' '.$s->start_hm.':00',$tz);
        $end=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$greg.' '.$s->end_hm.':00',$tz);
        return $start&&$end?[$start->getTimestamp(),$end->getTimestamp()]:null;
    }
    private static function window($s,$event){
        if(!$s||$s->status!=='open')return false;
        $r=self::time_range($s);if(!$r)return false;
        $now=time();return $now>=$r[0] && $now<=($r[1]+($event==='O'?self::EXIT_GRACE_SECONDS:0));
    }
    private static function session_open_now($s){return self::window($s,'I');}
    /** QR embeds an unguessable opaque token, not national ID, phone or personal information. */
    public static function qr_token($s,$event){
        if(!preg_match('/\A[0-9a-f]{64}\z/D',(string)$s->qr_secret)||!in_array($event,['I','O'],true))return '';
        return substr(hash_hmac('sha256','QYS1|'.$s->id.'|'.$event.'|'.$s->qr_version,hex2bin($s->qr_secret)),0,32);
    }
    public static function qr_payload($s,$event){$token=self::qr_token($s,$event);return $token?'QYS1:'.$s->id.':'.$event.':'.$token:'';}
    private static function qr_matches($s,$event,$code){
        $expected=self::qr_token($s,$event);
        if(!$expected||!is_string($code))return false;
        return hash_equals($expected,$code);
    }
    private static function rate_limit($sid,$mid,$bad=false){
        $session_key='qys_att_scan_'.substr(hash_hmac('sha256',$sid.'|'.$mid,wp_salt('nonce')),0,34);
        $member_key='qys_att_member_'.substr(hash_hmac('sha256',(string)$mid,wp_salt('nonce')),0,32);
        $session_attempts=(int)get_transient($session_key);
        $member_attempts=(int)get_transient($member_key);
        if($session_attempts>=5 || $member_attempts>=12)return false;
        if($bad){
            set_transient($session_key,$session_attempts+1,5*MINUTE_IN_SECONDS);
            set_transient($member_key,$member_attempts+1,5*MINUTE_IN_SECONDS);
        }else{
            delete_transient($session_key);delete_transient($member_key);
        }
        return true;
    }
    private static function own_record($sid,$mid){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::records_table().' WHERE session_id=%d AND member_id=%d',$sid,$mid));
    }
    private static function duration_minutes($r){
        if(!$r||empty($r->checked_out_at_utc)||$r->status==='void')return null;
        $diff=strtotime($r->checked_out_at_utc.' UTC')-strtotime($r->checked_in_at_utc.' UTC');
        return $diff>=0?(int)floor($diff/60):null;
    }
    private static function record_view($r){
        if(!$r||$r->status==='void')return ['entry'=>null,'exit'=>null,'exit_next_day'=>false,'minutes'=>null];
        return ['entry'=>substr((string)$r->checked_in_at_iran,11,5),'exit'=>empty($r->checked_out_at_iran)?null:substr($r->checked_out_at_iran,11,5),'exit_next_day'=>!empty($r->checked_out_at_iran)&&substr($r->checked_out_at_iran,0,10)!==substr($r->checked_in_at_iran,0,10),'minutes'=>self::duration_minutes($r)];
    }
    public static function rest_sessions(WP_REST_Request $req){
        global $wpdb;$m=self::member_from_request($req);if(is_wp_error($m))return $m;
        if(!self::schema_ready())return new WP_Error('schema','حضور و غیاب هنوز آماده نیست.',['status'=>503]);
        nocache_headers();$now=time();$recent=self::iran_now()->modify('-2 days');
        $after=QYS_Calendar_Service::jalali_from_date($recent);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,title,jalali_day,start_hm,end_hm,role_groups,status,session_mode FROM '.self::sessions_table().' WHERE university=%s AND session_mode=%s AND jalali_day >= %s ORDER BY id DESC LIMIT 75',(string)$m->university,'dated',$after));
        $out=[];
        foreach((array)$rows as $s){
            if(!self::role_allowed($s->role_groups,$m->member_role))continue;
            $r=self::own_record((int)$s->id,(int)$m->id);$view=self::record_view($r);
            $out[]=['type'=>'dated','id'=>(int)$s->id,'program_id'=>0,'title'=>$s->title,'jalali_day'=>$s->jalali_day,'start_hm'=>$s->start_hm,'end_hm'=>$s->end_hm,'occurrence_active'=>self::window($s,'I')||self::window($s,'O'),
                'mode'=>'dual','entry_open'=>self::window($s,'I'),'exit_open'=>self::window($s,'O'),
                'present'=>$view['entry']!==null,'entry'=>$view['entry'],'exit'=>$view['exit'],'exit_next_day'=>$view['exit_next_day'],'minutes'=>$view['minutes']];
        }
        if(class_exists('QYS_Attendance_Programs'))$out=array_merge($out,QYS_Attendance_Programs::public_models($m));
        return rest_ensure_response(['ok'=>true,'sessions'=>$out]);
    }

    private static function insert_entry($sid,$mid,$method){
        global $wpdb;$now=self::iran_now();
        $ok=$wpdb->insert(self::records_table(),['session_id'=>$sid,'member_id'=>$mid,'method'=>$method,
            'checked_in_at_utc'=>gmdate('Y-m-d H:i:s'),'checked_in_at_iran'=>$now->format('Y-m-d H:i:s'),
            'created_by'=>0,'note'=>'','status'=>'present'],['%d','%d','%s','%s','%s','%d','%s','%s']);
        if($ok!==1){if(self::own_record($sid,$mid))return rest_ensure_response(['ok'=>true,'already'=>true,'message'=>'ورود شما قبلاً ثبت شده است.']);
            return new WP_Error('database','ثبت ورود انجام نشد. دوباره تلاش کنید.',['status'=>500]);}
        return rest_ensure_response(['ok'=>true,'event'=>'entry','already'=>false,'entry'=>$now->format('H:i'),'message'=>'ساعت ورود شما با موفقیت ثبت شد.']);
    }
    public static function rest_scan(WP_REST_Request $req){
        global $wpdb;$m=self::member_from_request($req);if(is_wp_error($m))return $m;
        if(!self::schema_ready())return new WP_Error('schema','حضور و غیاب آماده نیست.',['status'=>503]);
        if(function_exists('nocache_headers'))nocache_headers();
        $raw_qr=trim((string)$req->get_param('qr'));
        if((str_starts_with($raw_qr,'QYSP1:')||absint($req->get_param('program_id'))>0)&&class_exists('QYS_Attendance_Programs'))return QYS_Attendance_Programs::scan($req,$m);
        $qr=$raw_qr;$is_qr=$qr!=='';
        if($is_qr){
            if(!preg_match('/\AQYS1:([1-9][0-9]{0,15}):(I|O):([0-9a-f]{32})\z/D',$qr,$matches))return new WP_Error('invalid','کد QR مربوط به قرآن‌یار نیست.',['status'=>400]);
            $sid=(int)$matches[1];$event=$matches[2];$token=$matches[3];
        }else{
            $sid=absint($req->get_param('session_id'));$event=$req->get_param('event');
            $token=QYS_Text_Normalizer::digits(trim((string)$req->get_param('code')));
            if(!$sid||!in_array($event,['I','O'],true)||!preg_match('/\A[0-9]{6}\z/D',$token))return new WP_Error('invalid','کد عددی شش‌رقمی جلسه را وارد کنید.',['status'=>400]);
        }
        $s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$sid));
        if(!$s||$s->university!==$m->university)return new WP_Error('session','جلسه QR پیدا نشد.',['status'=>404]);
        if(!self::role_allowed($s->role_groups,(string)$m->member_role))return new WP_Error('group','این جلسه برای گروه شما نیست.',['status'=>403]);
        if(!self::rate_limit($sid,(int)$m->id))return new WP_Error('rate','تلاش‌های ناموفق زیاد است؛ پنج دقیقه بعد تلاش کنید.',['status'=>429]);
        // A session has ONE six-digit number for both manually selected events.
        // QR scanners still use distinct event-bound HMACs so a printed entry QR cannot be used as an exit QR.
        $valid=$is_qr?self::qr_matches($s,$event,$token):hash_equals((string)$s->access_code,$token);
        if(!$valid){self::rate_limit($sid,(int)$m->id,true);return new WP_Error('qr','کد جلسه معتبر نیست یا توسط مدیر تغییر کرده است.',['status'=>400]);}
        self::rate_limit($sid,(int)$m->id,false);
        $r=self::own_record($sid,(int)$m->id);
        if($event==='I'){
            if($r){if($r->status==='void')return new WP_Error('void','ثبت قبلی توسط مدیر لغو شده است؛ برای اصلاح به مسئول جلسه مراجعه کنید.',['status'=>409]);return rest_ensure_response(['ok'=>true,'event'=>'entry','already'=>true,'entry'=>substr($r->checked_in_at_iran,11,5),'message'=>'ورود شما قبلاً ثبت شده است.']);}
            if(!self::window($s,'I'))return new WP_Error('window','مهلت ثبت ورود این جلسه فعال نیست.',['status'=>403]);
            return self::insert_entry($sid,(int)$m->id,$is_qr?'qr':'code');
        }
        if(!$r||$r->status==='void')return new WP_Error('entry_first','ابتدا باید QR ورود را اسکن کرده باشید.',['status'=>409]);
        if($r->checked_out_at_utc)return rest_ensure_response(['ok'=>true,'event'=>'exit','already'=>true,'exit'=>substr($r->checked_out_at_iran,11,5),'minutes'=>self::duration_minutes($r),'message'=>'خروج شما قبلاً ثبت شده است.']);
        if(!self::window($s,'O'))return new WP_Error('window','بازه ثبت خروج جلسه فعال نیست.',['status'=>403]);
        $now=self::iran_now();$utc=gmdate('Y-m-d H:i:s');
        // Conditional update is atomic: simultaneous scans cannot overwrite the first exit time.
        $updated=$wpdb->query($wpdb->prepare('UPDATE '.self::records_table().' SET checked_out_at_utc=%s,checked_out_at_iran=%s,exit_method=%s WHERE id=%d AND checked_out_at_utc IS NULL AND status=%s',
            $utc,$now->format('Y-m-d H:i:s'),$is_qr?'qr':'code',(int)$r->id,'present'));
        if($updated!==1){$latest=self::own_record($sid,(int)$m->id);if($latest&&$latest->checked_out_at_utc)return rest_ensure_response(['ok'=>true,'event'=>'exit','already'=>true,'exit'=>substr($latest->checked_out_at_iran,11,5),'minutes'=>self::duration_minutes($latest),'message'=>'خروج شما قبلاً ثبت شده است.']);
            return new WP_Error('database','ثبت خروج انجام نشد؛ دوباره تلاش کنید.',['status'=>500]);}
        $latest=self::own_record($sid,(int)$m->id);
        return rest_ensure_response(['ok'=>true,'event'=>'exit','already'=>false,'exit'=>$now->format('H:i'),'minutes'=>self::duration_minutes($latest),'message'=>'ساعت خروج شما با موفقیت ثبت شد.']);
    }
    public static function rest_history(WP_REST_Request $req){
        global $wpdb;$m=self::member_from_request($req);if(is_wp_error($m))return $m;
        if(!self::schema_ready())return new WP_Error('schema','حضور و غیاب آماده نیست.',['status'=>503]);
        nocache_headers();$rows=$wpdb->get_results($wpdb->prepare('SELECT r.*,s.title,s.jalali_day,s.start_hm,s.end_hm,s.session_mode,s.program_id FROM '.self::records_table().' r JOIN '.self::sessions_table().' s ON s.id=r.session_id WHERE r.member_id=%d AND r.status=%s ORDER BY r.id DESC LIMIT 200',(int)$m->id,'present'));
        $out=[];foreach((array)$rows as $r){$v=self::record_view($r);$out[]=['title'=>$r->title,'jalali_day'=>$r->jalali_day,'start_hm'=>$r->start_hm,'end_hm'=>$r->end_hm,'type'=>$r->session_mode==='dynamic'?'program':'dated','entry'=>$v['entry'],'exit'=>$v['exit'],'exit_next_day'=>$v['exit_next_day'],'minutes'=>$v['minutes']];}
        $summary=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) sessions,COALESCE(SUM(checked_out_at_utc IS NOT NULL),0) completed,COALESCE(SUM(checked_out_at_utc IS NULL),0) open_records,COALESCE(SUM(CASE WHEN checked_out_at_utc IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,checked_in_at_utc,checked_out_at_utc) ELSE 0 END),0) total_minutes FROM ".self::records_table()." WHERE member_id=%d AND status=%s",(int)$m->id,'present'),ARRAY_A);
        return rest_ensure_response(['ok'=>true,'history'=>$out,'summary'=>['sessions'=>(int)($summary['sessions']??0),'completed'=>(int)($summary['completed']??0),'open_records'=>(int)($summary['open_records']??0),'total_minutes'=>max(0,(int)($summary['total_minutes']??0))]]);
    }
    private static function generate_code(){
        global $wpdb;for($i=0;$i<20;$i++){$code=(string)random_int(100000,999999);
            $used=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::sessions_table().' WHERE access_code=%s',$code));
            if(!$used&&class_exists('QYS_Attendance_Programs')&&QYS_Attendance_Programs::schema_ready())$used=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.QYS_Attendance_Programs::table().' WHERE access_code=%s',$code));
            if(!$used)return $code;}
        return '';
    }
    private static function create_session(){
        global $wpdb;
        $title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));
        $day=self::valid_jalali_day(wp_unslash($_POST['jalali_day']??''));
        $start=self::valid_hm(wp_unslash($_POST['start_hm']??''));$end=self::valid_hm(wp_unslash($_POST['end_hm']??''));
        if($title===''||QYS_Text_Normalizer::chars($title)>self::MAX_TITLE||!$day||!$start||!$end)return 'عنوان، تاریخ یا ساعت جلسه معتبر نیست.';
        if($end<=$start)return 'ساعت پایان باید بعد از ساعت شروع باشد؛ جلسه‌های عبوری از نیمه‌شب در این نسخه پشتیبانی نمی‌شوند.';
        $groups=self::normalize_groups(wp_unslash($_POST['role_groups']??[]));
        $scope=QYS_Permission_Service::scope('attendance');
        if($scope===null)return 'مجوز ایجاد جلسه ندارید.';
        $uni=$scope==='*'?sanitize_key(wp_unslash($_POST['university']??'')):$scope;
        if(!QYS_Multitenant::university_active($uni))return 'یک دانشگاه فعال و معتبر انتخاب کنید.';
        $code=self::generate_code();if(!$code)return 'ایجاد شناسه داخلی جلسه انجام نشد.';
        $now=current_time('mysql');$secret=bin2hex(random_bytes(32));
        $ok=$wpdb->insert(self::sessions_table(),['university'=>$uni,'title'=>$title,'jalali_day'=>$day,'start_hm'=>$start,'end_hm'=>$end,
            'role_groups'=>$groups,'access_code'=>$code,'status'=>'open','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,
            'qr_secret'=>$secret,'qr_version'=>1],['%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%d']);
        return $ok===1?'جلسه با دو QR ثابت ورود و خروج ساخته شد. از جزئیات، پوسترها را چاپ کنید.':'ایجاد جلسه ناموفق بود؛ گزارش خطای پایگاه داده را بررسی کنید.';
    }
    private static function change_status($id,$status){
        global $wpdb;if(!in_array($status,['open','closed'],true))return 'وضعیت نامعتبر است.';
        $changed=$wpdb->update(self::sessions_table(),['status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);
        return $changed===false?'تغییر وضعیت انجام نشد.':($status==='closed'?'جلسه بسته شد.':'جلسه دوباره فعال شد.');
    }
    private static function regenerate_code($id){global $wpdb;
     $s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$id));if(!$s)return 'جلسه پیدا نشد.';
     try{$secret=bin2hex(random_bytes(32));}catch(Throwable $e){return 'ساخت کلید امن امکان‌پذیر نیست.';}
     $code=self::generate_code();if($code==='')return 'ساخت کد عددی یکتا انجام نشد.';
     $changed=$wpdb->query($wpdb->prepare('UPDATE '.self::sessions_table().' SET qr_secret=%s,qr_version=qr_version+1,access_code=%s,updated_at=%s WHERE id=%d',$secret,$code,current_time('mysql'),$id));
     return $changed===1?'کد و هر دو QR جلسه تعویض شدند.':'تعویض کد انجام نشد.';
    }
    /** Correct timestamps on the session's Iranian calendar day; audit all old/new values and reason. */
    private static function correct_record($sid,$mid,$action,$entry_hm,$exit_hm,$reason,$exit_next_day=false){
        global $wpdb;$reason=trim(sanitize_textarea_field(wp_unslash($reason)));
        if(QYS_Text_Normalizer::chars($reason)<5||QYS_Text_Normalizer::chars($reason)>400)return 'دلیل اصلاح را حداقل در پنج نویسه و حداکثر چهارصد نویسه بنویسید.';
        $s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$sid));
        if(!$s)return 'جلسه یا عضو مجاز پیدا نشد.';
        $m=QYS_Member_Repository::find_by_id_in_university($mid,(string)$s->university);
        if(!$m||!(int)$m->verified||!self::role_allowed($s->role_groups,$m->member_role))return 'جلسه یا عضو مجاز پیدا نشد.';
        $old=self::own_record($sid,$mid);
        if($action==='void'){
            if(!$old||$old->status==='void')return 'رکورد فعالی برای لغو وجود ندارد.';
            $new_entry=$old->checked_in_at_utc;$new_exit=$old->checked_out_at_utc;
        }else{
            $entry=self::valid_hm($entry_hm);$exit=$exit_hm===''?'':self::valid_hm($exit_hm);
            if(!$entry||($exit_hm!==''&&!$exit)||(!$exit_next_day&&$exit!==''&&$exit<$entry)||($exit_next_day&&$exit===''))return 'ساعت ورود یا خروج معتبر نیست؛ خروج نباید پیش از ورود باشد.';
            $range=self::time_range($s);if(!$range)return 'تاریخ جلسه معتبر نیست.';
            // The calendar day remains that of the session, without device-local ambiguity.
            $day=(new DateTimeImmutable('@'.$range[0]))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
            $zone=new DateTimeZone('Asia/Tehran');$en=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$day.' '.$entry,$zone);
            $ex=$exit!==''?DateTimeImmutable::createFromFormat('!Y-m-d H:i',$day.' '.$exit,$zone):null;
            if($ex&&$exit_next_day)$ex=$ex->modify('+1 day');
            if(!$en||($exit!==''&&!$ex))return 'زمان اصلاح معتبر نیست.';
            $new_entry=$en->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $new_exit=$ex?$ex->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
            if($old&&$old->status==='present'&&$old->checked_in_at_utc===$new_entry&&($old->checked_out_at_utc??null)===$new_exit)return 'تغییری برای ثبت وجود ندارد.';
        }
        $now=gmdate('Y-m-d H:i:s');$actor=get_current_user_id();
        $wpdb->query('START TRANSACTION');
        if($action==='void'){
            $changed=$wpdb->update(self::records_table(),['status'=>'void','note'=>'لغو مستند توسط مدیر'],['id'=>(int)$old->id,'status'=>'present'],['%s','%s'],['%d','%s']);
        }elseif($old){
            $changed=$wpdb->update(self::records_table(),[
                'checked_in_at_utc'=>$new_entry,'checked_in_at_iran'=>$en->format('Y-m-d H:i:s'),
                'checked_out_at_utc'=>$new_exit,'checked_out_at_iran'=>$ex?$ex->format('Y-m-d H:i:s'):null,
                'method'=>'manual','exit_method'=>$ex?'manual':'','status'=>'present','note'=>'اصلاح مستند توسط مدیر'],
                ['id'=>(int)$old->id],['%s','%s','%s','%s','%s','%s','%s','%s'],['%d']);
        }else{
            $changed=$wpdb->insert(self::records_table(),['session_id'=>$sid,'member_id'=>$mid,'method'=>'manual',
                'checked_in_at_utc'=>$new_entry,'checked_in_at_iran'=>$en->format('Y-m-d H:i:s'),
                'checked_out_at_utc'=>$new_exit,'checked_out_at_iran'=>$ex?$ex->format('Y-m-d H:i:s'):null,
                'exit_method'=>$ex?'manual':'','status'=>'present','created_by'=>$actor,'note'=>'ثبت مستند مدیر'],
                ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s']);
        }
        if($changed!==1){$wpdb->query('ROLLBACK');return 'اصلاح رکورد انجام نشد؛ دوباره تلاش کنید.';}
        $audited=$wpdb->insert(self::audit_table(),[
            'session_id'=>$sid,'member_id'=>$mid,'actor_id'=>$actor,'action'=>$action,'reason'=>$reason,
            'old_entry_utc'=>$old?$old->checked_in_at_utc:null,'new_entry_utc'=>$action==='void'?null:$new_entry,
            'old_exit_utc'=>$old?$old->checked_out_at_utc:null,'new_exit_utc'=>$action==='void'?null:$new_exit,'created_at_utc'=>$now],
            ['%d','%d','%d','%s','%s','%s','%s','%s','%s','%s']);
        if($audited!==1){$wpdb->query('ROLLBACK');return 'ثبت سابقه اصلاح انجام نشد؛ هیچ تغییری ذخیره نشد.';}
        $wpdb->query('COMMIT');return $action==='void'?'حضور با دلیل ثبت‌شده لغو شد.':'زمان‌های ورود و خروج با سابقه مستند اصلاح شدند.';
    }
    private static function session_access($s,$permission='attendance'){
        $scope=QYS_Permission_Service::scope($permission);
        return $scope!==null && ($scope==='*'||($s&&(string)$s->university===$scope));
    }

    private static function admin_print($s){
        if(QYS_Permission_Service::scope('attendance')===null||!self::session_access($s))wp_die('دسترسی غیرمجاز');
        if((int)($s->program_id??0)>0)wp_die('این نوبت از QR ثابت برنامه مادر استفاده می‌کند و QR جداگانه ندارد.');
        check_admin_referer('qys_att_print_'.(int)$s->id);
        echo '<div class="wrap qys-att-print-root" dir="rtl"><div class="qys-att-print-toolbar"><button class="button button-primary" onclick="window.print()">چاپ هر دو پوستر</button> <a class="button" href="'.esc_url(self::admin_link(['session_id'=>(int)$s->id])).'">بازگشت</a></div>';
        foreach(['I'=>['ورود','#077c64'],'O'=>['خروج','#14407f']] as $event=>$meta){
            [$label,$color]=$meta;$payload=self::qr_payload($s,$event);if(!$payload)continue;
            echo '<section class="qys-att-poster"><div class="qys-att-poster-heading" style="--qys-qr-accent:'.esc_attr($color).'"><small>اداره قرآن و عترت · '.esc_html(QYS_Multitenant::university_label((string)$s->university)).'</small><h1>ثبت '.esc_html($label).'</h1><h2>'.esc_html($s->title).'</h2></div>';
            echo '<div class="qys-att-poster-instructions" role="note"><strong class="qys-att-poster-alert">توجه: با دوربین معمولی گوشی اسکن نکنید!</strong><div class="qys-att-poster-steps"><p><b>۱</b> ایتا را باز کنید و وارد برنامک <strong>قرآن‌یار</strong> شوید.</p><p><b>۲</b> در برنامک، <strong>حضور و غیاب</strong> را انتخاب کنید.</p><p><b>۳</b> روی <strong>باز کردن اسکنر داخل ایتا</strong> بزنید و این کد را اسکن کنید.</p></div></div>';
            echo '<div class="qys-att-poster-code" data-qys-qr="'.esc_attr($payload).'" aria-label="QR ثبت '.esc_attr($label).' فقط با اسکنر برنامک ایتا"></div>';
            echo '<div class="qys-att-poster-info"><p class="qys-att-poster-time">'.esc_html($s->jalali_day).' | ساعت جلسه: '.esc_html($s->start_hm).' تا '.esc_html($s->end_hm).'</p>';
            echo '<p class="qys-att-poster-fallback">اسکنر ایتا ندارید؟ در برنامک قرآن‌یار، همین جلسه را باز کنید، نوع ثبت (ورود یا خروج) را انتخاب و <strong>کد عددی مشترک جلسه</strong> را وارد کنید: <strong class="qys-att-manual-code" dir="ltr">'.esc_html($s->access_code).'</strong></p>';
            echo '<small>هر عضو فقط یک ورود و یک خروج ثبت می‌کند. اشتراک تصویر این کد ممکن است؛ حضور فیزیکی را به‌تنهایی ثابت نمی‌کند.</small></div></section>';
        }
        echo '</div>';
    }
    private static function admin_link($args=[]){
        if(class_exists('QYS_Multitenant') && QYS_Multitenant::is_portal_request())return QYS_Multitenant::campus_url('attendance',$args);
        return add_query_arg(array_merge(['page'=>'qys-attendance'],$args),admin_url('admin.php'));
    }
    private static function group_label($s,$roles){
        $groups=self::groups_array($s->role_groups);
        return $groups?implode('، ',array_map(fn($r)=>$roles[$r]??$r,$groups)):'همه اعضای تأییدشده';
    }
    /** Server-side tab predicates: a completed or manually closed meeting lives in the archive. */
    private static function session_filters($today,$hm){
        global $wpdb;
        $live=$wpdb->prepare('session_mode=%s AND status=%s AND jalali_day=%s AND start_hm<=%s AND end_hm>=%s','dated','open',$today,$hm,$hm);
        $future=$wpdb->prepare('session_mode=%s AND status=%s AND (jalali_day>%s OR (jalali_day=%s AND start_hm>%s))','dated','open',$today,$today,$hm);
        return ['live'=>'('.$live.')','future'=>'('.$future.')','past'=>"session_mode='dated' AND NOT((".$live.') OR ('.$future.'))'];
    }
    private static function detail_section(){
        $section=isset($_GET['att_tab'])?sanitize_key(wp_unslash($_GET['att_tab'])):'summary';
        return in_array($section,['summary','people','codes','report'],true)?$section:'summary';
    }
    private static function detail_nav($s,$active){
        $items=['summary'=>'خلاصه','people'=>'افراد','codes'=>'QR و کد','report'=>'گزارش'];
        echo '<nav class="qys-att-detail-tabs" aria-label="بخش‌های جلسه">';
        foreach($items as $key=>$label){
            $link=self::admin_link(['session_id'=>(int)$s->id,'att_tab'=>$key]);
            echo '<a href="'.esc_url($link).'" class="qys-att-detail-tab'.($active===$key?' is-active':'').'"'.($active===$key?' aria-current="page"':'').'>'.esc_html($label).'</a>';
        }
        echo '</nav>';
    }
    private static function status_form($s){
        echo '<form method="post" class="qys-att-inline qys-att-status-form">';wp_nonce_field('qys_attendance','qys_attendance_nonce');
        echo '<input type="hidden" name="session_id" value="'.(int)$s->id.'"><button class="button" name="qys_action" value="'.($s->status==='open'?'close':'open').'">'.($s->status==='open'?'بستن جلسه':'فعال‌کردن جلسه').'</button></form>';
    }
    private static function export_form($s){
        if(!self::session_access($s,'attendance_export'))return;
        $portal_export=QYS_Multitenant::is_portal_request();
        $export_action=$portal_export?'qys_attendance_export_portal|'.QYS_Tenant_Context::campus():'qys_attendance_export_global';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="qys-att-inline">';
        wp_nonce_field($export_action,'qys_export_nonce');
        echo '<input type="hidden" name="action" value="qys_attendance_export"><input type="hidden" name="session_id" value="'.(int)$s->id.'">';
        if($portal_export)echo QYS_Multitenant::portal_scope_fields();
        echo '<button class="button button-primary">دریافت CSV</button></form>';
    }
    private static function admin_create($roles,$today){
        $p=wp_unslash($_POST);
        $title=isset($p['title'])?sanitize_text_field($p['title']):'';
        $day=isset($p['jalali_day'])?sanitize_text_field($p['jalali_day']):$today;
        $start=isset($p['start_hm'])?sanitize_text_field($p['start_hm']):'';
        $end=isset($p['end_hm'])?sanitize_text_field($p['end_hm']):'';
        $selected=isset($p['role_groups'])&&is_array($p['role_groups'])?array_map('sanitize_key',$p['role_groups']):[];
        echo '<section class="qys-att-panel qys-att-create"><div class="qys-att-panel-title"><div><h2>ایجاد جلسه جدید</h2><span>پس از ذخیره، دو QR و یک کد عددی شش‌رقمی ساخته می‌شود.</span></div></div>';
        echo '<form method="post" class="qys-att-form">';wp_nonce_field('qys_attendance','qys_attendance_nonce');
        echo '<input type="hidden" name="qys_action" value="create">';
        $scope=QYS_Permission_Service::scope('attendance');
        if($scope==='*'){
            echo '<label class="qys-att-wide">دانشگاه<select name="university" required><option value="">انتخاب دانشگاه</option>';
            foreach(QYS_Multitenant::universities() as $u)echo '<option value="'.esc_attr($u['code']).'">'.esc_html($u['title']).'</option>';
            echo '</select></label>';
        }else echo '<input type="hidden" name="university" value="'.esc_attr($scope).'">';
        echo '<label class="qys-att-wide">عنوان جلسه<input name="title" maxlength="180" required placeholder="مثلاً محفل انس با قرآن" value="'.esc_attr($title).'"></label>';
        echo '<label>تاریخ شمسی<input name="jalali_day" dir="ltr" inputmode="numeric" required placeholder="1405/07/06" value="'.esc_attr($day).'"></label>';
        echo '<label>شروع<input type="time" name="start_hm" required value="'.esc_attr($start).'"></label><label>پایان<input type="time" name="end_hm" required value="'.esc_attr($end).'"></label>';
        echo '<fieldset class="qys-att-wide"><legend>گروه‌های مجاز <small>(عدم انتخاب یعنی همه اعضای تأییدشده)</small></legend><div class="qys-att-groups">';
        foreach($roles as $key=>$label)echo '<label><input type="checkbox" name="role_groups[]" value="'.esc_attr($key).'"'.checked(in_array($key,$selected,true),true,false).'> '.esc_html($label).'</label>';
        echo '</div></fieldset><div class="qys-att-wide qys-att-create-actions"><button class="button button-primary button-hero" type="submit">ایجاد جلسه</button> <a class="button" href="'.esc_url(self::admin_link()).'">انصراف و بازگشت</a></div></form></section>';
    }
    private static function admin_overview($roles,$today){
        global $wpdb;
        $now=self::iran_now();$hm=$now->format('H:i');$filters=self::session_filters($today,$hm);
        $scope=QYS_Permission_Service::scope('attendance');
        if($scope===null)wp_die('دسترسی مجاز نیست.');
        $scope_where=$scope==='*'?'':$wpdb->prepare(' AND university=%s',$scope);

        if(class_exists('QYS_Attendance_Programs'))QYS_Attendance_Programs::render_overview($roles);

        // Dated sessions remain available for one-off meetings.
        // Keep the first screen task-focused: what is happening now, and what is next.
        $live_focus=$wpdb->get_results('SELECT id,title,jalali_day,start_hm,end_hm,role_groups,status FROM '.self::sessions_table().' WHERE '.$filters['live'].$scope_where.' ORDER BY start_hm ASC,id ASC LIMIT 2');
        $next_focus=$wpdb->get_row('SELECT id,title,jalali_day,start_hm,end_hm,role_groups,status FROM '.self::sessions_table().' WHERE '.$filters['future'].$scope_where.' ORDER BY jalali_day ASC,start_hm ASC,id ASC LIMIT 1');
        if($live_focus||$next_focus){
            echo '<section class="qys-att-focus" aria-label="جلسات مهم">';
            if($live_focus){foreach((array)$live_focus as $row){
                echo '<article class="qys-att-focus-card is-live"><span class="qys-att-focus-kicker">● در حال برگزاری</span><h2>'.esc_html($row->title).'</h2><p>'.esc_html($row->jalali_day.' · '.$row->start_hm.' تا '.$row->end_hm).'</p><a class="button button-primary" href="'.esc_url(self::admin_link(['session_id'=>(int)$row->id])).'">مدیریت همین جلسه</a></article>';
            }}elseif($next_focus){
                echo '<article class="qys-att-focus-card is-empty"><span class="qys-att-focus-kicker">اکنون</span><h2>جلسه فعالی ندارید</h2><p>جلسه بعدی در کارت کناری مشخص است.</p></article>';
            }
            if($next_focus){
                echo '<article class="qys-att-focus-card is-next"><span class="qys-att-focus-kicker">جلسه بعدی</span><h2>'.esc_html($next_focus->title).'</h2><p>'.esc_html($next_focus->jalali_day.' · '.$next_focus->start_hm.' تا '.$next_focus->end_hm).'</p><a class="button" href="'.esc_url(self::admin_link(['session_id'=>(int)$next_focus->id])).'">مشاهده جزئیات</a></article>';
            }
            echo '</section>';
        }

        $search=isset($_GET['q'])?trim(sanitize_text_field(wp_unslash($_GET['q']))):'';
        $search=preg_replace('/\A(.{0,80}).*\z/us','$1',$search);
        $qwhere=$scope_where;
        if($search!==''){
            $like='%'.$wpdb->esc_like(QYS_Text_Normalizer::digits($search)).'%';
            $title_like='%'.$wpdb->esc_like($search).'%';
            $qwhere.=$wpdb->prepare(' AND (title LIKE %s OR jalali_day LIKE %s)',$title_like,$like);
        }
        $counts=[];foreach($filters as $key=>$predicate)$counts[$key]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::sessions_table().' WHERE '.$predicate.$qwhere);
        $tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'';
        if(!isset($filters[$tab]))$tab=$counts['live']?'live':($counts['future']?'future':'past');
        $labels=['live'=>'در حال برگزاری','future'=>'آینده','past'=>'بایگانی'];
        echo '<nav class="qys-att-tabs" aria-label="فیلتر وضعیت جلسات">';
        foreach($labels as $key=>$label){
            $link=self::admin_link(['tab'=>$key]+($search!==''?['q'=>$search]:[]));
            echo '<a href="'.esc_url($link).'" class="qys-att-tab'.($key===$tab?' is-active':'').'"'.($key===$tab?' aria-current="page"':'').'><span>'.esc_html($label).'</span><strong>'.(int)$counts[$key].'</strong></a>';
        }
        echo '</nav>';
        echo '<section class="qys-att-panel qys-att-index"><div class="qys-att-panel-title"><div><h2>'.esc_html($labels[$tab]).'</h2><span>هر جلسه یک کارت مستقل دارد؛ برای QR، افراد یا گزارش همان کارت را باز کنید.</span></div></div>';
        echo '<form method="get" class="qys-att-search"><input type="hidden" name="page" value="qys-attendance"><input type="hidden" name="tab" value="'.esc_attr($tab).'"><label class="screen-reader-text" for="qys-att-query">جست‌وجوی جلسه</label><input id="qys-att-query" name="q" type="search" maxlength="80" value="'.esc_attr($search).'" placeholder="نام جلسه یا تاریخ شمسی…"><button class="button button-primary">جست‌وجو</button>';
        if($search!=='')echo '<a class="button" href="'.esc_url(self::admin_link(['tab'=>$tab])).'">پاک‌کردن</a>';
        echo '</form>';
        $per_page=10;$total=$counts[$tab];$pages=max(1,(int)ceil($total/$per_page));$pageno=min($pages,max(1,absint($_GET['pageno']??1)));$offset=($pageno-1)*$per_page;
        $order=$tab==='future'?'ASC':'DESC';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,title,jalali_day,start_hm,end_hm,role_groups,status FROM '.self::sessions_table().' WHERE '.$filters[$tab].$qwhere.' ORDER BY jalali_day '.$order.',start_hm '.$order.',id '.$order.' LIMIT %d OFFSET %d',$per_page,$offset));
        $totals=[];$ids=array_map(fn($row)=>(int)$row->id,(array)$rows);
        if($ids){
            $in=implode(',',$ids);
            $stats=$wpdb->get_results("SELECT session_id,COUNT(*) AS entries,COALESCE(SUM(checked_out_at_utc IS NOT NULL),0) AS exits FROM ".self::records_table()." WHERE status='present' AND session_id IN ($in) GROUP BY session_id");
            foreach((array)$stats as $stat)$totals[(int)$stat->session_id]=$stat;
        }
        if(!$rows){
            echo '<div class="qys-att-empty-index"><strong>'.($search!==''?'جلسه‌ای با این جست‌وجو پیدا نشد.':'در این بخش جلسه‌ای ندارید.').'</strong><p>جلسه جدید بسازید یا یکی از بخش‌های دیگر را انتخاب کنید.</p><a class="button button-primary" href="'.esc_url(self::admin_link(['view'=>'create'])).'">+ جلسه جدید</a></div>';
        }else{
            echo '<div class="qys-att-session-list">';
            foreach((array)$rows as $row){
                $stat=$totals[(int)$row->id]??null;
                $badge=$tab==='live'?'اکنون':($tab==='future'?'آینده':'پایان‌یافته');
                echo '<article class="qys-att-session-card"><div class="qys-att-session-main"><div class="qys-att-session-meta"><span class="qys-att-session-badge qys-att-session-badge--'.esc_attr($tab).'">'.esc_html($badge).'</span><span class="qys-att-session-day">'.esc_html($row->jalali_day).' · '.esc_html($row->start_hm.' تا '.$row->end_hm).'</span></div><h3>'.esc_html($row->title).'</h3><p>'.esc_html(self::group_label($row,$roles)).'</p></div>';
                echo '<div class="qys-att-session-side"><div class="qys-att-session-numbers"><span>ورود <strong>'.($stat?(int)$stat->entries:0).'</strong></span><span>خروج <strong>'.($stat?(int)$stat->exits:0).'</strong></span></div>';
                echo '<a class="button button-primary" href="'.esc_url(self::admin_link(['session_id'=>(int)$row->id])).'">باز کردن جلسه</a></div></article>';
            }
            echo '</div>';
            if($pages>1){echo '<nav class="qys-att-pagination" aria-label="صفحه‌بندی جلسات">';
                for($i=1;$i<=$pages;$i++){if($i>1&&$i<$pages&&abs($i-$pageno)>2)continue;
                    $args=['tab'=>$tab,'pageno'=>$i];if($search!=='')$args['q']=$search;
                    echo '<a href="'.esc_url(self::admin_link($args)).'"'.($i===$pageno?' class="current" aria-current="page"':'').'>'.(int)$i.'</a>';}
                echo '</nav>';}
        }
        echo '</section>';
    }
    public static function admin_page(){
        if(QYS_Permission_Service::scope('attendance')===null)wp_die('دسترسی غیرمجاز');
        global $wpdb;if(!self::schema_ready())self::ensure_schema();
        if(!self::schema_ready()){echo '<div class="wrap"><h1>حضور و غیاب</h1><div class="notice notice-error"><p>جدول حضور و غیاب آماده نیست. مجوز پایگاه داده و گزارش خطای وردپرس را بررسی کنید.</p></div></div>';return;}
        $notice='';$view_id=absint($_GET['session_id']??0);$program_view_id=absint($_GET['program_id']??0);$mode=isset($_GET['view'])?sanitize_key(wp_unslash($_GET['view'])):'';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $nonce=isset($_POST['qys_attendance_nonce'])?sanitize_text_field(wp_unslash($_POST['qys_attendance_nonce'])):'';
            if(!$nonce||!wp_verify_nonce($nonce,'qys_attendance'))wp_die('درخواست نامعتبر یا منقضی شده است؛ صفحه را تازه‌سازی کنید.');
            $action=sanitize_key(wp_unslash($_POST['qys_action']??''));$view_id=absint($_POST['session_id']??0)?:$view_id;$program_view_id=absint($_POST['program_id']??0)?:$program_view_id;
            $sid=absint($_POST['session_id']??0);
            if(!in_array($action,['create','create_program'],true)&&!str_starts_with($action,'program_')){
                $selected=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$sid));
                if(!$selected||!self::session_access($selected))wp_die('اجازه تغییر جلسه این دانشگاه را ندارید.');
            }
            if(str_starts_with($action,'program_')){
                $program=QYS_Attendance_Programs::get($program_view_id);$pscope=QYS_Permission_Service::scope('attendance');
                if(!$program||$pscope===null||($pscope!=='*'&&(string)$program->university!==$pscope))wp_die('اجازه تغییر این برنامه را ندارید.');
            }
            if($action==='create'){$notice=self::create_session();if(strpos($notice,'دو QR ثابت')!==false){$view_id=(int)$wpdb->insert_id;$mode='';}else $mode='create';}
            elseif($action==='create_program'){$made=QYS_Attendance_Programs::create_from_post();$notice=$made['message'];if($made['id']){$program_view_id=(int)$made['id'];$mode='';}else $mode='create_program';}
            elseif($action==='program_close')$notice=QYS_Attendance_Programs::change_status($program_view_id,'closed');
            elseif($action==='program_open')$notice=QYS_Attendance_Programs::change_status($program_view_id,'open');
            elseif($action==='program_regenerate')$notice=QYS_Attendance_Programs::regenerate($program_view_id);
            elseif($action==='program_close_occurrence')$notice=QYS_Attendance_Programs::close_current_occurrence($program_view_id);
            elseif($action==='close')$notice=self::change_status($view_id,'closed');
            elseif($action==='open')$notice=self::change_status($view_id,'open');
            elseif($action==='regenerate')$notice=self::regenerate_code($view_id);
            elseif($action==='correct')$notice=self::correct_record($view_id,absint($_POST['member_id']??0),'correct',
                wp_unslash($_POST['entry_hm']??''),wp_unslash($_POST['exit_hm']??''),$_POST['reason']??'',!empty($_POST['exit_next_day']));
            elseif($action==='void')$notice=self::correct_record($view_id,absint($_POST['member_id']??0),'void','','',$_POST['reason']??'');
        }
        $view=$view_id?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$view_id)):null;$program=$program_view_id&&class_exists('QYS_Attendance_Programs')?QYS_Attendance_Programs::get($program_view_id):null;
        if($view&&!self::session_access($view))wp_die('این جلسه متعلق به دانشگاه شما نیست.');
        if($program){$pscope=QYS_Permission_Service::scope('attendance');if($pscope===null||($pscope!=='*'&&(string)$program->university!==$pscope))wp_die('این برنامه متعلق به دانشگاه شما نیست.');}
        if($view&&!empty($_GET['print_qr'])){self::admin_print($view);return;}
        if($program&&!empty($_GET['print_program_qr'])){QYS_Attendance_Programs::print_program($program);return;}
        $roles=QYS_Messenger::member_roles();$today=self::today_jalali();
        $heading=$program?'برنامه حضور ثابت':($view?'جزئیات جلسه':($mode==='create_program'?'ایجاد برنامه ثابت':($mode==='create'?'ایجاد جلسه تاریخ‌دار':'حضور و غیاب')));
        $desc=$program?'یک QR ثابت برای نوبت‌های متعدد؛ هر نوبت با اولین ورود ساخته می‌شود.':($view?'ثبت ورود و خروج و اصلاح مستند همین نوبت.':($mode==='create_program'?'برای برنامه‌های تکرارشونده بدون تعریف تاریخ و ساعت از قبل.':($mode==='create'?'برای جلسه یک‌باره با تاریخ و ساعت مشخص.':'برنامه‌های ثابت و جلسه‌های تاریخ‌دار را یک‌جا مدیریت کنید.')));
        echo '<div class="wrap qys-admin qys-attendance-admin" dir="rtl"><div class="qys-att-head"><div><span class="qys-admin-eyebrow">مدیریت قرآن‌یار</span><h1>'.esc_html($heading).'</h1><p>'.esc_html($desc).'</p></div><div class="qys-att-head-actions">';
        if($view||$program||in_array($mode,['create','create_program'],true))echo '<a class="button" href="'.esc_url(self::admin_link()).'">← بازگشت به حضور و غیاب</a>';
        else echo '<a class="button button-primary" href="'.esc_url(self::admin_link(['view'=>'create_program'])).'">+ برنامه ثابت</a><a class="button" href="'.esc_url(self::admin_link(['view'=>'create'])).'">+ جلسه تاریخ‌دار</a>';
        echo '<div class="qys-att-today"><span>امروز</span><strong>'.esc_html($today).'</strong></div></div></div>';
        if($notice)echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        if($program)QYS_Attendance_Programs::render_detail($program,$roles);
        elseif($view)self::admin_detail($view,$roles);
        elseif($mode==='create_program')QYS_Attendance_Programs::render_create($roles);
        elseif($mode==='create')self::admin_create($roles,$today);
        else self::admin_overview($roles,$today);
        echo '</div>';
    }
    private static function admin_detail($s,$roles){
        global $wpdb;$sid=(int)$s->id;$section=self::detail_section();$groups=self::groups_array($s->role_groups);
        $eligible=QYS_Member_Repository::attendance_eligible((string)$s->university,$groups);
        $family_summaries=QYS_Family_Relationship_Repository::summaries_for_members(array_map(static fn($member)=>(int)$member->id,(array)$eligible),(string)$s->university);
        $rows=QYS_Member_Repository::attendance_records_join(self::records_table(),$sid);
        $by_member=[];$present=0;$complete=0;foreach((array)$rows as $r){$by_member[(int)$r->member_id]=$r;if($r->status==='present'){$present++;if($r->checked_out_at_utc)$complete++;}}
        $print=wp_nonce_url(self::admin_link(['session_id'=>$sid,'print_qr'=>1]),'qys_att_print_'.$sid);

        echo '<section class="qys-att-session-hero"><div><span class="qys-att-session-badge '.($s->status==='open'?'is-open':'is-closed').'">'.($s->status==='open'?'فعال':'بسته').'</span><h2>'.esc_html($s->title).'</h2><p>'.esc_html($s->jalali_day.' · '.$s->start_hm.' تا '.$s->end_hm).' · '.esc_html(self::group_label($s,$roles)).'</p></div><div class="qys-att-session-hero-actions">';
        self::status_form($s);
        echo '</div></section>';
        self::detail_nav($s,$section);

        if($section==='summary'){
            echo '<section class="qys-att-panel qys-att-summary-view">';
            echo '<div class="qys-att-summary"><div><span>واجد شرایط</span><strong>'.count($eligible).'</strong></div><div><span>ثبت ورود</span><strong>'.$present.'</strong></div><div><span>ثبت خروج</span><strong>'.$complete.'</strong></div><div><span>بدون خروج</span><strong>'.max(0,$present-$complete).'</strong></div></div>';
            if((int)($s->program_id??0)>0){$p=QYS_Attendance_Programs::get((int)$s->program_id);echo '<div class="qys-att-shared-code qys-att-program-occurrence-note"><div><span>این نوبت خودکار ساخته شده</span><strong>برنامه ثابت</strong></div><p>QR و کد متعلق به برنامه مادر است؛ این نوبت با اولین ثبت ورود ایجاد شده است.'.($p?' <a href="'.esc_url(self::admin_link(['program_id'=>(int)$p->id])).'">مدیریت برنامه ثابت</a>':'').'</p></div>';}else echo '<div class="qys-att-shared-code"><div><span>تنها کد عددی این جلسه</span><strong dir="ltr">'.esc_html($s->access_code).'</strong></div><p>برای ورود و خروج فقط همین یک عدد شش‌رقمی استفاده می‌شود؛ عضو داخل برنامک مشخص می‌کند «ورود» یا «خروج». QRهای چاپی نیز برای همین جلسه ثابت‌اند.</p></div>';
            $code_tile=(int)($s->program_id??0)>0?'<strong>QR برنامه مادر</strong><span>این نوبت از QR ثابت برنامه استفاده می‌کند</span>':'<strong>QR و کد</strong><span>چاپ پوسترها و تعویض کد جلسه</span>';
            echo '<div class="qys-att-task-grid"><a href="'.esc_url(self::admin_link(['session_id'=>$sid,'att_tab'=>'people'])).'"><strong>افراد جلسه</strong><span>حاضرها، خروج‌ها و اصلاح مستند</span></a><a href="'.esc_url(self::admin_link(['session_id'=>$sid,'att_tab'=>'codes'])).'">'.$code_tile.'</a><a href="'.esc_url(self::admin_link(['session_id'=>$sid,'att_tab'=>'report'])).'"><strong>گزارش</strong><span>CSV و سابقه اصلاحات مدیریتی</span></a></div>';
            echo '</section>';
            return;
        }

        if($section==='codes'){
            if((int)($s->program_id??0)>0){$p=QYS_Attendance_Programs::get((int)$s->program_id);echo '<section class="qys-att-panel qys-att-code-view"><div class="qys-att-panel-title"><div><h3>QR این نوبت</h3><span>این نوبت QR مستقل ندارد؛ از QR ثابت برنامه مادر استفاده می‌شود.</span></div></div><div class="qys-att-program-explainer"><strong>نیازی به چاپ دوباره نیست.</strong><span>همان پوستر ثابت برنامه برای ورود و خروج همه نوبت‌ها معتبر است.</span></div>'.($p?'<p><a class="button button-primary" href="'.esc_url(self::admin_link(['program_id'=>(int)$p->id])).'">رفتن به برنامه ثابت</a></p>':'').'</section>';return;}
            echo '<section class="qys-att-panel qys-att-code-view"><div class="qys-att-panel-title"><div><h3>QR و کد جلسه</h3><span>کد عددی فقط یک عدد است و برای ورود و خروج مشترک است.</span></div></div>';
            echo '<div class="qys-att-code-focus"><span>کد شش‌رقمی جلسه</span><strong dir="ltr">'.esc_html($s->access_code).'</strong><small>ورود/خروج در خود برنامک انتخاب می‌شود.</small></div>';
            echo '<div class="qys-att-code-actions"><a class="button button-primary button-hero" href="'.esc_url($print).'">چاپ دو پوستر QR</a><form method="post" class="qys-att-inline">';wp_nonce_field('qys_attendance','qys_attendance_nonce');
            echo '<input type="hidden" name="session_id" value="'.$sid.'"><button class="button" name="qys_action" value="regenerate" onclick="return confirm(\'هر دو QR و کد شش‌رقمی فعلی نامعتبر می‌شوند و باید پوسترهای جدید چاپ شوند. ادامه؟\')">تعویض QR و کد</button></form></div>';
            echo '<div class="qys-att-code-note"><strong>روش استفاده</strong><ol><li>پوستر ورود و پوستر خروج را یک‌بار چاپ کنید.</li><li>عضو QR را فقط داخل برنامک قرآن‌یار اسکن می‌کند.</li><li>اگر اسکنر در دسترس نبود، همین یک کد شش‌رقمی را وارد و نوع ثبت را انتخاب می‌کند.</li></ol></div></section>';
            return;
        }

        if($section==='people'){
            echo '<section class="qys-att-panel qys-att-people-view"><div class="qys-att-panel-title qys-att-people-head"><div><h3>افراد جلسه</h3><span>وضعیت حضور را سریع ببینید؛ اصلاح مستند فقط هنگام نیاز باز می‌شود.</span></div><div class="qys-att-people-kpis" aria-label="خلاصه حضور جلسه"><span><small>حاضر</small><strong>'.$present.'</strong></span><span><small>خروج ثبت‌شده</small><strong>'.$complete.'</strong></span><span'.(max(0,$present-$complete)>0?' class="needs-attention"':'').'><small>بدون خروج</small><strong>'.max(0,$present-$complete).'</strong></span></div></div>';
            if(!$eligible){
                echo '<div class="qys-att-empty-people"><strong>هنوز عضوی برای این جلسه در دسترس نیست.</strong><span>گروه‌های جلسه یا وضعیت تأیید اعضا را بررسی کنید.</span></div></section>';
                return;
            }
            echo '<div class="qys-att-person-list">';
            foreach((array)$eligible as $m){
                $r=$by_member[(int)$m->id]??null;
                $live=$r&&$r->status==='present';
                $entry=$live?substr($r->checked_in_at_iran,11,5):'';
                $exit=$live&&$r->checked_out_at_iran?substr($r->checked_out_at_iran,11,5):'';
                $nextday=$exit&&substr($r->checked_out_at_iran,0,10)!==substr($r->checked_in_at_iran,0,10);
                $mins=$live?self::duration_minutes($r):null;
                $name=trim($m->first_name.' '.$m->last_name);
                $mobile=self::session_access($s,'sensitive_identity')?$m->mobile:('*******'.substr((string)$m->mobile,-4));
                $duration=$mins===null?($live?'خروج ثبت نشده':'ثبت نشده'):(int)$mins.' دقیقه';
                $state_class=$live?($exit?'is-complete':'is-open-entry'):'is-absent';
                $state_text=$live?($exit?'تکمیل':'بدون خروج'):'ثبت‌نشده';
                echo '<article class="qys-att-person-card '.$state_class.'"><div class="qys-att-person-grid">';
                echo '<div class="qys-att-person-identity"><span class="qys-att-person-avatar" aria-hidden="true">'.esc_html(mb_substr($name?:'؟',0,1)).'</span><div><strong>'.esc_html($name?:'بدون نام').'</strong><small dir="ltr">'.esc_html($mobile).'</small></div></div>';
                echo '<div class="qys-att-person-group"><span>گروه</span><strong>'.esc_html($roles[$m->member_role]??'—').'</strong><small>'.esc_html((string)($family_summaries[(int)$m->id]??'انتساب خانوادگی: —')).'</small></div>';
                echo '<div class="qys-att-person-metric"><span>ورود</span><strong dir="ltr">'.($entry?esc_html($entry):'—').'</strong></div>';
                echo '<div class="qys-att-person-metric"><span>خروج</span><strong dir="ltr">'.($exit?esc_html($exit):'—').'</strong></div>';
                echo '<div class="qys-att-person-metric"><span>مدت</span><strong>'.esc_html($duration).'</strong><small class="qys-att-person-state '.$state_class.'">'.esc_html($state_text).'</small></div>';
                echo '<details class="qys-att-row-editor"><summary>اصلاح مستند</summary><div class="qys-att-editor-sheet"><div class="qys-att-editor-title"><strong>اصلاح حضور '.esc_html($name?:'عضو').'</strong><span>هر تغییر با دلیل در سابقه ممیزی ذخیره می‌شود.</span></div><form method="post" class="qys-att-correction qys-att-correction--wide">';wp_nonce_field('qys_attendance','qys_attendance_nonce');
                echo '<input type="hidden" name="session_id" value="'.$sid.'"><label>ورود<input type="time" name="entry_hm" value="'.esc_attr($entry).'" required></label><label>خروج (اختیاری)<input type="time" name="exit_hm" value="'.esc_attr($exit).'"></label><label class="qys-att-nextday"><span>عبور از نیمه‌شب</span><span class="qys-att-checkline"><input type="checkbox" name="exit_next_day" value="1"'.checked((bool)$nextday,true,false).'> خروج روز بعد</span></label><label class="qys-att-reason">دلیل اصلاح<textarea name="reason" minlength="5" maxlength="400" required placeholder="مثلاً: اصلاح بر اساس فهرست امضاشده جلسه"></textarea></label><div class="qys-att-editor-actions"><button class="button button-primary" name="qys_action" value="correct">ذخیره اصلاح مستند</button>';
                if($live)echo '<button class="button qys-att-danger" name="qys_action" value="void" formnovalidate onclick="return confirm(\'این عملیات حضور ثبت‌شده را لغو می‌کند اما سابقه ممیزی را نگه می‌دارد. ادامه می‌دهید؟\') && confirm(\'تأیید نهایی: لغو حضور این عضو انجام شود؟\')">لغو حضور با حفظ سابقه</button>';
                echo '</div></form></div></details></div></article>';
            }
            echo '</div></section>';
            return;
        }

        $audit=QYS_Member_Repository::attendance_audit_join(self::audit_table(),$sid,40);
        echo '<section class="qys-att-panel qys-att-report-view"><div class="qys-att-panel-title"><div><h3>گزارش جلسه</h3><span>خروجی CSV و سابقه اصلاحات مدیریتی در این بخش نگهداری می‌شود.</span></div><div class="qys-att-detail-actions">';
        self::export_form($s);echo '</div></div>';
        echo '<div class="qys-att-report-facts"><span>ورود: <strong>'.$present.'</strong></span><span>خروج: <strong>'.$complete.'</strong></span><span>بدون خروج: <strong>'.max(0,$present-$complete).'</strong></span><span>اصلاحات اخیر: <strong>'.count($audit).'</strong></span></div>';
        echo '<details class="qys-att-audit" open><summary>سابقه اصلاحات مدیریتی ('.count($audit).' مورد اخیر)</summary><div class="qys-att-table-wrap"><table class="widefat striped"><thead><tr><th>نام</th><th>عملیات</th><th>مدیر</th><th>تاریخ</th><th>دلیل</th><th>ورود/خروج پیشین UTC</th><th>ورود/خروج جدید UTC</th></tr></thead><tbody>';
        foreach((array)$audit as $a)echo '<tr><td>'.esc_html(trim($a->first_name.' '.$a->last_name)).'</td><td>'.($a->action==='void'?'لغو':'اصلاح').'</td><td>'.(int)$a->actor_id.'</td><td dir="ltr">'.esc_html($a->created_at_utc).'</td><td>'.esc_html($a->reason).'</td><td dir="ltr">'.esc_html(($a->old_entry_utc?:'—').' / '.($a->old_exit_utc?:'—')).'</td><td dir="ltr">'.esc_html(($a->new_entry_utc?:'—').' / '.($a->new_exit_utc?:'—')).'</td></tr>';
        if(!$audit)echo '<tr><td colspan="7">هنوز اصلاحی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></details></section>';
    }
    private static function csv_safe($v){$s=(string)$v;return preg_match('/\A\s*[=+@\-]/u',$s)?"'".$s:$s;}
    public static function export_csv(){
        $portal_flag=sanitize_key(wp_unslash($_POST['qys_portal_scope']??''));
        if($portal_flag==='1'){
            $submitted=sanitize_key(wp_unslash($_POST['qys_portal_university']??''));
            $campus=QYS_Multitenant::campus_university();
            if($campus===''||$submitted===''||!hash_equals($campus,$submitted))wp_die('دامنه دانشگاه معتبر نیست.',403);
            check_admin_referer('qys_attendance_export_portal|'.$campus,'qys_export_nonce');
            QYS_Tenant_Context::reset();
            if(QYS_Tenant_Context::campus()!==$campus||QYS_Permission_Service::scope('attendance')!==$campus||QYS_Permission_Service::scope('attendance_export')!==$campus)wp_die('دسترسی خروجی مجاز نیست.',403);
        }else{
            check_admin_referer('qys_attendance_export_global','qys_export_nonce');
            QYS_Tenant_Context::reset();
            if(!QYS_Security_Service::is_global_admin()||QYS_Tenant_Context::current()!==QYS_Tenant_Context::GLOBAL||QYS_Permission_Service::scope('attendance')!=='*'||QYS_Permission_Service::scope('attendance_export')!=='*')wp_die('دسترسی خروجی مجاز نیست.',403);
        }
        if(!self::schema_ready())wp_die('جدول حضور و غیاب آماده نیست.');
        global $wpdb;$sid=absint($_POST['session_id']??0);$s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::sessions_table().' WHERE id=%d',$sid));if(!$s||!self::session_access($s)||!self::session_access($s,'attendance_export'))wp_die('جلسه این دانشگاه در اختیار شما نیست.');
        $groups=self::groups_array($s->role_groups);
        $identity_access=self::session_access($s,'sensitive_identity');
        try{$h=QYS_Batch_Export::open_csv('qys-attendance-'.$sid.'.csv',['عنوان جلسه','تاریخ','نام','نام خانوادگی','گروه','انتساب خانوادگی','موبایل','کد ملی','وضعیت','ورود به وقت ایران','خروج به وقت ایران','مدت دقیقه','روش ورود','روش خروج']);}
        catch(Throwable $e){wp_die('ساخت خروجی ممکن نیست.',500);}
        $roles=QYS_Messenger::member_roles();$after_member_id=0;$size=QYS_Batch_Export::chunk_size();
        do{$rows=QYS_Member_Repository::attendance_export_chunk_after(self::records_table(),$sid,(string)$s->university,$groups,$after_member_id,$size);
            $family_export=QYS_Family_Relationship_Repository::summaries_for_members(array_map(static fn($member)=>(int)$member->member_id,(array)$rows),(string)$s->university);
            foreach((array)$rows as $r){$after_member_id=max($after_member_id,(int)$r->member_id);$yes=$r->status==='present';$minutes=null;
                if($yes&&$r->checked_out_at_utc){$seconds=strtotime($r->checked_out_at_utc.' UTC')-strtotime($r->checked_in_at_utc.' UTC');$minutes=$seconds>=0?(int)floor($seconds/60):null;}
                QYS_Batch_Export::write_csv_row($h,[self::csv_safe($s->title),$s->jalali_day,self::csv_safe($r->first_name),self::csv_safe($r->last_name),self::csv_safe($roles[$r->member_role]??''),self::csv_safe((string)($family_export[(int)$r->member_id]??'—')),"\t".($identity_access?$r->mobile:("*******".substr((string)$r->mobile,-4))),"\t".($identity_access?$r->national_id:("******".substr((string)$r->national_id,-4))),$yes?'حاضر':'ثبت نشده',
                    $yes?$r->checked_in_at_iran:'',$yes?$r->checked_out_at_iran:'',$minutes===null?'':$minutes,$yes?self::csv_safe($r->method):'',$yes?self::csv_safe($r->exit_method):'']);
            }if($rows)QYS_Batch_Export::flush_chunk($h);
        }while(count((array)$rows)===$size);
        QYS_Batch_Export::close($h);exit;
    }
}
