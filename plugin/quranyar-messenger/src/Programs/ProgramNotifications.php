<?php
if (!defined('ABSPATH')) exit;

/**
 * Program applicant status notifications.
 *
 * Templates are configuration, not domain state. They live in non-autoload
 * WordPress options at a global messaging scope: one site-wide default set and
 * optional university-wide overrides. There is no per-Program template state.
 * Delivery reuses the canonical campaign/queue worker and its lease/state safety.
 */
final class QYS_Program_Notifications {
    private const OPTION_PREFIX='qys_program_notify_scope_';
    private const LEGACY_OPTION_PREFIX='qys_program_notify_';
    private const MAX_TEMPLATE=1200;
    private const EVENTS=['under_review','waitlisted','accepted','rejected','enrolled'];

    public static function contract(): array {
        return [
            'ready'=>true,
            'events'=>'review_waitlist_accept_reject_enroll',
            'queue'=>'canonical',
            'timing'=>'immediate_enqueue',
            'templates'=>'university_global_editable',
            'settings_scope'=>'all_programs',
            'variables'=>'member_program_status_university',
            'consent'=>'required',
            'editor'=>'collapsed_disclosure',
        ];
    }

    public static function labels(): array {
        return [
            'under_review'=>'در حال بررسی',
            'waitlisted'=>'فهرست انتظار',
            'accepted'=>'پذیرفته‌شده',
            'rejected'=>'ردشده',
            'enrolled'=>'ثبت‌نام قطعی',
        ];
    }

    public static function defaults(): array {
        return [
            'under_review'=>"سلام {نام} عزیز 🌱\nدرخواست شما برای «{برنامه}» در حال بررسی است.\nنتیجه بعدی از طریق قرآن‌یار اطلاع‌رسانی می‌شود.",
            'waitlisted'=>"سلام {نام} عزیز 🌱\nدرخواست شما برای «{برنامه}» در فهرست انتظار قرار گرفت.\nدر صورت آزادشدن ظرفیت، وضعیت جدید از طریق قرآن‌یار اعلام می‌شود.",
            'accepted'=>"سلام {نام} عزیز 🌱\nدرخواست شما برای «{برنامه}» پذیرفته شد.\nوضعیت فعلی: {وضعیت}",
            'rejected'=>"سلام {نام} عزیز 🌱\nوضعیت درخواست شما برای «{برنامه}» به «{وضعیت}» تغییر کرد.\nبرای پیگیری بیشتر می‌توانید با واحد برگزارکننده در ارتباط باشید.",
            'enrolled'=>"سلام {نام} عزیز 🌱\nثبت‌نام شما در «{برنامه}» قطعی شد.\nاطلاعیه‌های بعدی این برنامه از طریق قرآن‌یار اعلام می‌شود.",
        ];
    }

    private static function scope_key(string $university): string {
        $university=$university==='*'?'global':sanitize_key($university);
        return $university!==''?$university:'global';
    }

    private static function option_key(string $university): string {
        return self::OPTION_PREFIX.self::scope_key($university);
    }

    private static function raw_scope_settings(string $university): array {
        $raw=get_option(self::option_key($university),[]);
        return is_array($raw)?$raw:[];
    }

    /**
     * One template set applies to every Program. A university override wins over
     * the site-wide default; neither layer contains a Program id.
     */
    public static function scope_templates(string $university): array {
        $base=self::defaults();
        $global=self::raw_scope_settings('*');
        foreach(self::EVENTS as $event){
            if(isset($global[$event])&&is_string($global[$event])&&trim($global[$event])!=='')$base[$event]=$global[$event];
        }
        if($university!==''&&$university!=='*'){
            $campus=self::raw_scope_settings($university);
            foreach(self::EVENTS as $event){
                if(isset($campus[$event])&&is_string($campus[$event])&&trim($campus[$event])!=='')$base[$event]=$campus[$event];
            }
        }
        return $base;
    }

