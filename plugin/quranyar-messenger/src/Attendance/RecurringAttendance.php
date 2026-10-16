<?php
/**
 * QuranYar recurring / floating attendance programs.
 *
 * A program owns one long-lived QR and numeric code. Concrete attendance
 * sessions (occurrences) are created automatically from the first ENTRY scan.
 * No calendar dates have to be pre-created by an administrator.
 */
if (!defined('ABSPATH')) exit;

final class QYS_Attendance_Programs {
    public const OCCURRENCE_MAX_SECONDS = 64800; // 18 hours: one physical meeting window.
    public const LATE_EXIT_MAX_SECONDS = 129600; // 36 hours: allow a forgotten late checkout.

    public static function table(){ return QYS_Table_Names::name('attendance_programs'); }

    private static function columns($table): array {
        $out=[];foreach(QYS_Table_Names::diagnostic_columns((string)$table) as $field=>$row)$out[(string)$field]=true;return $out;
    }

    public static function schema_ready(): bool { return QYS_Schema_Runtime::ready(); }
    public static function diagnostic_schema_ready(): bool {
        $p=self::columns(self::table());
        foreach(['id','title','university','role_groups','access_code','status','current_session_id','qr_secret','qr_version','core_program_id'] as $c)if(!isset($p[$c]))return false;
        $s=self::columns(QYS_Attendance::sessions_table());
        foreach(['program_id','session_mode'] as $c)if(!isset($s[$c]))return false;
        return true;
    }

