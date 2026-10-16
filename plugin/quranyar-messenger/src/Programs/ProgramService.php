<?php
if (!defined('ABSPATH')) exit;

/** Business rules for generic Programs, applications, enrollment and member history. */
final class QYS_Program_Service {
    public const PROGRAM_STATUSES=['draft','registration_open','registration_closed','confirmed','running','completed','cancelled','archived'];
    public const APPLICATION_STATUSES=['submitted','under_review','waitlisted','accepted','rejected','withdrawn','closed','cancelled'];
    public const ENROLLMENT_STATUSES=['enrolled','participating','completed','withdrawn','no_show','cancelled','closed'];
    public const FIELD_TYPES=['short_text','long_text','number','date','single_choice','multi_choice','yes_no','notice'];

    private const PROGRAM_TRANSITIONS=[
        'draft'=>['registration_open','confirmed','cancelled','archived'],
        'registration_open'=>['registration_closed','confirmed','cancelled'],
        'registration_closed'=>['registration_open','confirmed','cancelled'],
        'confirmed'=>['running','cancelled'],
        'running'=>['completed','cancelled'],
        'completed'=>['archived'],
        'cancelled'=>['archived'],
        'archived'=>[],
    ];
    private const APPLICATION_TRANSITIONS=[
        'submitted'=>['under_review','waitlisted','accepted','rejected','withdrawn'],
        'under_review'=>['waitlisted','accepted','rejected','withdrawn'],
        'waitlisted'=>['accepted','rejected','withdrawn'],
        'accepted'=>['withdrawn'],
        'rejected'=>[],
        'withdrawn'=>[],
        'closed'=>[],
        'cancelled'=>[],
    ];
    private const ENROLLMENT_TRANSITIONS=[
        'enrolled'=>['participating','withdrawn','no_show'],
        'participating'=>['completed','withdrawn'],
        'completed'=>[],
        'withdrawn'=>[],
        'no_show'=>[],
        'cancelled'=>[],
        'closed'=>[],
    ];