    /** Backward-compatible facade: Program id is intentionally ignored in RC48+. */
    public static function templates(int $program_id,string $university): array {
        return self::scope_templates($university);
    }

    private static function validate_template(string $template): ?string {
        $template=sanitize_textarea_field($template);
        if($template===''||QYS_Text_Normalizer::chars($template)>self::MAX_TEMPLATE)return null;
        $probe=QYS_Text_Normalizer::render_template($template,[
            'نام'=>'نام','نام خانوادگی'=>'نام خانوادگی','برنامه'=>'برنامه','وضعیت'=>'وضعیت','دانشگاه'=>'دانشگاه',
        ]);
        return $probe===null?null:$template;
    }

    public static function save_scope_templates(string $university,array $raw,int $actor_id=0){
        if($university!=='*'){
            $university=sanitize_key($university);
            if($university===''||!QYS_Multitenant::university_active($university))return new WP_Error('scope','دانشگاه معتبر نیست.');
        }
        $payload=[];
        foreach(self::EVENTS as $event){
            $value=self::validate_template((string)($raw[$event]??''));
            if($value===null)return new WP_Error('template','متن یکی از پیام‌ها خالی، طولانی یا دارای متغیر ناشناخته است.');
            $payload[$event]=$value;
        }
        $payload['_updated_by']=$actor_id;
        $payload['_updated_at_utc']=gmdate('Y-m-d H:i:s');
        $key=self::option_key($university);
        if(get_option($key,null)===null)$ok=add_option($key,$payload,'','no');
        else $ok=update_option($key,$payload,false);
        $saved=$ok || get_option($key) === $payload;
        return $saved ? true : new WP_Error('save','ذخیره پیام‌های وضعیت انجام نشد.');
    }

    /** Backward-compatible facade: existing callers may still pass a Program id. */
    public static function save_templates(int $program_id,string $university,array $raw,int $actor_id=0){
        return self::save_scope_templates($university,$raw,$actor_id);
    }

    /** Legacy per-Program options are preserved but no longer read or written. */
    public static function legacy_storage_policy(): array {
        return ['read'=>'ignored','write'=>'disabled','delete'=>'preserved'];
    }

    private static function event_label(string $event): string { return self::labels()[$event]??$event; }

    private static function contexts(array $application_ids): array {
        $out=[];
        foreach(array_values(array_unique(array_filter(array_map('absint',$application_ids)))) as $aid){
            $a=QYS_Program_Repository::get_application($aid);if(!$a)continue;
            $p=QYS_Program_Repository::get_program((int)$a->program_id);if(!$p)continue;
            $m=QYS_Member_Repository::find_by_id((int)$a->member_id);if(!$m)continue;
            $out[]=['application'=>$a,'program'=>$p,'member'=>$m];
        }
        return $out;
    }

