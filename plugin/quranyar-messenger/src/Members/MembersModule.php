<?php
if (!defined('ABSPATH')) exit;

trait QYS_Members_Module {
    static function profile_schema_ready(){return QYS_Member_Repository::schema_ready()&&QYS_Family_Relationship_Repository::schema_ready();}

    /** RC49 product rule: active messaging consent is a prerequisite for profile completion. */
    public static function membership_consent_contract(): array {
      return [
       'ready'=>true,
       'membership'=>'requires_messaging',
       'messaging'=>'required',
       'new_member_default'=>'permission_first',
       'profile_requires_consent'=>true,
       'status_fields'=>'linked_prerequisite',
       'gate'=>'before_profile',
       'disable'=>'blocked_for_member',
       'reactivation'=>'required_to_resume',
      ];
    }

    /** RC50: institutional role and family attribution are independent axes. */
    public static function family_attribution_contract(): array {
      $repo=QYS_Family_Relationship_Repository::contract();
      return array_merge($repo,[
       'profile_rule'=>'staff_family_requires_link',
       'other_roles'=>'optional_links',
       'max_links'=>4,
       'privacy'=>'exact_lookup_no_directory',
      ]);
    }

    static function family_relation_types(): array { return QYS_Family_Relationship_Repository::TYPES; }


    /** RC51: mobile ownership is verified by server-side OTP through IPPanel. */
    public static function mobile_otp_contract(): array { return QYS_Mobile_OTP::contract(); }

    static function family_attribution_complete($m,string $scope=''): bool {
      if(!$m)return false;
      if((string)($m->member_role??'')!=='staff_family')return true;
      $scope=sanitize_key($scope!==''?$scope:(string)($m->university??''));
      return $scope!=='' && QYS_Family_Relationship_Repository::has_relation_for_member_scope((int)$m->id,$scope);
    }

    static function normalize_family_relations($raw,string $university,string $subject_national_id,int $subject_member_id=0){
      if($raw===null||$raw==='')$raw=[];
      if(!is_array($raw))return new WP_Error('family_relations','اطلاعات انتساب خانوادگی نامعتبر است.',['status'=>400]);
      if(count($raw)>4)return new WP_Error('family_relations','حداکثر چهار انتساب خانوادگی قابل ثبت است.',['status'=>400]);
      $out=[];$seen=[];
      foreach($raw as $item){
       if(!is_array($item))return new WP_Error('family_relations','یکی از انتساب‌های خانوادگی نامعتبر است.',['status'=>400]);
       $type=sanitize_key((string)($item['relation_type']??''));
       if(!isset(self::family_relation_types()[$type]))return new WP_Error('family_relation_type','نسبت خانوادگی را از گزینه‌های همسر یا فرزند انتخاب کنید.',['status'=>400]);
       $staff=null;$staff_id=absint($item['staff_member_id']??0);
       if($staff_id>0)$staff=QYS_Family_Relationship_Repository::staff_by_id($staff_id,$university,$subject_member_id);
       else{
        $nid=self::national_id((string)($item['national_id']??''));
        if($nid===''||$nid===$subject_national_id)return new WP_Error('family_staff','کد ملی همکار معتبر نیست یا با کد ملی خودتان یکسان است.',['status'=>400]);
        $staff=QYS_Family_Relationship_Repository::staff_by_national_id($nid,$university,$subject_member_id);
       }
       if(!$staff)return new WP_Error('family_staff','این کد ملی قابل استفاده نیست. همکار موردنظر باید در همین دانشگاه پرونده تأییدشده داشته باشد.',['status'=>409]);
       $staff_id=(int)$staff->id;
       if(isset($seen[$staff_id])){
        if($seen[$staff_id]!==$type)return new WP_Error('family_duplicate','برای یک پرسنل نمی‌توان هم‌زمان دو نسبت متفاوت ثبت کرد.',['status'=>400]);
        continue;
       }
       $seen[$staff_id]=$type;$out[]=['staff_member_id'=>$staff_id,'relation_type'=>$type];
      }
      return $out;
    }

    /** Phase E / E10: profile progress is server-authoritative and shares the exact completion basis. */
    public static function profile_progress_contract(): array {
      return [
       'ready'=>true,
       'source'=>'server',
       'basis'=>'canonical_required_plus_consent_plus_mobile_otp',
       'total_fields'=>9,
       'consent_gate'=>'required',
       'mobile_otp'=>'registration_and_change',
       'family_attribution'=>'conditional_staff_family',
       'hundred'=>'complete_only',
       'ui'=>'incomplete_only',
      ];
    }

