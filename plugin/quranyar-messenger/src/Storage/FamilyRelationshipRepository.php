<?php
if (!defined('ABSPATH')) exit;

/**
 * Canonical storage boundary for family attribution between QuranYar members.
 *
 * The relationship is intentionally independent from member_role. A person can
 * be a staff/faculty member and at the same time be the child/spouse of another
 * employee. This avoids collapsing institutional role and family relationship
 * into one field.
 */
final class QYS_Family_Relationship_Repository {
    public const TYPES=['spouse'=>'همسر','child'=>'فرزند'];

    private static function table(): string { return QYS_Table_Names::name('family_links'); }
    private static function members_table(): string { return QYS_Table_Names::name('members'); }
    private static function identifier(string $table): string {
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new InvalidArgumentException('Unsafe table identifier.');
        return $table;
    }

    public static function schema_ready(): bool { return QYS_Schema_Runtime::ready(); }

    public static function diagnostic_schema_ready(): bool {
        return QYS_Table_Names::diagnostic_has_columns(self::table(),[
            'id','member_id','staff_member_id','relation_type','university','created_at','updated_at'
        ]);
    }

    public static function ensure_schema(): bool {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $table=self::identifier(self::table());
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint unsigned NOT NULL,
            staff_member_id bigint unsigned NOT NULL,
            relation_type varchar(16) NOT NULL,
            university varchar(40) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY member_staff_scope (member_id,staff_member_id,university),
            KEY member_scope (member_id,university),
            KEY staff_scope (staff_member_id,university),
            KEY relation_scope (relation_type,university)
        ) {$c};");
        QYS_Table_Names::clear_diagnostic_cache($table);
        return self::diagnostic_schema_ready();
    }

    /** Employee/faculty target lookup without public enumeration. */
    public static function staff_by_national_id(string $national_id,string $university,int $exclude_member_id=0){
        global $wpdb;
        $national_id=trim($national_id);$university=sanitize_key($university);
        if($national_id===''||$university==='')return null;
        $sql="SELECT id,first_name,last_name,member_role,university FROM ".self::members_table()." WHERE national_id=%s AND university=%s AND verified=1 AND member_role IN ('staff','faculty')";
        $args=[$national_id,$university];
        if($exclude_member_id>0){$sql.=' AND id<>%d';$args[]=$exclude_member_id;}
        $sql.=' LIMIT 1';
        return $wpdb->get_row($wpdb->prepare($sql,...$args));
    }

    public static function staff_by_id(int $staff_member_id,string $university,int $exclude_member_id=0){
        global $wpdb;
        $university=sanitize_key($university);if($staff_member_id<=0||$university==='')return null;
        $sql="SELECT id,first_name,last_name,member_role,university FROM ".self::members_table()." WHERE id=%d AND university=%s AND verified=1 AND member_role IN ('staff','faculty')";
        $args=[$staff_member_id,$university];
        if($exclude_member_id>0){$sql.=' AND id<>%d';$args[]=$exclude_member_id;}
        $sql.=' LIMIT 1';
        return $wpdb->get_row($wpdb->prepare($sql,...$args));
    }

    /** Outgoing links owned by this profile in one university scope. */
    public static function relations_for_member_scope(int $member_id,string $university): array {
        global $wpdb;
        $university=sanitize_key($university);if($member_id<=0||$university==='')return [];
        $sql='SELECT l.staff_member_id,l.relation_type,s.first_name staff_first_name,s.last_name staff_last_name,s.member_role staff_role '
            .'FROM '.self::table().' l JOIN '.self::members_table().' s ON s.id=l.staff_member_id '
            ."WHERE l.member_id=%d AND l.university=%s AND s.university=l.university AND s.verified=1 AND s.member_role IN ('staff','faculty') ORDER BY l.id ASC";
        $rows=(array)$wpdb->get_results($wpdb->prepare($sql,$member_id,$university));
        $out=[];
        foreach($rows as $r){
            $type=sanitize_key((string)($r->relation_type??''));if(!isset(self::TYPES[$type]))continue;
            $out[]=[
                'staff_member_id'=>(int)$r->staff_member_id,
                'relation_type'=>$type,
                'relation_label'=>self::TYPES[$type],
                'staff_name'=>trim((string)$r->staff_first_name.' '.(string)$r->staff_last_name),
                'staff_role'=>(string)$r->staff_role,
            ];
        }
        return $out;
    }

    public static function has_relation_for_member_scope(int $member_id,string $university): bool {
        global $wpdb;
        $university=sanitize_key($university);if($member_id<=0||$university==='')return false;
        $sql="SELECT l.id FROM ".self::table()." l JOIN ".self::members_table()." s ON s.id=l.staff_member_id WHERE l.member_id=%d AND l.university=%s AND s.university=l.university AND s.verified=1 AND s.member_role IN ('staff','faculty') LIMIT 1";
        return (bool)$wpdb->get_var($wpdb->prepare($sql,$member_id,$university));
    }

    /** Caller owns transaction. Replaces only one university scope. */
    public static function replace_for_member_scope(int $member_id,string $university,array $relations): bool {
        global $wpdb;
        $university=sanitize_key($university);if($member_id<=0||$university==='')return false;
        if($wpdb->delete(self::table(),['member_id'=>$member_id,'university'=>$university],['%d','%s'])===false)return false;
        $now=current_time('mysql');
        foreach($relations as $relation){
            $staff_id=absint($relation['staff_member_id']??0);$type=sanitize_key((string)($relation['relation_type']??''));
            if($staff_id<=0||!isset(self::TYPES[$type])||!self::staff_by_id($staff_id,$university,$member_id))return false;
            $ok=$wpdb->insert(self::table(),[
                'member_id'=>$member_id,'staff_member_id'=>$staff_id,'relation_type'=>$type,'university'=>$university,
                'created_at'=>$now,'updated_at'=>$now,
            ],['%d','%d','%s','%s','%s','%s']);
            if($ok!==1)return false;
        }
        return true;
    }

    /** Remove stale relationship scopes after transfer cancellation/completion. */
    public static function prune_member_scopes(int $member_id,array $keep_scopes): bool {
        global $wpdb;
        $keep=array_values(array_unique(array_filter(array_map('sanitize_key',$keep_scopes))));
        if($member_id<=0)return false;
        if(!$keep)return $wpdb->delete(self::table(),['member_id'=>$member_id],['%d'])!==false;
        $ph=implode(',',array_fill(0,count($keep),'%s'));
        $sql='DELETE FROM '.self::table().' WHERE member_id=%d AND university NOT IN ('.$ph.')';
        return $wpdb->query($wpdb->prepare($sql,...array_merge([$member_id],$keep)))!==false;
    }

    /** Batch admin/campus summary in both directions; no N+1 queries. */
    public static function summaries_for_members(array $member_ids,string $university=''): array {
        global $wpdb;
        $ids=array_values(array_unique(array_filter(array_map('absint',$member_ids))));if(!$ids)return [];
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        $sql='SELECT l.member_id,l.staff_member_id,l.relation_type,l.university,'
            .'m.first_name member_first,m.last_name member_last,s.first_name staff_first,s.last_name staff_last '
            .'FROM '.self::table().' l JOIN '.self::members_table().' m ON m.id=l.member_id JOIN '.self::members_table().' s ON s.id=l.staff_member_id '
            ."WHERE (l.member_id IN ({$ph}) OR l.staff_member_id IN ({$ph})) AND s.university=l.university AND s.verified=1 AND s.member_role IN ('staff','faculty')";
        $args=array_merge($ids,$ids);$university=sanitize_key($university);
        if($university!==''){$sql.=' AND l.university=%s';$args[]=$university;}
        $sql.=' ORDER BY l.id ASC';
        $rows=(array)$wpdb->get_results($wpdb->prepare($sql,...$args));
        $wanted=array_fill_keys($ids,true);$out=[];
        foreach($rows as $r){
            $type=sanitize_key((string)$r->relation_type);if(!isset(self::TYPES[$type]))continue;
            $member_id=(int)$r->member_id;$staff_id=(int)$r->staff_member_id;
            $member_name=trim((string)$r->member_first.' '.(string)$r->member_last);
            $staff_name=trim((string)$r->staff_first.' '.(string)$r->staff_last);
            if(isset($wanted[$member_id])){
                $label=$type==='spouse'?'همسر: '.$staff_name:'فرزندِ '.$staff_name;
                $out[$member_id][$label]=true;
            }
            if(isset($wanted[$staff_id])){
                $label=$type==='spouse'?'همسر: '.$member_name:'فرزند: '.$member_name;
                $out[$staff_id][$label]=true;
            }
        }
        $final=[];foreach($out as $id=>$labels)$final[$id]=implode('؛ ',array_keys($labels));return $final;
    }

    public static function contract(): array {
        return [
            'ready'=>self::schema_ready(),
            'model'=>'relation_graph',
            'role_independent'=>true,
            'staff_family'=>'required',
            'employee_overlap'=>'allowed',
            'multiple_parents'=>'allowed',
            'types'=>'spouse_child',
            'scope'=>'university',
            'lookup'=>'exact_national_id_or_existing_link',
        ];
    }
}
