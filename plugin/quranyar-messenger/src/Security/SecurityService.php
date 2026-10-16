<?php
if (!defined('ABSPATH')) exit;

/**
 * Central security boundary for signed Eitaa WebApp requests, global-owner
 * authority and privacy headers. Feature modules must not reimplement these.
 */
final class QYS_Security_Service {
    private static array $request_users=[];

    public static function init(): void {
        add_action('template_redirect',[__CLASS__,'protect_app_surfaces'],0);
    }

    /** The central owner is authoritative only while the WordPress capability is still present. */
    public static function is_global_admin($user_id=null): bool {
        $uid=$user_id===null?get_current_user_id():(int)$user_id;
        $owner=max(0,(int)get_option(QYS_Config::OWNER_OPTION,0));
        if($uid<=0 || $owner<=0 || $uid!==$owner)return false;
        return $user_id===null?current_user_can('manage_options'):user_can($uid,'manage_options');
    }

    /**
     * Verify the signed Eitaa initData before WordPress dispatches a public REST
     * callback. A small per-user throttle limits replay/flood abuse without
     * storing personal identifiers in transient names.
     */
    public static function authorize_eitaa(WP_REST_Request $req,string $bucket,int $limit=120,int $window=60){
        if(function_exists('nocache_headers'))nocache_headers();
        $user=self::verify_init_data($req->get_param('initData'));
        if(is_wp_error($user))return $user;
        $id=(string)$user['id'];
        if(!self::consume_limit($id,$bucket,$limit,$window)){
            return new WP_Error('rate_limited','تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.',['status'=>429]);
        }
        self::$request_users[spl_object_id($req)]=$user;
        return true;
    }

    /** Retrieve the user already authenticated by the route permission callback. */
    public static function request_user(WP_REST_Request $req){
        $key=spl_object_id($req);
        if(isset(self::$request_users[$key]))return self::$request_users[$key];
        // Fail-safe for direct/internal callback invocation outside REST dispatch.
        return self::verify_init_data($req->get_param('initData'));
    }

    /** Strict parser and validator for Eitaa WebApp initData. */
    public static function verify_init_data($raw){
        $token=QYS_Token_Store::secret('webapp');
        if(!$token || !is_string($raw) || $raw==='' || strlen($raw)>12000){
            return new WP_Error('configuration','تنظیمات ایتا یا داده ورود موجود نیست',['status'=>401]);
        }
        $parts=explode('&',$raw);
        if(count($parts)<3 || count($parts)>32)return new WP_Error('init','داده ورود نامعتبر است',['status'=>401]);
        $params=[];
        foreach($parts as $part){
            $pair=explode('=',$part,2);
            if(count($pair)!==2)return new WP_Error('init','داده ورود نامعتبر است',['status'=>401]);
            $key=urldecode($pair[0]);$value=urldecode($pair[1]);
            if($key==='' || strlen($key)>64 || strlen($value)>9000 || str_contains($key,"\0") || str_contains($value,"\0") || isset($params[$key])){
                return new WP_Error('init','داده ورود نامعتبر است',['status'=>401]);
            }
            if(str_contains($key,'[') || str_contains($key,']'))return new WP_Error('init','داده ورود نامعتبر است',['status'=>401]);
            $params[$key]=$value;
        }
        $given=$params['hash']??'';unset($params['hash']);
        if(!is_string($given)||!preg_match('/\A[0-9a-f]{64}\z/iD',$given)||empty($params['user'])||empty($params['auth_date'])){
            return new WP_Error('init','داده ورود ناقص است',['status'=>401]);
        }
        $date_raw=(string)$params['auth_date'];
        if(!preg_match('/\A[0-9]{9,12}\z/D',$date_raw))return new WP_Error('expired','زمان ورود معتبر نیست',['status'=>401]);
        $date=(int)$date_raw;$now=time();
        if($date>$now+120 || $now-$date>3600)return new WP_Error('expired','ورود منقضی شده؛ برنامک را دوباره باز کنید',['status'=>401]);
        ksort($params,SORT_STRING);
        $pairs=[];
        foreach($params as $key=>$value){
            if(!is_string($value))return new WP_Error('init','داده ورود نامعتبر است',['status'=>401]);
            $pairs[]=$key.'='.$value;
        }
        $secret=hash_hmac('sha256',$token,'WebAppData',true);
        $calculated=hash_hmac('sha256',implode("\n",$pairs),$secret);
        if(!hash_equals($calculated,strtolower($given)))return new WP_Error('signature','امضای ایتا نامعتبر است',['status'=>401]);
        $user=json_decode((string)$params['user'],true,8,JSON_BIGINT_AS_STRING);
        if(!is_array($user)||!isset($user['id'])||!preg_match('/\A[0-9]{1,20}\z/D',(string)$user['id'])){
            return new WP_Error('user','شناسه کاربر نامعتبر است',['status'=>401]);
        }
        return $user;
    }

    private static function consume_limit(string $subject,string $bucket,int $limit,int $window): bool {
        $limit=max(1,min(1000,$limit));$window=max(10,min(3600,$window));
        $bucket=preg_replace('/[^a-z0-9_\-]/i','',substr($bucket,0,40));
        if($bucket==='')$bucket='rest';
        $key='qys_rl_'.substr(hash_hmac('sha256',$subject.'|'.$bucket,wp_salt('nonce')),0,32);
        $state=get_transient($key);
        $now=time();
        if(!is_array($state)||!isset($state['count'],$state['until'])||(int)$state['until']<=$now){
            set_transient($key,['count'=>1,'until'=>$now+$window],$window);
            return true;
        }
        if((int)$state['count'] >= $limit)return false;
        $state['count']=(int)$state['count']+1;
        $ttl=max(1,(int)$state['until']-$now);
        set_transient($key,$state,$ttl);
        return true;
    }

    /** App/portal pages must not be cached or indexed and must not leak referrers. */
    public static function protect_app_surfaces(): void {
        if(!function_exists('is_page'))return;
        $portal_id=(int)get_option(QYS_Config::PORTAL_PAGE_OPTION,0);
        $home_id=(int)get_option(QYS_Config::HOME_PAGE_OPTION,0);
        $mini_id=(int)get_option(QYS_Config::MINIAPP_PAGE_OPTION,0);
        $portal=($portal_id>0 && is_page($portal_id));
        if(!$portal && $home_id>0 && get_option('show_on_front')==='page' && (int)get_option('page_on_front')===$home_id){
            $portal=function_exists('is_front_page') && is_front_page() && is_page($home_id);
        }
        $is_mini=$mini_id>0 && is_page($mini_id);
        if(!$portal&&!$is_mini)return;
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        if(function_exists('nocache_headers'))nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive',true);
        header('Referrer-Policy: no-referrer',true);
        header('X-Content-Type-Options: nosniff',true);
    }
}