    public static function ensure_schema(): bool { global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();$p=self::table();
        dbDelta("CREATE TABLE $p (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        title varchar(180) NOT NULL,
        university varchar(40) NOT NULL DEFAULT '',
        role_groups varchar(255) NOT NULL DEFAULT 'all',
        access_code varchar(10) NOT NULL,
        status varchar(12) NOT NULL DEFAULT 'open',
        current_session_id bigint unsigned NOT NULL DEFAULT 0,
        created_by bigint unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        qr_secret varchar(64) NOT NULL DEFAULT '',
        qr_version int unsigned NOT NULL DEFAULT 1,
        core_program_id bigint unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id), UNIQUE KEY access_code (access_code), KEY university_status (university,status), KEY core_program_id (core_program_id)
        ) $c;");
        return self::diagnostic_schema_ready();
    }

    private static function groups_array($stored): array {
        if($stored===''||$stored==='all')return [];$roles=QYS_Messenger::member_roles();$out=[];
        foreach(explode(',',(string)$stored) as $r)if(isset($roles[$r]))$out[]=$r;return $out;
    }
    public static function role_allowed($stored,$role): bool {$g=self::groups_array($stored);return !$g||in_array((string)$role,$g,true);}
    private static function iran_now(): DateTimeImmutable {return new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));}
    private static function today_jalali(): string {return QYS_Calendar_Service::jalali_from_date(self::iran_now());}
    private static function access($p,$permission='attendance'): bool {
        $scope=QYS_Permission_Service::scope($permission);
        return $scope!==null&&($scope==='*'||($p&&(string)$p->university===$scope));
    }
    private static function link(array $args=[]): string {
        if(class_exists('QYS_Multitenant')&&QYS_Multitenant::is_portal_request())return QYS_Multitenant::campus_url('attendance',$args);
        return add_query_arg(array_merge(['page'=>'qys-attendance'],$args),admin_url('admin.php'));
    }
    private static function generate_code(): string { global $wpdb;
        for($i=0;$i<30;$i++){$code=(string)random_int(100000,999999);
            $in_programs=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE access_code=%s',$code));
            $in_sessions=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.QYS_Attendance::sessions_table().' WHERE access_code=%s',$code));
            if(!$in_programs&&!$in_sessions)return $code;
        }return '';
    }
    private static function generate_internal_session_code(): string { global $wpdb;
        for($i=0;$i<30;$i++){$code=(string)random_int(100000,999999);
            if(!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.QYS_Attendance::sessions_table().' WHERE access_code=%s',$code)))return $code;
        }return '';
    }

    /** One opaque QR for the entire program; event is explicitly chosen in the mini-app. */
    public static function qr_token($p): string {
        if(!$p||!preg_match('/\A[0-9a-f]{64}\z/D',(string)$p->qr_secret))return '';
        return substr(hash_hmac('sha256','QYSP1|'.$p->id.'|'.$p->qr_version,hex2bin($p->qr_secret)),0,32);
    }
    public static function qr_payload($p): string {$t=self::qr_token($p);return $t?'QYSP1:'.$p->id.':'.$t:'';}
    private static function qr_matches($p,$token): bool {$expected=self::qr_token($p);return $expected&&is_string($token)&&hash_equals($expected,$token);}

    public static function get(int $id){ global $wpdb;return $id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id)):null; }

    /** A linked attendance program may be opened only while its core Program can accept attendance. */
    private static function core_allows_open($p): bool {
        if(!$p||(int)($p->core_program_id??0)<=0)return true;
        if(!class_exists('QYS_Program_Repository'))return false;
        $core=QYS_Program_Repository::get_program((int)$p->core_program_id);
        return $core&&in_array((string)$core->status,['confirmed','running'],true);
    }

    /**
     * Trusted terminal-state hook called by ProgramService inside the same DB transaction.
     * Open records are intentionally left intact so members can still register their final exit.
     */
    public static function terminalize_core_program(int $core_program_id): bool { global $wpdb;
        if($core_program_id<=0)return true;
        $rows=(array)$wpdb->get_results($wpdb->prepare('SELECT id FROM '.self::table().' WHERE core_program_id=%d FOR UPDATE',$core_program_id));
        if(!$rows)return true;
        $ids=array_map(static fn($r)=>(int)$r->id,$rows);
        $ids=array_values(array_filter($ids,static fn($id)=>$id>0));if(!$ids)return true;
        $in=implode(',',$ids);$now=self::iran_now()->format('Y-m-d H:i:s');
        $a=$wpdb->query($wpdb->prepare("UPDATE ".QYS_Attendance::sessions_table()." SET status='closed',updated_at=%s WHERE session_mode='dynamic' AND status='open' AND program_id IN ($in)",$now));
        if($a===false)return false;
        $b=$wpdb->query($wpdb->prepare("UPDATE ".self::table()." SET status='closed',current_session_id=0,updated_at=%s WHERE id IN ($in)",$now));
        return $b!==false;
    }

    private static function occurrence_is_current($s): bool {
        if(!$s||$s->status!=='open'||$s->session_mode!=='dynamic')return false;
        $created=strtotime((string)$s->created_at.' Asia/Tehran');if(!$created)return false;
        return (time()-$created)<=self::OCCURRENCE_MAX_SECONDS;
    }
    public static function current_occurrence($p){ global $wpdb;
        if(!$p)return null;$s=null;$sid=(int)$p->current_session_id;
        if($sid)$s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.QYS_Attendance::sessions_table().' WHERE id=%d AND program_id=%d AND session_mode=%s',$sid,(int)$p->id,'dynamic'));
        if(self::occurrence_is_current($s))return $s;
        $cutoff=self::iran_now()->modify('-18 hours')->format('Y-m-d H:i:s');
        $s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.QYS_Attendance::sessions_table().' WHERE program_id=%d AND session_mode=%s AND status=%s AND created_at>=%s ORDER BY id DESC LIMIT 1',(int)$p->id,'dynamic','open',$cutoff));
        return self::occurrence_is_current($s)?$s:null;
    }

    /** Serialize first-scan session creation so two simultaneous first users share one occurrence. */
    private static function ensure_occurrence($p){ global $wpdb;
        $wpdb->query('START TRANSACTION');
        $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d FOR UPDATE',(int)$p->id));
        if(!$locked||$locked->status!=='open'){$wpdb->query('ROLLBACK');return new WP_Error('program_closed','این برنامه حضور در حال حاضر فعال نیست.',['status'=>403]);}
        $existing=self::current_occurrence($locked);
        if($existing){$wpdb->query('COMMIT');return $existing;}
        if((int)$locked->current_session_id>0)$wpdb->update(QYS_Attendance::sessions_table(),['status'=>'closed','updated_at'=>self::iran_now()->format('Y-m-d H:i:s')],['id'=>(int)$locked->current_session_id,'program_id'=>(int)$locked->id],['%s','%s'],['%d','%d']);
        $now=self::iran_now();$code=self::generate_internal_session_code();
        if($code===''){$wpdb->query('ROLLBACK');return new WP_Error('database','ساخت نوبت جدید برنامه انجام نشد.',['status'=>500]);}
        try{$secret=bin2hex(random_bytes(32));}catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error('crypto','ساخت شناسه امن جلسه انجام نشد.',['status'=>500]);}
        $ok=$wpdb->insert(QYS_Attendance::sessions_table(),[
            'title'=>(string)$locked->title,'university'=>(string)$locked->university,'jalali_day'=>self::today_jalali(),
            'start_hm'=>$now->format('H:i'),'end_hm'=>$now->format('H:i'),'role_groups'=>(string)$locked->role_groups,'access_code'=>$code,
            'status'=>'open','created_by'=>0,'created_at'=>$now->format('Y-m-d H:i:s'),'updated_at'=>$now->format('Y-m-d H:i:s'),
            'qr_secret'=>$secret,'qr_version'=>1,'program_id'=>(int)$locked->id,'session_mode'=>'dynamic'
        ],['%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%d','%d','%s']);
        if($ok!==1){$wpdb->query('ROLLBACK');return new WP_Error('database','ساخت نوبت جدید برنامه انجام نشد.',['status'=>500]);}
        $sid=(int)$wpdb->insert_id;
        $updated=$wpdb->update(self::table(),['current_session_id'=>$sid,'updated_at'=>$now->format('Y-m-d H:i:s')],['id'=>(int)$locked->id],['%d','%s'],['%d']);
        if($updated===false){$wpdb->query('ROLLBACK');return new WP_Error('database','اتصال نوبت جدید به برنامه انجام نشد.',['status'=>500]);}
        $wpdb->query('COMMIT');return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.QYS_Attendance::sessions_table().' WHERE id=%d',$sid));
    }
    private static function touch_occurrence(int $sid): void { global $wpdb;$now=self::iran_now();$wpdb->update(QYS_Attendance::sessions_table(),['end_hm'=>$now->format('H:i'),'updated_at'=>$now->format('Y-m-d H:i:s')],['id'=>$sid,'session_mode'=>'dynamic'],['%s','%s'],['%d','%s']); }
    private static function record(int $sid,int $mid){ global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.QYS_Attendance::records_table().' WHERE session_id=%d AND member_id=%d',$sid,$mid)); }
    private static function latest_open_record(int $pid,int $mid){ global $wpdb;
        $cutoff=gmdate('Y-m-d H:i:s',time()-self::LATE_EXIT_MAX_SECONDS);
        return $wpdb->get_row($wpdb->prepare('SELECT r.*,s.program_id FROM '.QYS_Attendance::records_table().' r JOIN '.QYS_Attendance::sessions_table().' s ON s.id=r.session_id WHERE s.program_id=%d AND s.session_mode=%s AND r.member_id=%d AND r.status=%s AND r.checked_out_at_utc IS NULL AND r.checked_in_at_utc>=%s ORDER BY r.id DESC LIMIT 1',$pid,'dynamic',$mid,'present',$cutoff));
    }
    private static function duration_minutes($r): ?int {if(!$r||empty($r->checked_out_at_utc)||$r->status==='void')return null;$d=strtotime($r->checked_out_at_utc.' UTC')-strtotime($r->checked_in_at_utc.' UTC');return $d>=0?(int)floor($d/60):null;}
    private static function rate_limit(int $pid,int $mid,bool $bad=false): bool {
        $key='qys_att_prog_'.substr(hash_hmac('sha256',$pid.'|'.$mid,wp_salt('nonce')),0,34);$n=(int)get_transient($key);if($n>=8)return false;
        if($bad)set_transient($key,$n+1,5*MINUTE_IN_SECONDS);else delete_transient($key);return true;
    }

    /** Program scan contract: the QR identifies the program; the mini-app supplies I/O explicitly. */
    public static function scan(WP_REST_Request $req,$m){ global $wpdb;
        $qr=trim((string)$req->get_param('qr'));$event=(string)$req->get_param('event');$is_qr=$qr!=='';
        if(!in_array($event,['I','O'],true))return new WP_Error('event','نوع ثبت ورود یا خروج را مشخص کنید.',['status'=>400]);
        if($is_qr){
            if(!preg_match('/\AQYSP1:([1-9][0-9]{0,15}):([0-9a-f]{32})\z/D',$qr,$mm))return new WP_Error('invalid','QR برنامه حضور معتبر نیست.',['status'=>400]);
            $pid=(int)$mm[1];$token=$mm[2];
        }else{
            $pid=absint($req->get_param('program_id'));$token=QYS_Text_Normalizer::digits(trim((string)$req->get_param('code')));
            if(!$pid||!preg_match('/\A[0-9]{6}\z/D',$token))return new WP_Error('invalid','کد عددی شش‌رقمی برنامه را وارد کنید.',['status'=>400]);
        }
        $p=self::get($pid);if(!$p||$p->university!==$m->university)return new WP_Error('program','برنامه حضور پیدا نشد.',['status'=>404]);
        if(!self::role_allowed($p->role_groups,(string)$m->member_role))return new WP_Error('group','این برنامه برای گروه شما نیست.',['status'=>403]);
        if(!self::rate_limit($pid,(int)$m->id))return new WP_Error('rate','تلاش‌های ناموفق زیاد است؛ پنج دقیقه بعد تلاش کنید.',['status'=>429]);
        $valid=$is_qr?self::qr_matches($p,$token):hash_equals((string)$p->access_code,$token);
        if(!$valid){self::rate_limit($pid,(int)$m->id,true);return new WP_Error('qr','QR یا کد برنامه معتبر نیست یا توسط مدیر تغییر کرده است.',['status'=>400]);}
        self::rate_limit($pid,(int)$m->id,false);
        if($event==='I' && (int)($p->core_program_id??0)>0 && class_exists('QYS_Program_Service') && !QYS_Program_Service::member_can_attend((int)$p->core_program_id,(int)$m->id)){
            return new WP_Error('not_enrolled','ثبت‌نام قطعی این برنامه برای شما وجود ندارد.',['status'=>403]);
        }
        if($event==='I'){
            if($p->status!=='open')return new WP_Error('program_closed','این برنامه حضور در حال حاضر فعال نیست.',['status'=>403]);
            $open=self::latest_open_record($pid,(int)$m->id);
            if($open){$current=self::current_occurrence($p);if($current&&(int)$current->id===(int)$open->session_id)return rest_ensure_response(['ok'=>true,'event'=>'entry','already'=>true,'entry'=>substr((string)$open->checked_in_at_iran,11,5),'message'=>'ورود شما در این نوبت قبلاً ثبت شده است.']);return new WP_Error('exit_previous','برای نوبت قبلی این برنامه هنوز خروج ثبت نشده است؛ ابتدا خروج را ثبت کنید.',['status'=>409]);}
            $s=self::ensure_occurrence($p);if(is_wp_error($s))return $s;
            $r=self::record((int)$s->id,(int)$m->id);
            if($r){if($r->status==='void')return new WP_Error('void','ثبت قبلی این نوبت توسط مدیر لغو شده است.',['status'=>409]);return rest_ensure_response(['ok'=>true,'event'=>'entry','already'=>true,'entry'=>substr($r->checked_in_at_iran,11,5),'message'=>'ورود شما در این نوبت قبلاً ثبت شده است.']);}
            $now=self::iran_now();$wpdb->query('START TRANSACTION');
            $ok=$wpdb->insert(QYS_Attendance::records_table(),['session_id'=>(int)$s->id,'member_id'=>(int)$m->id,'method'=>$is_qr?'program_qr':'program_code','checked_in_at_utc'=>gmdate('Y-m-d H:i:s'),'checked_in_at_iran'=>$now->format('Y-m-d H:i:s'),'created_by'=>0,'note'=>'','status'=>'present'],['%d','%d','%s','%s','%s','%d','%s','%s']);
            if($ok!==1){$wpdb->query('ROLLBACK');if(self::record((int)$s->id,(int)$m->id))return rest_ensure_response(['ok'=>true,'event'=>'entry','already'=>true,'message'=>'ورود شما در این نوبت قبلاً ثبت شده است.']);return new WP_Error('database','ثبت ورود انجام نشد؛ دوباره تلاش کنید.',['status'=>500]);}
            if((int)($p->core_program_id??0)>0 && class_exists('QYS_Program_Service')){
                $participation=QYS_Program_Service::record_attendance_participation((int)$p->core_program_id,(int)$m->id,0,false);
                if(is_wp_error($participation)){$wpdb->query('ROLLBACK');return $participation;}
            }
            self::touch_occurrence((int)$s->id);$wpdb->query('COMMIT');
            return rest_ensure_response(['ok'=>true,'event'=>'entry','already'=>false,'entry'=>$now->format('H:i'),'session_day'=>(string)$s->jalali_day,'message'=>'ورود شما ثبت شد؛ این نوبت به‌صورت خودکار در پرونده حضورتان ایجاد شد.']);
        }
        $r=self::latest_open_record($pid,(int)$m->id);if(!$r)return new WP_Error('entry_first','برای این برنامه ورود بازی پیدا نشد؛ ابتدا ورود را ثبت کنید.',['status'=>409]);
        if($r->checked_out_at_utc)return rest_ensure_response(['ok'=>true,'event'=>'exit','already'=>true,'exit'=>substr($r->checked_out_at_iran,11,5),'minutes'=>self::duration_minutes($r),'message'=>'خروج شما قبلاً ثبت شده است.']);
        $now=self::iran_now();$utc=gmdate('Y-m-d H:i:s');
        $updated=$wpdb->query($wpdb->prepare('UPDATE '.QYS_Attendance::records_table().' SET checked_out_at_utc=%s,checked_out_at_iran=%s,exit_method=%s WHERE id=%d AND checked_out_at_utc IS NULL AND status=%s',$utc,$now->format('Y-m-d H:i:s'),$is_qr?'program_qr':'program_code',(int)$r->id,'present'));
        if($updated!==1)return new WP_Error('database','ثبت خروج انجام نشد؛ دوباره تلاش کنید.',['status'=>500]);
        self::touch_occurrence((int)$r->session_id);$latest=self::record((int)$r->session_id,(int)$m->id);
        return rest_ensure_response(['ok'=>true,'event'=>'exit','already'=>false,'exit'=>$now->format('H:i'),'minutes'=>self::duration_minutes($latest),'message'=>'خروج شما با موفقیت ثبت شد.']);
    }

    /** Public program cards; no future dates are manufactured. */
    public static function public_models($m): array { global $wpdb;
        if(!self::schema_ready())return [];
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE university=%s ORDER BY status ASC,title,id LIMIT 50',(string)$m->university));
        $out=[];foreach((array)$rows as $p){if(!self::role_allowed($p->role_groups,(string)$m->member_role))continue;
            if((int)($p->core_program_id??0)>0&&class_exists('QYS_Program_Service')&&!QYS_Program_Service::member_can_attend((int)$p->core_program_id,(int)$m->id)){if(!self::latest_open_record((int)$p->id,(int)$m->id))continue;}
            $current=self::current_occurrence($p);$s=$current;$r=$s?self::record((int)$s->id,(int)$m->id):null;
            if(!$r||$r->checked_out_at_utc){$late=self::latest_open_record((int)$p->id,(int)$m->id);if($late){$r=$late;if(!$s||((int)$s->id!==(int)$late->session_id))$s=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.QYS_Attendance::sessions_table().' WHERE id=%d',(int)$late->session_id));}}
            if($p->status!=='open'&&!($r&&$r->status==='present'&&!$r->checked_out_at_utc))continue;
            $minutes=self::duration_minutes($r);
            $out[]=['type'=>'program','program_id'=>(int)$p->id,'id'=>$s?(int)$s->id:0,'title'=>(string)$p->title,'program_open'=>$p->status==='open','jalali_day'=>$s?(string)$s->jalali_day:'','start_hm'=>$s?(string)$s->start_hm:'','end_hm'=>$s?(string)$s->end_hm:'','occurrence_active'=>(bool)$current,
                'entry_open'=>(bool)($p->status==='open'&&$s&&(!$r||$r->status!=='present')),'exit_open'=>(bool)($r&&$r->status==='present'&&!$r->checked_out_at_utc),
                'present'=>(bool)($r&&$r->status==='present'),'entry'=>$r&&$r->status==='present'?substr((string)$r->checked_in_at_iran,11,5):null,'exit'=>$r&&$r->checked_out_at_iran?substr((string)$r->checked_out_at_iran,11,5):null,'exit_next_day'=>(bool)($r&&$r->checked_out_at_iran&&substr($r->checked_out_at_iran,0,10)!==substr($r->checked_in_at_iran,0,10)),'minutes'=>$minutes];
        }return $out;
    }


    /** Create the reusable attendance QR for an existing generic Program. */
    public static function create_for_core_program(int $core_program_id,int $actor_id=0){ global $wpdb;
        if($core_program_id<=0||!class_exists('QYS_Program_Repository')||!QYS_Program_Repository::schema_ready())return new WP_Error('program','برنامه اصلی معتبر نیست.');
        $program=QYS_Program_Repository::get_program($core_program_id);if(!$program)return new WP_Error('program','برنامه اصلی پیدا نشد.');if(!in_array((string)$program->status,['confirmed','running'],true))return new WP_Error('program_state','QR حضور فقط برای برنامه قطعی‌شده یا در حال اجرا قابل ایجاد است.');
        $universities=QYS_Program_Repository::program_universities($core_program_id);if(count($universities)!==1)return new WP_Error('university','برای QR حضور ثابت، برنامه باید فعلاً متعلق به یک دانشگاه باشد.');
        $uni=(string)$universities[0];$scope=QYS_Permission_Service::scope('attendance');if($scope===null||($scope!=='*'&&$scope!==$uni))return new WP_Error('forbidden','مجوز حضور و غیاب این دانشگاه را ندارید.');
        if(QYS_Program_Repository::attendance_link($core_program_id))return new WP_Error('duplicate','برای این برنامه قبلاً QR حضور ایجاد شده است.');
        $code=self::generate_code();if($code==='')return new WP_Error('database','ساخت کد برنامه انجام نشد.');try{$secret=bin2hex(random_bytes(32));}catch(Throwable $e){return new WP_Error('crypto','ساخت کلید امن QR انجام نشد.');}
        $now=current_time('mysql');$actor_id=$actor_id?:get_current_user_id();$wpdb->query('START TRANSACTION');
        $ok=$wpdb->insert(self::table(),['title'=>(string)$program->title,'university'=>$uni,'role_groups'=>(string)$program->audience_roles,'access_code'=>$code,'status'=>'open','current_session_id'=>0,'created_by'=>$actor_id,'created_at'=>$now,'updated_at'=>$now,'qr_secret'=>$secret,'qr_version'=>1,'core_program_id'=>$core_program_id],['%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%d','%d']);
        if($ok!==1){$wpdb->query('ROLLBACK');return new WP_Error('database','ایجاد QR حضور انجام نشد.');}$attendance_id=(int)$wpdb->insert_id;
        $linked=QYS_Program_Service::link_attendance($core_program_id,$attendance_id,$uni,$actor_id,false);if(is_wp_error($linked)){$wpdb->query('ROLLBACK');return $linked;}
        $wpdb->query('COMMIT');return $attendance_id;
    }

    public static function create_from_post(): array { global $wpdb;
        $title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));if($title===''||QYS_Text_Normalizer::chars($title)>180)return ['message'=>'عنوان برنامه معتبر نیست.','id'=>0];
        $groups=QYS_Attendance::normalize_groups(wp_unslash($_POST['role_groups']??[]));$scope=QYS_Permission_Service::scope('attendance');if($scope===null)return ['message'=>'مجوز ایجاد برنامه ندارید.','id'=>0];
        $uni=$scope==='*'?sanitize_key(wp_unslash($_POST['university']??'')):$scope;if(!QYS_Multitenant::university_active($uni))return ['message'=>'یک دانشگاه فعال و معتبر انتخاب کنید.','id'=>0];
        $code=self::generate_code();if($code==='')return ['message'=>'ساخت کد برنامه انجام نشد.','id'=>0];try{$secret=bin2hex(random_bytes(32));}catch(Throwable $e){return ['message'=>'ساخت کلید امن QR انجام نشد.','id'=>0];}
        $now=current_time('mysql');$actor=get_current_user_id();
        $wpdb->query('START TRANSACTION');
        $core_id=class_exists('QYS_Program_Service')?QYS_Program_Service::create_internal([
            'manager_university'=>$uni,'universities'=>[$uni],'title'=>$title,'program_type'=>'recurring_session','status'=>'running',
            'audience_roles'=>$groups,'registration_mode'=>'none','attendance_enabled'=>1
        ],$actor,false):0;
        if(is_wp_error($core_id)||!(int)$core_id){$wpdb->query('ROLLBACK');return ['message'=>'ایجاد هسته برنامه انجام نشد.','id'=>0];}
        $ok=$wpdb->insert(self::table(),['title'=>$title,'university'=>$uni,'role_groups'=>$groups,'access_code'=>$code,'status'=>'open','current_session_id'=>0,'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now,'qr_secret'=>$secret,'qr_version'=>1,'core_program_id'=>(int)$core_id],['%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%d','%d']);
        if($ok!==1){$wpdb->query('ROLLBACK');return ['message'=>'ایجاد برنامه ناموفق بود.','id'=>0];}
        $attendance_id=(int)$wpdb->insert_id;
        $linked=QYS_Program_Service::link_attendance((int)$core_id,$attendance_id,$uni,$actor,false);
        if(is_wp_error($linked)){$wpdb->query('ROLLBACK');return ['message'=>'اتصال برنامه به حضور و غیاب انجام نشد.','id'=>0];}
        $wpdb->query('COMMIT');
        return ['message'=>'برنامه ثابت ساخته شد. همین QR را برای همه نوبت‌های آینده استفاده کنید.','id'=>$attendance_id];
    }
    public static function change_status(int $id,string $status): string { global $wpdb;if(!in_array($status,['open','closed'],true))return 'وضعیت نامعتبر است.';$p=self::get($id);if(!$p||!self::access($p))return 'دسترسی غیرمجاز.';if($status==='open'&&!self::core_allows_open($p))return 'برنامه اصلی پایان یافته، لغو یا بایگانی شده است؛ حضور و غیاب دوباره فعال نمی‌شود.';$n=$wpdb->update(self::table(),['status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);return $n===false?'تغییر وضعیت انجام نشد.':($status==='open'?'برنامه فعال شد.':'برنامه متوقف شد؛ خروج‌های باز همچنان قابل ثبت‌اند.'); }
    public static function close_current_occurrence(int $id): string { global $wpdb;
        $p=self::get($id);if(!$p||!self::access($p))return 'دسترسی غیرمجاز.';
        $s=self::current_occurrence($p);if(!$s)return 'نوبت جاری فعالی برای پایان‌دادن وجود ندارد.';
        $now=self::iran_now()->format('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');
        $a=$wpdb->update(QYS_Attendance::sessions_table(),['status'=>'closed','updated_at'=>$now],['id'=>(int)$s->id,'program_id'=>$id,'session_mode'=>'dynamic'],['%s','%s'],['%d','%d','%s']);
        $b=$wpdb->update(self::table(),['current_session_id'=>0,'updated_at'=>$now],['id'=>$id],['%d','%s'],['%d']);
        if($a===false||$b===false){$wpdb->query('ROLLBACK');return 'پایان نوبت جاری انجام نشد.';}
        $wpdb->query('COMMIT');return 'نوبت جاری پایان یافت؛ ثبت ورود بعدی یک نوبت تازه می‌سازد. اعضایی که خروجشان باز مانده هنوز می‌توانند خروج را ثبت کنند.';
    }
    public static function regenerate(int $id): string { global $wpdb;$p=self::get($id);if(!$p)return 'برنامه پیدا نشد.';if(!self::access($p))return 'دسترسی غیرمجاز.';$code=self::generate_code();if($code==='')return 'ساخت کد جدید انجام نشد.';try{$secret=bin2hex(random_bytes(32));}catch(Throwable $e){return 'ساخت کلید امن انجام نشد.';}$n=$wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET qr_secret=%s,qr_version=qr_version+1,access_code=%s,updated_at=%s WHERE id=%d',$secret,$code,current_time('mysql'),$id));return $n===1?'QR و کد ثابت برنامه تعویض شدند؛ پوستر قبلی دیگر معتبر نیست.':'تعویض QR و کد انجام نشد.'; }

    public static function render_create(array $roles): void {
        $p=wp_unslash($_POST);$title=isset($p['title'])?sanitize_text_field($p['title']):'';$selected=isset($p['role_groups'])&&is_array($p['role_groups'])?array_map('sanitize_key',$p['role_groups']):[];
        echo '<section class="qys-att-panel qys-att-program-create"><div class="qys-att-panel-title"><div><h2>ایجاد برنامه حضور ثابت</h2><span>تاریخ و ساعت تعریف نمی‌کنید؛ اولین ثبت ورود، نوبت همان روز را می‌سازد.</span></div></div>';
        echo '<div class="qys-att-program-explainer"><strong>یک بار بسازید، یک بار QR را چاپ کنید.</strong><span>همین QR و کد برای هفته‌ها و نوبت‌های آینده باقی می‌ماند؛ زمان‌ها فقط از ساعت سرور ثبت می‌شوند.</span></div>';
        echo '<form method="post" class="qys-att-form">';wp_nonce_field('qys_attendance','qys_attendance_nonce');echo '<input type="hidden" name="qys_action" value="create_program">';
        $scope=QYS_Permission_Service::scope('attendance');if($scope==='*'){echo '<label class="qys-att-wide">دانشگاه<select name="university" required><option value="">انتخاب دانشگاه</option>';foreach(QYS_Multitenant::universities() as $u)echo '<option value="'.esc_attr($u['code']).'">'.esc_html($u['title']).'</option>';echo '</select></label>';}else echo '<input type="hidden" name="university" value="'.esc_attr($scope).'">';
        echo '<label class="qys-att-wide">عنوان برنامه<input name="title" maxlength="180" required placeholder="مثلاً جلسه هفتگی قرآن" value="'.esc_attr($title).'"></label>';
        echo '<fieldset class="qys-att-wide"><legend>گروه‌های مجاز <small>(عدم انتخاب یعنی همه اعضای تأییدشده)</small></legend><div class="qys-att-groups">';foreach($roles as $k=>$label)echo '<label><input type="checkbox" name="role_groups[]" value="'.esc_attr($k).'"'.checked(in_array($k,$selected,true),true,false).'> '.esc_html($label).'</label>';echo '</div></fieldset>';
        echo '<div class="qys-att-wide qys-att-create-actions"><button class="button button-primary button-hero" type="submit">ایجاد برنامه و QR ثابت</button> <a class="button" href="'.esc_url(self::link()).'">انصراف</a></div></form></section>';
    }
    private static function group_label($p,array $roles): string {$g=self::groups_array($p->role_groups);return $g?implode('، ',array_map(fn($r)=>$roles[$r]??$r,$g)):'همه اعضای تأییدشده';}
    public static function render_overview(array $roles): void { global $wpdb;
        $scope=QYS_Permission_Service::scope('attendance');if($scope===null)return;$where=$scope==='*'?'1=1':$wpdb->prepare('university=%s',$scope);$rows=$wpdb->get_results('SELECT * FROM '.self::table().' WHERE '.$where.' ORDER BY status ASC,title ASC,id DESC LIMIT 100');
        echo '<section class="qys-att-panel qys-att-programs"><div class="qys-att-panel-title"><div><h2>برنامه‌های ثابت</h2><span>برای جلسات تکرارشونده با تاریخ و ساعت متغیر؛ بدون تعریف تقویم.</span></div><a class="button button-primary" href="'.esc_url(self::link(['view'=>'create_program'])).'">+ برنامه ثابت جدید</a></div>';
        if(!$rows){echo '<div class="qys-att-empty-index"><strong>هنوز برنامه ثابتی ندارید.</strong><p>برای جلسه هفتگی یا برنامه‌های بدون تاریخ ثابت، یک برنامه بسازید و QR آن را فقط یک‌بار چاپ کنید.</p></div></section>';return;}
        echo '<div class="qys-att-program-list">';foreach((array)$rows as $p){$s=self::current_occurrence($p);$stats=null;if($s)$stats=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) entries,COALESCE(SUM(checked_out_at_utc IS NOT NULL),0) exits FROM ".QYS_Attendance::records_table()." WHERE session_id=%d AND status='present'",(int)$s->id));
            echo '<article class="qys-att-program-card"><div><span class="qys-att-session-badge '.($p->status==='open'?'is-open':'is-closed').'">'.($p->status==='open'?'فعال':'متوقف').'</span><h3>'.esc_html($p->title).'</h3><p>'.esc_html(self::group_label($p,$roles)).'</p><small>'.($s?'نوبت جاری: '.esc_html($s->jalali_day.' از '.$s->start_hm):'نوبت جاری ندارد؛ اولین ورود آن را می‌سازد.').'</small></div><div class="qys-att-program-side">'.($s?'<span>ورود <strong>'.(int)($stats->entries??0).'</strong></span><span>خروج <strong>'.(int)($stats->exits??0).'</strong></span>':'').'<a class="button button-primary" href="'.esc_url(self::link(['program_id'=>(int)$p->id])).'">مدیریت برنامه</a></div></article>';
        }echo '</div></section>';
    }
    public static function render_detail($p,array $roles): void { global $wpdb;if(!$p||!self::access($p))wp_die('دسترسی غیرمجاز');
        $print=wp_nonce_url(self::link(['program_id'=>(int)$p->id,'print_program_qr'=>1]),'qys_att_program_print_'.(int)$p->id);
        $current_for_controls=self::current_occurrence($p);
        $can_open=self::core_allows_open($p);
        echo '<section class="qys-att-session-hero qys-att-program-hero"><div><span class="qys-att-session-badge '.($p->status==='open'?'is-open':'is-closed').'">'.($p->status==='open'?'فعال':'متوقف').'</span><h2>'.esc_html($p->title).'</h2><p>برنامه ثابت · '.esc_html(self::group_label($p,$roles)).'</p></div><div class="qys-att-session-hero-actions">';
        if($p->status==='open'||$can_open){echo '<form method="post" class="qys-att-inline">';wp_nonce_field('qys_attendance','qys_attendance_nonce');echo '<input type="hidden" name="program_id" value="'.(int)$p->id.'"><button class="button" name="qys_action" value="'.($p->status==='open'?'program_close':'program_open').'">'.($p->status==='open'?'توقف برنامه':'فعال‌کردن برنامه').'</button></form>';}else echo '<span class="qys-att-program-security">برنامه اصلی بسته شده است؛ فقط خروج‌های باز قبلی قابل تکمیل‌اند.</span>';
        if($current_for_controls){echo '<form method="post" class="qys-att-inline">';wp_nonce_field('qys_attendance','qys_attendance_nonce');echo '<input type="hidden" name="program_id" value="'.(int)$p->id.'"><button class="button" name="qys_action" value="program_close_occurrence" onclick="return confirm(\'نوبت جاری پایان یابد؟ ثبت ورود بعدی نوبت تازه می‌سازد.\')">پایان نوبت جاری</button></form>';}echo '</div></section>';
        echo '<section class="qys-att-panel qys-att-program-code"><div class="qys-att-panel-title"><div><h3>QR ثابت برنامه</h3><span>این پوستر را برای همه نوبت‌های آینده نگه دارید.</span></div></div><div class="qys-att-program-code-grid"><div><span>کد شش‌رقمی ثابت</span><strong dir="ltr">'.esc_html($p->access_code).'</strong><small>در برنامک، ورود یا خروج انتخاب می‌شود.</small></div><div class="qys-att-code-actions"><a class="button button-primary button-hero" href="'.esc_url($print).'">چاپ پوستر QR ثابت</a><form method="post" class="qys-att-inline">';wp_nonce_field('qys_attendance','qys_attendance_nonce');echo '<input type="hidden" name="program_id" value="'.(int)$p->id.'"><button class="button" name="qys_action" value="program_regenerate" onclick="return confirm(\'QR و کد چاپ‌شده فعلی باطل می‌شوند. ادامه؟\')">تعویض QR و کد</button></form></div></div><p class="qys-att-program-security">QR ثابت برای سهولت استفاده است و به‌تنهایی حضور فیزیکی را اثبات نمی‌کند؛ در صورت انتشار ناخواسته، از «تعویض QR و کد» استفاده کنید.</p></section>';
        $occ=$wpdb->get_results($wpdb->prepare("SELECT s.id,s.jalali_day,s.start_hm,s.end_hm,s.status,s.created_at,s.updated_at,COUNT(r.id) entries,COALESCE(SUM(r.checked_out_at_utc IS NOT NULL AND r.status='present'),0) exits FROM ".QYS_Attendance::sessions_table()." s LEFT JOIN ".QYS_Attendance::records_table()." r ON r.session_id=s.id AND r.status='present' WHERE s.program_id=%d AND s.session_mode='dynamic' GROUP BY s.id ORDER BY s.id DESC LIMIT 50",(int)$p->id));
        echo '<section class="qys-att-panel"><div class="qys-att-panel-title"><div><h3>نوبت‌های ایجادشده خودکار</h3><span>هر ردیف با اولین ثبت ورود ایجاد شده است؛ نیازی به تعریف تاریخ از قبل نبوده.</span></div></div>';
        if(!$occ)echo '<div class="qys-att-empty-index"><strong>هنوز نوبتی ثبت نشده است.</strong><p>اولین اسکن «ورود» توسط یکی از اعضا، نخستین نوبت را ایجاد می‌کند.</p></div>';
        else{echo '<div class="qys-att-session-list">';foreach((array)$occ as $s){echo '<article class="qys-att-session-card"><div class="qys-att-session-main"><div class="qys-att-session-meta"><span class="qys-att-session-badge '.($s->status==='open'?'is-open':'is-closed').'">'.($s->status==='open'&&self::occurrence_is_current($s)?'جاری':'بایگانی').'</span><span class="qys-att-session-day">'.esc_html($s->jalali_day).' · شروع واقعی '.esc_html($s->start_hm).'</span></div><h3>نوبت '.esc_html($p->title).'</h3></div><div class="qys-att-session-side"><div class="qys-att-session-numbers"><span>ورود <strong>'.(int)$s->entries.'</strong></span><span>خروج <strong>'.(int)$s->exits.'</strong></span></div><a class="button" href="'.esc_url(self::link(['session_id'=>(int)$s->id])).'">جزئیات و گزارش</a></div></article>'; }echo '</div>';}
        echo '</section>';
    }
    public static function print_program($p): void {
        if(!$p||!self::access($p))wp_die('دسترسی غیرمجاز');check_admin_referer('qys_att_program_print_'.(int)$p->id);$payload=self::qr_payload($p);if(!$payload)wp_die('QR برنامه آماده نیست.');
        echo '<div class="wrap qys-att-print-root" dir="rtl"><div class="qys-att-print-toolbar"><button class="button button-primary" onclick="window.print()">چاپ پوستر</button> <a class="button" href="'.esc_url(self::link(['program_id'=>(int)$p->id])).'">بازگشت</a></div>';
        echo '<section class="qys-att-poster qys-att-program-poster"><div class="qys-att-poster-heading" style="--qys-qr-accent:#0f766e"><small>اداره قرآن و عترت · '.esc_html(QYS_Multitenant::university_label((string)$p->university)).'</small><h1>حضور در برنامه</h1><h2>'.esc_html($p->title).'</h2></div>';
        echo '<div class="qys-att-poster-instructions" role="note"><strong class="qys-att-poster-alert">این QR ثابت است؛ تاریخ و ساعت روی پوستر ندارد</strong><div class="qys-att-poster-steps"><p><b>۱</b> ایتا ← برنامک <strong>قرآن‌یار</strong> ← حضور و غیاب.</p><p><b>۲</b> این QR را با اسکنر داخل برنامک اسکن کنید.</p><p><b>۳</b> «ورود» یا «خروج» را تأیید کنید؛ ساعت از سرور ثبت می‌شود.</p></div></div>';
        echo '<div class="qys-att-poster-code" data-qys-qr="'.esc_attr($payload).'" aria-label="QR ثابت برنامه حضور قرآن‌یار"></div>';
        echo '<div class="qys-att-poster-info"><p class="qys-att-poster-fallback">اگر اسکنر فعال نیست، کد ثابت برنامه را در بخش حضور وارد کنید: <strong class="qys-att-manual-code" dir="ltr">'.esc_html($p->access_code).'</strong></p><small>یک QR برای همه نوبت‌ها. اولین ورود، نوبت همان جلسه را به‌صورت خودکار ایجاد می‌کند.</small></div></section></div>';
    }
}