    public static function can_program_transition(string $from,string $to): bool {
        return isset(self::PROGRAM_TRANSITIONS[$from])&&in_array($to,self::PROGRAM_TRANSITIONS[$from],true);
    }
    public static function can_application_transition(string $from,string $to): bool {
        return isset(self::APPLICATION_TRANSITIONS[$from])&&in_array($to,self::APPLICATION_TRANSITIONS[$from],true);
    }
    public static function can_enrollment_transition(string $from,string $to): bool {
        return isset(self::ENROLLMENT_TRANSITIONS[$from])&&in_array($to,self::ENROLLMENT_TRANSITIONS[$from],true);
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function clean_roles($raw): string {
        if($raw==='all'||$raw===''||$raw===null)return 'all';
        $list=is_array($raw)?$raw:explode(',',(string)$raw);$allowed=QYS_Messenger::member_roles();$seen=[];
        foreach($list as $role){$role=sanitize_key((string)$role);if(isset($allowed[$role]))$seen[$role]=true;}
        return $seen?implode(',',array_keys($seen)):'all';
    }
    private static function role_allowed(string $stored,string $role): bool {
        if($stored===''||$stored==='all')return true;
        return in_array($role,array_filter(explode(',',$stored)),true);
    }

    /**
     * Canonical admission snapshot. Capacity is global to the Program, even when
     * the Program is shared between universities. Accepted applications reserve
     * one seat until they either become an active enrollment or reach a terminal
     * enrollment state. Waitlisted/submitted applications never consume capacity.
     */
    public static function admission_snapshot(int $program_id){
        $p=QYS_Program_Repository::get_program($program_id);
        if(!$p)return new WP_Error('program','برنامه پیدا نشد.',['status'=>404]);
        return self::admission_snapshot_for_program($p);
    }

    private static function admission_snapshot_for_program($p): array {
        $program_id=(int)$p->id;$counts=QYS_Program_Repository::admission_counts($program_id);$used=(int)$counts['used'];
        $capacity=max(0,(int)$p->capacity);$limited=$capacity>0;$remaining=$limited?max(0,$capacity-$used):null;
        return [
            'program_id'=>$program_id,'capacity'=>$capacity,'limited'=>$limited,'used'=>$used,'remaining'=>$remaining,
            'full'=>$limited&&$used>=$capacity,'over_capacity'=>$limited&&$used>$capacity,'waitlist_enabled'=>(int)$p->waitlist_enabled===1,
            'waitlisted'=>(int)$counts['waitlisted'],'accepted_pending'=>(int)$counts['accepted_pending'],'active_enrollments'=>(int)$counts['active_enrollments'],
        ];
    }

    private static function capacity_error(array $snapshot){
        if(!empty($snapshot['waitlist_enabled']))return new WP_Error('capacity_waitlist','ظرفیت برنامه تکمیل شده است؛ در صورت نیاز درخواست را در فهرست انتظار قرار دهید.',['status'=>409,'action'=>'waitlist','capacity'=>(int)$snapshot['capacity'],'used'=>(int)$snapshot['used']]);
        return new WP_Error('capacity_full','ظرفیت برنامه تکمیل شده و فهرست انتظار برای این برنامه فعال نیست.',['status'=>409,'capacity'=>(int)$snapshot['capacity'],'used'=>(int)$snapshot['used']]);
    }
    /** One eligibility policy used at submit, accept, enroll and attendance boundaries. */
    public static function member_eligibility(int $program_id,int $member_id,string $expected_university=''){
        $p=QYS_Program_Repository::get_program($program_id);if(!$p)return new WP_Error('program','برنامه پیدا نشد.',['status'=>404]);
        $m=QYS_Member_Repository::find_by_id($member_id);if(!$m||!QYS_Messenger::profile_complete($m))return new WP_Error('profile_incomplete','پرونده عضو کامل نیست.',['status'=>409]);
        if((int)$m->verified!==1)return new WP_Error('not_verified','هویت عضو در دانشگاه فعلی تأیید نشده است.',['status'=>409]);
        $member_uni=sanitize_key((string)$m->university);if($member_uni===''||!QYS_Multitenant::university_active($member_uni))return new WP_Error('university','دانشگاه فعلی عضو معتبر و فعال نیست.',['status'=>409]);
        if($expected_university!==''&&$member_uni!==sanitize_key($expected_university))return new WP_Error('tenant_changed','دانشگاه فعلی عضو با دانشگاه ثبت درخواست/ثبت‌نام تطابق ندارد. ابتدا وضعیت انتقال دانشگاه را تعیین تکلیف کنید.',['status'=>409]);
        if(!in_array($member_uni,QYS_Program_Repository::program_universities($program_id),true)||!self::role_allowed((string)$p->audience_roles,(string)$m->member_role))return new WP_Error('audience','عضو در دامنه فعلی این برنامه واجد شرایط نیست.',['status'=>409]);
        return true;
    }
    private static function normalize_universities(array $universities): array {
        $valid=[];foreach($universities as $u){$u=sanitize_key((string)$u);if($u!==''&&QYS_Multitenant::university_active($u))$valid[$u]=true;}
        return array_keys($valid);
    }
    private static function audit(string $action,int $program_id=0,int $application_id=0,int $enrollment_id=0,int $actor_id=0,string $from='',string $to=''): bool {
        return QYS_Program_Repository::insert_audit([
            'program_id'=>$program_id,'application_id'=>$application_id,'enrollment_id'=>$enrollment_id,
            'actor_id'=>$actor_id,'action'=>substr(sanitize_key($action),0,50),'from_status'=>substr(sanitize_key($from),0,24),'to_status'=>substr(sanitize_key($to),0,24),'created_at_utc'=>self::now()
        ]);
    }

    /** Permission-aware creation path for the future Programs UI. */
    public static function create(array $data){
        $scope=QYS_Permission_Service::scope('programs');
        if($scope===null)return new WP_Error('forbidden','مجوز مدیریت برنامه‌ها را ندارید.',['status'=>403]);
        if($scope!=='*'){
            $data['manager_university']=$scope;
            $data['universities']=[$scope];
        } else {
            $data['manager_university']=sanitize_key((string)($data['manager_university']??''));
        }
        return self::create_internal($data,get_current_user_id());
    }

    /** Trusted internal creation path used by attendance compatibility/backfill. */
    public static function create_internal(array $data,int $actor_id=0,bool $manage_transaction=true){
        if(!QYS_Program_Repository::schema_ready()){
            // Fresh-install/backfill runs before the durable runtime gate opens.
            if($manage_transaction || !QYS_Program_Repository::diagnostic_schema_ready())return new WP_Error('schema','هسته برنامه‌ها آماده نیست.',['status'=>503]);
        }
        $title=trim(sanitize_text_field((string)($data['title']??'')));
        if($title===''||QYS_Text_Normalizer::chars($title)>190)return new WP_Error('title','عنوان برنامه معتبر نیست.',['status'=>400]);
        $universities=self::normalize_universities((array)($data['universities']??[]));
        if(!$universities)return new WP_Error('university','دست‌کم یک دانشگاه فعال لازم است.',['status'=>400]);
        $manager=sanitize_key((string)($data['manager_university']??''));
        if($manager!==''&&!in_array($manager,$universities,true))return new WP_Error('manager_university','دانشگاه مدیر باید در دامنه برنامه باشد.',['status'=>400]);
        $type=sanitize_key((string)($data['program_type']??'course'));if($type==='')$type='course';
        $status=sanitize_key((string)($data['status']??'draft'));if(!in_array($status,self::PROGRAM_STATUSES,true))return new WP_Error('status','وضعیت برنامه نامعتبر است.',['status'=>400]);
        $registration=sanitize_key((string)($data['registration_mode']??'approval'));if(!in_array($registration,['none','approval'],true))return new WP_Error('registration_mode','شیوه ثبت‌نام نامعتبر است.',['status'=>400]);
        $capacity=max(0,(int)($data['capacity']??0));
        $waitlist=!empty($data['waitlist_enabled'])?1:0;
        $attendance=!empty($data['attendance_enabled'])?1:0;
        $roles=self::clean_roles($data['audience_roles']??'all');
        $description=wp_kses_post((string)($data['description']??''));
        $now=self::now();
        if($manage_transaction)QYS_Program_Repository::begin();
        $id=QYS_Program_Repository::insert_program([
            'manager_university'=>$manager,'title'=>$title,'description'=>$description,'program_type'=>$type,'status'=>$status,
            'audience_roles'=>$roles,'registration_mode'=>$registration,'capacity'=>$capacity,'waitlist_enabled'=>$waitlist,
            'registration_opens_at_utc'=>self::nullable_datetime($data['registration_opens_at_utc']??null),
            'registration_closes_at_utc'=>self::nullable_datetime($data['registration_closes_at_utc']??null),
            'starts_at_utc'=>self::nullable_datetime($data['starts_at_utc']??null),'ends_at_utc'=>self::nullable_datetime($data['ends_at_utc']??null),
            'attendance_enabled'=>$attendance,'current_form_version'=>0,'created_by'=>$actor_id,'created_at_utc'=>$now,'updated_at_utc'=>$now
        ],['%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%s']);
        if($id<=0){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('database','ایجاد برنامه انجام نشد.',['status'=>500]);}
        foreach($universities as $u){
            if(!QYS_Program_Repository::insert_program_university($id,$u)){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('database','ثبت دامنه دانشگاهی برنامه انجام نشد.',['status'=>500]);}
        }
        if(!self::audit('program_created',$id,0,0,$actor_id,'',$status)){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد برنامه انجام نشد.',['status'=>500]);}
        if($manage_transaction)QYS_Program_Repository::commit();return $id;
    }

    /** Phase E / E4 state-aware editing policy for primary Program metadata. */
    public static function edit_policy($program,bool $attendance_linked=false): array {
        $status=is_object($program)?sanitize_key((string)($program->status??'')):sanitize_key((string)$program);
        $terminal=in_array($status,['completed','cancelled','archived'],true);
        $text=!$terminal&&in_array($status,['draft','registration_open','registration_closed','confirmed','running'],true);
        $capacity=!$terminal&&in_array($status,['draft','registration_open','registration_closed','confirmed'],true);
        $audience=$status==='draft';
        $attendance=!$terminal&&!$attendance_linked&&in_array($status,['draft','registration_open','registration_closed','confirmed'],true);
        return [
            'status'=>$status,
            'editable'=>$text||$capacity||$audience||$attendance,
            'title'=>$text,
            'description'=>$text,
            'capacity'=>$capacity,
            'audience'=>$audience,
            'attendance'=>$attendance,
            'terminal'=>$terminal,
        ];
    }

    /**
     * Update primary Program metadata without bypassing state, capacity or tenant rules.
     * Audience scope becomes immutable after draft; attendance is immutable after linking.
     */
    public static function update_details(int $program_id,array $data,int $actor_id=0){
        $scope=QYS_Permission_Service::scope('programs');
        if($scope===null||!QYS_Program_Repository::program_manageable_in_scope($program_id,(string)$scope))return new WP_Error('forbidden','مجوز ویرایش مشخصات این برنامه را ندارید.',['status'=>403]);

        QYS_Program_Repository::begin();
        $p=QYS_Program_Repository::lock_program($program_id);
        if(!$p){QYS_Program_Repository::rollback();return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);}
        $attendance_link=QYS_Program_Repository::attendance_link($program_id);
        $policy=self::edit_policy($p,(bool)$attendance_link);
        if(empty($policy['editable'])){QYS_Program_Repository::rollback();return new WP_Error('program_terminal','مشخصات برنامه در وضعیت پایانی فقط خواندنی است.',['status'=>409]);}

        $updates=[];$formats=[];
        if(!empty($policy['title'])){
            $title=trim(sanitize_text_field((string)($data['title']??$p->title)));
            if($title===''||QYS_Text_Normalizer::chars($title)>190){QYS_Program_Repository::rollback();return new WP_Error('title','عنوان برنامه معتبر نیست.',['status'=>400]);}
            $updates['title']=$title;$formats[]='%s';
        }
        if(!empty($policy['description'])){
            $updates['description']=wp_kses_post((string)($data['description']??$p->description));$formats[]='%s';
        }
        if(!empty($policy['capacity'])){
            $capacity=max(0,(int)($data['capacity']??$p->capacity));
            $counts=QYS_Program_Repository::admission_counts($program_id);$used=(int)($counts['used']??0);
            if($capacity>0&&$capacity<$used){QYS_Program_Repository::rollback();return new WP_Error('capacity_floor','ظرفیت محدود نمی‌تواند از تعداد صندلی‌های رزروشده/فعال کمتر باشد.',['status'=>409,'used'=>$used]);}
            $updates['capacity']=$capacity;$formats[]='%d';
        }
        if(!empty($policy['audience'])){
            $updates['audience_roles']=self::clean_roles($data['audience_roles']??'all');$formats[]='%s';
        }
        if(!empty($policy['attendance'])){
            $attendance=!empty($data['attendance_enabled'])?1:0;
            $updates['attendance_enabled']=$attendance;$formats[]='%d';
        }elseif($attendance_link && array_key_exists('attendance_enabled',$data) && empty($data['attendance_enabled'])){
            QYS_Program_Repository::rollback();return new WP_Error('attendance_linked','پس از ایجاد اتصال حضور، حضور و غیاب برنامه قابل غیرفعال‌سازی نیست.',['status'=>409]);
        }

        $new_universities=null;
        if(!empty($policy['audience'])&&$scope==='*'){
            $new_universities=self::normalize_universities((array)($data['universities']??[]));
            if(!$new_universities){QYS_Program_Repository::rollback();return new WP_Error('university','دست‌کم یک دانشگاه مخاطب لازم است.',['status'=>400]);}
            $manager=sanitize_key((string)$p->manager_university);
            if($manager!==''&&!in_array($manager,$new_universities,true)){QYS_Program_Repository::rollback();return new WP_Error('manager_university','دانشگاه مدیر باید در دامنه برنامه باقی بماند.',['status'=>400]);}
        }

        $updates['updated_at_utc']=self::now();$formats[]='%s';
        if(QYS_Program_Repository::update_program_details($program_id,$updates,$formats)<0){
            QYS_Program_Repository::rollback();return new WP_Error('database','ویرایش مشخصات برنامه ذخیره نشد.',['status'=>500]);
        }
        if(is_array($new_universities)&&!QYS_Program_Repository::replace_program_universities($program_id,$new_universities)){
            QYS_Program_Repository::rollback();return new WP_Error('database','دامنه دانشگاهی برنامه ذخیره نشد.',['status'=>500]);
        }
        if(!self::audit('program_updated',$program_id,0,0,$actor_id,(string)$p->status,(string)$p->status)){
            QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد ویرایش برنامه انجام نشد.',['status'=>500]);
        }
        QYS_Program_Repository::commit();return true;
    }

    private static function nullable_datetime($value): ?string {
        if($value===null||$value==='')return null;
        $s=trim((string)$value);
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$s,new DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d H:i:s')===$s?$s:null;
    }

    public static function transition_program(int $program_id,string $to,int $actor_id=0){
        $p=QYS_Program_Repository::get_program($program_id);if(!$p)return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);
        $to=sanitize_key($to);$from=(string)$p->status;if(!self::can_program_transition($from,$to))return new WP_Error('transition','تغییر وضعیت برنامه مجاز نیست.',['status'=>409]);
        if($to==='registration_open'&&$p->registration_mode==='approval'&&(int)$p->current_form_version<=0)return new WP_Error('form','پیش از بازکردن ثبت‌نام، یک نسخه فرم را منتشر کنید.',['status'=>409]);
        $now=self::now();QYS_Program_Repository::begin();
        $n=QYS_Program_Repository::compare_and_set_program_status($program_id,$from,$to,$now);
        if($n!==1||!self::audit('program_status',$program_id,0,0,$actor_id,$from,$to)){QYS_Program_Repository::rollback();return new WP_Error('conflict','وضعیت برنامه هم‌زمان تغییر کرده است.',['status'=>409]);}
        if(in_array($to,['completed','cancelled','archived'],true)){
            $r=self::orchestrate_terminal_program_state($program_id,$to,$actor_id,false);
            if(is_wp_error($r)){QYS_Program_Repository::rollback();return $r;}
        }
        QYS_Program_Repository::commit();return true;
    }