    static function profile_progress($m): array {
      $checks=[
       'messaging_consent'=>$m && (int)($m->consent??0)===1,
       'university'=>$m && class_exists('QYS_Multitenant') && QYS_Multitenant::university_active((string)($m->university??'')),
       'member_role'=>$m && array_key_exists((string)($m->member_role??''),self::member_roles()),
       'first_name'=>$m && trim((string)($m->first_name??''))!=='',
       'last_name'=>$m && trim((string)($m->last_name??''))!=='',
       'national_id'=>$m && trim((string)($m->national_id??''))!=='',
       'mobile'=>$m && self::mobile((string)($m->mobile??''))!=='',
       'mobile_otp'=>$m && QYS_Member_Repository::mobile_ownership_ready($m),
       'birth_jalali'=>$m && self::birth_jalali((string)($m->birth_jalali??''))!=='',
      ];
      if($m && (string)($m->member_role??'')==='staff_family')
       $checks['family_attribution']=self::family_attribution_complete($m,(string)($m->university??''));
      $completed=count(array_filter($checks,static fn($ready)=>(bool)$ready));
      $total=count($checks);
      $complete=$m!==null && $completed===$total;
      return [
       'percent'=>$total?($complete?100:(int)round(($completed/$total)*100)):0,
       'completed'=>$completed,
       'total'=>$total,
       'complete'=>$complete,
       'missing'=>array_values(array_keys(array_filter($checks,static fn($ready)=>!$ready))),
      ];
    }

    static function profile_identity_complete($m){
      if(!$m)return false;
      $progress=self::profile_progress($m);
      $missing=array_flip((array)$progress['missing']);
      foreach(['member_role','first_name','last_name','national_id','mobile','mobile_otp','birth_jalali'] as $field){
       if(isset($missing[$field]))return false;
      }
      $scope=sanitize_key((string)(!empty($m->pending_university)?$m->pending_university:$m->university));
      return self::family_attribution_complete($m,$scope);
     }

    static function profile_complete($m){
      return (bool)(self::profile_progress($m)['complete']??false);
     }

    static function profile_view($m){
      $current_scope=sanitize_key((string)($m->university??''));
      $pending_scope=sanitize_key((string)($m->pending_university??''));
      $scope=$pending_scope!==''?$pending_scope:$current_scope;$by_scope=[];
      if($m&&$current_scope!=='')$by_scope[$current_scope]=QYS_Family_Relationship_Repository::relations_for_member_scope((int)$m->id,$current_scope);
      if($m&&$pending_scope!==''&&$pending_scope!==$current_scope)$by_scope[$pending_scope]=QYS_Family_Relationship_Repository::relations_for_member_scope((int)$m->id,$pending_scope);
      return ['university'=>$current_scope,
       'pending_university'=>$pending_scope,
       'transfer_requested_at_utc'=>(string)($m->transfer_requested_at_utc??''),
       'member_role'=>(string)($m->member_role??''),'first_name'=>(string)$m->first_name,
       'last_name'=>(string)$m->last_name,'national_id'=>(string)$m->national_id,
       'mobile'=>(string)($m->mobile??''),
       'mobile_verified'=>!empty($m->mobile_verified_at_utc),
       'mobile_verification_required'=>(bool)($m->mobile_verification_required??false),
       'mobile_otp_state'=>!empty($m->mobile_verified_at_utc)?'verified':((int)($m->mobile_verification_required??0)===0?'legacy':'required'),
       'birth_jalali'=>(string)($m->birth_jalali??''),
       'birthday_opt_in'=>(bool)($m->birthday_opt_in??false),
       'family_relation_scope'=>$scope,'family_relations_by_scope'=>$by_scope,
       'family_relations'=>$by_scope[$scope]??[]];
     }

    static function cancel_pending_after_scope_change($member_id){
      $member_id=absint($member_id);if(!$member_id)return false;
      $ok=QYS_Campaign_Repository::cancel_pending_for_member($member_id)!==false;
      if(self::birthday_log_ready()&&!QYS_Member_Repository::cancel_pending_birthday_for_member(self::table('birthday_log'),$member_id))$ok=false;
      return $ok;
     }

