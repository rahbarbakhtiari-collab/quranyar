<?php
if (!defined('ABSPATH')) exit;

/** Canonical storage boundary for universities and representative assignments. */
final class QYS_University_Repository {
    private static function universities(): string { return QYS_Table_Names::name('universities'); }
    private static function representatives(): string { return QYS_Table_Names::name('university_reps'); }

    public static function begin(): bool { global $wpdb; return $wpdb->query('START TRANSACTION')!==false; }
    public static function commit(): bool { global $wpdb; return $wpdb->query('COMMIT')!==false; }
    public static function rollback(): bool { global $wpdb; return $wpdb->query('ROLLBACK')!==false; }
    public static function insert_audit(string $audit_table,array $data): bool { global $wpdb;
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$audit_table))return false;
        return $wpdb->insert($audit_table,$data,['%d','%s','%s','%d','%s'])===1;
    }

    public static function schema_ready(): bool { return QYS_Schema_Runtime::ready(); }
    public static function diagnostic_schema_ready(): bool {
        return QYS_Table_Names::diagnostic_has_columns(self::universities(),['code','title','status'])
            && QYS_Table_Names::diagnostic_has_columns(self::representatives(),['id','user_id','university','status','permissions']);
    }
    public static function active(string $code): bool { global $wpdb;
        return $code!==''&&$wpdb->get_var($wpdb->prepare('SELECT status FROM '.self::universities().' WHERE code=%s',$code))==='active';
    }
    public static function list(bool $active_only=true): array { global $wpdb;
        return (array)$wpdb->get_results('SELECT code,title,status FROM '.self::universities().($active_only?" WHERE status='active'":'').' ORDER BY title',ARRAY_A);
    }
    public static function approved_assignments(int $user_id): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT r.*,u.title,u.status university_status FROM ".self::representatives()." r JOIN ".self::universities()." u ON u.code=r.university WHERE r.user_id=%d AND r.status='approved' AND u.status='active' ORDER BY r.id",$user_id),ARRAY_A);
    }
    public static function approved_permissions(int $user_id,string $university): string { global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT permissions FROM ".self::representatives()." WHERE user_id=%d AND university=%s AND status='approved' LIMIT 1",$user_id,$university));
    }
    public static function representatives_for_admin(int $limit=150): array { global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::representatives()." ORDER BY FIELD(status,'pending','approved','suspended','rejected'),id DESC LIMIT %d",max(1,min(500,$limit))));
    }
    public static function representative(int $id){ global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::representatives().' WHERE id=%d',$id));
    }
    public static function approved_other_count(int $user_id,int $exclude_id): int { global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::representatives()." WHERE user_id=%d AND status='approved' AND id<>%d",$user_id,$exclude_id));
    }
    /** Caller must have started a transaction. Locks every assignment row for this user. */
    public static function lock_user_assignments(int $user_id): bool { global $wpdb;
        if($user_id<=0)return false;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,status FROM '.self::representatives().' WHERE user_id=%d FOR UPDATE',$user_id),ARRAY_A);
        return is_array($rows);
    }
    public static function create_university(string $code,string $title,int $creator): bool { global $wpdb;
        return $wpdb->insert(self::universities(),['code'=>$code,'title'=>$title,'status'=>'active','created_by'=>$creator,'created_at_utc'=>gmdate('Y-m-d H:i:s')],['%s','%s','%s','%d','%s'])===1;
    }
    public static function create_nomination(int $user_id,string $code): bool { global $wpdb;
        return $wpdb->insert(self::representatives(),['user_id'=>$user_id,'university'=>$code,'status'=>'pending','permissions'=>'','updated_at_utc'=>gmdate('Y-m-d H:i:s')],['%d','%s','%s','%s','%s'])===1;
    }
    public static function upsert_pending(int $user_id,string $code): bool { global $wpdb;
        return $wpdb->query($wpdb->prepare("INSERT INTO ".self::representatives()." (user_id,university,status,permissions,updated_at_utc) VALUES (%d,%s,'pending','',%s) ON DUPLICATE KEY UPDATE status='pending',permissions='',approved_by=0,approved_at_utc=NULL,updated_at_utc=VALUES(updated_at_utc)",$user_id,$code,gmdate('Y-m-d H:i:s')))!==false;
    }
    public static function approve(int $id,string $permissions,int $approved_by,string $now){ global $wpdb;
        return $wpdb->update(self::representatives(),['status'=>'approved','permissions'=>$permissions,'approved_by'=>$approved_by,'approved_at_utc'=>$now,'updated_at_utc'=>$now],['id'=>$id],['%s','%s','%d','%s','%s'],['%d']);
    }
    public static function suspend(int $id,string $now){ global $wpdb;
        return $wpdb->update(self::representatives(),['status'=>'suspended','permissions'=>'','approved_by'=>0,'approved_at_utc'=>null,'updated_at_utc'=>$now],['id'=>$id],['%s','%s','%d','%s','%s'],['%d']);
    }
    public static function ensure_saveh(int $creator=0): bool { global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO ".self::universities()." (code,title,status,created_by,created_at_utc) VALUES (%s,%s,%s,%d,%s)",'saveh_ums','دانشگاه علوم پزشکی ساوه','active',$creator,gmdate('Y-m-d H:i:s')));
        return self::active('saveh_ums');
    }
    public static function upsert_owner_assignment(int $user_id,string $university,string $permissions,int $approver,string $now){ global $wpdb;
        return $wpdb->query($wpdb->prepare("INSERT INTO ".self::representatives()." (user_id,university,status,permissions,approved_by,approved_at_utc,updated_at_utc) VALUES (%d,%s,'approved',%s,%d,%s,%s) ON DUPLICATE KEY UPDATE status='approved',permissions=VALUES(permissions),approved_by=VALUES(approved_by),approved_at_utc=VALUES(approved_at_utc),updated_at_utc=VALUES(updated_at_utc)",$user_id,$university,$permissions,$approver,$now,$now));
    }
    /** Table names are exposed only for dbDelta schema creation inside Multitenant::ensure_schema. */
    public static function schema_tables(): array { return ['universities'=>self::universities(),'representatives'=>self::representatives()]; }
}
