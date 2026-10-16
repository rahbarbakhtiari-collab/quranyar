<?php
if (!defined('ABSPATH')) exit;

trait QYS_Messaging_Module {
    static function secret($kind){return QYS_Token_Store::secret((string)$kind);}
    static function token_source($kind){return QYS_Token_Store::source((string)$kind);}
    static function token_key(){return QYS_Token_Store::key();}
    static function valid_token($token){return QYS_Token_Store::valid($token);}
    static function encrypt_token($token){return QYS_Token_Store::encrypt($token);}
    static function decrypt_token($stored){return QYS_Token_Store::decrypt($stored);}

    static function save_token_setting($action){
      if(!QYS_Security_Service::is_global_admin())return 'دسترسی کافی برای تغییر توکن وجود ندارد.';
      $kind=str_contains($action,'webapp')?'webapp':'delivery';
      if(!in_array($action,['save_webapp_token','clear_webapp_token','save_delivery_token','clear_delivery_token'],true))return 'عملیات نامعتبر.';
      $title=$kind==='webapp'?'توکن اختصاصی برنامک':'کلید API ایتایار';
      if(str_starts_with($action,'clear_')){
       QYS_Token_Store::clear($kind);
       return $title.' از پیشخوان حذف شد. ثابت wp-config.php در صورت تعریف باقی می‌ماند.';
      }
      $submitted=$_POST[$kind==='webapp'?'qys_webapp_token':'qys_delivery_token']??'';
      if(!is_string($submitted))return 'مقدار توکن نامعتبر است.';
      $token=trim(wp_unslash($submitted));
      if(!self::valid_token($token))return 'فرمت نامعتبر است؛ توکن باید ۱۰ تا ۲۰۴۸ نویسه ASCII قابل چاپ، بدون فاصله یا خط جدید داشته باشد.';
      if(!function_exists('openssl_encrypt')){unset($token,$submitted);return 'ذخیره امن امکان‌پذیر نیست؛ OpenSSL را بررسی کنید.';}
      $ok=QYS_Token_Store::save($kind,$token);
      unset($token,$submitted);
      return $ok?$title.' رمزگذاری و ذخیره شد.': 'ذخیره انجام نشد؛ دوباره تلاش کنید.';
     }
    static function check_bot(){
     $token=self::secret('delivery');$base=['configured'=>$token!=='','reachable'=>false,'authorized'=>false,'http_status'=>0,'result'=>'not_configured'];
     if($token==='')return $base;
     $url='https://eitaayar.ir/api/'.rawurlencode($token).'/getMe';
     $response=wp_remote_get($url,['timeout'=>12,'redirection'=>0,'reject_unsafe_urls'=>true,'limit_response_size'=>65536]);
     if(is_wp_error($response))return array_merge($base,['result'=>'network_error']);
     $code=(int)wp_remote_retrieve_response_code($response);$body=json_decode(wp_remote_retrieve_body($response),true);
     return ['configured'=>true,'reachable'=>true,'authorized'=>$code===200&&!empty($body['ok']),'http_status'=>$code,'result'=>$code===200&&!empty($body['ok'])?'ok':'http_error'];
    }

    static function failure_reason($response,$body){
      if(is_wp_error($response))return 'network_failure';
      $http=(int)wp_remote_retrieve_response_code($response);
      if(!is_array($body))return 'HTTP '.$http.'; reason=invalid_response';
      $reason='api_rejected';
      $raw='';
      foreach(['description','message','error','result'] as $key){
       if(isset($body[$key])&&is_string($body[$key])){$raw=$body[$key];break;}
      }
      $raw=self::lower($raw);
      if(str_contains($raw,'token')||str_contains($raw,'unauthorized'))$reason='invalid_token';
      elseif(str_contains($raw,'chat not found')||str_contains($raw,'chat_id')||str_contains($raw,'user not found'))$reason='chat_not_found';
      elseif(str_contains($raw,'forbidden')||str_contains($raw,'permission')||str_contains($raw,'blocked')||str_contains($raw,'access denied'))$reason='permission_denied';
      elseif(str_contains($raw,'rate limit')||str_contains($raw,'too many')||$http===429)$reason='rate_limited';
      $code=isset($body['error_code'])&&is_numeric($body['error_code'])?(int)$body['error_code']:0;
      return 'HTTP '.$http.'; reason='.$reason.($code?'; code='.$code:'');
     }

    static function safe_failure_label($stored){
      $stored=trim((string)$stored);
      if(preg_match('/^API result HTTP ([1-5][0-9]{2})$/',$stored,$m))return 'HTTP '.$m[1].'';
      if(preg_match('/^HTTP ([1-5][0-9]{2}); reason=([a-z_]{1,30})(?:; code=([0-9]{1,6}))?$/',$stored,$m))return 'HTTP '.$m[1].' / '.$m[2].(isset($m[3])?' / code '.$m[3]:'');
      if($stored==='network_failure')return 'خطای ارتباط شبکه (ممکن است وضعیت تحویل نامشخص باشد)';
      if($stored==='app_token_not_configured')return 'توکن اختصاصی برنامک تنظیم نشده است';
      if($stored==='invalid_private_message')return 'شناسه مخاطب یا متن پیام معتبر نیست';
      return $stored!==''?'خطای قدیمی/نامشخص؛ متن خام به دلایل امنیتی نمایش داده نمی‌شود.':'—';
     }

    static function send_batch(){ if(class_exists('QYS_Upgrade_Coordinator')&&!QYS_Upgrade_Coordinator::worker_allowed()){if(class_exists('QYS_Health_Center'))QYS_Health_Center::mark_result('schema_not_ready');return;} $result=QYS_Queue_Service::process_targeted_batch(); if(class_exists('QYS_Health_Center'))QYS_Health_Center::mark_result($result); }

}