    static function save_profile(WP_REST_Request $req,$user,$previous=null){
      if(!self::profile_schema_ready())return new WP_Error('schema','پایگاه داده پرونده هنوز ارتقا نیافته است. مدیر سایت باید یک بار پیشخوان قرآن‌یار را باز کند.',['status'=>503]);
      $id=(string)$user['id'];
      $consent_ready=$previous?((int)($previous->consent??0)===1):($req->get_param('consent')===true);
      if(!$consent_ready)return new WP_Error('consent_required','برای تشکیل یا تکمیل پرونده، ابتدا مجوز دریافت پیام قرآن‌یار را در ایتا فعال کنید.',['status'=>409,'action'=>'enable_messaging']);
      $requested_uni=sanitize_key((string)$req->get_param('university'));
      if(!class_exists('QYS_Multitenant')||!QYS_Multitenant::university_active($requested_uni))return new WP_Error('university','دانشگاه انتخاب‌شده فعال یا معتبر نیست.',['status'=>400]);
      $role=(string)$req->get_param('member_role');
      if(!array_key_exists($role,self::member_roles()))return new WP_Error('member_role','عنوان عضویت را انتخاب کنید.',['status'=>400]);
      $first=trim(sanitize_text_field((string)$req->get_param('first_name')));
      $last=trim(sanitize_text_field((string)$req->get_param('last_name')));
      if($first===''||$last===''||self::chars($first)>100||self::chars($last)>100)return new WP_Error('name','نام و نام خانوادگی معتبر وارد کنید.',['status'=>400]);
      $nid=self::national_id((string)$req->get_param('national_id'));
      if($nid==='')return new WP_Error('nid','کد ملی ۱۰ رقمی معتبر وارد کنید.',['status'=>400]);
      $mobile=self::mobile((string)$req->get_param('mobile'));
      if($mobile==='')return new WP_Error('mobile','شماره موبایل ایران را به صورت ۰۹xxxxxxxxx وارد کنید.',['status'=>400]);
      $parts=[$req->get_param('birth_year'),$req->get_param('birth_month'),$req->get_param('birth_day')];
      if($parts[0]!==null||$parts[1]!==null||$parts[2]!==null){
       $year=self::normalize_digits((string)$parts[0]);$month=self::normalize_digits((string)$parts[1]);$day=self::normalize_digits((string)$parts[2]);
       if(!preg_match('/\A[0-9]{4}\z/D',$year)||!preg_match('/\A(?:[1-9]|1[0-2])\z/D',$month)||!preg_match('/\A(?:[1-9]|[12][0-9]|3[01])\z/D',$day))
        return new WP_Error('birth','سال چهاررقمی و ماه و روز را از فهرست‌های مشخص انتخاب کنید.',['status'=>400]);
       $birth=self::birth_jalali(sprintf('%04d/%02d/%02d',(int)$year,(int)$month,(int)$day));
      }else $birth=self::birth_jalali((string)$req->get_param('birth_jalali'));
      if($birth==='')return new WP_Error('birth','تاریخ تولد شمسی معتبر و غیرآینده نیست؛ روز، ماه و سال را بررسی کنید.',['status'=>400]);
      if(QYS_Member_Repository::exists_other_national_id($nid,$id))return new WP_Error('duplicate','این کد ملی برای پرونده دیگری ثبت شده است؛ با مدیر تماس بگیرید.',['status'=>409]);
      if(QYS_Member_Repository::exists_other_mobile($mobile,$id))return new WP_Error('duplicate_mobile','این شماره موبایل برای پرونده دیگری ثبت شده است؛ با مدیر تماس بگیرید.',['status'=>409]);
      $same_mobile=$previous && self::mobile((string)($previous->mobile??''))===$mobile;
      $stored_mobile_ready=$same_mobile && QYS_Member_Repository::mobile_ownership_ready($previous);
      $otp_claim=QYS_Mobile_OTP::has_verified_claim($id,$mobile);
      if(!$stored_mobile_ready && !$otp_claim)return new WP_Error('mobile_otp_required','برای ثبت این شماره موبایل ابتدا کد تأیید پیامکی را دریافت و تأیید کنید.',['status'=>409,'action'=>'verify_mobile']);
      $mobile_verified_at=$same_mobile&&!empty($previous->mobile_verified_at_utc)?(string)$previous->mobile_verified_at_utc:($otp_claim?gmdate('Y-m-d H:i:s'):null);
      $mobile_verification_required=$same_mobile && (int)($previous->mobile_verification_required??0)===0 && !$otp_claim?0:1;
      $family_raw=$req->get_param('family_relations');
      // Backward-compatible API behavior: an older cached client that omits the
      // new field must not silently erase already saved optional relationships.
      if($previous&&$family_raw===null){
       $family_raw=array_map(static fn($r)=>['staff_member_id'=>(int)$r['staff_member_id'],'relation_type'=>(string)$r['relation_type']],QYS_Family_Relationship_Repository::relations_for_member_scope((int)$previous->id,$requested_uni));
      }
      $family_relations=self::normalize_family_relations($family_raw,$requested_uni,$nid,$previous?(int)$previous->id:0);
      if(is_wp_error($family_relations))return $family_relations;
      if($role==='staff_family'&&!$family_relations)return new WP_Error('family_required','برای عنوان «خانواده کارکنان» باید حداقل همسر یا یکی از والدینِ شاغل در همین دانشگاه مشخص شود.',['status'=>409]);

      $identity_changed=$previous && (
       (string)$previous->national_id!==$nid||(string)$previous->first_name!==$first
       ||(string)$previous->last_name!==$last||(string)($previous->mobile??'')!==$mobile
       ||(string)($previous->member_role??'')!==$role);
      $current_uni=$previous?sanitize_key((string)($previous->university??'')):$requested_uni;
      $transfer_requested=$previous&&$current_uni!==$requested_uni;
      $verified=$previous&&!$identity_changed?(int)$previous->verified:0;
      $effective_uni=$transfer_requested?$current_uni:$requested_uni;
      $pending_uni=$transfer_requested?$requested_uni:'';
      $transfer_at=$transfer_requested?gmdate('Y-m-d H:i:s'):null;
      // RC49: reaching this point proves the required messaging permission gate.
      // Existing members must reactivate before editing; new members submit consent=true
      // only after the native Eitaa write-access dialog has succeeded.
      $initial_consent=1;

      $values=['university'=>$effective_uni,'pending_university'=>$pending_uni,'transfer_requested_at_utc'=>$transfer_at,
       'member_role'=>$role,'first_name'=>$first,'last_name'=>$last,'national_id'=>$nid,'mobile'=>$mobile,
       'mobile_verified_at_utc'=>$mobile_verified_at,'mobile_verification_required'=>$mobile_verification_required,'birth_jalali'=>$birth,
       'birthday_opt_in'=>$req->get_param('birthday_opt_in')===true?1:0,'verified'=>$verified,'updated_at'=>current_time('mysql')];
      if(!QYS_Member_Repository::begin())return new WP_Error('database','آغاز ذخیره امن پرونده انجام نشد؛ دوباره تلاش کنید.',['status'=>500]);
      $member_id=$previous?(int)$previous->id:0;
      if($previous){
       $result=QYS_Member_Repository::update($member_id,$values,['%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d','%s']);
      }else{
       $values=['eitaa_id'=>$id]+$values;$values['consent']=$initial_consent;
       $member_id=QYS_Member_Repository::insert($values,['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d','%s','%d']);
       $result=$member_id===false?false:1;
      }
      if($result===false
       || !QYS_Family_Relationship_Repository::replace_for_member_scope($member_id,$requested_uni,$family_relations)
       || !QYS_Family_Relationship_Repository::prune_member_scopes($member_id,array_filter([$effective_uni,$pending_uni]))){
       QYS_Member_Repository::rollback();
       return new WP_Error('database','ذخیره پرونده و انتساب خانوادگی کامل نشد؛ هیچ تغییر ناقصی ثبت نشد.',['status'=>500]);
      }
      if(!QYS_Member_Repository::commit())return new WP_Error('database','نتیجه ذخیره پرونده نامشخص است؛ پیش از تکرار، وضعیت پرونده را دوباره بررسی کنید.',['status'=>500]);
      if($otp_claim)QYS_Mobile_OTP::consume_verified_claim($id,$mobile);
      $welcome=['attempted'=>false,'sent'=>false,'status'=>'not_new'];
      if(!$previous&&$initial_consent===1)$welcome=self::welcome_send_once((int)$member_id);
      if($previous&&$identity_changed)self::cancel_pending_after_scope_change((int)$previous->id);

      $message=$previous?'پرونده قرآن‌یار به‌روز شد.':'پرونده قرآن‌یار تشکیل شد.';
      if(!$previous&&!empty($welcome['sent']))$message.=' پیام خوش‌آمدگویی در ایتا برای شما ارسال شد.';
      if(!$verified)$message.=' برخی خدمات پس از تأیید پرونده توسط دانشگاه فعال می‌شوند.';
      if($previous&&$identity_changed)$message.=' به علت تغییر اطلاعات هویتی، پیام‌های معلق قبلی لغو شدند.';
      if($transfer_requested)$message.=' درخواست انتقال دانشگاه ثبت شد. تا تأیید دانشگاه مقصد، دانشگاه فعلی و دسترسی‌های شما تغییر نمی‌کند.';
      elseif($previous&&!empty($previous->pending_university)&&$requested_uni===$current_uni)$message.=' درخواست انتقال قبلی لغو شد.';
      return rest_ensure_response(['ok'=>true,'message'=>$message,'verified'=>(bool)$verified,
       'membership_active'=>true,'enabled'=>(bool)$initial_consent,'messaging_consent'=>(bool)$initial_consent,'transfer_pending'=>$transfer_requested,
       'pending_university'=>$pending_uni,'current_university'=>$effective_uni,
       'welcome_sent'=>(bool)($welcome['sent']??false),'welcome_status'=>(string)($welcome['status']??'')]);
     }

