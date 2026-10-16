<?php
if (!defined('ABSPATH')) exit;

/**
 * Canonical storage boundary for qys_members.
 * No feature/service outside src/Storage may query the members table directly.
 */
final class QYS_Member_Repository {
    private static function table(): string { return QYS_Table_Names::name('members'); }
    private static function identifier(string $table): string {
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new InvalidArgumentException('Unsafe table identifier.');
        return $table;
    }

    public static function table_exists(): bool { return QYS_Table_Names::runtime_exists(self::table()); }
    public static function diagnostic_table_exists(): bool { return QYS_Table_Names::diagnostic_exists(self::table()); }

    public static function schema_ready(): bool { return QYS_Schema_Runtime::ready(); }

    public static function diagnostic_schema_ready(): bool {
        return QYS_Table_Names::diagnostic_has_columns(self::table(),['id','eitaa_id','first_name','last_name','national_id','university','pending_university','transfer_requested_at_utc','member_role','mobile','mobile_verified_at_utc','mobile_verification_required','birth_jalali','birth_md','birthday_opt_in','verified','consent','updated_at'])
            && self::diagnostic_birthday_lookup_index_ready();
    }

    /** D9 materialized Jalali month/day key (MM/DD) used by exact birthday lookups. */
    public static function birthday_md_from_jalali(string $birth_jalali): string {
        $birth_jalali=trim($birth_jalali);
        if(!preg_match('~\A[0-9]{4}/((?:0[1-9]|1[0-2])/(?:0[1-9]|[12][0-9]|3[01]))\z~D',$birth_jalali,$m))return '';
        return (string)$m[1];
    }

    private static function birthday_md_from_suffix(string $suffix): string {
        $suffix=ltrim(trim($suffix),'/');
        return preg_match('~\A(?:0[1-9]|1[0-2])/(?:0[1-9]|[12][0-9]|3[01])\z~D',$suffix)?$suffix:'';
    }

    private static function with_birth_md(array $values,array $formats): array {
        if(array_key_exists('birth_jalali',$values)){
            $had=array_key_exists('birth_md',$values);
            $values['birth_md']=self::birthday_md_from_jalali((string)$values['birth_jalali']);
            if(!$had)$formats[]='%s';
        }
        return [$values,$formats];
    }

    /** Any index name is accepted when its exact leading columns match the D9 contract. */
    public static function diagnostic_birthday_lookup_index_ready(): bool {
        if(!QYS_Table_Names::diagnostic_has_columns(self::table(),['birth_md','birthday_opt_in','verified','consent','id']))return false;
        $grouped=[];
        foreach(QYS_Table_Names::diagnostic_indexes(self::table()) as $row){
            $name=(string)($row['Key_name']??'');
            $seq=(int)($row['Seq_in_index']??0);
            $column=(string)($row['Column_name']??'');
            if($name===''||$seq<1||$column==='')continue;
            $grouped[$name][$seq]=$column;
        }
        $wanted=['birth_md','birthday_opt_in','verified','consent','id'];
        foreach($grouped as $columns){
            ksort($columns,SORT_NUMERIC);
            if(array_slice(array_values($columns),0,count($wanted))===$wanted)return true;
        }
        return false;
    }

