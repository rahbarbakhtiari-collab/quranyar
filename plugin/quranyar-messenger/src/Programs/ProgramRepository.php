<?php
if (!defined('ABSPATH')) exit;

/**
 * Storage layer for the generic QuranYar Program domain.
 *
 * Programs are intentionally independent from registration and attendance:
 * Application -> Enrollment -> Attendance.  This keeps pre-registration from
 * becoming an activity record before a person actually participates.
 */
final class QYS_Program_Repository {
    public static function programs_table(): string { return QYS_Table_Names::name('programs'); }
    public static function universities_table(): string { return QYS_Table_Names::name('program_universities'); }
    public static function form_versions_table(): string { return QYS_Table_Names::name('program_form_versions'); }
    public static function fields_table(): string { return QYS_Table_Names::name('program_fields'); }
    public static function applications_table(): string { return QYS_Table_Names::name('program_applications'); }
    public static function answers_table(): string { return QYS_Table_Names::name('program_answers'); }
    public static function enrollments_table(): string { return QYS_Table_Names::name('program_enrollments'); }
    public static function attendance_links_table(): string { return QYS_Table_Names::name('program_attendance_links'); }
    public static function audit_table(): string { return QYS_Table_Names::name('program_audit'); }

    private static function table_columns(string $table): array {
        $out=[];foreach(QYS_Table_Names::diagnostic_columns($table) as $field=>$row)$out[(string)$field]=true;return $out;
    }

    public static function schema_ready(): bool { return QYS_Schema_Runtime::ready(); }

    public static function diagnostic_schema_ready(): bool {
        $checks=[
            self::programs_table()=>['id','manager_university','title','program_type','status','registration_mode','capacity','waitlist_enabled','attendance_enabled','current_form_version','created_by','created_at_utc','updated_at_utc'],
            self::universities_table()=>['program_id','university'],
            self::form_versions_table()=>['id','program_id','version','status','created_by','created_at_utc','published_at_utc'],
            self::fields_table()=>['id','form_version_id','field_key','label','field_type','required','options_json','sort_order','active'],
            self::applications_table()=>['id','program_id','member_id','university','form_version_id','status','submitted_at_utc','updated_at_utc','decided_by','decided_at_utc'],
            self::answers_table()=>['id','application_id','field_id','answer_value','created_at_utc','updated_at_utc'],
            self::enrollments_table()=>['id','program_id','member_id','university','application_id','status','enrolled_at_utc','participation_started_at_utc','completed_at_utc','withdrawn_at_utc','updated_at_utc'],
            self::attendance_links_table()=>['program_id','attendance_program_id','university','created_at_utc','created_by'],
            self::audit_table()=>['id','program_id','application_id','enrollment_id','actor_id','action','from_status','to_status','created_at_utc'],
        ];
        foreach($checks as $table=>$required){
            $cols=self::table_columns($table);
            foreach($required as $column)if(!isset($cols[$column]))return false;
        }
        return true;
    }