    /**
     * Keep Application / Enrollment / Attendance state aligned with a terminal Program.
     * When called from transition_program(), it participates in the caller transaction.
     */
    public static function orchestrate_terminal_program_state(int $program_id,string $program_status,int $actor_id=0,bool $manage_transaction=true){
        $program_status=sanitize_key($program_status);
        if(!in_array($program_status,['completed','cancelled','archived'],true))return new WP_Error('program_state','وضعیت برنامه پایانی نیست.',['status'=>409]);
        $p=QYS_Program_Repository::get_program($program_id);
        if(!$p)return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);
        if((string)$p->status!==$program_status)return new WP_Error('program_state','وضعیت برنامه با عملیات پایانی تطابق ندارد.',['status'=>409]);

        if($manage_transaction)QYS_Program_Repository::begin();
        $now=self::now();
        // Undecided applications must not remain "under review" after the program ends.
        $app_target=$program_status==='cancelled'?'cancelled':'closed';
        $app_note=$program_status==='cancelled'?'برنامه لغو شده است.':'فرآیند این برنامه بسته شده است.';
        $open_apps=QYS_Program_Repository::lock_open_applications($program_id);
        foreach($open_apps as $a){
            $from=(string)$a->status;
            $u=QYS_Program_Repository::update_application_if_status((int)$a->id,$from,[
                'status'=>$app_target,'updated_at_utc'=>$now,'decided_by'=>$actor_id,'decided_at_utc'=>$now,'decision_note'=>$app_note
            ],['%s','%s','%d','%s','%s']);
            if($u!==1||!self::audit('application_status',$program_id,(int)$a->id,0,$actor_id,$from,$app_target)){
                if($manage_transaction)QYS_Program_Repository::rollback();
                return new WP_Error('database','بستن درخواست‌های باز برنامه انجام نشد.',['status'=>500]);
            }
        }

        // Active enrollments become an explicit terminal state.
        $active=QYS_Program_Repository::lock_active_enrollments($program_id);
        foreach($active as $e){
            $from=(string)$e->status;$data=['updated_at_utc'=>$now];$formats=['%s'];
            if($program_status==='completed'){
                if($from==='participating'){$to='completed';$data['status']=$to;$data['completed_at_utc']=$now;$formats=['%s','%s','%s'];}
                else {$to='no_show';$data['status']=$to;$formats=['%s','%s'];}
            }elseif($program_status==='cancelled'){
                $to='cancelled';$data['status']=$to;$formats=['%s','%s'];
            }else{
                // "archived" cannot reliably tell whether an old record came from completion or cancellation.
                $to='closed';$data['status']=$to;$formats=['%s','%s'];
            }
            $u=QYS_Program_Repository::update_enrollment_if_status((int)$e->id,$from,$data,$formats);
            if($u!==1||!self::audit('enrollment_status',$program_id,(int)$e->application_id,(int)$e->id,$actor_id,$from,$to)){
                if($manage_transaction)QYS_Program_Repository::rollback();
                return new WP_Error('database','بستن وضعیت شرکت‌کنندگان انجام نشد.',['status'=>500]);
            }
        }

        $attendance_link=QYS_Program_Repository::attendance_link($program_id);
        if($attendance_link){
            if(!QYS_Program_Attendance_Boundary::registered() || !QYS_Program_Attendance_Boundary::terminalize_core_program($program_id)){
                if($manage_transaction)QYS_Program_Repository::rollback();
                return new WP_Error('attendance','بستن حضور و غیاب متصل به برنامه انجام نشد.',['status'=>500]);
            }
        }