    static function update_profile(WP_REST_Request $req){
      $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
      $existing=QYS_Member_Repository::find_by_eitaa_id((string)$user['id']);
      if(!$existing)return new WP_Error('not_registered','ابتدا پرونده را تشکیل دهید.',['status'=>404]);
      return self::save_profile($req,$user,$existing);
     }


    static function status(WP_REST_Request $req){
      $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
      $member=QYS_Member_Repository::find_by_eitaa_id((string)$user['id']);
      if(!$member)return rest_ensure_response(['ok'=>true,'registered'=>false,'membership_active'=>false,'enabled'=>false,'messaging_consent'=>false,'verified'=>false,'profile_complete'=>false,'profile_progress'=>self::profile_progress(null)]);
      // Do not cache personally identifiable data in intermediaries.
      if(function_exists('nocache_headers'))nocache_headers();
      $progress=self::profile_progress($member);
      return rest_ensure_response(['ok'=>true,'registered'=>true,'membership_active'=>(bool)$member->consent,'enabled'=>(bool)$member->consent,
       'messaging_consent'=>(bool)$member->consent,'verified'=>(bool)$member->verified,'profile_complete'=>(bool)$progress['complete'],
       'profile_progress'=>$progress,'profile'=>self::profile_view($member)]);
     }

