<?php
if (!defined('ABSPATH')) exit;

/** Secure token persistence/config fallback boundary for QuranYar transports. */
final class QYS_Token_Store {
    private static function kind_valid(string $kind): bool { return in_array($kind,['webapp','delivery','ippanel'],true); }

    private static function option_name(string $kind): string {
        if($kind==='webapp')return 'qys_webapp_token_ciphertext';
        if($kind==='delivery')return 'qys_delivery_token_ciphertext';
        return 'qys_ippanel_token_ciphertext';
    }

    public static function secret(string $kind): string {
        if(!self::kind_valid($kind))return '';
        $saved=get_option(self::option_name($kind),'');
        if(is_string($saved)&&$saved!=='')return self::decrypt($saved);
        if($kind==='webapp'&&defined('QYS_EITAA_WEBAPP_TOKEN'))return trim((string)QYS_EITAA_WEBAPP_TOKEN);
        if($kind==='delivery'&&defined('QYS_EITAAYAR_TOKEN'))return trim((string)QYS_EITAAYAR_TOKEN);
        if($kind==='ippanel'&&defined('QYS_IPPANEL_API_KEY'))return trim((string)QYS_IPPANEL_API_KEY);
        return '';
    }

    public static function source(string $kind): string {
        if(!self::kind_valid($kind))return 'none';
        $saved=get_option(self::option_name($kind),'');
        if(is_string($saved)&&$saved!=='')return self::decrypt($saved)!==''?'admin':'unreadable';
        if($kind==='webapp'&&defined('QYS_EITAA_WEBAPP_TOKEN')&&QYS_EITAA_WEBAPP_TOKEN!=='')return 'config';
        if($kind==='delivery'&&defined('QYS_EITAAYAR_TOKEN')&&QYS_EITAAYAR_TOKEN!=='')return 'config';
        if($kind==='ippanel'&&defined('QYS_IPPANEL_API_KEY')&&QYS_IPPANEL_API_KEY!=='')return 'config';
        return 'none';
    }

    public static function key(): string { return hash('sha256',wp_salt('auth').'|'.wp_salt('secure_auth'),true); }

    public static function valid($token): bool {
        return is_string($token)&&strlen($token)>=10&&strlen($token)<=2048
            &&preg_match('/\A[\x21-\x7E]+\z/D',$token)===1;
    }

    public static function encrypt($token): string {
        if(!self::valid($token)||!function_exists('openssl_encrypt'))return '';
        try{$iv=random_bytes(12);}catch(Throwable $e){return '';}
        $tag='';$cipher=openssl_encrypt($token,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'qys-bot-token:v1',16);
        if($cipher===false||strlen($tag)!==16)return '';
        return 'v1:'.base64_encode($iv.$tag.$cipher);
    }

    public static function decrypt($stored): string {
        if(!function_exists('openssl_decrypt')||!is_string($stored)||!str_starts_with($stored,'v1:'))return '';
        $raw=base64_decode(substr($stored,3),true);
        if($raw===false||strlen($raw)<29||strlen($raw)>2300)return '';
        $token=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'qys-bot-token:v1');
        return self::valid($token)?$token:'';
    }

    public static function save(string $kind,string $token): bool {
        if(!self::kind_valid($kind)||!self::valid($token))return false;
        $encrypted=self::encrypt($token);
        if($encrypted==='')return false;
        $option=self::option_name($kind);
        if(get_option($option,null)===null)return (bool)add_option($option,$encrypted,'',false);
        return (bool)update_option($option,$encrypted,false);
    }

    public static function clear(string $kind): bool {
        if(!self::kind_valid($kind))return false;
        return (bool)delete_option(self::option_name($kind));
    }

    /** @return array{ready:bool,code:string} */
    public static function health(): array {
        $crypto=function_exists('openssl_encrypt')&&function_exists('openssl_decrypt');
        // Config-constant tokens remain usable even when encrypted option storage is unavailable.
        // Do not turn an optional storage capability into a critical boot dependency.
        return ['ready'=>true,'code'=>$crypto?'ok':'encryption_unavailable'];
    }
}
