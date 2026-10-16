<?php
if (!defined('ABSPATH')) exit;

/** Attendance-owned adapter for the core Program/Attendance port. */
final class QYS_Attendance_Program_Adapter implements QYS_Program_Attendance_Port {
    public function schema_ready(): bool {
        return QYS_Attendance_Programs::schema_ready();
    }
    public function diagnostic_schema_ready(): bool {
        return QYS_Attendance_Programs::diagnostic_schema_ready();
    }

    public function terminalize_core_program(int $program_id): bool {
        return QYS_Attendance_Programs::terminalize_core_program($program_id);
    }

    public function create_for_core_program(int $program_id,int $actor_id=0){
        return QYS_Attendance_Programs::create_for_core_program($program_id,$actor_id);
    }

    public function get_attendance_program(int $attendance_program_id){
        return QYS_Attendance_Programs::get($attendance_program_id);
    }

    public function member_summary(int $program_id,int $member_id): array { global $wpdb;
        if($program_id<=0||$member_id<=0||!$this->schema_ready()){
            return ['sessions'=>0,'completed_sessions'=>0,'minutes'=>0,'open_records'=>0];
        }
        $links=QYS_Program_Repository::attendance_links_table();
        $sessions=QYS_Attendance::sessions_table();
        $records=QYS_Attendance::records_table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(r.id) sessions,COALESCE(SUM(r.checked_out_at_utc IS NOT NULL),0) completed_sessions,COALESCE(SUM(CASE WHEN r.checked_out_at_utc IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,r.checked_in_at_utc,r.checked_out_at_utc) ELSE 0 END),0) minutes,COALESCE(SUM(r.checked_out_at_utc IS NULL),0) open_records FROM $links l JOIN $sessions s ON s.program_id=l.attendance_program_id AND s.session_mode='dynamic' JOIN $records r ON r.session_id=s.id AND r.status='present' WHERE l.program_id=%d AND r.member_id=%d",
            $program_id,$member_id
        ),ARRAY_A);
        return [
            'sessions'=>(int)($row['sessions']??0),
            'completed_sessions'=>(int)($row['completed_sessions']??0),
            'minutes'=>(int)($row['minutes']??0),
            'open_records'=>(int)($row['open_records']??0),
        ];
    }

    /** Legacy fresh-install/backfill bridge, now owned by Attendance rather than Programs. */
    public function backfill_legacy_programs(): bool { global $wpdb;
        if(!QYS_Program_Repository::diagnostic_schema_ready()||!QYS_Attendance_Programs::diagnostic_schema_ready())return false;
        $table=QYS_Attendance_Programs::table();
        $rows=(array)$wpdb->get_results("SELECT * FROM $table WHERE core_program_id=0 ORDER BY id ASC LIMIT 500");
        foreach($rows as $row){
            $wpdb->query('START TRANSACTION');
            $id=QYS_Program_Service::create_internal([
                'manager_university'=>(string)$row->university,
                'universities'=>[(string)$row->university],
                'title'=>(string)$row->title,
                'program_type'=>'recurring_session',
                'status'=>'running',
                'audience_roles'=>(string)$row->role_groups,
                'registration_mode'=>'none',
                'attendance_enabled'=>1,
            ],(int)$row->created_by,false);
            if(is_wp_error($id)){$wpdb->query('ROLLBACK');return false;}
            $linked=QYS_Program_Service::link_attendance((int)$id,(int)$row->id,(string)$row->university,(int)$row->created_by,false);
            if(is_wp_error($linked)){$wpdb->query('ROLLBACK');return false;}
            if($wpdb->update($table,['core_program_id'=>(int)$id],['id'=>(int)$row->id],['%d'],['%d'])===false){
                $wpdb->query('ROLLBACK');return false;
            }
            $wpdb->query('COMMIT');
        }
        return true;
    }
}