    static function stop_member($member_id){
      $member_id=absint($member_id);if(!$member_id)return false;
      $result=QYS_Member_Repository::set_consent($member_id,false);
      if($result===false)return false;
      QYS_Campaign_Repository::cancel_pending_for_member($member_id);
      if(self::birthday_log_ready())QYS_Member_Repository::cancel_pending_birthday_for_member(self::table('birthday_log'),$member_id);
      return true;
     }

    static function resume_member($member_id){
      $member_id=absint($member_id);if(!$member_id)return false;
      $member=QYS_Member_Repository::find_by_id($member_id);if(!$member)return false;
      if((int)$member->consent===1)return true;
      $result=QYS_Member_Repository::set_consent($member_id,true);
      // Deliberately do not resurrect cancelled queue rows or birthday jobs.
      return $result!==false;
     }

    static function disable(WP_REST_Request $req){
      $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
      $member_id=QYS_Member_Repository::id_by_eitaa_id((string)$user['id']);
      if(!$member_id)return new WP_Error('not_registered','ابتدا عضو قرآن‌یار شوید.',['status'=>404]);
      return new WP_Error('consent_required','دریافت پیام جزء الزامات پرونده فعال قرآن‌یار است و از داخل برنامک قابل غیرفعال‌سازی نیست.',['status'=>409,'action'=>'membership_required']);
     }

    static function reactivate(WP_REST_Request $req){
      $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
      if($req->get_param('consent')!==true)return new WP_Error('consent','ابتدا در پنجره رسمی ایتا اجازه ارسال پیام بدهید.',['status'=>400]);
      $member_id=QYS_Member_Repository::id_by_eitaa_id((string)$user['id']);
      if(!$member_id)return new WP_Error('not_registered','ابتدا فرم عضویت را کامل کنید.',['status'=>404]);
      $updated=QYS_Member_Repository::set_consent((int)$member_id,true);
      if($updated===false)return new WP_Error('database','ذخیره وضعیت انجام نشد؛ دوباره تلاش کنید.',['status'=>500]);
      return rest_ensure_response(['ok'=>true,'enabled'=>true,'message'=>'دریافت پیام مجدداً فعال شد. پیام‌های لغوشده گذشته خودکار ارسال نخواهند شد.']);
     }

    static function enroll(WP_REST_Request $req){
      $user=QYS_Security_Service::request_user($req);if(is_wp_error($user))return $user;
      $existing=QYS_Member_Repository::id_by_eitaa_id((string)$user['id']);
      if($existing)return new WP_Error('registered','برای ویرایش اطلاعات از گزینه تکمیل یا ویرایش پرونده استفاده کنید.',['status'=>409]);
      return self::save_profile($req,$user,null);
     }

    public static function lazy_feature_contract(): array {
      $programs=class_exists('QYS_Programs_Module')&&method_exists('QYS_Programs_Module','public_asset_manifest')?QYS_Programs_Module::public_asset_manifest():[];
      $attendance=class_exists('QYS_Attendance')&&method_exists('QYS_Attendance','public_asset_manifest')?QYS_Attendance::public_asset_manifest():[];
      $program_ready=!empty($programs['script'])&&!empty($programs['style'])&&($programs['global']??'')==='QYSPrograms';
      $attendance_ready=!empty($attendance['script'])&&!empty($attendance['style'])&&($attendance['global']??'')==='QYSAttendance';
      return [
       'ready'=>$program_ready&&$attendance_ready,
       'programs'=>$program_ready?'lazy':'unavailable',
       'attendance'=>$attendance_ready?'lazy':'unavailable',
       'full_data'=>'on_view',
       'home_programs'=>'compact_summary',
      ];
     }

