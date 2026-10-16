<?php
if (!defined('ABSPATH')) exit;

trait QYS_Core_Module {
    static function init(){
     add_action('rest_api_init',[__CLASS__,'routes']);
     add_action('wp_abilities_api_categories_init',[__CLASS__,'ability_category']);
     add_action('wp_abilities_api_init',[__CLASS__,'register_abilities']);
     add_action('admin_menu',[__CLASS__,'menu'],5);
     add_action('admin_enqueue_scripts',[__CLASS__,'enqueue_admin_ui']);
     add_action('admin_post_qys_birthday_archive_export',[__CLASS__,'birthday_archive_export']);
     add_shortcode('quranyar_miniapp',[__CLASS__,'miniapp']);
     add_action('qys_send_batch',[__CLASS__,'send_batch'],10);
     add_action('qys_send_batch',[__CLASS__,'welcome_activation_worker'],15);
     add_action('qys_send_batch',[__CLASS__,'birthday_worker'],20);
     add_filter('cron_schedules',static function($s){$s['qys_minute']=['interval'=>60,'display'=>'Every minute (QYS)'];return $s;});
     add_action('init',static function(){if(!wp_next_scheduled('qys_send_batch'))wp_schedule_event(time()+60,'qys_minute','qys_send_batch');});
    }
    static function routes(){
      register_rest_route('qys/v1','/enroll', ['methods'=>'POST','callback'=>[__CLASS__,'enroll'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'enroll',12,300)]);
      register_rest_route('qys/v1','/profile', ['methods'=>'POST','callback'=>[__CLASS__,'update_profile'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'profile',20,300)]);
      register_rest_route('qys/v1','/status', ['methods'=>'POST','callback'=>[__CLASS__,'status'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'status',120,60)]);
      register_rest_route('qys/v1','/home-summary', ['methods'=>'POST','callback'=>[QYS_Home_Summary_Service::class,'rest'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'home_summary',120,60)]);
      register_rest_route('qys/v1','/disable', ['methods'=>'POST','callback'=>[__CLASS__,'disable'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'consent',20,300)]);
      register_rest_route('qys/v1','/reactivate', ['methods'=>'POST','callback'=>[__CLASS__,'reactivate'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'consent',20,300)]);
      register_rest_route('qys/v1','/mobile-otp/send', ['methods'=>'POST','callback'=>[QYS_Mobile_OTP::class,'rest_send'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'mobile_otp_send',12,300)]);
      register_rest_route('qys/v1','/mobile-otp/verify', ['methods'=>'POST','callback'=>[QYS_Mobile_OTP::class,'rest_verify'],'permission_callback'=>static fn($req)=>QYS_Security_Service::authorize_eitaa($req,'mobile_otp_verify',30,300)]);
     }

    static function ability_category(){
      if(function_exists('wp_register_ability_category')){
       wp_register_ability_category('qys-messenger',['label'=>'QuranYar Messenger','description'=>'Administrator-only operational checks for the Eitaa mini app and message queue.']);
      }
     }
    static function ability_properties(){
     $props=[];
     foreach(['version','schema_version','checked_at_utc','webview_entry','private_delivery_gateway'] as $k)$props[$k]=['type'=>'string'];
     foreach(['webapp_token_configured','delivery_token_configured','members_table','profile_schema_ready','welcome_log_ready','activation_log_ready','birthday_log_ready','birthday_enabled','campaigns_table','queue_table','programs_schema_ready','cron_scheduled','webview_shortcode_present','zip_supported','simplexml_supported'] as $k)$props[$k]=['type'=>'boolean'];
     foreach(['members_total','members_enabled','members_verified','members_eligible','members_disabled','members_profile_complete','members_birthday_opt_in','welcome_accepted','welcome_failed','welcome_sending','welcome_pending','welcome_sent','welcome_retryable_failed','welcome_definitive_failed','welcome_uncertain','welcome_cancelled','activation_sending','activation_pending','activation_sent','activation_retryable_failed','activation_definitive_failed','activation_uncertain','activation_cancelled','birthday_pending','birthday_sent','birthday_failed','birthday_cancelled','birthday_uncertain','campaigns_draft','campaigns_active','programs_total','program_applications_total','program_enrollments_total','program_attendance_links_total','queue_pending','queue_sending','queue_sent','queue_failed','queue_retryable_failed','queue_definitive_failed','queue_uncertain','queue_legacy_failed','queue_cancelled'] as $k)$props[$k]=['type'=>'integer','minimum'=>0];
     return $props;
    }