    public static function ensure_schema(): bool { global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $programs=self::programs_table();
        $universities=self::universities_table();
        $versions=self::form_versions_table();
        $fields=self::fields_table();
        $applications=self::applications_table();
        $answers=self::answers_table();
        $enrollments=self::enrollments_table();
        $attendance=self::attendance_links_table();
        $audit=self::audit_table();

        dbDelta("CREATE TABLE $programs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            manager_university varchar(40) NOT NULL DEFAULT '',
            title varchar(190) NOT NULL,
            description longtext NOT NULL,
            program_type varchar(32) NOT NULL DEFAULT 'course',
            status varchar(24) NOT NULL DEFAULT 'draft',
            audience_roles varchar(255) NOT NULL DEFAULT 'all',
            registration_mode varchar(20) NOT NULL DEFAULT 'approval',
            capacity int unsigned NOT NULL DEFAULT 0,
            waitlist_enabled tinyint unsigned NOT NULL DEFAULT 1,
            registration_opens_at_utc datetime NULL DEFAULT NULL,
            registration_closes_at_utc datetime NULL DEFAULT NULL,
            starts_at_utc datetime NULL DEFAULT NULL,
            ends_at_utc datetime NULL DEFAULT NULL,
            attendance_enabled tinyint unsigned NOT NULL DEFAULT 0,
            current_form_version int unsigned NOT NULL DEFAULT 0,
            created_by bigint unsigned NOT NULL DEFAULT 0,
            created_at_utc datetime NOT NULL,
            updated_at_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_status (manager_university,status),
            KEY registration_window (status,registration_opens_at_utc,registration_closes_at_utc),
            KEY program_type (program_type)
        ) $c;");

        dbDelta("CREATE TABLE $universities (
            program_id bigint unsigned NOT NULL,
            university varchar(40) NOT NULL,
            PRIMARY KEY  (program_id,university),
            KEY university_program (university,program_id)
        ) $c;");

        dbDelta("CREATE TABLE $versions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            program_id bigint unsigned NOT NULL,
            version int unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            created_by bigint unsigned NOT NULL DEFAULT 0,
            created_at_utc datetime NOT NULL,
            published_at_utc datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY program_version (program_id,version),
            KEY program_status (program_id,status)
        ) $c;");

        dbDelta("CREATE TABLE $fields (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            form_version_id bigint unsigned NOT NULL,
            field_key varchar(64) NOT NULL,
            label varchar(190) NOT NULL,
            field_type varchar(20) NOT NULL,
            required tinyint unsigned NOT NULL DEFAULT 0,
            help_text varchar(500) NOT NULL DEFAULT '',
            options_json longtext NOT NULL,
            sort_order int unsigned NOT NULL DEFAULT 0,
            active tinyint unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY version_key (form_version_id,field_key),
            KEY version_order (form_version_id,sort_order,id)
        ) $c;");

        dbDelta("CREATE TABLE $applications (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            program_id bigint unsigned NOT NULL,
            member_id bigint unsigned NOT NULL,
            university varchar(40) NOT NULL DEFAULT '',
            form_version_id bigint unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'submitted',
            submitted_at_utc datetime NOT NULL,
            updated_at_utc datetime NOT NULL,
            decided_by bigint unsigned NOT NULL DEFAULT 0,
            decided_at_utc datetime NULL DEFAULT NULL,
            decision_note varchar(500) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY program_member (program_id,member_id),
            KEY program_status (program_id,status),
            KEY member_status (member_id,status),
            KEY program_university_status (program_id,university,status)
        ) $c;");

        dbDelta("CREATE TABLE $answers (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint unsigned NOT NULL,
            field_id bigint unsigned NOT NULL,
            answer_value longtext NOT NULL,
            created_at_utc datetime NOT NULL,
            updated_at_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY application_field (application_id,field_id),
            KEY field_id (field_id)
        ) $c;");

        dbDelta("CREATE TABLE $enrollments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            program_id bigint unsigned NOT NULL,
            member_id bigint unsigned NOT NULL,
            university varchar(40) NOT NULL DEFAULT '',
            application_id bigint unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'enrolled',
            created_by bigint unsigned NOT NULL DEFAULT 0,
            enrolled_at_utc datetime NOT NULL,
            participation_started_at_utc datetime NULL DEFAULT NULL,
            completed_at_utc datetime NULL DEFAULT NULL,
            withdrawn_at_utc datetime NULL DEFAULT NULL,
            updated_at_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY program_member (program_id,member_id),
            KEY program_status (program_id,status),
            KEY member_status (member_id,status),
            KEY program_university_status (program_id,university,status)
        ) $c;");

        dbDelta("CREATE TABLE $attendance (
            program_id bigint unsigned NOT NULL,
            attendance_program_id bigint unsigned NOT NULL,
            university varchar(40) NOT NULL,
            created_at_utc datetime NOT NULL,
            created_by bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (program_id,attendance_program_id),
            UNIQUE KEY attendance_program (attendance_program_id),
            KEY university_program (university,program_id)
        ) $c;");

        dbDelta("CREATE TABLE $audit (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            program_id bigint unsigned NOT NULL DEFAULT 0,
            application_id bigint unsigned NOT NULL DEFAULT 0,
            enrollment_id bigint unsigned NOT NULL DEFAULT 0,
            actor_id bigint unsigned NOT NULL DEFAULT 0,
            action varchar(50) NOT NULL,
            from_status varchar(24) NOT NULL DEFAULT '',
            to_status varchar(24) NOT NULL DEFAULT '',
            created_at_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY program_time (program_id,created_at_utc),
            KEY actor_time (actor_id,created_at_utc),
            KEY action_time (action,created_at_utc)
        ) $c;");

        return self::diagnostic_schema_ready();
    }

    private static function positive_ids(array $ids): array {
        $out=[];
        foreach($ids as $id){$id=(int)$id;if($id>0)$out[$id]=$id;}
        return array_values($out);
    }

    private static function id_placeholders(int $count): string {
        return implode(',',array_fill(0,max(0,$count),'%d'));
    }

    /**
     * Phase D / D2: fetch active fields for the current published form of many
     * Programs in one query. Result is keyed by program id.
     *
     * @return array<int,array<int,object>>
     */
    public static function current_form_fields_for_programs(array $program_ids): array { global $wpdb;
        $ids=self::positive_ids($program_ids);$out=[];foreach($ids as $id)$out[$id]=[];
        if(!$ids)return $out;
        $p=self::programs_table();$v=self::form_versions_table();$f=self::fields_table();$in=self::id_placeholders(count($ids));
        $sql="SELECT p.id program_id,f.* FROM $p p JOIN $v v ON v.program_id=p.id AND v.version=p.current_form_version AND v.status='published' JOIN $f f ON f.form_version_id=v.id AND f.active=1 WHERE p.id IN ($in) ORDER BY p.id,f.sort_order,f.id";
        foreach((array)$wpdb->get_results($wpdb->prepare($sql,...$ids)) as $row){$pid=(int)$row->program_id;unset($row->program_id);$out[$pid][]=$row;}
        return $out;
    }