    static function miniapp(){
      if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::ready())return QYS_Upgrade_Coordinator::unavailable_markup();
      // Only the Eitaa in-app WebView shows registration; ordinary browsers see a static notice.
      wp_enqueue_script('eitaa-webapp','https://developer.eitaa.com/eitaa-web-app.js',[],null,false);
      wp_enqueue_script('qys-miniapp',plugins_url('assets/miniapp.js',self::PLUGIN_FILE),['eitaa-webapp'],self::VERSION,true);
      wp_enqueue_style('qys-fonts',plugins_url('assets/qys-fonts.css',self::PLUGIN_FILE),[],self::VERSION);
      wp_enqueue_style('qys-ui',plugins_url('assets/ui.css',self::PLUGIN_FILE),['qys-fonts'],self::VERSION);
      wp_enqueue_style('qys-public-ui',plugins_url('assets/qys-public-ui.css',self::PLUGIN_FILE),['qys-ui'],self::VERSION);
      wp_enqueue_script('qys-public-ui',plugins_url('assets/qys-public-ui.js',self::PLUGIN_FILE),['qys-miniapp'],self::VERSION,true);
      $leap_years=[];
      for($year=1300;$year<=1499;$year++)if(self::jal_cal($year)['leap']===0)$leap_years[]=$year;
      $uni_options='';$uni_labels=[];
      if(class_exists('QYS_Multitenant'))foreach(QYS_Multitenant::universities() as $u){
       $code=(string)$u['code'];$title=(string)$u['title'];$uni_labels[$code]=$title;
       $uni_options.='<option value="'.esc_attr($code).'">'.esc_html($title).'</option>';
      }
      if($uni_options===''){$uni_labels=['saveh_ums'=>'دانشگاه علوم پزشکی ساوه'];$uni_options='<option value="saveh_ums">دانشگاه علوم پزشکی ساوه</option>';}
      wp_localize_script('qys-miniapp','QYS',[
       'jalaliLeapYears'=>$leap_years,
       'endpoint'=>rest_url('qys/v1/enroll'),
       'profileEndpoint'=>rest_url('qys/v1/profile'),
       'statusEndpoint'=>rest_url('qys/v1/status'),
       'homeSummaryEndpoint'=>rest_url('qys/v1/home-summary'),
       'disableEndpoint'=>rest_url('qys/v1/disable'),
       'reactivateEndpoint'=>rest_url('qys/v1/reactivate'),
       'mobileOtpSendEndpoint'=>rest_url('qys/v1/mobile-otp/send'),
       'mobileOtpVerifyEndpoint'=>rest_url('qys/v1/mobile-otp/verify'),
       'universities'=>$uni_labels,
       'memberRoles'=>self::member_roles()]);
      $lazy=[];
      if(class_exists('QYS_Programs_Module')&&method_exists('QYS_Programs_Module','public_asset_manifest'))$lazy['programs']=QYS_Programs_Module::public_asset_manifest();
      if(class_exists('QYS_Attendance')&&method_exists('QYS_Attendance','public_asset_manifest'))$lazy['attendance']=QYS_Attendance::public_asset_manifest();
      wp_localize_script('qys-public-ui','QYSLazyFeatures',$lazy);
      return '<section dir="rtl" id="qys-app" class="qys-app" data-qys-view="home" aria-busy="true">'
       .QYS_Member_Experience::skip_link()
       .'<div id="qys-browser-notice" hidden><strong>قرآن‌یار</strong><p>برای حفظ امنیت پرونده، این بخش فقط در برنامک رسمی قرآن‌یار داخل ایتا باز می‌شود.</p></div>'
       .'<div id="qys-inapp" hidden>'.QYS_Member_Experience::system_state().'<main id="qys-member-content" class="qys-member-shell" tabindex="-1">'
       .'<section id="qys-view-home" class="qys-member-view" data-qys-view="home" aria-hidden="false" aria-labelledby="qys-home-title">'
       .'<header class="qys-hero"><div class="qys-brand"><span class="qys-brand-mark" aria-hidden="true">۞</span><div><span class="qys-eyebrow">همراه قرآنی دانشگاه</span><h1 id="qys-home-title">قرآن‌یار</h1></div></div>'
       .'<p class="qys-hero-greeting">سلام <strong id="qys-display-name">همراه قرآنی</strong> 🌿</p><p class="qys-hero-subtitle">برنامه‌ها، حضور و پیام‌های شما</p></header>'
       .'<div class="qys-dash-card" aria-label="وضعیت عضویت"><div class="qys-section-title"><div><span class="qys-section-kicker">وضعیت من</span><h2>پرونده قرآن‌یار</h2></div><span id="qys-badge-profile" class="qys-pill">در حال بررسی</span></div>'
       .'<p id="qys-member-status" role="status" aria-live="polite">در حال بررسی پرونده...</p><div id="qys-progress-wrap"><div class="qys-progress-label"><span>میزان تکمیل پرونده</span><span id="qys-progress-number">—</span></div><div class="qys-progress" role="progressbar" aria-label="میزان تکمیل پرونده" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="qys-progress-track"><span id="qys-progress-fill"></span></div></div>'
       .'<div class="qys-status-line"><span id="qys-badge-identity" class="qys-pill qys-pill--neutral">تأیید هویت: در حال بررسی</span><span id="qys-badge-consent" class="qys-pill qys-pill--neutral">دریافت پیام: در حال بررسی</span></div></div>'
       .QYS_Member_App_Shell::home_dashboard().'</section>'
       .'<section id="qys-view-profile" class="qys-member-view" data-qys-view="profile" hidden aria-hidden="true" aria-labelledby="qys-profile-title"><div class="qys-view-heading"><span class="qys-section-kicker">پرونده من</span><h2 id="qys-profile-title">پرونده قرآن‌یار</h2><p>برای استفاده از قرآن‌یار، ابتدا دریافت پیام را فعال کنید و سپس اطلاعات پرونده را کامل کنید.</p></div>'
       .'<div id="qys-initial" class="qys-surface qys-surface--accent" hidden><h3>شروع عضویت</h3><p>ابتدا دریافت پیام را در ایتا فعال کنید. بعد از تأیید، فرم پرونده برای شما باز می‌شود.</p>'
       .'<p><button type="button" id="qys-permission">فعال‌سازی مجوز دریافت پیام</button> <span id="qys-permission-status" role="status">بدون این مجوز امکان ادامه ثبت‌نام نیست.</span></p></div>'
       .'<div id="qys-manage" class="qys-surface" hidden><h3>پرونده من</h3><p id="qys-identity-status"></p>'
       .'<p><button type="button" id="qys-edit-profile">تکمیل یا ویرایش پرونده</button></p>'
       .'<p><button type="button" id="qys-reactivate">فعال‌سازی مجدد دریافت پیام</button></p>'
       .'<p><button type="button" id="qys-disable" class="qys-danger" hidden>توقف دریافت پیام</button></p>'
       .'<small>اگر دریافت پیام متوقف شده است، ابتدا آن را دوباره فعال کنید.</small></div>'
       .'<div id="qys-profile-wrap" class="qys-surface qys-surface--form" hidden><div class="qys-form-heading"><span class="qys-section-kicker">اطلاعات شما</span><h3 id="qys-form-title">تشکیل پرونده</h3><p>اطلاعات را وارد کنید و در پایان «ثبت پرونده» را بزنید.</p></div><form id="qys-enroll" autocomplete="on">'
       .'<section class="qys-form-step" data-qys-step="0"><h4><span>۱</span> اطلاعات فردی</h4><p class="qys-step-note">ابتدا مشخصات فردی و عنوان عضویت خود را ثبت کنید.</p>'
       .'<p><label for="qys-university">دانشگاه</label><select id="qys-university" name="university" required>'.$uni_options.'</select></p>'
       .'<p><label for="qys-role">عنوان عضویت</label><select id="qys-role" name="member_role" required><option value="">انتخاب کنید</option><option value="staff">کارمند</option><option value="faculty">استاد</option><option value="student">دانشجو</option><option value="staff_family">خانواده کارکنان</option></select></p>'
       .'<div id="qys-family-section" class="qys-family-section"><div class="qys-family-heading"><div><h5>نسبت خانوادگی با همکاران دانشگاه</h5><p>اگر همسر یا فرزند یکی از همکاران دانشگاه هستید، نسبت خود را اینجا ثبت کنید. اگر خودتان هم همکار هستید، عنوان عضویت شما تغییر نمی‌کند.</p></div><span id="qys-family-required" class="qys-pill qys-pill--neutral">اختیاری</span></div>'
       .'<div id="qys-family-list" class="qys-family-list"></div><button type="button" id="qys-family-add" class="qys-family-add">افزودن نسبت خانوادگی</button>'
       .'<small>اگر «خانواده کارکنان» را انتخاب کرده‌اید، حداقل یک نفر را اضافه کنید. اگر هر دو والد همکارند، هر دو را جداگانه ثبت کنید.</small></div>'
       .'<p><label for="qys-first-name">نام</label><input id="qys-first-name" name="first_name" maxlength="100" autocomplete="given-name" required></p>'
       .'<p><label for="qys-last-name">نام خانوادگی</label><input id="qys-last-name" name="last_name" maxlength="100" autocomplete="family-name" required></p>'
       .'</section><section class="qys-form-step" data-qys-step="1"><h4><span>۲</span> شماره همراه و کد ملی</h4><p class="qys-step-note">کد ملی و شماره موبایل خود را وارد و شماره را تأیید کنید.</p>'
       .'<p><label for="qys-national-id">کد ملی</label><input id="qys-national-id" name="national_id" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="۱۰ رقم، شامل صفرهای ابتدایی" required></p>'
       .'<div class="qys-mobile-field"><p><label for="qys-mobile">شماره موبایل</label><input id="qys-mobile" name="mobile" inputmode="tel" type="tel" maxlength="16" autocomplete="tel" placeholder="۰۹xxxxxxxxx" required><small>برای ثبت یا تغییر شماره، یک کد تأیید پیامکی برای شما ارسال می‌شود.</small></p>'
       .'<div class="qys-mobile-otp" aria-labelledby="qys-mobile-otp-title"><div class="qys-mobile-otp-head"><strong id="qys-mobile-otp-title">تأیید شماره موبایل</strong><span id="qys-mobile-otp-status" class="qys-pill qys-pill--neutral">تأیید نشده</span></div>'
       .'<p><button type="button" id="qys-mobile-otp-send">ارسال کد تأیید</button></p><div id="qys-mobile-otp-confirm" hidden><label for="qys-mobile-otp-code">کد ۶ رقمی</label><div class="qys-mobile-otp-code"><input id="qys-mobile-otp-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="------"><button type="button" id="qys-mobile-otp-verify">تأیید کد</button></div></div>'
       .'<small id="qys-mobile-otp-help" role="status" aria-live="polite">کد ارسال‌شده ۵ دقیقه اعتبار دارد.</small></div></div>'
       .'</section><section class="qys-form-step" data-qys-step="2"><h4><span>۳</span> تاریخ تولد و پیام تبریک</h4>'
       .'<fieldset class="qys-birthday"><legend>تاریخ تولد شمسی</legend><div class="qys-birthday-fields">'
       .'<label for="qys-birth-day">روز<select id="qys-birth-day" name="birth_day" required><option value="">انتخاب روز</option>'.implode('',array_map(static fn($d)=>'<option value="'.$d.'">'.$d.'</option>',range(1,31))).'</select></label>'
       .'<label for="qys-birth-month">ماه<select id="qys-birth-month" name="birth_month" required><option value="">انتخاب ماه</option><option value="1">فروردین</option><option value="2">اردیبهشت</option><option value="3">خرداد</option><option value="4">تیر</option><option value="5">مرداد</option><option value="6">شهریور</option><option value="7">مهر</option><option value="8">آبان</option><option value="9">آذر</option><option value="10">دی</option><option value="11">بهمن</option><option value="12">اسفند</option></select></label>'
       .'<label for="qys-birth-year">سال<input id="qys-birth-year" name="birth_year" type="text" inputmode="numeric" maxlength="4" minlength="4" pattern="[0-9]{4}" placeholder="مثلاً ۱۳۷۵" autocomplete="bday-year" required></label>'
       .'</div><small id="qys-birth-hint" aria-live="polite"></small></fieldset>'
       .'<p class="qys-check"><label><input type="checkbox" name="birthday_opt_in" value="1"> موافقم در روز تولدم از قرآن‌یار پیام تبریک دریافت کنم.</label></p>'
       .'<p><button type="submit" id="qys-save">ثبت پرونده</button> <button type="button" id="qys-cancel-edit" hidden>انصراف</button></p>'
       .'</section></form></div><p id="qys-feedback" role="status" aria-live="polite"></p>'
       .'<small class="qys-privacy-note">اطلاعات پرونده فقط از حساب ایتای خودتان قابل ویرایش است. برای استفاده از خدمات، دریافت پیام و تأیید هویت باید فعال باشد.</small></section>'
       .'<section id="qys-view-attendance" class="qys-member-view" data-qys-view="attendance" hidden aria-hidden="true" aria-labelledby="qys-att-view-title"><div class="qys-view-heading"><span class="qys-section-kicker">جلسات من</span><h2 id="qys-att-view-title">حضور و غیاب</h2><p>جلسه‌های فعال، ورود و خروج و سابقه حضور خود را اینجا می‌بینید.</p></div><div id="qys-attendance-slot"></div></section>'
       .(class_exists('QYS_Programs_Module')?QYS_Programs_Module::member_panel():'')
       .QYS_Member_App_Shell::services_panel()
       .QYS_Member_App_Shell::more_panel()
       .'</main>'.QYS_Member_App_Shell::bottom_nav().'</div></section>';
     }

}