        if($manage_transaction)QYS_Program_Repository::commit();
        return true;
    }

    public static function ensure_draft_form(int $program_id,int $actor_id=0){
        $p=QYS_Program_Repository::get_program($program_id);if(!$p)return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);
        $existing=QYS_Program_Repository::latest_form_by_status($program_id,'draft');
        if($existing)return (int)$existing->id;
        $next=QYS_Program_Repository::next_form_version($program_id);
        $now=self::now();QYS_Program_Repository::begin();
        $id=QYS_Program_Repository::insert_form_version(['program_id'=>$program_id,'version'=>$next,'status'=>'draft','created_by'=>$actor_id,'created_at_utc'=>$now,'published_at_utc'=>null]);
        if($id<=0){QYS_Program_Repository::rollback();return new WP_Error('database','ساخت نسخه فرم انجام نشد.',['status'=>500]);}
        $published=QYS_Program_Repository::latest_form_by_status($program_id,'published');
        if($published){
            foreach(QYS_Program_Repository::form_fields((int)$published->id) as $field){
                $copied=QYS_Program_Repository::insert_form_field([
                    'form_version_id'=>$id,'field_key'=>(string)$field->field_key,'label'=>(string)$field->label,'field_type'=>(string)$field->field_type,
                    'required'=>(int)$field->required,'help_text'=>(string)$field->help_text,'options_json'=>(string)$field->options_json,
                    'sort_order'=>(int)$field->sort_order,'active'=>(int)$field->active
                ]);
                if($copied<=0){QYS_Program_Repository::rollback();return new WP_Error('database','کپی نسخه قبلی فرم انجام نشد.',['status'=>500]);}
            }
        }
        if(!self::audit('form_draft_created',$program_id,0,0,$actor_id,'',(string)$next)){QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد فرم انجام نشد.',['status'=>500]);}
        QYS_Program_Repository::commit();return $id;
    }

    public static function add_field(int $form_version_id,array $data){
        $version=QYS_Program_Repository::get_form_version($form_version_id);
        if(!$version||$version->status!=='draft')return new WP_Error('form_locked','فقط نسخه پیش‌نویس فرم قابل ویرایش است.',['status'=>409]);
        $key=sanitize_key((string)($data['field_key']??''));$label=trim(sanitize_text_field((string)($data['label']??'')));$type=sanitize_key((string)($data['field_type']??''));
        if($key===''||strlen($key)>64||$label===''||QYS_Text_Normalizer::chars($label)>190||!in_array($type,self::FIELD_TYPES,true))return new WP_Error('field','تعریف فیلد معتبر نیست.',['status'=>400]);
        $options=[];foreach((array)($data['options']??[]) as $option){$option=trim(sanitize_text_field((string)$option));if($option!==''&&QYS_Text_Normalizer::chars($option)<=190)$options[$option]=true;}
        if(in_array($type,['single_choice','multi_choice'],true)&&!$options)return new WP_Error('options','برای فیلد انتخابی گزینه لازم است.',['status'=>400]);
        if($type==='notice')$data['required']=false;
        $id=QYS_Program_Repository::insert_form_field([
            'form_version_id'=>$form_version_id,'field_key'=>$key,'label'=>$label,'field_type'=>$type,'required'=>!empty($data['required'])?1:0,
            'help_text'=>substr(sanitize_text_field((string)($data['help_text']??'')),0,500),'options_json'=>wp_json_encode(array_keys($options),JSON_UNESCAPED_UNICODE),
            'sort_order'=>max(0,(int)($data['sort_order']??0)),'active'=>1
        ]);
        return $id>0?$id:new WP_Error('database','افزودن فیلد انجام نشد.',['status'=>500]);
    }


    /**
     * Turn a visible published field into its editable counterpart in a draft copy.
     * Published/retired versions are never mutated in place, so existing answers
     * remain bound to the exact version that users originally completed.
     */
    public static function prepare_field_for_edit(int $field_id,int $program_id,int $actor_id=0){
        $source=QYS_Program_Repository::get_field_context($field_id);
        if(!$source||(int)$source->program_id!==$program_id)return new WP_Error('field','سؤال موردنظر در این برنامه پیدا نشد.',['status'=>404]);
        if((string)$source->form_status==='draft')return (int)$source->id;
        if((string)$source->form_status!=='published')return new WP_Error('form_locked','این نسخه فرم قابل ویرایش نیست.',['status'=>409]);
        $program=QYS_Program_Repository::get_program($program_id);
        if(!$program||(int)$program->current_form_version!==(int)$source->form_version)return new WP_Error('form_locked','فقط نسخه فعال فرم قابل تبدیل به پیش‌نویس و ویرایش است.',['status'=>409]);
        $draft=self::ensure_draft_form($program_id,$actor_id);if(is_wp_error($draft))return $draft;
        $copy=QYS_Program_Repository::field_by_key((int)$draft,(string)$source->field_key);
        return $copy?(int)$copy->id:new WP_Error('field_copy','نسخه قابل ویرایش سؤال ساخته نشد.',['status'=>500]);
    }

    /** Update one field only inside a draft form version. Stable field_key is preserved. */
    public static function update_draft_field(int $field_id,int $program_id,array $data,int $actor_id=0){
        $field=QYS_Program_Repository::get_field_context($field_id);
        if(!$field||(int)$field->program_id!==$program_id)return new WP_Error('field','سؤال موردنظر در این برنامه پیدا نشد.',['status'=>404]);
        if((string)$field->form_status!=='draft')return new WP_Error('form_locked','فقط نسخه پیش‌نویس فرم قابل ویرایش است.',['status'=>409]);
        $label=trim(sanitize_text_field((string)($data['label']??'')));$type=sanitize_key((string)($data['field_type']??''));
        if($label===''||QYS_Text_Normalizer::chars($label)>190||!in_array($type,self::FIELD_TYPES,true))return new WP_Error('field','تعریف سؤال معتبر نیست.',['status'=>400]);
        $options=[];foreach((array)($data['options']??[]) as $option){$option=trim(sanitize_text_field((string)$option));if($option!==''&&QYS_Text_Normalizer::chars($option)<=190)$options[$option]=true;}
        if(in_array($type,['single_choice','multi_choice'],true)&&!$options)return new WP_Error('options','برای سؤال انتخابی دست‌کم یک گزینه لازم است.',['status'=>400]);
        $required=$type==='notice'?0:(!empty($data['required'])?1:0);
        $payload=[
            'label'=>$label,'field_type'=>$type,'required'=>$required,
            'help_text'=>substr(sanitize_text_field((string)($data['help_text']??'')),0,500),
            'options_json'=>wp_json_encode(array_keys($options),JSON_UNESCAPED_UNICODE),
            'sort_order'=>max(0,(int)($data['sort_order']??0)),'active'=>1
        ];
        QYS_Program_Repository::begin();
        $n=QYS_Program_Repository::update_field($field_id,(int)$field->form_version_id,$payload,['%s','%s','%d','%s','%s','%d','%d']);
        if($n===false||!self::audit('form_field_updated',$program_id,0,0,$actor_id,'','')){QYS_Program_Repository::rollback();return new WP_Error('database','ویرایش سؤال ذخیره نشد.',['status'=>500]);}
        QYS_Program_Repository::commit();return true;
    }


    public static function remove_draft_field(int $field_id,int $program_id=0): bool {
        if($field_id<=0)return false;$field=QYS_Program_Repository::removable_field_context($field_id);
        if(!$field||$field->status!=='draft'||($program_id>0&&(int)$field->program_id!==$program_id))return false;
        return QYS_Program_Repository::delete_field($field_id,(int)$field->form_version_id);
    }

    public static function publish_form(int $form_version_id,int $actor_id=0){
        $v=QYS_Program_Repository::get_form_version($form_version_id);
        if(!$v||$v->status!=='draft')return new WP_Error('form_locked','نسخه فرم قابل انتشار نیست.',['status'=>409]);
        $program_id=(int)$v->program_id;$now=self::now();QYS_Program_Repository::begin();
        QYS_Program_Repository::retire_published_forms($program_id);
        $n=QYS_Program_Repository::publish_form_if_draft($form_version_id,$now);
        $m=QYS_Program_Repository::set_program_current_form_version($program_id,(int)$v->version,$now);
        if($n!==1||$m===false||!self::audit('form_published',$program_id,0,0,$actor_id,'',(string)$v->version)){QYS_Program_Repository::rollback();return new WP_Error('database','انتشار فرم انجام نشد.',['status'=>500]);}
        QYS_Program_Repository::commit();return true;
    }

    public static function submit_application(int $program_id,int $member_id,array $answers=[]){
        $p=QYS_Program_Repository::get_program($program_id);if(!$p||$p->status!=='registration_open')return new WP_Error('registration_closed','ثبت‌نام این برنامه باز نیست.',['status'=>409]);
        if($p->registration_mode==='none')return new WP_Error('registration_disabled','این برنامه ثبت‌نام ندارد.',['status'=>409]);
        $m=QYS_Member_Repository::find_by_id($member_id);if(!$m||!QYS_Messenger::profile_complete($m))return new WP_Error('profile_incomplete','برای ثبت‌نام در برنامه‌ها ابتدا پرونده قرآن‌یار خود را تکمیل کنید.',['status'=>403,'action'=>'complete_profile']);if((int)$m->verified!==1)return new WP_Error('member','پس از تکمیل پرونده، تأیید هویت مدیر دانشگاه برای ثبت‌نام لازم است.',['status'=>403,'action'=>'profile_status']);
        $eligible=self::member_eligibility($program_id,$member_id);if(is_wp_error($eligible))return new WP_Error($eligible->get_error_code(),$eligible->get_error_message(),['status'=>403]);
        if($p->registration_opens_at_utc&&strtotime($p->registration_opens_at_utc.' UTC')>time())return new WP_Error('not_open','زمان ثبت‌نام هنوز آغاز نشده است.',['status'=>409]);
        if($p->registration_closes_at_utc&&strtotime($p->registration_closes_at_utc.' UTC')<time())return new WP_Error('closed','مهلت ثبت‌نام پایان یافته است.',['status'=>409]);
        $admission=self::admission_snapshot_for_program($p);
        if(!empty($admission['full'])&&empty($admission['waitlist_enabled']))return self::capacity_error($admission);
        if(QYS_Program_Repository::application_exists_for_member($program_id,$member_id)){
            $existing=QYS_Program_Repository::application_for_member($program_id,$member_id);
            $existing_enrollment=QYS_Program_Repository::enrollment_for_member($program_id,$member_id);
            $reapply=self::member_reapply_state_from_statuses((string)$p->status,(string)($existing->status??''),(string)($existing_enrollment->status??''),(bool)$existing_enrollment);
            if(!empty($reapply['allowed']))return new WP_Error('reapply_required','برای ثبت‌نام دوباره از گزینه «ثبت‌نام مجدد» استفاده کنید.',['status'=>409,'action'=>'reapply']);
            return new WP_Error('duplicate','برای این برنامه قبلاً درخواست ثبت شده است.',['status'=>409]);
        }
        $form=QYS_Program_Repository::current_form($program_id);$form_id=$form?(int)$form->id:0;$normalized=[];
        foreach(QYS_Program_Repository::form_fields($form_id) as $field){
            if($field->field_type==='notice')continue;
            $value=array_key_exists($field->field_key,$answers)?$answers[$field->field_key]:null;
            $clean=self::validate_answer($field,$value);if(is_wp_error($clean))return $clean;
            if($clean!==null)$normalized[(int)$field->id]=$clean;
        }
        $now=self::now();QYS_Program_Repository::begin();
        $application_id=QYS_Program_Repository::insert_application(['program_id'=>$program_id,'member_id'=>$member_id,'university'=>(string)$m->university,'form_version_id'=>$form_id,'status'=>'submitted','submitted_at_utc'=>$now,'updated_at_utc'=>$now,'decided_by'=>0,'decided_at_utc'=>null,'decision_note'=>'']);
        if($application_id<=0){QYS_Program_Repository::rollback();return new WP_Error('database','ثبت درخواست انجام نشد.',['status'=>500]);}
        foreach($normalized as $field_id=>$value){if(!QYS_Program_Repository::insert_answer($application_id,(int)$field_id,(string)$value,$now)){QYS_Program_Repository::rollback();return new WP_Error('database','ثبت پاسخ‌های فرم انجام نشد.',['status'=>500]);}}
        if(!self::audit('application_submitted',$program_id,$application_id,0,$member_id,'','submitted')){QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد درخواست انجام نشد.',['status'=>500]);}
        QYS_Program_Repository::commit();return $application_id;
    }

    private static function validation_error(string $code,string $message,$field): WP_Error {
        return new WP_Error($code,$message,[
            'status'=>400,
            'field_key'=>(string)($field->field_key??''),
            'field_type'=>(string)($field->field_type??''),
        ]);
    }

    private static function validate_answer($field,$value){
        $type=(string)$field->field_type;$required=(int)$field->required===1;
        $label=(string)$field->label;
        if($value===null||$value===''||$value===[]){if($required)return self::validation_error('required','تکمیل «'.$label.'» الزامی است.',$field);return null;}
        if($type==='short_text'||$type==='long_text'){$v=trim(sanitize_textarea_field((string)$value));$limit=$type==='short_text'?500:5000;if($v===''&&$required)return self::validation_error('required','تکمیل «'.$label.'» الزامی است.',$field);if(QYS_Text_Normalizer::chars($v)>$limit)return self::validation_error('length','پاسخ «'.$label.'» بیش از حد طولانی است.',$field);return $v;}
        if($type==='number'){if(!is_numeric(QYS_Text_Normalizer::digits((string)$value)))return self::validation_error('number','مقدار «'.$label.'» باید عدد باشد.',$field);return (string)(0+(float)QYS_Text_Normalizer::digits((string)$value));}
        if($type==='date'){$v=trim(QYS_Text_Normalizer::digits((string)$value));if(!preg_match('/\A\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}\z/D',$v))return self::validation_error('date','تاریخ «'.$label.'» معتبر نیست.',$field);return $v;}
        if($type==='yes_no'){$v=is_bool($value)?($value?'yes':'no'):sanitize_key((string)$value);if(!in_array($v,['yes','no','1','0'],true))return self::validation_error('choice','پاسخ «'.$label.'» باید بله یا خیر باشد.',$field);return in_array($v,['yes','1'],true)?'yes':'no';}
        $allowed=json_decode((string)$field->options_json,true);if(!is_array($allowed))$allowed=[];
        if($type==='single_choice'){$v=sanitize_text_field((string)$value);if(!in_array($v,$allowed,true))return self::validation_error('choice','گزینه انتخاب‌شده برای «'.$label.'» معتبر نیست.',$field);return $v;}
        if($type==='multi_choice'){$values=is_array($value)?$value:[$value];if($required&&!$values)return self::validation_error('required','حداقل یک گزینه برای «'.$label.'» انتخاب کنید.',$field);$out=[];foreach($values as $v){$v=sanitize_text_field((string)$v);if($v==='')continue;if(!in_array($v,$allowed,true))return self::validation_error('choice','یکی از گزینه‌های «'.$label.'» معتبر نیست.',$field);$out[$v]=true;}if($required&&!$out)return self::validation_error('required','حداقل یک گزینه برای «'.$label.'» انتخاب کنید.',$field);return $out?wp_json_encode(array_keys($out),JSON_UNESCAPED_UNICODE):null;}
        return null;
    }

    /** RC53: a withdrawn member may submit the same Program relationship for review again. */
    public static function member_reapply_state_from_statuses(string $program_status,string $application_status='',string $enrollment_status='',bool $has_enrollment=false): array {
        if($program_status!=='registration_open')return ['allowed'=>false,'reason'=>'registration_closed'];
        if($has_enrollment){
            if($enrollment_status==='withdrawn'&&in_array($application_status,['accepted','withdrawn'],true))return ['allowed'=>true,'reason'=>'withdrawn_enrollment'];
            return ['allowed'=>false,'reason'=>'enrollment_not_withdrawn'];
        }
        if($application_status==='withdrawn')return ['allowed'=>true,'reason'=>'withdrawn_application'];
        return ['allowed'=>false,'reason'=>'not_withdrawn'];
    }

    public static function member_reapply_state(int $program_id,int $member_id): array {
        if($program_id<=0||$member_id<=0)return ['allowed'=>false,'reason'=>'invalid'];
        $p=QYS_Program_Repository::get_program($program_id);if(!$p)return ['allowed'=>false,'reason'=>'invalid'];
        $a=QYS_Program_Repository::application_for_member($program_id,$member_id);
        $e=QYS_Program_Repository::enrollment_for_member($program_id,$member_id);
        return self::member_reapply_state_from_statuses((string)$p->status,(string)($a->status??''),(string)($e->status??''),(bool)$e);
    }

    /**
     * RC53 self-service re-application. The existing application/enrollment rows
     * are retained so audit/attendance history is not deleted. A renewed request
     * always returns to submitted and must pass the normal review/capacity flow.
     */
    public static function reapply_member(int $program_id,int $member_id,int $actor_id=0){
        if($program_id<=0||$member_id<=0)return new WP_Error('invalid','برنامه یا عضو معتبر نیست.',['status'=>400]);
        QYS_Program_Repository::begin();
        $p=QYS_Program_Repository::lock_program($program_id);
        if(!$p){QYS_Program_Repository::rollback();return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);}
        if((string)$p->status!=='registration_open'){QYS_Program_Repository::rollback();return new WP_Error('registration_closed','ثبت‌نام مجدد فقط زمانی ممکن است که ثبت‌نام برنامه باز باشد.',['status'=>409]);}
        if($p->registration_opens_at_utc&&strtotime($p->registration_opens_at_utc.' UTC')>time()){QYS_Program_Repository::rollback();return new WP_Error('not_open','زمان ثبت‌نام هنوز آغاز نشده است.',['status'=>409]);}
        if($p->registration_closes_at_utc&&strtotime($p->registration_closes_at_utc.' UTC')<time()){QYS_Program_Repository::rollback();return new WP_Error('closed','مهلت ثبت‌نام پایان یافته است.',['status'=>409]);}
        $a=QYS_Program_Repository::lock_application_for_member($program_id,$member_id);
        $e=QYS_Program_Repository::lock_enrollment_for_member($program_id,$member_id);
        $state=self::member_reapply_state_from_statuses((string)$p->status,(string)($a->status??''),(string)($e->status??''),(bool)$e);
        if(empty($state['allowed'])||!$a){QYS_Program_Repository::rollback();return new WP_Error('not_reapplicable','برای این برنامه انصراف قابل بازگشتی پیدا نشد.',['status'=>409]);}
        $eligible=self::member_eligibility($program_id,$member_id,(string)$a->university);if(is_wp_error($eligible)){QYS_Program_Repository::rollback();return $eligible;}
        $admission=self::admission_snapshot_for_program($p);
        if(!empty($admission['full'])&&empty($admission['waitlist_enabled'])){QYS_Program_Repository::rollback();return self::capacity_error($admission);}
        $from=(string)$a->status;
        if(!in_array($from,['withdrawn','accepted'],true)){QYS_Program_Repository::rollback();return new WP_Error('not_reapplicable','وضعیت درخواست برای ثبت‌نام مجدد مناسب نیست.',['status'=>409]);}
        $now=self::now();
        $n=QYS_Program_Repository::update_application_if_status((int)$a->id,$from,[
            'status'=>'submitted','updated_at_utc'=>$now,'decided_by'=>0,'decided_at_utc'=>null,'decision_note'=>''
        ],['%s','%s','%d','%s','%s']);
        if($n!==1||!self::audit('member_reapplied',$program_id,(int)$a->id,(int)($e->id??0),$actor_id,$from,'submitted')){
            QYS_Program_Repository::rollback();return new WP_Error('conflict','ثبت‌نام مجدد هم‌زمان با تغییر دیگری انجام نشد. دوباره تلاش کنید.',['status'=>409]);
        }
        QYS_Program_Repository::commit();
        return ['application_id'=>(int)$a->id,'from'=>$from,'to'=>'submitted','program_id'=>$program_id,'previous_enrollment'=>(int)($e->id??0)];
    }

    /** Phase E / E5: state-aware self-service withdrawal without deleting history. */
    public static function member_withdraw_state_from_statuses(string $program_status,string $application_status='',string $enrollment_status='',bool $has_enrollment=false): array {
        if(in_array($program_status,['completed','cancelled','archived'],true))return ['allowed'=>false,'kind'=>'','reason'=>'program_terminal'];
        if($has_enrollment&&in_array($enrollment_status,['enrolled','participating'],true))return ['allowed'=>true,'kind'=>'enrollment','reason'=>'active_enrollment'];
        // A terminal enrollment row may coexist with a newly re-submitted
        // application. In that case the renewed application remains withdrawable.
        if(in_array($application_status,['submitted','under_review','waitlisted','accepted'],true))return ['allowed'=>true,'kind'=>'application','reason'=>'active_application'];
        if($has_enrollment)return ['allowed'=>false,'kind'=>'','reason'=>'enrollment_terminal'];
        return ['allowed'=>false,'kind'=>'','reason'=>'no_active_record'];
    }

    public static function member_withdraw_state(int $program_id,int $member_id): array {
        if($program_id<=0||$member_id<=0)return ['allowed'=>false,'kind'=>'','reason'=>'invalid'];
        $p=QYS_Program_Repository::get_program($program_id);
        if(!$p)return ['allowed'=>false,'kind'=>'','reason'=>'invalid'];
        $e=QYS_Program_Repository::enrollment_for_member($program_id,$member_id);
        $a=QYS_Program_Repository::application_for_member($program_id,$member_id);
        return self::member_withdraw_state_from_statuses((string)$p->status,(string)($a->status??''),(string)($e->status??''),(bool)$e);
    }

    public static function withdraw_member(int $program_id,int $member_id,int $actor_id=0){
        if($program_id<=0||$member_id<=0)return new WP_Error('invalid','برنامه یا عضو معتبر نیست.',['status'=>400]);
        QYS_Program_Repository::begin();
        $p=QYS_Program_Repository::lock_program($program_id);
        if(!$p){QYS_Program_Repository::rollback();return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);}
        if(in_array((string)$p->status,['completed','cancelled','archived'],true)){QYS_Program_Repository::rollback();return new WP_Error('program_terminal','پس از پایان، لغو یا بایگانی برنامه امکان انصراف جدید وجود ندارد.',['status'=>409]);}

        $e=QYS_Program_Repository::lock_enrollment_for_member($program_id,$member_id);
        if($e&&in_array((string)$e->status,['enrolled','participating'],true)){
            $from=(string)$e->status;
            $now=self::now();$data=['status'=>'withdrawn','updated_at_utc'=>$now,'withdrawn_at_utc'=>$now];
            $n=QYS_Program_Repository::update_enrollment_if_status((int)$e->id,$from,$data,['%s','%s','%s']);
            if($n!==1||!self::audit('member_withdrawal',$program_id,(int)$e->application_id,(int)$e->id,$actor_id,$from,'withdrawn')){QYS_Program_Repository::rollback();return new WP_Error('conflict','ثبت انصراف هم‌زمان با تغییر دیگری انجام نشد. دوباره تلاش کنید.',['status'=>409]);}
            QYS_Program_Repository::commit();
            return ['kind'=>'enrollment','from'=>$from,'to'=>'withdrawn','program_id'=>$program_id];
        }

        $a=QYS_Program_Repository::lock_application_for_member($program_id,$member_id);
        if(!$a){QYS_Program_Repository::rollback();return new WP_Error('not_found','درخواست فعالی برای انصراف پیدا نشد.',['status'=>404]);}
        $from=(string)$a->status;
        if(!in_array($from,['submitted','under_review','waitlisted','accepted'],true)){QYS_Program_Repository::rollback();return new WP_Error('not_withdrawable','این درخواست دیگر در وضعیت قابل انصراف نیست.',['status'=>409]);}
        $now=self::now();$n=QYS_Program_Repository::update_application_if_status((int)$a->id,$from,['status'=>'withdrawn','updated_at_utc'=>$now],['%s','%s']);
        if($n!==1||!self::audit('member_withdrawal',$program_id,(int)$a->id,0,$actor_id,$from,'withdrawn')){QYS_Program_Repository::rollback();return new WP_Error('conflict','ثبت انصراف هم‌زمان با تغییر دیگری انجام نشد. دوباره تلاش کنید.',['status'=>409]);}
        QYS_Program_Repository::commit();
        return ['kind'=>'application','from'=>$from,'to'=>'withdrawn','program_id'=>$program_id];
    }

    public static function transition_application(int $application_id,string $to,int $actor_id=0,string $note=''){
        $seed=QYS_Program_Repository::get_application($application_id);if(!$seed)return new WP_Error('not_found','درخواست پیدا نشد.',['status'=>404]);
        $to=sanitize_key($to);QYS_Program_Repository::begin();
        $p=QYS_Program_Repository::lock_program((int)$seed->program_id);
        if(!$p){QYS_Program_Repository::rollback();return new WP_Error('not_found','برنامه پیدا نشد.',['status'=>404]);}
        if(in_array((string)$p->status,['completed','cancelled','archived'],true)){QYS_Program_Repository::rollback();return new WP_Error('program_terminal','پس از پایان، لغو یا بایگانی برنامه، وضعیت درخواست قابل تغییر نیست.',['status'=>409]);}
        $a=QYS_Program_Repository::lock_application($application_id);
        if(!$a|| (int)$a->program_id!==(int)$p->id){QYS_Program_Repository::rollback();return new WP_Error('not_found','درخواست پیدا نشد.',['status'=>404]);}
        $from=(string)$a->status;if(!self::can_application_transition($from,$to)){QYS_Program_Repository::rollback();return new WP_Error('transition','تغییر وضعیت درخواست مجاز نیست.',['status'=>409]);}
        if($to==='waitlisted'&&(int)$p->waitlist_enabled!==1){QYS_Program_Repository::rollback();return new WP_Error('waitlist_disabled','فهرست انتظار برای این برنامه فعال نیست.',['status'=>409]);}
        if($to==='accepted'){
            $eligible=self::member_eligibility((int)$a->program_id,(int)$a->member_id,(string)$a->university);if(is_wp_error($eligible)){QYS_Program_Repository::rollback();return $eligible;}
            $snapshot=self::admission_snapshot_for_program($p);if(!empty($snapshot['full'])){QYS_Program_Repository::rollback();return self::capacity_error($snapshot);}
        }
        if($to==='withdrawn'){
            $enrollment_status=QYS_Program_Repository::enrollment_status_for_member((int)$a->program_id,(int)$a->member_id);
            if(in_array($enrollment_status,['enrolled','participating'],true)){QYS_Program_Repository::rollback();return new WP_Error('enrollment_exists','برای فرد ثبت‌نام قطعی وجود دارد؛ انصراف باید از وضعیت شرکت ثبت شود.',['status'=>409]);}
        }
        $now=self::now();$decision=in_array($to,['accepted','rejected','waitlisted'],true);$data=['status'=>$to,'updated_at_utc'=>$now];$formats=['%s','%s'];
        if($decision){$data['decided_by']=$actor_id;$data['decided_at_utc']=$now;$data['decision_note']=substr(sanitize_textarea_field($note),0,500);$formats=array_merge($formats,['%d','%s','%s']);}
        $n=QYS_Program_Repository::update_application_if_status($application_id,$from,$data,$formats);
        if($n!==1||!self::audit('application_status',(int)$a->program_id,$application_id,0,$actor_id,$from,$to)){QYS_Program_Repository::rollback();return new WP_Error('conflict','وضعیت درخواست هم‌زمان تغییر کرده است.',['status'=>409]);}
        QYS_Program_Repository::commit();return true;
    }

    public static function enroll_application(int $application_id,int $actor_id=0){
        $seed=QYS_Program_Repository::get_application($application_id);if(!$seed)return new WP_Error('application','درخواست پذیرفته‌شده لازم است.',['status'=>409]);
        QYS_Program_Repository::begin();
        $p=QYS_Program_Repository::lock_program((int)$seed->program_id);
        if(!$p||in_array((string)($p->status??''),['cancelled','archived','completed'],true)){QYS_Program_Repository::rollback();return new WP_Error('program','امکان ثبت‌نام قطعی در وضعیت فعلی برنامه وجود ندارد.',['status'=>409]);}
        $a=QYS_Program_Repository::lock_application($application_id);
        if(!$a||$a->status!=='accepted'||(int)$a->program_id!==(int)$p->id){QYS_Program_Repository::rollback();return new WP_Error('application','درخواست پذیرفته‌شده لازم است.',['status'=>409]);}
        $eligible=self::member_eligibility((int)$a->program_id,(int)$a->member_id,(string)$a->university);if(is_wp_error($eligible)){QYS_Program_Repository::rollback();return $eligible;}
        // Acceptance itself is the seat reservation. Enrollment converts that reservation
        // into an active enrollment and therefore must not consume a second seat.
        $existing=QYS_Program_Repository::lock_enrollment_for_member((int)$a->program_id,(int)$a->member_id);
        $now=self::now();
        if($existing){
            if((string)$existing->status!=='withdrawn'){QYS_Program_Repository::rollback();return new WP_Error('duplicate','ثبت‌نام قطعی قبلاً برای این فرد ثبت شده است.',['status'=>409]);}
            $eid=(int)$existing->id;
            $n=QYS_Program_Repository::reactivate_withdrawn_enrollment($eid,$application_id,(string)$a->university,$actor_id,$now);
            if($n!==1||!self::audit('enrollment_reactivated',(int)$a->program_id,$application_id,$eid,$actor_id,'withdrawn','enrolled')){QYS_Program_Repository::rollback();return new WP_Error('conflict','فعال‌سازی دوباره ثبت‌نام انجام نشد. دوباره تلاش کنید.',['status'=>409]);}
        }else{
            $eid=QYS_Program_Repository::insert_enrollment(['program_id'=>(int)$a->program_id,'member_id'=>(int)$a->member_id,'university'=>(string)$a->university,'application_id'=>$application_id,'status'=>'enrolled','created_by'=>$actor_id,'enrolled_at_utc'=>$now,'participation_started_at_utc'=>null,'completed_at_utc'=>null,'withdrawn_at_utc'=>null,'updated_at_utc'=>$now]);
            if($eid<=0){QYS_Program_Repository::rollback();return new WP_Error('duplicate','ثبت‌نام قطعی قبلاً انجام شده یا ذخیره آن ممکن نیست.',['status'=>409]);}
            if(!self::audit('enrollment_created',(int)$a->program_id,$application_id,$eid,$actor_id,'','enrolled')){QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد ثبت‌نام انجام نشد.',['status'=>500]);}
        }
        QYS_Program_Repository::commit();return $eid;
    }

    public static function transition_enrollment(int $enrollment_id,string $to,int $actor_id=0){
        $e=QYS_Program_Repository::get_enrollment($enrollment_id);if(!$e)return new WP_Error('not_found','ثبت‌نام قطعی پیدا نشد.',['status'=>404]);
        $p=QYS_Program_Repository::get_program((int)$e->program_id);if(!$p||in_array((string)$p->status,['completed','cancelled','archived'],true))return new WP_Error('program_terminal','پس از پایان، لغو یا بایگانی برنامه، وضعیت شرکت قابل تغییر نیست.',['status'=>409]);
        $from=(string)$e->status;$to=sanitize_key($to);if(!self::can_enrollment_transition($from,$to))return new WP_Error('transition','تغییر وضعیت شرکت مجاز نیست.',['status'=>409]);
        $now=self::now();$data=['status'=>$to,'updated_at_utc'=>$now];$formats=['%s','%s'];
        if($to==='participating'&&empty($e->participation_started_at_utc)){$data['participation_started_at_utc']=$now;$formats[]='%s';}
        if($to==='completed'){$data['completed_at_utc']=$now;$formats[]='%s';}
        if($to==='withdrawn'){$data['withdrawn_at_utc']=$now;$formats[]='%s';}
        QYS_Program_Repository::begin();$n=QYS_Program_Repository::update_enrollment_if_status($enrollment_id,$from,$data,$formats);
        if($n!==1||!self::audit('enrollment_status',(int)$e->program_id,(int)$e->application_id,$enrollment_id,$actor_id,$from,$to)){QYS_Program_Repository::rollback();return new WP_Error('conflict','وضعیت شرکت هم‌زمان تغییر کرده است.',['status'=>409]);}
        QYS_Program_Repository::commit();return true;
    }

    public static function link_attendance(int $program_id,int $attendance_program_id,string $university,int $actor_id=0,bool $manage_transaction=true){
        $p=QYS_Program_Repository::get_program($program_id);if(!$p)return new WP_Error('program','برنامه اصلی پیدا نشد.',['status'=>404]);
        $university=sanitize_key($university);if(!in_array($university,QYS_Program_Repository::program_universities($program_id),true))return new WP_Error('scope','دانشگاه حضور خارج از دامنه برنامه است.',['status'=>409]);
        if($manage_transaction)QYS_Program_Repository::begin();
        $ok=QYS_Program_Repository::insert_attendance_link(['program_id'=>$program_id,'attendance_program_id'=>$attendance_program_id,'university'=>$university,'created_at_utc'=>self::now(),'created_by'=>$actor_id]);
        if(!$ok){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('duplicate','این برنامه حضور قبلاً متصل شده است.',['status'=>409]);}
        if(!self::audit('attendance_linked',$program_id,0,0,$actor_id,'',(string)$attendance_program_id)){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد اتصال حضور انجام نشد.',['status'=>500]);}
        if($manage_transaction)QYS_Program_Repository::commit();return true;
    }

    public static function member_can_attend(int $program_id,int $member_id): bool {
        $p=QYS_Program_Repository::get_program($program_id);if(!$p||!in_array((string)$p->status,['confirmed','running'],true))return false;
        if($p->registration_mode==='none')return !is_wp_error(self::member_eligibility($program_id,$member_id));
        $e=QYS_Program_Repository::enrollment_for_member($program_id,$member_id);
        if(!$e||!in_array((string)$e->status,['enrolled','participating'],true))return false;
        return !is_wp_error(self::member_eligibility($program_id,$member_id,(string)$e->university));
    }

    /** Attendance is the boundary where a real participation record begins. */
    public static function record_attendance_participation(int $program_id,int $member_id,int $actor_id=0,bool $manage_transaction=true){
        $p=QYS_Program_Repository::get_program($program_id);if(!$p)return new WP_Error('program','برنامه اصلی پیدا نشد.',['status'=>404]);
        if(!self::member_can_attend($program_id,$member_id))return new WP_Error('not_enrolled','ثبت‌نام قطعی این برنامه برای شما وجود ندارد.',['status'=>403]);
        if($manage_transaction)QYS_Program_Repository::begin();
        if($p->status==='confirmed'){
            $n=QYS_Program_Repository::compare_and_set_program_status($program_id,'confirmed','running',self::now());
            if($n!==1||!self::audit('program_status',$program_id,0,0,$actor_id,'confirmed','running')){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('conflict','شروع برنامه ثبت نشد.',['status'=>409]);}
            $p->status='running';
        }
        $e=QYS_Program_Repository::lock_enrollment_for_member($program_id,$member_id);
        $now=self::now();
        if(!$e){
            if($p->registration_mode!=='none'){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('not_enrolled','ثبت‌نام قطعی این برنامه برای شما وجود ندارد.',['status'=>403]);}
            $member=QYS_Member_Repository::find_by_id($member_id);
            $eid=QYS_Program_Repository::insert_enrollment([
                'program_id'=>$program_id,'member_id'=>$member_id,'university'=>(string)($member->university??''),'application_id'=>0,'status'=>'participating','created_by'=>$actor_id,
                'enrolled_at_utc'=>$now,'participation_started_at_utc'=>$now,'completed_at_utc'=>null,'withdrawn_at_utc'=>null,'updated_at_utc'=>$now
            ]);
            if($eid<=0){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('database','ثبت سابقه شرکت انجام نشد.',['status'=>500]);}
            if(!self::audit('participation_started',$program_id,0,$eid,$actor_id,'','participating')){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('database','ثبت رویداد شرکت انجام نشد.',['status'=>500]);}
        } elseif($e->status==='enrolled'){
            $n=QYS_Program_Repository::update_enrollment_if_status((int)$e->id,'enrolled',['status'=>'participating','participation_started_at_utc'=>$e->participation_started_at_utc?:$now,'updated_at_utc'=>$now],['%s','%s','%s']);
            if($n!==1||!self::audit('participation_started',$program_id,(int)$e->application_id,(int)$e->id,$actor_id,'enrolled','participating')){if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('conflict','ثبت شروع شرکت انجام نشد.',['status'=>409]);}
        } elseif($e->status!=='participating'){
            if($manage_transaction)QYS_Program_Repository::rollback();return new WP_Error('enrollment_state','وضعیت فعلی ثبت‌نام اجازه حضور جدید نمی‌دهد.',['status'=>409]);
        }
        if($manage_transaction)QYS_Program_Repository::commit();return true;
    }

    /** Read model for the member profile. Pre-registration and true activity remain distinct. */
    public static function member_record(int $member_id): array {
        if($member_id<=0||!QYS_Program_Repository::schema_ready())return ['applications'=>[],'current'=>[],'history'=>[],'closed_without_activity'=>[]];
        $rows=QYS_Program_Repository::member_record_rows($member_id);$apps=$rows['applications'];$ens=$rows['enrollments'];
        $current=[];$history=[];$closed_without_activity=[];
        foreach($ens as $row){
            $stats=self::attendance_summary((int)$row['program_id'],$member_id);$row['attendance']=$stats;
            $participated=!empty($row['participation_started_at_utc'])||(int)($stats['sessions']??0)>0;$row['counts_as_activity']=$participated;
            $program_terminal=in_array((string)$row['program_status'],['completed','cancelled','archived'],true);
            $enrollment_terminal=in_array((string)$row['status'],['completed','withdrawn','no_show','cancelled','closed'],true);
            if($participated&&($program_terminal||$enrollment_terminal))$history[]=$row;
            elseif(!$participated&&($program_terminal||$enrollment_terminal))$closed_without_activity[]=$row;
            else $current[]=$row;
        }
        return ['applications'=>$apps,'current'=>$current,'history'=>$history,'closed_without_activity'=>$closed_without_activity];
    }

    private static function attendance_summary(int $program_id,int $member_id): array {
        return QYS_Program_Attendance_Boundary::member_summary($program_id,$member_id);
    }

    /** Upgrade bridge: freeze tenant ownership for legacy Applications/Enrollments. Unknown shared legacy rows fail closed. */
    public static function backfill_tenant_snapshots(): bool {
        return QYS_Program_Repository::backfill_tenant_snapshots();
    }

    /**
     * RC16 upgrade bridge: normalize Programs that were already terminal before
     * terminal-state orchestration existed. Idempotent by status predicates.
     */
    public static function reconcile_terminal_programs(): bool {
        if(!QYS_Program_Repository::diagnostic_schema_ready()||!QYS_Program_Attendance_Boundary::diagnostic_schema_ready())return false;
        $ids=QYS_Program_Repository::terminal_program_ids();
        foreach($ids as $id){
            $p=QYS_Program_Repository::get_program((int)$id);if(!$p)continue;
            $r=self::orchestrate_terminal_program_state((int)$id,(string)$p->status,0,true);
            if(is_wp_error($r))return false;
        }
        return true;
    }

    /** Safe upgrade bridge. Existing attendance programs become generic programs once. */
    public static function backfill_attendance_programs(): bool {
        return QYS_Program_Attendance_Boundary::backfill_legacy_programs();
    }

}