    /**
     * Phase D / D2: grouped application/enrollment counters for a Program list.
     * Query count stays constant (two grouped queries) regardless of list size.
     *
     * @return array<int,array{applications:int,enrollments:int}>
     */
    public static function counts_for_programs(array $program_ids,string $scope='*'): array { global $wpdb;
        $ids=self::positive_ids($program_ids);$out=[];foreach($ids as $id)$out[$id]=['applications'=>0,'enrollments'=>0];
        if(!$ids||$scope==='')return $out;
        $in=self::id_placeholders(count($ids));
        foreach([['table'=>self::applications_table(),'key'=>'applications'],['table'=>self::enrollments_table(),'key'=>'enrollments']] as $def){
            $sql='SELECT program_id,COUNT(*) total FROM '.$def['table'].' WHERE program_id IN ('.$in.')';$args=$ids;
            if($scope!=='*'){$sql.=' AND university=%s';$args[]=$scope;}
            $sql.=' GROUP BY program_id';
            foreach((array)$wpdb->get_results($wpdb->prepare($sql,...$args)) as $row){$pid=(int)$row->program_id;if(isset($out[$pid]))$out[$pid][$def['key']]=(int)$row->total;}
        }
        return $out;
    }

    /**
     * Phase D / D2: batch answers for admin detail and CSV export. Result is
     * keyed by application id and preserves field display order.
     *
     * @return array<int,array<int,object>>
     */
    public static function answers_for_applications(array $application_ids): array { global $wpdb;
        $ids=self::positive_ids($application_ids);$out=[];foreach($ids as $id)$out[$id]=[];
        if(!$ids)return $out;
        $a=self::answers_table();$f=self::fields_table();$in=self::id_placeholders(count($ids));
        $sql="SELECT a.application_id,a.answer_value,f.id field_id,f.form_version_id,f.label,f.field_type,f.sort_order FROM $a a JOIN $f f ON f.id=a.field_id WHERE a.application_id IN ($in) ORDER BY a.application_id,f.sort_order,f.id";
        foreach((array)$wpdb->get_results($wpdb->prepare($sql,...$ids)) as $row){$out[(int)$row->application_id][]=$row;}
        return $out;
    }

    public static function performance_contract(): array {
        return ['ready'=>true,'code'=>'ok','forms'=>'batch','counts'=>'grouped','answers'=>'batch'];
    }

