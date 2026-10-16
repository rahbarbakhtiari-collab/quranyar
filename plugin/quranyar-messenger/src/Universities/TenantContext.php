<?php
if (!defined('ABSPATH')) exit;

/** Single source of truth for the active global/campus execution context. */
final class QYS_Tenant_Context {
    public const GLOBAL='global';

    private static ?string $cached=null;

    public static function reset(): void { self::$cached=null; }

    public static function current(): string {
        if(self::$cached!==null)return self::$cached;
        $portal='';
        if(QYS_Multitenant::is_portal_request()){
            $portal=QYS_Multitenant::campus_university();
        }else{
            $source=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?$_POST:$_GET;
            $flag=sanitize_key(wp_unslash($source['qys_portal_scope']??''));
            if($flag==='1'){
                $code=sanitize_key(wp_unslash($source['qys_portal_university']??''));
                $campus=QYS_Multitenant::campus_university();
                // Explicit portal intent is a security boundary. Never fall back
                // to global scope when the submitted campus is missing/forged.
                if($campus==='' || $code==='' || !hash_equals($campus,$code))return self::$cached='';
                $portal=$campus;
            }
        }
        if($portal!=='')return self::$cached=$portal;
        if(QYS_Security_Service::is_global_admin())return self::$cached=self::GLOBAL;
        return self::$cached='';
    }

    public static function is_global(): bool { return self::current()===self::GLOBAL; }
    public static function campus(): string { $c=self::current(); return $c!==''&&$c!==self::GLOBAL?$c:''; }
    public static function has_context(): bool { return self::current()!==''; }
}