    static function register_abilities(){
      if(!function_exists('wp_register_ability'))return;
      $safe=['permission_callback'=>function(){return QYS_Security_Service::is_global_admin();},'category'=>'qys-messenger',
             'meta'=>['public'=>true,'show_in_rest'=>true,'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]];
      wp_register_ability('qys-messenger/readiness',array_merge($safe,[
       'label'=>'QuranYar readiness','description'=>'Read-only deployment, enrollment and delivery counters with NO personal data or secrets.',
       'output_schema'=>['type'=>'object','properties'=>self::ability_properties(),'additionalProperties'=>false],
       'execute_callback'=>[__CLASS__,'readiness']]));
      wp_register_ability('qys-messenger/check-bot',array_merge($safe,[
       'label'=>'Check EitaaYar API','description'=>'Check the independent EitaaYar delivery key and outbound connectivity using getMe. This does NOT verify the Eitaa Mini App program token or private message delivery. Never returns tokens or raw errors.',
       'output_schema'=>['type'=>'object','properties'=>['configured'=>['type'=>'boolean'],'reachable'=>['type'=>'boolean'],'authorized'=>['type'=>'boolean'],'http_status'=>['type'=>'integer','minimum'=>0],'result'=>['type'=>'string']],'additionalProperties'=>false],
       'execute_callback'=>[__CLASS__,'check_bot']]));
      wp_register_ability('qys-messenger/birthday-readiness',array_merge($safe,[
       'label'=>'Birthday service readiness',
       'description'=>'Read-only opt-in birthday service status and aggregate delivery counts. No personal data or message text.',
       'output_schema'=>['type'=>'object','properties'=>[
        'enabled'=>['type'=>'boolean'],'profile_schema_ready'=>['type'=>'boolean'],
        'birthday_log_ready'=>['type'=>'boolean'],'archive_ready'=>['type'=>'boolean'],'cron_scheduled'=>['type'=>'boolean'],
        'archive_total'=>['type'=>'integer'],'eligible_today'=>['type'=>'integer'],
        'pending'=>['type'=>'integer'],'sent'=>['type'=>'integer'],'failed'=>['type'=>'integer'],
        'cancelled'=>['type'=>'integer'],'uncertain'=>['type'=>'integer']],'additionalProperties'=>false],
       'execute_callback'=>[__CLASS__,'birthday_readiness']]));
      wp_register_ability('qys-messenger/archive-readiness',array_merge($safe,[
       'label'=>'Birthday archive and access audit readiness',
       'description'=>'Aggregate archive coverage and access audit counters, no personal records or messages.',
       'output_schema'=>['type'=>'object','properties'=>[
        'archive_ready'=>['type'=>'boolean'],'access_audit_ready'=>['type'=>'boolean'],
        'archive_total'=>['type'=>'integer'],'automatic_total'=>['type'=>'integer'],
        'test_total'=>['type'=>'integer'],'accepted_total'=>['type'=>'integer'],
                'archive_views'=>['type'=>'integer'],'export_requests'=>['type'=>'integer']],
        'additionalProperties'=>false],
       'execute_callback'=>[__CLASS__,'archive_readiness']]));
      wp_register_ability('qys-messenger/import-summary',array_merge($safe,[
       'label'=>'Import audit counters',
       'description'=>'Aggregate personal spreadsheet-import audit across campaigns; counts only, no national IDs, names, file contents, or message text.',
       'output_schema'=>['type'=>'object','properties'=>[
        'reports'=>['type'=>'integer'],'input_rows'=>['type'=>'integer'],'readable_rows'=>['type'=>'integer'],
        'queued_rows'=>['type'=>'integer'],'excluded_rows'=>['type'=>'integer'],'invalid_id'=>['type'=>'integer'],
        'duplicate_id'=>['type'=>'integer'],'unmatched_id'=>['type'=>'integer'],'ineligible_member'=>['type'=>'integer'],
        'ambiguous_match'=>['type'=>'integer'],'missing_variables'=>['type'=>'integer'],
        'queue_error'=>['type'=>'integer'],'malformed_csv'=>['type'=>'integer']], 'additionalProperties'=>false],
       'execute_callback'=>[__CLASS__,'import_summary']]));
     }
    static function readiness(){
     global $wpdb;
     $m=QYS_Member_Repository::table_exists();$profile_ready=$m&&QYS_Member_Repository::schema_ready();
     $c=QYS_Campaign_Repository::campaigns_ready();$q=QYS_Campaign_Repository::queue_ready();
     $miniapp_url=class_exists('QYS_Multitenant')?QYS_Multitenant::miniapp_url():'';
     $shortcode=false;if($miniapp_url!==''){$id=url_to_postid($miniapp_url);$p=$id?get_post($id):null;$shortcode=$p&&has_shortcode($p->post_content,'quranyar_miniapp');}
     $programs_ready=class_exists('QYS_Program_Repository')&&QYS_Program_Repository::schema_ready();
     $counts=['members_total'=>0,'members_enabled'=>0,'members_verified'=>0,'members_eligible'=>0,'members_disabled'=>0,'members_profile_complete'=>0,'members_birthday_opt_in'=>0,'welcome_accepted'=>0,'welcome_failed'=>0,'welcome_sending'=>0,'welcome_pending'=>0,'welcome_sent'=>0,'welcome_retryable_failed'=>0,'welcome_definitive_failed'=>0,'welcome_uncertain'=>0,'welcome_cancelled'=>0,'activation_sending'=>0,'activation_pending'=>0,'activation_sent'=>0,'activation_retryable_failed'=>0,'activation_definitive_failed'=>0,'activation_uncertain'=>0,'activation_cancelled'=>0,'birthday_pending'=>0,'birthday_sent'=>0,'birthday_failed'=>0,'birthday_cancelled'=>0,'birthday_uncertain'=>0,'campaigns_draft'=>0,'campaigns_active'=>0,'programs_total'=>0,'program_applications_total'=>0,'program_enrollments_total'=>0,'program_attendance_links_total'=>0,'queue_pending'=>0,'queue_sending'=>0,'queue_sent'=>0,'queue_failed'=>0,'queue_retryable_failed'=>0,'queue_definitive_failed'=>0,'queue_uncertain'=>0,'queue_legacy_failed'=>0,'queue_cancelled'=>0];
     if($m){$v=QYS_Member_Repository::readiness_counts();foreach(['total'=>'members_total','enabled'=>'members_enabled','verified'=>'members_verified','eligible'=>'members_eligible','disabled'=>'members_disabled','complete'=>'members_profile_complete','birthdays'=>'members_birthday_opt_in'] as $k=>$d)$counts[$d]=(int)($v[$k]??0);}
     if($c||$q){$v=QYS_Campaign_Repository::readiness_counts();$counts['campaigns_draft']=(int)($v['draft']??0);$counts['campaigns_active']=(int)($v['active']??0);foreach(['pending','sending','sent','failed','retryable_failed','definitive_failed','uncertain','legacy_failed','cancelled'] as $k)$counts['queue_'.$k]=(int)($v[$k]??0);}
     if($programs_ready){$v=QYS_Program_Repository::readiness_counts();$counts['programs_total']=(int)($v['programs']??0);$counts['program_applications_total']=(int)($v['applications']??0);$counts['program_enrollments_total']=(int)($v['enrollments']??0);$counts['program_attendance_links_total']=(int)($v['attendance_links']??0);}
     if(self::welcome_log_ready()){$v=self::onboarding_delivery_counts('welcome');foreach(['pending','sending','sent','retryable_failed','definitive_failed','uncertain','cancelled'] as $k)$counts['welcome_'.$k]=(int)($v[$k]??0);$counts['welcome_accepted']=$counts['welcome_sent']+(int)($v['legacy_accepted']??0);$counts['welcome_failed']=$counts['welcome_retryable_failed']+$counts['welcome_definitive_failed']+$counts['welcome_uncertain']+(int)($v['legacy_failed']??0);}
     if(self::activation_log_ready()){$v=self::onboarding_delivery_counts('activation');foreach(['pending','sending','sent','retryable_failed','definitive_failed','uncertain','cancelled'] as $k)$counts['activation_'.$k]=(int)($v[$k]??0);}
     if(self::birthday_log_ready()){$v=$wpdb->get_row("SELECT COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='sent'),0) sent,COALESCE(SUM(status='failed'),0) failed,COALESCE(SUM(status='cancelled'),0) cancelled,COALESCE(SUM(status='sending'),0) uncertain FROM ".self::table('birthday_log'),ARRAY_A);if(is_array($v))foreach(['pending','sent','failed','cancelled','uncertain'] as $k)$counts['birthday_'.$k]=(int)$v[$k];}
     return array_merge(['version'=>QYS_Config::VERSION,'schema_version'=>(string)get_option('qys_schema_version',''),'checked_at_utc'=>gmdate('c'),'webview_entry'=>$miniapp_url?:home_url('/'),'private_delivery_gateway'=>'eitaa_miniapp','webapp_token_configured'=>QYS_Token_Store::secret('webapp')!=='','delivery_token_configured'=>QYS_Token_Store::secret('delivery')!=='','members_table'=>$m,'profile_schema_ready'=>$profile_ready,'welcome_log_ready'=>self::welcome_log_ready(),'activation_log_ready'=>self::activation_log_ready(),'birthday_log_ready'=>self::birthday_log_ready(),'birthday_enabled'=>self::birthday_enabled(),'campaigns_table'=>$c,'queue_table'=>$q,'programs_schema_ready'=>$programs_ready,'cron_scheduled'=>(bool)wp_next_scheduled('qys_send_batch'),'webview_shortcode_present'=>(bool)$shortcode,'zip_supported'=>class_exists('ZipArchive'),'simplexml_supported'=>function_exists('simplexml_load_string')],$counts);
    }

    static function import_summary(){global $wpdb;
      $keys=['input_rows','readable_rows','queued_rows','invalid_id','duplicate_id','unmatched_id','ineligible_member','ambiguous_match','missing_variables','queue_error','malformed_csv'];
      $out=array_fill_keys(array_merge(['reports','excluded_rows'],$keys),0);
      $t=self::table('import_reports');if(!self::table_exists($t))return $out;
      $expr=['COUNT(*) AS reports'];foreach($keys as $k)$expr[]='COALESCE(SUM('.$k.'),0) AS '.$k;
      $row=$wpdb->get_row('SELECT '.implode(',',$expr).' FROM '.$t,ARRAY_A);
      if(is_array($row))foreach(array_merge(['reports'],$keys) as $k)$out[$k]=max(0,(int)($row[$k]??0));
      $out['excluded_rows']=max(0,$out['input_rows']-$out['queued_rows']);
      return $out;
     }

}