    /** Add/backfill the D9 materialized lookup column and exact-match composite index idempotently. */
    public static function ensure_birthday_lookup_index(): bool { global $wpdb;
        if(!self::diagnostic_table_exists())return false;
        $table=self::identifier(self::table());
        $columns=QYS_Table_Names::diagnostic_columns($table);
        if(!isset($columns['birth_md'])){
            if($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `birth_md` char(5) NOT NULL DEFAULT '' AFTER `birth_jalali`")===false)return false;
            QYS_Table_Names::clear_diagnostic_cache($table);
        }
        $case="CASE WHEN birth_jalali REGEXP '^[0-9]{4}/[0-9]{2}/[0-9]{2}$' THEN RIGHT(birth_jalali,5) ELSE '' END";
        if($wpdb->query('UPDATE `'.$table.'` SET `birth_md`='.$case.' WHERE `birth_md`<>'.$case)===false)return false;
        if(!self::diagnostic_birthday_lookup_index_ready()){
            $names=[];
            foreach(QYS_Table_Names::diagnostic_indexes($table) as $row){$name=(string)($row['Key_name']??'');if($name!=='')$names[$name]=true;}
            $index=isset($names['birthday_lookup'])?'birthday_lookup_d9':'birthday_lookup';
            if($wpdb->query('ALTER TABLE `'.$table.'` ADD KEY `'.$index.'` (`birth_md`,`birthday_opt_in`,`verified`,`consent`,`id`)')===false)return false;
            QYS_Table_Names::clear_diagnostic_cache($table);
        }
        return self::diagnostic_birthday_lookup_index_ready();
    }

    /** Explicit Health/acceptance contract for D9; never used by normal member hot paths. */

    /** RC51: ensure mobile ownership verification columns without rewriting legacy trust. */
    public static function ensure_mobile_verification_schema(): bool { global $wpdb;
        if(!self::diagnostic_table_exists())return false;
        $table=self::identifier(self::table());$columns=QYS_Table_Names::diagnostic_columns($table);
        if(!isset($columns['mobile_verified_at_utc'])){
            if($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `mobile_verified_at_utc` datetime NULL DEFAULT NULL AFTER `mobile`")===false)return false;
            QYS_Table_Names::clear_diagnostic_cache($table);$columns=QYS_Table_Names::diagnostic_columns($table);
        }
        if(!isset($columns['mobile_verification_required'])){
            if($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `mobile_verification_required` tinyint unsigned NOT NULL DEFAULT 0 AFTER `mobile_verified_at_utc`")===false)return false;
            QYS_Table_Names::clear_diagnostic_cache($table);
        }
        return QYS_Table_Names::diagnostic_has_columns($table,['mobile_verified_at_utc','mobile_verification_required']);
    }

    public static function mark_mobile_verified(int $id,string $mobile){ global $wpdb;
        if($id<=0||$mobile==='')return false;
        return $wpdb->update(self::table(),[
            'mobile_verified_at_utc'=>gmdate('Y-m-d H:i:s'),'mobile_verification_required'=>1,'updated_at'=>current_time('mysql')
        ],['id'=>$id,'mobile'=>$mobile],['%s','%d','%s'],['%d','%s']);
    }

    public static function mobile_ownership_ready($member): bool {
        if(!$member)return false;
        return (int)($member->mobile_verification_required??0)===0 || !empty($member->mobile_verified_at_utc);
    }

    public static function birthday_lookup_contract(): array {
        $column=isset(QYS_Table_Names::diagnostic_columns(self::table())['birth_md']);
        $index=$column && self::diagnostic_birthday_lookup_index_ready();
        return [
            'ready'=>$column && $index,
            'column'=>$column?'birth_md':'missing',
            'query'=>'exact',
            'index'=>$index?'ready':'missing',
            'legacy_like'=>'removed',
        ];
    }

    public static function begin(): bool { global $wpdb; return $wpdb->query('START TRANSACTION')!==false; }
    public static function commit(): bool { global $wpdb; return $wpdb->query('COMMIT')!==false; }
    public static function rollback(): bool { global $wpdb; return $wpdb->query('ROLLBACK')!==false; }

    public static function find_by_eitaa_id(string $eitaa_id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE eitaa_id=%s',$eitaa_id));
    }
    public static function find_by_id(int $id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id));
    }
    public static function find_by_id_in_university(int $id,string $university){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d AND university=%s',$id,$university));
    }
    public static function id_by_eitaa_id(string $eitaa_id): int { global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE eitaa_id=%s',$eitaa_id));
    }
    public static function exists_other_national_id(string $national_id,string $eitaa_id): bool { global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE national_id=%s AND eitaa_id<>%s LIMIT 1',$national_id,$eitaa_id));
    }
    public static function exists_other_mobile(string $mobile,string $eitaa_id): bool { global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE mobile=%s AND eitaa_id<>%s LIMIT 1',$mobile,$eitaa_id));
    }
    public static function update(int $id,array $values,array $formats){ global $wpdb;
        [$values,$formats]=self::with_birth_md($values,$formats);
        return $wpdb->update(self::table(),$values,['id'=>$id],$formats,['%d']);
    }
    public static function insert(array $values,array $formats){ global $wpdb;
        [$values,$formats]=self::with_birth_md($values,$formats);
        $ok=$wpdb->insert(self::table(),$values,$formats);
        return $ok===1?(int)$wpdb->insert_id:false;
    }
    public static function set_consent(int $id,bool $enabled){ global $wpdb;
        return $wpdb->update(self::table(),['consent'=>$enabled?1:0,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']);
    }
    public static function verify_in_university(int $id,string $university){ global $wpdb;
        return $wpdb->update(self::table(),['verified'=>1],['id'=>$id,'university'=>$university,'verified'=>0],['%d'],['%d','%s','%d']);
    }
    public static function verify(int $id){ global $wpdb;
        return $wpdb->update(self::table(),['verified'=>1],['id'=>$id,'verified'=>0],['%d'],['%d','%d']);
    }
    public static function lock_by_id(int $id){ global $wpdb;
        return $id>0?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d FOR UPDATE',$id)):null;
    }
    public static function request_transfer(int $id,string $destination,string $requested_at_utc){ global $wpdb;
        $destination=sanitize_key($destination);if($id<=0||$destination==='')return false;
        return $wpdb->update(self::table(),['pending_university'=>$destination,'transfer_requested_at_utc'=>$requested_at_utc,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s','%s'],['%d']);
    }
    public static function clear_transfer(int $id){ global $wpdb;
        if($id<=0)return false;
        return $wpdb->update(self::table(),['pending_university'=>'','transfer_requested_at_utc'=>null,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s','%s'],['%d']);
    }
    /** Caller must hold a transaction/row lock. Destination approval is the verification boundary. */
    public static function approve_transfer_locked(int $id,string $from,string $destination){ global $wpdb;
        $destination=sanitize_key($destination);$from=sanitize_key($from);if($id<=0||$destination===''||$from===''||$destination===$from)return false;
        return $wpdb->update(self::table(),['university'=>$destination,'pending_university'=>'','transfer_requested_at_utc'=>null,'verified'=>1,'updated_at'=>current_time('mysql')],['id'=>$id,'university'=>$from,'pending_university'=>$destination],['%s','%s','%s','%d','%s'],['%d','%s','%s']);
    }
    public static function pending_transfer_count(string $destination): int { global $wpdb;
        $destination=sanitize_key($destination);if($destination==='')return 0;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table()." WHERE pending_university=%s AND university<>%s",$destination,$destination));
    }
    public static function pending_transfer_search(string $destination,string $query='',int $limit=100,bool $allow_sensitive=false): array { global $wpdb;
        $destination=sanitize_key($destination);if($destination==='')return [];$limit=max(1,min(500,$limit));$query=trim($query);
        $fields='id,first_name,last_name,mobile,national_id,member_role,university,pending_university,transfer_requested_at_utc,verified,consent';
        if($query==='')return (array)$wpdb->get_results($wpdb->prepare('SELECT '.$fields.' FROM '.self::table().' WHERE pending_university=%s AND university<>%s ORDER BY transfer_requested_at_utc ASC,id ASC LIMIT %d',$destination,$destination,$limit));
        $like='%'.$wpdb->esc_like(QYS_Text_Normalizer::digits($query)).'%';
        if($allow_sensitive)return (array)$wpdb->get_results($wpdb->prepare('SELECT '.$fields.' FROM '.self::table().' WHERE pending_university=%s AND university<>%s AND (first_name LIKE %s OR last_name LIKE %s OR national_id LIKE %s OR mobile LIKE %s) ORDER BY transfer_requested_at_utc ASC,id ASC LIMIT %d',$destination,$destination,$like,$like,$like,$like,$limit));
        return (array)$wpdb->get_results($wpdb->prepare('SELECT '.$fields.' FROM '.self::table().' WHERE pending_university=%s AND university<>%s AND (first_name LIKE %s OR last_name LIKE %s) ORDER BY transfer_requested_at_utc ASC,id ASC LIMIT %d',$destination,$destination,$like,$like,$limit));
    }
    public static function eligible_delivery_member(int $id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT id,eitaa_id,university FROM '.self::table().' WHERE id=%d AND consent=1 AND verified=1',$id));
    }
    public static function welcome_member(int $id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT id,eitaa_id,first_name,last_name,university,consent FROM '.self::table().' WHERE id=%d',$id));
    }
    public static function university_counts(string $university): array { global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT COUNT(*) total,COALESCE(SUM(verified=1),0) verified,COALESCE(SUM(verified=0),0) pending,COALESCE(SUM(verified=1 AND consent=1),0) eligible,COALESCE(SUM(consent=0),0) messaging_off FROM '.self::table().' WHERE university=%s',$university),ARRAY_A);
        return [
            'total'=>(int)($row['total']??0),
            'verified'=>(int)($row['verified']??0),
            'pending'=>(int)($row['pending']??0),
            'eligible'=>(int)($row['eligible']??0),
            'messaging_off'=>(int)($row['messaging_off']??0),
        ];
    }
    public static function admin_list(string $university='',int $limit=100): array {
        return self::admin_search($university,'all','',$limit);
    }
    public static function admin_search(string $university='',string $status='all',string $query='',int $limit=200): array { global $wpdb;
        $limit=max(1,min(500,$limit));
        $where=['1=1'];$args=[];
        if($university!==''){$where[]='university=%s';$args[]=$university;}
        if($status==='eligible')$where[]='verified=1 AND consent=1';
        elseif($status==='stopped')$where[]='consent=0';
        elseif($status==='pending')$where[]='verified=0';
        elseif($status==='verified')$where[]='verified=1';
        elseif($status==='transfer')$where[]="pending_university<>''";
        $query=trim(QYS_Text_Normalizer::digits($query));
        if($query!==''){
            $like='%'.$wpdb->esc_like($query).'%';
            $where[]='(first_name LIKE %s OR last_name LIKE %s OR national_id LIKE %s OR mobile LIKE %s)';
            array_push($args,$like,$like,$like,$like);
        }
        $sql='SELECT * FROM '.self::table().' WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT %d';
        $args[]=$limit;
        return (array)$wpdb->get_results($wpdb->prepare($sql,...$args));
    }
    public static function university_search(string $university,string $query,int $limit=100,bool $allow_sensitive=false): array { global $wpdb;
        $limit=max(1,min(500,$limit));
        $query=trim($query);
        if($query===''){
            return (array)$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name,mobile,national_id,member_role,verified,consent FROM '.self::table().' WHERE university=%s ORDER BY id DESC LIMIT %d',$university,$limit));
        }
        $like='%'.$wpdb->esc_like(QYS_Text_Normalizer::digits($query)).'%';
        if($allow_sensitive){
            return (array)$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name,mobile,national_id,member_role,verified,consent FROM '.self::table().' WHERE university=%s AND (first_name LIKE %s OR last_name LIKE %s OR national_id LIKE %s OR mobile LIKE %s) ORDER BY id DESC LIMIT %d',$university,$like,$like,$like,$like,$limit));
        }
        return (array)$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name,mobile,national_id,member_role,verified,consent FROM '.self::table().' WHERE university=%s AND (first_name LIKE %s OR last_name LIKE %s) ORDER BY id DESC LIMIT %d',$university,$like,$like,$limit));
    }
    public static function targeted_search(string $query,array $universities,int $limit=12,bool $allow_sensitive=false): array { global $wpdb;
        $universities=array_values(array_filter(array_map('sanitize_key',$universities)));
        if(!$universities)return [];
        $query=QYS_Text_Normalizer::digits($query);$like='%'.$wpdb->esc_like($query).'%';
        $university_where=$wpdb->prepare('university IN ('.implode(',',array_fill(0,count($universities),'%s')).')',...$universities);
        $limit=max(1,min(50,$limit));
        $fields=$allow_sensitive?'id,first_name,last_name,member_role,mobile,university':'id,first_name,last_name,member_role,university';
        if($allow_sensitive){
            return (array)$wpdb->get_results($wpdb->prepare('SELECT '.$fields.' FROM '.self::table()." WHERE $university_where AND consent=1 AND verified=1 AND (first_name LIKE %s OR last_name LIKE %s OR national_id LIKE %s OR mobile LIKE %s) ORDER BY id DESC LIMIT %d",$like,$like,$like,$like,$limit));
        }
        return (array)$wpdb->get_results($wpdb->prepare('SELECT '.$fields.' FROM '.self::table()." WHERE $university_where AND consent=1 AND verified=1 AND (first_name LIKE %s OR last_name LIKE %s) ORDER BY id DESC LIMIT %d",$like,$like,$limit));
    }
    public static function targeted_members(string $audience,array $chosen,array $groups,array $universities,int $max_selected,int $max_recipients){ global $wpdb;
        $universities=array_values(array_filter(array_map('sanitize_key',$universities)));
        if(!$universities)return null;
        $campus_where=$wpdb->prepare('university IN ('.implode(',',array_fill(0,count($universities),'%s')).')',...$universities);
        if($audience==='selected'){
            $ids=array_values(array_unique(array_filter(array_map('absint',$chosen))));
            if(!$ids||count($ids)>$max_selected)return null;
            $placeholders=implode(',',array_fill(0,count($ids),'%d'));
            $rows=$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name,member_role FROM '.self::table()." WHERE verified=1 AND consent=1 AND $campus_where AND id IN ($placeholders) ORDER BY id",...$ids));
            return is_array($rows)&&count($rows)===count($ids)?$rows:null;
        }
        $where='verified=1 AND consent=1 AND '.$campus_where;
        if($audience==='groups'){
            if(!$groups)return null;
            $where.=$wpdb->prepare(' AND member_role IN ('.implode(',',array_fill(0,count($groups),'%s')).')',...$groups);
        }elseif($audience!=='all'){
            if(!isset(QYS_Messenger::member_roles()[$audience]))return null;
            $where.=$wpdb->prepare(' AND member_role=%s',$audience);
        }
        $rows=$wpdb->get_results('SELECT id,first_name,last_name,member_role FROM '.self::table()." WHERE $where ORDER BY id LIMIT ".($max_recipients+1));
        return !is_array($rows)||count($rows)>$max_recipients?null:$rows;
    }
    public static function match_national_ids(string $university,array $national_ids): array { global $wpdb;
        $ids=array_values(array_filter(array_unique(array_map('strval',$national_ids))));
        if(!$ids)return [];
        $placeholders=implode(',',array_fill(0,count($ids),'%s'));
        return (array)$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name,mobile,verified,consent,national_id FROM '.self::table()." WHERE university=%s AND national_id IN ($placeholders)",...array_merge([$university],$ids)));
    }
    public static function readiness_counts(): array { global $wpdb;
        $out=['total'=>0,'enabled'=>0,'verified'=>0,'eligible'=>0,'disabled'=>0,'complete'=>0,'birthdays'=>0];
        if(!self::table_exists())return $out;
        $row=$wpdb->get_row("SELECT COUNT(*) AS total,COALESCE(SUM(consent=1),0) AS enabled,COALESCE(SUM(verified=1),0) AS verified,COALESCE(SUM(consent=1 AND verified=1),0) AS eligible,COALESCE(SUM(consent=0),0) AS disabled FROM ".self::table(),ARRAY_A);
        if(is_array($row))foreach(['total','enabled','verified','eligible','disabled'] as $k)$out[$k]=max(0,(int)($row[$k]??0));
        if(self::schema_ready()){
            $family_clause=self::family_complete_sql();
            $row=$wpdb->get_row("SELECT COALESCE(SUM(consent=1 AND member_role IN ('staff','faculty','student','staff_family') AND university<>'' AND first_name<>'' AND last_name<>'' AND CHAR_LENGTH(national_id)=10 AND mobile REGEXP '^09[0-9]{9}$' AND (mobile_verification_required=0 OR mobile_verified_at_utc IS NOT NULL) AND birth_jalali<>''{$family_clause}),0) AS complete,COALESCE(SUM(birthday_opt_in=1),0) AS birthdays FROM ".self::table(),ARRAY_A);
            if(is_array($row)){$out['complete']=(int)($row['complete']??0);$out['birthdays']=(int)($row['birthdays']??0);}
        }
        return $out;
    }

    private static function family_complete_sql(string $alias=''): string {
        $prefix=$alias!==''?rtrim($alias,'.').'.':self::table().'.';
        $family=QYS_Table_Names::name('family_links');
        return " AND ({$prefix}member_role<>'staff_family' OR EXISTS (SELECT 1 FROM {$family} qyf JOIN ".self::table()." qyfs ON qyfs.id=qyf.staff_member_id WHERE qyf.member_id={$prefix}id AND qyf.university={$prefix}university AND qyfs.university=qyf.university AND qyfs.verified=1 AND qyfs.member_role IN ('staff','faculty')))";
    }

    /* Attendance read models live here so Attendance never reaches into member storage. */
    public static function attendance_eligible(string $university,array $role_groups): array { global $wpdb;
        $where=$wpdb->prepare('verified=1 AND consent=1 AND university=%s',$university);
        if($role_groups)$where.=$wpdb->prepare(' AND member_role IN ('.implode(',',array_fill(0,count($role_groups),'%s')).')',...$role_groups);
        $where.=' AND (mobile_verification_required=0 OR mobile_verified_at_utc IS NOT NULL)';
        $where.=self::family_complete_sql();
        return (array)$wpdb->get_results('SELECT id,first_name,last_name,member_role,mobile FROM '.self::table()." WHERE $where ORDER BY last_name,first_name");
    }
    public static function attendance_records_join(string $records_table,int $session_id): array { global $wpdb;
        $records_table=self::identifier($records_table);
        return (array)$wpdb->get_results($wpdb->prepare('SELECT r.*,m.first_name,m.last_name,m.member_role,m.mobile FROM '.$records_table.' r JOIN '.self::table().' m ON m.id=r.member_id WHERE r.session_id=%d ORDER BY r.checked_in_at_iran',$session_id));
    }
    public static function attendance_audit_join(string $audit_table,int $session_id,int $limit=40): array { global $wpdb;
        $audit_table=self::identifier($audit_table);
        return (array)$wpdb->get_results($wpdb->prepare('SELECT a.*,m.first_name,m.last_name FROM '.$audit_table.' a LEFT JOIN '.self::table().' m ON m.id=a.member_id WHERE a.session_id=%d ORDER BY a.id DESC LIMIT %d',$session_id,max(1,min(200,$limit))));
    }
    public static function attendance_export_chunk(string $records_table,int $session_id,string $university,array $role_groups,int $size,int $offset): array { global $wpdb;
        $records_table=self::identifier($records_table);
        $where=$wpdb->prepare('m.verified=1 AND m.consent=1 AND m.university=%s',$university);
        if($role_groups)$where.=$wpdb->prepare(' AND m.member_role IN ('.implode(',',array_fill(0,count($role_groups),'%s')).')',...$role_groups);
        $where.=' AND (m.mobile_verification_required=0 OR m.mobile_verified_at_utc IS NOT NULL)';
        $where.=self::family_complete_sql('m');
        $sql='SELECT m.first_name,m.last_name,m.member_role,m.mobile,m.national_id,r.status,r.checked_in_at_iran,r.checked_out_at_iran,r.checked_in_at_utc,r.checked_out_at_utc,r.method,r.exit_method FROM '.self::table().' m LEFT JOIN '.$records_table.' r ON r.member_id=m.id AND r.session_id=%d WHERE '.$where.' ORDER BY m.id LIMIT %d OFFSET %d';
        return (array)$wpdb->get_results($wpdb->prepare($sql,$session_id,$size,$offset));
    }

    /** Phase D / D8: keyset chunk for attendance CSV export. */
    public static function attendance_export_chunk_after(string $records_table,int $session_id,string $university,array $role_groups,int $after_member_id,int $size=250): array { global $wpdb;
        $records_table=self::identifier($records_table);
        $where=$wpdb->prepare('m.verified=1 AND m.consent=1 AND m.university=%s AND m.id>%d',$university,max(0,$after_member_id));
        if($role_groups)$where.=$wpdb->prepare(' AND m.member_role IN ('.implode(',',array_fill(0,count($role_groups),'%s')).')',...$role_groups);
        $where.=' AND (m.mobile_verification_required=0 OR m.mobile_verified_at_utc IS NOT NULL)';
        $where.=self::family_complete_sql('m');
        $sql='SELECT m.id member_id,m.first_name,m.last_name,m.member_role,m.mobile,m.national_id,r.status,r.checked_in_at_iran,r.checked_out_at_iran,r.checked_in_at_utc,r.checked_out_at_utc,r.method,r.exit_method FROM '.self::table().' m LEFT JOIN '.$records_table.' r ON r.member_id=m.id AND r.session_id=%d WHERE '.$where.' ORDER BY m.id ASC LIMIT %d';
        return (array)$wpdb->get_results($wpdb->prepare($sql,$session_id,max(1,min(500,$size))));
    }
    public static function names_by_ids(array $ids): array { global $wpdb;
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids)return [];
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        $rows=(array)$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name FROM '.self::table()." WHERE id IN ($ph)",...$ids));
        $out=[];foreach($rows as $r)$out[(int)$r->id]=$r;return $out;
    }
    public static function followup_contacts_by_ids(array $ids): array { global $wpdb;
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids)return [];
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        $rows=(array)$wpdb->get_results($wpdb->prepare('SELECT id,first_name,last_name,mobile,national_id,verified,consent FROM '.self::table()." WHERE id IN ($ph)",...$ids));
        $out=[];foreach($rows as $r)$out[(int)$r->id]=$r;return $out;
    }

    public static function birthday_count_enabled_campuses(string $settings_table,string $suffix): int { global $wpdb;
        $settings_table=self::identifier($settings_table);$md=self::birthday_md_from_suffix($suffix);if($md==='')return 0;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table()." m JOIN $settings_table b ON b.university=m.university AND b.enabled=1 WHERE m.birth_md=%s AND m.birthday_opt_in=1 AND m.verified=1 AND m.consent=1",$md));
    }
    public static function birthday_members_after(string $suffix,int $after_id,int $limit=100): array { global $wpdb;
        $md=self::birthday_md_from_suffix($suffix);if($md==='')return [];
        return (array)$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE birth_md=%s AND birthday_opt_in=1 AND verified=1 AND consent=1 AND id>%d ORDER BY id ASC LIMIT %d",$md,$after_id,max(1,min(500,$limit))));
    }
    public static function birthday_complete_count_for_university(string $university,string $suffix): int { global $wpdb;
        $md=self::birthday_md_from_suffix($suffix);if($md==='')return 0;
        $where="birth_md=%s AND birthday_opt_in=1 AND verified=1 AND consent=1 AND university=%s AND member_role IN ('staff','faculty','student','staff_family') AND first_name<>'' AND last_name<>'' AND CHAR_LENGTH(national_id)=10 AND mobile REGEXP '^09[0-9]{9}$' AND (mobile_verification_required=0 OR mobile_verified_at_utc IS NOT NULL)".self::family_complete_sql();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table()." WHERE {$where}",$md,$university));
    }
    public static function birthday_pending_batch(string $log_table,string $scheduled_on,int $after_id,int $limit=100): array { global $wpdb;
        $log_table=self::identifier($log_table);
        return (array)$wpdb->get_results($wpdb->prepare("SELECT l.id,l.member_id,l.scheduled_on,m.university FROM $log_table l JOIN ".self::table()." m ON m.id=l.member_id WHERE l.status='pending' AND l.scheduled_on=%s AND l.id>%d ORDER BY l.id LIMIT %d",$scheduled_on,$after_id,max(1,min(500,$limit))));
    }
    public static function birthday_pending_count_for_university(string $log_table,string $university,string $scheduled_on): int { global $wpdb;
        $log_table=self::identifier($log_table);
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $log_table l JOIN ".self::table()." m ON m.id=l.member_id WHERE m.university=%s AND l.status='pending' AND l.scheduled_on=%s",$university,$scheduled_on));
    }
    public static function cancel_pending_birthday_for_university(string $log_table,string $university): bool { global $wpdb;
        $log_table=self::identifier($log_table);
        $changed=$wpdb->query($wpdb->prepare("UPDATE $log_table l INNER JOIN ".self::table()." m ON m.id=l.member_id SET l.status='cancelled',l.updated_at=%s WHERE m.university=%s AND l.status='pending'",current_time('mysql'),$university));
        return $changed!==false;
    }
    public static function cancel_pending_birthday_for_member(string $log_table,int $member_id): bool { global $wpdb;
        $log_table=self::identifier($log_table);
        if($member_id<=0)return false;
        $changed=$wpdb->update($log_table,['status'=>'cancelled','updated_at'=>current_time('mysql')],['member_id'=>$member_id,'status'=>'pending'],['%s','%s'],['%d','%s']);
        return $changed!==false;
    }

}
