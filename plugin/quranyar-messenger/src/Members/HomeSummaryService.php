<?php
if (!defined('ABSPATH')) exit;

/**
 * Lightweight member Home read model (Phase D / D3).
 *
 * The Home screen must never require the full Program application-form payload.
 * This service intentionally exposes only compact Program cards. Full Programs
 * and Attendance payloads keep their existing feature endpoints.
 */
final class QYS_Home_Summary_Service {
    private const TYPE_LABELS=['course'=>'کلاس / دوره','workshop'=>'کارگاه','event'=>'رویداد','competition'=>'مسابقه','camp'=>'اردو','recurring_session'=>'جلسه تکرارشونده','other'=>'سایر'];
    private const PROGRAM_LABELS=['registration_open'=>'ثبت‌نام باز','registration_closed'=>'ثبت‌نام بسته','confirmed'=>'قطعی‌شده','running'=>'در حال اجرا'];
    private const APP_LABELS=['submitted'=>'ثبت‌شده','under_review'=>'در حال بررسی','waitlisted'=>'لیست انتظار','accepted'=>'پذیرفته‌شده','rejected'=>'ردشده','withdrawn'=>'انصراف','closed'=>'بسته‌شده','cancelled'=>'لغوشده با برنامه'];
    private const ENROLL_LABELS=['enrolled'=>'ثبت‌نام قطعی','participating'=>'در حال شرکت','completed'=>'تکمیل‌شده','withdrawn'=>'انصراف','no_show'=>'عدم شرکت','cancelled'=>'لغوشده با برنامه','closed'=>'بسته‌شده'];

    public static function performance_contract(): array {
        return ['ready'=>true,'endpoint'=>'lightweight','programs'=>'compact','forms'=>'deferred','program_limit'=>3];
    }

    public static function rest(WP_REST_Request $req){
        $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
        $member=QYS_Member_Repository::find_by_eitaa_id((string)$user['id']);
        if(!$member)return rest_ensure_response(['ok'=>true,'eligible'=>false,'reason'=>'not_registered','programs'=>[]]);
        $profile_complete=QYS_Messenger::profile_complete($member);$verified=(int)$member->verified===1;
        if(!$profile_complete||!$verified)return rest_ensure_response(['ok'=>true,'eligible'=>false,'reason'=>$profile_complete?'not_verified':'profile_incomplete','programs'=>[]]);
        if(function_exists('nocache_headers'))nocache_headers();
        $items=[];
        foreach(QYS_Program_Repository::home_summaries_for_member($member,3) as $p){
            $description=trim(wp_strip_all_tags((string)$p->description));
            if(function_exists('mb_substr')&&function_exists('mb_strlen')&&mb_strlen($description,'UTF-8')>180)$description=mb_substr($description,0,177,'UTF-8').'…';
            elseif(strlen($description)>360)$description=substr($description,0,357).'...';
            $items[]=[
                'id'=>(int)$p->id,'title'=>(string)$p->title,'description'=>$description,
                'type'=>(string)$p->program_type,'type_label'=>self::TYPE_LABELS[(string)$p->program_type]??'برنامه',
                'status'=>(string)$p->status,'status_label'=>self::PROGRAM_LABELS[(string)$p->status]??(string)$p->status,
                'application_id'=>(int)($p->application_id??0),'application_status'=>(string)($p->application_status??''),
                'application_status_label'=>self::APP_LABELS[(string)($p->application_status??'')]??'',
                'enrollment_status'=>(string)($p->enrollment_status??''),'enrollment_status_label'=>self::ENROLL_LABELS[(string)($p->enrollment_status??'')]??'',
                'registration_open'=>(string)$p->status==='registration_open',
            ];
        }
        return rest_ensure_response(['ok'=>true,'eligible'=>true,'programs'=>$items,'generated_at_utc'=>gmdate('c')]);
    }
}