    public static function get_program(int $id){ global $wpdb;
        return $id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::programs_table().' WHERE id=%d',$id)):null;
    }

    public static function program_universities(int $program_id): array { global $wpdb;
        if($program_id<=0)return [];
        return array_values(array_map('strval',(array)$wpdb->get_col($wpdb->prepare('SELECT university FROM '.self::universities_table().' WHERE program_id=%d ORDER BY university',$program_id))));
    }

    public static function get_application(int $id){ global $wpdb;
        return $id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::applications_table().' WHERE id=%d',$id)):null;
    }

    public static function application_for_member(int $program_id,int $member_id){ global $wpdb;
        return ($program_id>0&&$member_id>0)?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::applications_table().' WHERE program_id=%d AND member_id=%d LIMIT 1',$program_id,$member_id)):null;
    }

    public static function lock_application_for_member(int $program_id,int $member_id){ global $wpdb;
        return ($program_id>0&&$member_id>0)?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::applications_table().' WHERE program_id=%d AND member_id=%d LIMIT 1 FOR UPDATE',$program_id,$member_id)):null;
    }

    public static function get_enrollment(int $id){ global $wpdb;
        return $id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::enrollments_table().' WHERE id=%d',$id)):null;
    }

    public static function current_form(int $program_id){ global $wpdb;
        $p=self::get_program($program_id);if(!$p||(int)$p->current_form_version<=0)return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::form_versions_table().' WHERE program_id=%d AND version=%d AND status=%s',$program_id,(int)$p->current_form_version,'published'));
    }

    public static function form_fields(int $form_version_id): array { global $wpdb;
        if($form_version_id<=0)return [];
        return (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::fields_table().' WHERE form_version_id=%d AND active=1 ORDER BY sort_order,id',$form_version_id));
    }


    /** Permission-scoped Program index. Campus scope may see shared Programs but does not own their definition. */
    public static function list_for_scope(string $scope,int $limit=100): array { global $wpdb;
        if(!self::schema_ready())return [];$limit=max(1,min(500,$limit));$p=self::programs_table();$u=self::universities_table();
        if($scope==='*')return (array)$wpdb->get_results("SELECT p.*,1 AS qys_manageable FROM $p p ORDER BY p.id DESC LIMIT $limit");
        if($scope==='')return [];
        return (array)$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.*,(p.manager_university=%s) AS qys_manageable FROM $p p JOIN $u u ON u.program_id=p.id WHERE u.university=%s ORDER BY p.id DESC LIMIT %d",$scope,$scope,$limit));
    }

    public static function program_visible_in_scope(int $program_id,string $scope): bool { global $wpdb;
        if($program_id<=0||$scope==='')return false;if($scope==='*')return (bool)self::get_program($program_id);
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::universities_table().' WHERE program_id=%d AND university=%s',$program_id,$scope))>0;
    }

    /** Definition ownership is distinct from visibility. */
    public static function program_manageable_in_scope(int $program_id,string $scope): bool { global $wpdb;
        if($program_id<=0||$scope==='')return false;if($scope==='*')return (bool)self::get_program($program_id);
        return (string)$wpdb->get_var($wpdb->prepare('SELECT manager_university FROM '.self::programs_table().' WHERE id=%d',$program_id))===$scope;
    }

    public static function application_in_scope(int $application_id,string $scope): bool { global $wpdb;
        if($application_id<=0||$scope==='')return false;$a=self::get_application($application_id);if(!$a)return false;if($scope==='*')return true;
        return (string)$a->university===$scope&&self::program_visible_in_scope((int)$a->program_id,$scope);
    }

    public static function applications_for_program(int $program_id,int $limit=500,string $scope='*'): array {
        return self::applications_for_program_filtered($program_id,$scope,[],$limit);
    }

    /**
     * Phase E / E9: tenant-scoped applicant read model with bounded server-side
     * filtering. Search intentionally covers only the already-visible member name;
     * national_id/mobile are not silently added to a broad search surface.
     *
     * Supported filters:
     * - status: application status
     * - role: member role
     * - enrollment: with|without
     * - search: first/last/full name contains
     */
    public static function applications_for_program_filtered(int $program_id,string $scope,array $filters=[],int $limit=500): array { global $wpdb;
        if($program_id<=0||$scope==='')return [];
        $limit=max(1,min(1000,$limit));$a=self::applications_table();$m=QYS_Table_Names::name('members');$e=self::enrollments_table();
        $where=['a.program_id=%d'];$args=[$program_id];
        if($scope!=='*'){$where[]='a.university=%s';$args[]=$scope;}

        $status=sanitize_key((string)($filters['status']??''));
        if($status!==''){$where[]='a.status=%s';$args[]=$status;}
        $role=sanitize_key((string)($filters['role']??''));
        if($role!==''){$where[]='m.member_role=%s';$args[]=$role;}
        $enrollment=sanitize_key((string)($filters['enrollment']??''));
        if($enrollment==='with')$where[]='e.id IS NOT NULL';
        elseif($enrollment==='without')$where[]='e.id IS NULL';
        $search=trim((string)($filters['search']??''));
        if($search!==''){
            $like='%'.$wpdb->esc_like($search).'%';
            $where[]="(m.first_name LIKE %s OR m.last_name LIKE %s OR CONCAT(m.first_name,' ',m.last_name) LIKE %s)";
            array_push($args,$like,$like,$like);
        }

        $sql="SELECT a.*,a.university university,m.university member_university,m.first_name,m.last_name,m.member_role,m.national_id,m.mobile,e.id enrollment_id,e.status enrollment_status,e.university enrollment_university FROM $a a JOIN $m m ON m.id=a.member_id LEFT JOIN $e e ON e.program_id=a.program_id AND e.member_id=a.member_id WHERE ".implode(' AND ',$where).' ORDER BY a.id DESC LIMIT %d';
        $args[]=$limit;
        return (array)$wpdb->get_results($wpdb->prepare($sql,...$args));
    }

    /** E9 preflight: every selected applicant must belong to this Program + tenant scope. */
    public static function applications_in_scope_by_ids(int $program_id,array $application_ids,string $scope): array { global $wpdb;
        $ids=self::positive_ids($application_ids);if($program_id<=0||$scope===''||!$ids)return [];
        $a=self::applications_table();$e=self::enrollments_table();$in=implode(',',array_fill(0,count($ids),'%d'));
        $where="a.program_id=%d AND a.id IN ($in)";$args=array_merge([$program_id],$ids);
        if($scope!=='*'){$where.=' AND a.university=%s';$args[]=$scope;}
        return (array)$wpdb->get_results($wpdb->prepare("SELECT a.id,a.program_id,a.member_id,a.university,a.status,e.id enrollment_id,e.status enrollment_status FROM $a a LEFT JOIN $e e ON e.application_id=a.id AND e.application_id>0 WHERE $where ORDER BY a.id ASC",...$args));
    }

    /**
     * Phase D / D8: keyset chunk for streaming Program CSV export.
     * Unlike applications_for_program(), this path has no whole-export row cap
     * and never uses OFFSET. Tenant scope is applied inside every chunk query.
     */
    public static function applications_export_chunk(int $program_id,string $scope,int $after_id=0,int $limit=250): array { global $wpdb;
        if($program_id<=0||$scope==='')return [];
        $limit=max(1,min(500,$limit));$after_id=max(0,$after_id);
        $a=self::applications_table();$m=QYS_Table_Names::name('members');$e=self::enrollments_table();
        $where='a.program_id=%d AND a.id>%d';$args=[$program_id,$after_id];
        if($scope!=='*'){$where.=' AND a.university=%s';$args[]=$scope;}
        $sql="SELECT a.*,a.university university,m.university member_university,m.first_name,m.last_name,m.member_role,m.national_id,m.mobile,e.id enrollment_id,e.status enrollment_status,e.university enrollment_university FROM $a a JOIN $m m ON m.id=a.member_id LEFT JOIN $e e ON e.program_id=a.program_id AND e.member_id=a.member_id WHERE $where ORDER BY a.id ASC LIMIT %d";
        $args[]=$limit;
        return (array)$wpdb->get_results($wpdb->prepare($sql,...$args));
    }

    public static function count_applications_for_program(int $program_id,string $scope='*'): int { global $wpdb;
        if($program_id<=0||$scope==='')return 0;if($scope==='*')return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::applications_table().' WHERE program_id=%d',$program_id));
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::applications_table().' WHERE program_id=%d AND university=%s',$program_id,$scope));
    }
    public static function count_enrollments_for_program(int $program_id,string $scope='*'): int { global $wpdb;
        if($program_id<=0||$scope==='')return 0;if($scope==='*')return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::enrollments_table().' WHERE program_id=%d',$program_id));
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::enrollments_table().' WHERE program_id=%d AND university=%s',$program_id,$scope));
    }

    public static function answers_for_application(int $application_id): array {
        if($application_id<=0)return [];
        $map=self::answers_for_applications([$application_id]);
        return $map[$application_id]??[];
    }

    public static function draft_form(int $program_id){ global $wpdb;
        if($program_id<=0)return null;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::form_versions_table().' WHERE program_id=%d AND status=%s ORDER BY version DESC LIMIT 1',$program_id,'draft'));
    }


    /** One field together with its form-version ownership/status. */
    public static function get_field_context(int $field_id){ global $wpdb;
        if($field_id<=0)return null;$f=self::fields_table();$v=self::form_versions_table();
        return $wpdb->get_row($wpdb->prepare("SELECT f.*,v.program_id,v.version form_version,v.status form_status FROM $f f JOIN $v v ON v.id=f.form_version_id WHERE f.id=%d LIMIT 1",$field_id));
    }

    /** Resolve the copied counterpart of a stable field_key inside one form version. */
    public static function field_by_key(int $form_version_id,string $field_key){ global $wpdb;
        $field_key=sanitize_key($field_key);if($form_version_id<=0||$field_key==='')return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::fields_table().' WHERE form_version_id=%d AND field_key=%s AND active=1 LIMIT 1',$form_version_id,$field_key));
    }

    public static function all_form_fields(int $program_id): array { global $wpdb;
        if($program_id<=0)return [];$f=self::fields_table();$v=self::form_versions_table();
        return (array)$wpdb->get_results($wpdb->prepare("SELECT f.*,v.version,v.status form_status FROM $f f JOIN $v v ON v.id=f.form_version_id WHERE v.program_id=%d AND f.active=1 ORDER BY v.version,f.sort_order,f.id",$program_id));
    }

    public static function attendance_link(int $program_id){ global $wpdb;
        if($program_id<=0)return null;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::attendance_links_table().' WHERE program_id=%d ORDER BY attendance_program_id LIMIT 1',$program_id));
    }

    /** Programs visible to one verified member, including their current application/enrollment state. */
    public static function list_for_member($member,int $limit=100): array { global $wpdb;
        if(!$member||!self::schema_ready())return [];$limit=max(1,min(200,$limit));$p=self::programs_table();$u=self::universities_table();$a=self::applications_table();$e=self::enrollments_table();
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.*,a.id application_id,a.status application_status,e.id enrollment_id,e.status enrollment_status FROM $p p JOIN $u u ON u.program_id=p.id LEFT JOIN $a a ON a.program_id=p.id AND a.member_id=%d AND a.university=%s LEFT JOIN $e e ON e.program_id=p.id AND e.member_id=%d AND e.university=%s WHERE u.university=%s AND p.registration_mode='approval' AND p.status IN ('registration_open','registration_closed','confirmed','running','completed') AND (p.status='registration_open' OR a.id IS NOT NULL OR e.id IS NOT NULL) ORDER BY FIELD(p.status,'registration_open','confirmed','running','registration_closed','completed'),p.id DESC LIMIT %d",(int)$member->id,(string)$member->university,(int)$member->id,(string)$member->university,(string)$member->university,$limit));
        $out=[];foreach($rows as $row){$roles=(string)$row->audience_roles;if($roles!==''&&$roles!=='all'&&!in_array((string)$member->member_role,array_filter(explode(',',$roles)),true))continue;$out[]=$row;}return $out;
    }

    /**
     * Phase D / D3: compact Program read model for the member Home screen.
     * No form versions, fields, answers, counts or attendance detail are read.
     * One bounded query returns only data needed by the three Home cards.
     */
    public static function home_summaries_for_member($member,int $limit=3): array { global $wpdb;
        if(!$member||!self::schema_ready())return [];
        $limit=max(1,min(6,$limit));$scan=max(12,$limit*4);
        $p=self::programs_table();$u=self::universities_table();$a=self::applications_table();$e=self::enrollments_table();
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.id,p.title,p.description,p.program_type,p.status,p.audience_roles,a.id application_id,a.status application_status,e.id enrollment_id,e.status enrollment_status FROM $p p JOIN $u u ON u.program_id=p.id LEFT JOIN $a a ON a.program_id=p.id AND a.member_id=%d AND a.university=%s LEFT JOIN $e e ON e.program_id=p.id AND e.member_id=%d AND e.university=%s WHERE u.university=%s AND p.registration_mode='approval' AND p.status IN ('registration_open','registration_closed','confirmed','running') AND (p.status='registration_open' OR a.id IS NOT NULL OR e.id IS NOT NULL) ORDER BY FIELD(p.status,'registration_open','confirmed','running','registration_closed'),p.id DESC LIMIT %d",(int)$member->id,(string)$member->university,(int)$member->id,(string)$member->university,(string)$member->university,$scan));
        $out=[];foreach($rows as $row){$roles=(string)$row->audience_roles;if($roles!==''&&$roles!=='all'&&!in_array((string)$member->member_role,array_filter(explode(',',$roles)),true))continue;$out[]=$row;if(count($out)>=$limit)break;}return $out;
    }

    public static function readiness_counts(): array { global $wpdb;
        $out=['programs'=>0,'applications'=>0,'enrollments'=>0,'attendance_links'=>0];
        if(!self::schema_ready())return $out;
        $out['programs']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::programs_table());
        $out['applications']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::applications_table());
        $out['enrollments']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::enrollments_table());
        $out['attendance_links']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::attendance_links_table());
        return $out;
    }
    // Phase C / C6 — Program persistence ownership.
    // Business services may coordinate transactions, but every SQL statement for
    // Program/Application/Enrollment/Form tables lives in this repository.
    public static function begin(): bool { global $wpdb; return $wpdb->query('START TRANSACTION')!==false; }
    public static function commit(): bool { global $wpdb; return $wpdb->query('COMMIT')!==false; }
    public static function rollback(): bool { global $wpdb; return $wpdb->query('ROLLBACK')!==false; }

    public static function admission_counts(int $program_id): array { global $wpdb;
        if($program_id<=0)return ['used'=>0,'waitlisted'=>0,'accepted_pending'=>0,'active_enrollments'=>0];
        $apps=self::applications_table();$ens=self::enrollments_table();
        // A withdrawn enrollment frees its seat. If that same member later
        // re-applies and is accepted again, the new decision timestamp is newer
        // than withdrawn_at_utc and must reserve the seat before reactivation.
        $sql="SELECT app.used,app.waitlisted,app.accepted_pending,en.active_enrollments
              FROM (SELECT
                    SUM(CASE WHEN e.status IN ('enrolled','participating') OR (a.status='accepted' AND (e.id IS NULL OR (e.status='withdrawn' AND e.withdrawn_at_utc IS NOT NULL AND a.decided_at_utc IS NOT NULL AND a.decided_at_utc>e.withdrawn_at_utc))) THEN 1 ELSE 0 END) used,
                    SUM(CASE WHEN a.status='waitlisted' THEN 1 ELSE 0 END) waitlisted,
                    SUM(CASE WHEN a.status='accepted' AND (e.id IS NULL OR (e.status='withdrawn' AND e.withdrawn_at_utc IS NOT NULL AND a.decided_at_utc IS NOT NULL AND a.decided_at_utc>e.withdrawn_at_utc)) THEN 1 ELSE 0 END) accepted_pending
                    FROM $apps a LEFT JOIN $ens e ON e.application_id=a.id WHERE a.program_id=%d) app
              CROSS JOIN (SELECT COUNT(*) active_enrollments FROM $ens WHERE program_id=%d AND status IN ('enrolled','participating')) en";
        $row=$wpdb->get_row($wpdb->prepare($sql,$program_id,$program_id));
        return [
            'used'=>(int)($row->used??0),
            'waitlisted'=>(int)($row->waitlisted??0),
            'accepted_pending'=>(int)($row->accepted_pending??0),
            'active_enrollments'=>(int)($row->active_enrollments??0),
        ];
    }

    public static function insert_audit(array $data): bool { global $wpdb;
        return $wpdb->insert(self::audit_table(),$data,['%d','%d','%d','%d','%s','%s','%s','%s'])===1;
    }

    public static function insert_program(array $data,array $formats): int { global $wpdb;
        return $wpdb->insert(self::programs_table(),$data,$formats)===1?(int)$wpdb->insert_id:0;
    }
    public static function insert_program_university(int $program_id,string $university): bool { global $wpdb;
        return $wpdb->insert(self::universities_table(),['program_id'=>$program_id,'university'=>$university],['%d','%s'])===1;
    }
    public static function update_program_details(int $program_id,array $data,array $formats): int { global $wpdb;
        if($program_id<=0||!$data)return -1;
        $n=$wpdb->update(self::programs_table(),$data,['id'=>$program_id],$formats,['%d']);
        return $n===false?-1:(int)$n;
    }
    public static function replace_program_universities(int $program_id,array $universities): bool { global $wpdb;
        if($program_id<=0||!$universities)return false;
        $deleted=$wpdb->delete(self::universities_table(),['program_id'=>$program_id],['%d']);
        if($deleted===false)return false;
        foreach($universities as $university){
            if(!self::insert_program_university($program_id,(string)$university))return false;
        }
        return true;
    }
    public static function compare_and_set_program_status(int $program_id,string $from,string $to,string $updated_at): int { global $wpdb;
        return (int)$wpdb->query($wpdb->prepare('UPDATE '.self::programs_table().' SET status=%s,updated_at_utc=%s WHERE id=%d AND status=%s',$to,$updated_at,$program_id,$from));
    }
    public static function lock_program(int $program_id){ global $wpdb;
        return $program_id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::programs_table().' WHERE id=%d FOR UPDATE',$program_id)):null;
    }

    public static function lock_open_applications(int $program_id): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT id,status FROM ".self::applications_table()." WHERE program_id=%d AND status IN ('submitted','under_review','waitlisted') FOR UPDATE",$program_id));
    }
    public static function update_application_if_status(int $application_id,string $from,array $data,array $formats): int { global $wpdb;
        $n=$wpdb->update(self::applications_table(),$data,['id'=>$application_id,'status'=>$from],$formats,['%d','%s']);
        return $n===false?-1:(int)$n;
    }
    public static function lock_active_enrollments(int $program_id): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT id,application_id,status FROM ".self::enrollments_table()." WHERE program_id=%d AND status IN ('enrolled','participating') FOR UPDATE",$program_id));
    }
    public static function update_enrollment_if_status(int $enrollment_id,string $from,array $data,array $formats): int { global $wpdb;
        $n=$wpdb->update(self::enrollments_table(),$data,['id'=>$enrollment_id,'status'=>$from],$formats,['%d','%s']);
        return $n===false?-1:(int)$n;
    }

    /** RC53: reactivate the same withdrawn enrollment row after a renewed application is accepted. */
    public static function reactivate_withdrawn_enrollment(int $enrollment_id,int $application_id,string $university,int $actor_id,string $now): int { global $wpdb;
        if($enrollment_id<=0||$application_id<=0||$university==='')return -1;
        $n=$wpdb->update(self::enrollments_table(),[
            'application_id'=>$application_id,
            'university'=>$university,
            'status'=>'enrolled',
            'created_by'=>$actor_id,
            'enrolled_at_utc'=>$now,
            'participation_started_at_utc'=>null,
            'completed_at_utc'=>null,
            'withdrawn_at_utc'=>null,
            'updated_at_utc'=>$now,
        ],['id'=>$enrollment_id,'status'=>'withdrawn'],['%d','%s','%s','%d','%s','%s','%s','%s','%s'],['%d','%s']);
        return $n===false?-1:(int)$n;
    }

    public static function latest_form_by_status(int $program_id,string $status){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::form_versions_table().' WHERE program_id=%d AND status=%s ORDER BY version DESC LIMIT 1',$program_id,$status));
    }
    public static function next_form_version(int $program_id): int { global $wpdb;
        return 1+(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(version),0) FROM '.self::form_versions_table().' WHERE program_id=%d',$program_id));
    }
    public static function insert_form_version(array $data): int { global $wpdb;
        return $wpdb->insert(self::form_versions_table(),$data,['%d','%d','%s','%d','%s','%s'])===1?(int)$wpdb->insert_id:0;
    }
    public static function insert_form_field(array $data): int { global $wpdb;
        return $wpdb->insert(self::fields_table(),$data,['%d','%s','%s','%s','%d','%s','%s','%d','%d'])===1?(int)$wpdb->insert_id:0;
    }
    public static function get_form_version(int $form_version_id){ global $wpdb;
        return $form_version_id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::form_versions_table().' WHERE id=%d',$form_version_id)):null;
    }
    public static function update_field(int $field_id,int $form_version_id,array $data,array $formats){ global $wpdb;
        return $wpdb->update(self::fields_table(),$data,['id'=>$field_id,'form_version_id'=>$form_version_id],$formats,['%d','%d']);
    }
    public static function removable_field_context(int $field_id){ global $wpdb;
        if($field_id<=0)return null;$fields=self::fields_table();$versions=self::form_versions_table();
        return $wpdb->get_row($wpdb->prepare("SELECT f.id,f.form_version_id,v.status,v.program_id FROM $fields f JOIN $versions v ON v.id=f.form_version_id WHERE f.id=%d",$field_id));
    }
    public static function delete_field(int $field_id,int $form_version_id): bool { global $wpdb;
        return $wpdb->delete(self::fields_table(),['id'=>$field_id,'form_version_id'=>$form_version_id],['%d','%d'])===1;
    }
    public static function retire_published_forms(int $program_id){ global $wpdb;
        return $wpdb->update(self::form_versions_table(),['status'=>'retired'],['program_id'=>$program_id,'status'=>'published'],['%s'],['%d','%s']);
    }
    public static function publish_form_if_draft(int $form_version_id,string $published_at): int { global $wpdb;
        return (int)$wpdb->query($wpdb->prepare('UPDATE '.self::form_versions_table().' SET status=%s,published_at_utc=%s WHERE id=%d AND status=%s','published',$published_at,$form_version_id,'draft'));
    }
    public static function set_program_current_form_version(int $program_id,int $version,string $updated_at){ global $wpdb;
        return $wpdb->update(self::programs_table(),['current_form_version'=>$version,'updated_at_utc'=>$updated_at],['id'=>$program_id],['%d','%s'],['%d']);
    }

    public static function application_exists_for_member(int $program_id,int $member_id): bool { global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::applications_table().' WHERE program_id=%d AND member_id=%d',$program_id,$member_id))>0;
    }
    public static function insert_application(array $data): int { global $wpdb;
        return $wpdb->insert(self::applications_table(),$data,['%d','%d','%s','%d','%s','%s','%s','%d','%s','%s'])===1?(int)$wpdb->insert_id:0;
    }
    public static function insert_answer(int $application_id,int $field_id,string $value,string $now): bool { global $wpdb;
        return $wpdb->insert(self::answers_table(),['application_id'=>$application_id,'field_id'=>$field_id,'answer_value'=>$value,'created_at_utc'=>$now,'updated_at_utc'=>$now],['%d','%d','%s','%s','%s'])===1;
    }
    public static function lock_application(int $application_id){ global $wpdb;
        return $application_id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::applications_table().' WHERE id=%d FOR UPDATE',$application_id)):null;
    }
    public static function enrollment_status_for_member(int $program_id,int $member_id): string { global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SELECT status FROM '.self::enrollments_table().' WHERE program_id=%d AND member_id=%d',$program_id,$member_id));
    }
    public static function enrollment_for_member(int $program_id,int $member_id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::enrollments_table().' WHERE program_id=%d AND member_id=%d',$program_id,$member_id));
    }
    public static function lock_enrollment_for_member(int $program_id,int $member_id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::enrollments_table().' WHERE program_id=%d AND member_id=%d FOR UPDATE',$program_id,$member_id));
    }
    public static function insert_enrollment(array $data): int { global $wpdb;
        return $wpdb->insert(self::enrollments_table(),$data,['%d','%d','%s','%d','%s','%d','%s','%s','%s','%s','%s'])===1?(int)$wpdb->insert_id:0;
    }
    public static function insert_attendance_link(array $data): bool { global $wpdb;
        return $wpdb->insert(self::attendance_links_table(),$data,['%d','%d','%s','%s','%d'])===1;
    }

    public static function member_record_rows(int $member_id): array { global $wpdb;
        $programs=self::programs_table();$applications=self::applications_table();$enrollments=self::enrollments_table();
        return [
            'applications'=>(array)$wpdb->get_results($wpdb->prepare("SELECT a.id,a.program_id,a.status,a.submitted_at_utc,p.title,p.program_type,p.status program_status FROM $applications a JOIN $programs p ON p.id=a.program_id WHERE a.member_id=%d ORDER BY a.id DESC",$member_id),ARRAY_A),
            'enrollments'=>(array)$wpdb->get_results($wpdb->prepare("SELECT e.id,e.program_id,e.status,e.enrolled_at_utc,e.participation_started_at_utc,e.completed_at_utc,e.withdrawn_at_utc,p.title,p.program_type,p.status program_status FROM $enrollments e JOIN $programs p ON p.id=e.program_id WHERE e.member_id=%d ORDER BY e.id DESC",$member_id),ARRAY_A),
        ];
    }

    /** Idempotent tenant snapshot repair used by the Phase-A compatibility migration. */
    public static function backfill_tenant_snapshots(): bool { global $wpdb;
        if(!self::diagnostic_schema_ready())return false;
        $apps=self::applications_table();$ens=self::enrollments_table();$members=QYS_Table_Names::name('members');$universities=self::universities_table();
        $queries=[
            "UPDATE $apps a JOIN $members m ON m.id=a.member_id JOIN $universities u ON u.program_id=a.program_id AND u.university=m.university SET a.university=m.university WHERE a.university=''",
            "UPDATE $apps a JOIN (SELECT program_id,MIN(university) university,COUNT(*) c FROM $universities GROUP BY program_id HAVING c=1) x ON x.program_id=a.program_id SET a.university=x.university WHERE a.university=''",
            "UPDATE $apps SET university='legacy_unknown' WHERE university=''",
            "UPDATE $ens e JOIN $apps a ON a.id=e.application_id AND e.application_id>0 SET e.university=a.university WHERE e.university=''",
            "UPDATE $ens e JOIN $members m ON m.id=e.member_id JOIN $universities u ON u.program_id=e.program_id AND u.university=m.university SET e.university=m.university WHERE e.university=''",
            "UPDATE $ens e JOIN (SELECT program_id,MIN(university) university,COUNT(*) c FROM $universities GROUP BY program_id HAVING c=1) x ON x.program_id=e.program_id SET e.university=x.university WHERE e.university=''",
            "UPDATE $ens SET university='legacy_unknown' WHERE university=''",
        ];
        foreach($queries as $sql)if($wpdb->query($sql)===false)return false;
        return true;
    }
    public static function terminal_program_ids(): array { global $wpdb;
        return array_map('intval',(array)$wpdb->get_col("SELECT id FROM ".self::programs_table()." WHERE status IN ('completed','cancelled','archived') ORDER BY id ASC"));
    }

}
