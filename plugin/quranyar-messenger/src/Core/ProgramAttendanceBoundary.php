<?php
if (!defined('ABSPATH')) exit;

/**
 * Narrow internal contract between Programs and Attendance (Phase C / C5).
 *
 * Programs depend only on this port. The Attendance module supplies the adapter
 * and may depend on Programs. This makes the dependency direction one-way:
 *
 *   Attendance -> Programs
 *   Programs   -> ProgramAttendanceBoundary (core contract)
 *
 * No public plugin SDK is introduced here; platform-level event contracts remain
 * a later Phase G concern.
 */
interface QYS_Program_Attendance_Port {
    public function schema_ready(): bool;
    public function diagnostic_schema_ready(): bool;
    public function terminalize_core_program(int $program_id): bool;
    public function create_for_core_program(int $program_id,int $actor_id=0);
    public function get_attendance_program(int $attendance_program_id);
    /** @return array{sessions:int,completed_sessions:int,minutes:int,open_records:int} */
    public function member_summary(int $program_id,int $member_id): array;
    public function backfill_legacy_programs(): bool;
}

final class QYS_Program_Attendance_Boundary {
    private static ?QYS_Program_Attendance_Port $adapter=null;

    public static function register(QYS_Program_Attendance_Port $adapter): void {
        if(self::$adapter!==null && get_class(self::$adapter)!==get_class($adapter)){
            throw new RuntimeException('QuranYar program-attendance adapter already registered');
        }
        self::$adapter=$adapter;
    }

    public static function registered(): bool { return self::$adapter!==null; }
    public static function schema_ready(): bool { return self::$adapter!==null && self::$adapter->schema_ready(); }
    public static function diagnostic_schema_ready(): bool { return self::$adapter!==null && self::$adapter->diagnostic_schema_ready(); }

    public static function terminalize_core_program(int $program_id): bool {
        return self::$adapter!==null && self::$adapter->terminalize_core_program($program_id);
    }

    public static function create_for_core_program(int $program_id,int $actor_id=0){
        if(self::$adapter===null)return new WP_Error('attendance_unavailable','ماژول حضور و غیاب موقتاً در دسترس نیست.',['status'=>503]);
        return self::$adapter->create_for_core_program($program_id,$actor_id);
    }

    public static function get_attendance_program(int $attendance_program_id){
        return self::$adapter!==null?self::$adapter->get_attendance_program($attendance_program_id):null;
    }

    /** @return array{sessions:int,completed_sessions:int,minutes:int,open_records:int} */
    public static function member_summary(int $program_id,int $member_id): array {
        if(self::$adapter===null)return self::empty_summary();
        $row=self::$adapter->member_summary($program_id,$member_id);
        return [
            'sessions'=>max(0,(int)($row['sessions']??0)),
            'completed_sessions'=>max(0,(int)($row['completed_sessions']??0)),
            'minutes'=>max(0,(int)($row['minutes']??0)),
            'open_records'=>max(0,(int)($row['open_records']??0)),
        ];
    }

    public static function backfill_legacy_programs(): bool {
        return self::$adapter!==null && self::$adapter->backfill_legacy_programs();
    }

    /** @return array{ready:bool,code:string} */
    public static function health(): array {
        // Lifecycle health checks the wiring contract only. Physical schema
        // readiness remains owned by Attendance diagnostics/migrations.
        return self::$adapter!==null
            ?['ready'=>true,'code'=>'ok']
            :['ready'=>false,'code'=>'attendance_adapter_missing'];
    }

    /** @return array{sessions:int,completed_sessions:int,minutes:int,open_records:int} */
    private static function empty_summary(): array {
        return ['sessions'=>0,'completed_sessions'=>0,'minutes'=>0,'open_records'=>0];
    }
}