    /**
     * Queue one or more successful status changes. Returns aggregate only; no PII.
     * Member consent/verification is rechecked now and again by the queue worker.
     */
    public static function queue_for_applications(array $application_ids,string $event): array {
        $event=sanitize_key($event);
        $out=['queued'=>0,'skipped'=>0,'failed'=>0,'campaigns'=>0];
        if(!in_array($event,self::EVENTS,true)){$out['failed']=count($application_ids);return $out;}
        $owner=(int)QYS_Multitenant::owner_id();if($owner<=0){$out['failed']=count($application_ids);return $out;}
        $groups=[];
        foreach(self::contexts($application_ids) as $ctx){
            $a=$ctx['application'];$p=$ctx['program'];$m=$ctx['member'];
            if((int)($m->verified??0)!==1||(int)($m->consent??0)!==1||(string)($m->university??'')!==(string)$a->university){$out['skipped']++;continue;}
            $uni=sanitize_key((string)$a->university);if($uni===''||!QYS_Multitenant::university_active($uni)){$out['skipped']++;continue;}
            $templates=self::scope_templates($uni);$template=(string)($templates[$event]??'');
            $text=QYS_Text_Normalizer::render_template($template,[
                'نام'=>(string)$m->first_name,
                'نام خانوادگی'=>(string)$m->last_name,
                'برنامه'=>(string)$p->title,
                'وضعیت'=>self::event_label($event),
                'دانشگاه'=>QYS_Multitenant::university_label($uni),
            ]);
            if($text===null){$out['failed']++;continue;}
            $key=(int)$p->id.'|'.$uni;
            if(!isset($groups[$key]))$groups[$key]=['program'=>$p,'university'=>$uni,'template'=>$template,'messages'=>[]];
            $groups[$key]['messages'][]=['id'=>(int)$m->id,'text'=>$text];
        }
        foreach($groups as $group){
            if(!$group['messages'])continue;
            if(!QYS_Campaign_Repository::begin()){$out['failed']+=count($group['messages']);continue;}
            $ok=false;
            try{
                $label='[سیستمی] '.self::event_label($event).' — '.QYS_Text_Normalizer::clip((string)$group['program']->title,120);
                $cid=QYS_Campaign_Repository::create_draft($label,(string)$group['template']);
                if($cid===false)throw new RuntimeException('campaign');
                $meta=[
                    'campaign_id'=>$cid,
                    'request_key'=>wp_generate_uuid4(),
                    'audience'=>'program_status',
                    'audience_groups'=>'',
                    'audience_universities'=>(string)$group['university'],
                    'manager_university'=>'',
                    'scheduled_at_utc'=>null,
                    'scheduled_jalali'=>'',
                    'scheduled_hm'=>'',
                    'created_by'=>$owner,
                    'created_at_utc'=>gmdate('Y-m-d H:i:s'),
                ];
                if(!QYS_Campaign_Repository::insert_targeted_meta($meta))throw new RuntimeException('meta');
                if(!QYS_Campaign_Repository::enqueue_many((int)$cid,$group['messages']))throw new RuntimeException('queue');
                if(QYS_Campaign_Repository::set_state((int)$cid,'draft','active')!==1)throw new RuntimeException('state');
                if(!QYS_Campaign_Repository::mark_targeted_launched((int)$cid,gmdate('Y-m-d H:i:s')))throw new RuntimeException('launch');
                if(!QYS_Campaign_Repository::commit())throw new RuntimeException('commit');
                $ok=true;
                $out['campaigns']++;
                $out['queued']+=count($group['messages']);
            }catch(Throwable $e){QYS_Campaign_Repository::rollback();}
            if(!$ok)$out['failed']+=count($group['messages']);
        }
        return $out;
    }

    /**
     * Notification is secondary to the already-committed Program state change.
     * An unexpected provider/repository/runtime exception must never turn a
     * successful status transition into a blank management screen.
     */
    public static function queue_for_applications_safe(array $application_ids,string $event): array {
        try {
            return self::queue_for_applications($application_ids,$event);
        } catch (Throwable $e) {
            return ['queued'=>0,'skipped'=>0,'failed'=>max(1,count($application_ids)),'campaigns'=>0,'safe_error'=>'notification_runtime'];
        }
    }

    public static function summary_text(array $result): string {
        $queued=max(0,(int)($result['queued']??0));$skipped=max(0,(int)($result['skipped']??0));$failed=max(0,(int)($result['failed']??0));
        if($queued>0 && $skipped===0 && $failed===0)return ' پیام اطلاع‌رسانی در صف امن قرار گرفت.';
        $parts=[];if($queued)$parts[]='پیام در صف: '.number_format_i18n($queued);if($skipped)$parts[]='دریافت پیام غیرفعال/نامعتبر: '.number_format_i18n($skipped);if($failed)$parts[]='خطای صف: '.number_format_i18n($failed);
        return $parts?' اطلاع‌رسانی — '.implode('، ',$parts).'.':'';
    }
}
