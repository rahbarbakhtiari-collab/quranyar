<?php
if (!defined('ABSPATH')) exit;

/**
 * Stable application configuration boundary introduced in Phase C / C4.
 *
 * Runtime/domain code should read shared configuration from this class rather
 * than from the historical QYS_Messenger god class. QYS_Messenger keeps
 * compatibility constants during Phase C so existing extensions do not break.
 */
final class QYS_Config {
    public const VERSION='1.3.1-rc54';
    public const SCHEMA_VERSION='2026.10.11.2';
    public const DEFAULT_UNIVERSITY='saveh_ums';
    public const DEFAULT_UNIVERSITY_NAME='دانشگاه علوم پزشکی ساوه';
    public const APP_SEND_ENDPOINT='https://eitaayar.ir/api/app/sendMessage';
    public const IPPANEL_SEND_ENDPOINT='https://edge.ippanel.com/v1/api/send';

    // Canonical tenant/entry-point keys moved out of Multitenant in C5 so
    // Security can resolve authority and protected surfaces without depending
    // back on the Multitenant feature class.
    public const OWNER_OPTION='qys_global_owner_id';
    public const PORTAL_PAGE_OPTION='qys_university_portal_page_id';
    public const HOME_PAGE_OPTION='qys_university_home_page_id';
    public const MINIAPP_PAGE_OPTION='qys_miniapp_page_id';
    public const HOME_SLUG='university-login';
    public const MINIAPP_SLUG='miniapp';
    public const PORTAL_SLUG='qys';

    // Stable permission vocabulary. C7 keeps permission policy in the tenant
    // service while removing registry validation's dependency on Multitenant.
    public const PERMISSIONS=[
        'view_members'=>'مشاهده پرونده‌های دانشگاه',
        'verify_members'=>'تأیید هویت اعضای دانشگاه',
        'sensitive_identity'=>'مشاهده کد ملی و موبایل کامل',
        'send_messages'=>'ارسال فوری و زمان‌بندی‌شده',
        'birthday'=>'تنظیم و گزارش تبریک تولد دانشگاه در ایتا',
        'welcome'=>'تنظیم پیام‌های خودکار عضویت و فعال‌سازی حساب',
        'attendance'=>'مدیریت حضور و غیاب',
        'attendance_export'=>'خروجی گزارش حضور و غیاب',
        'programs'=>'مدیریت برنامه‌ها و ثبت‌نام‌ها',
        'programs_export'=>'خروجی برنامه‌ها و ثبت‌نام‌ها',
    ];

    private static string $plugin_file='';

    public static function bootstrap(string $plugin_file): void {
        $plugin_file=trim($plugin_file);
        if($plugin_file==='')throw new InvalidArgumentException('QuranYar plugin file is required');
        if(self::$plugin_file!=='' && self::$plugin_file!==$plugin_file){
            throw new RuntimeException('QuranYar config already bootstrapped');
        }
        self::$plugin_file=$plugin_file;
    }

    public static function plugin_file(): string {
        return self::$plugin_file!==''?self::$plugin_file:dirname(__DIR__,2).'/quranyar-messenger.php';
    }

    public static function plugin_dir(): string { return dirname(self::plugin_file()); }
    public static function version(): string { return self::VERSION; }
    public static function schema_version(): string { return self::SCHEMA_VERSION; }
    public static function default_university(): string { return self::DEFAULT_UNIVERSITY; }
    public static function default_university_name(): string { return self::DEFAULT_UNIVERSITY_NAME; }
    public static function app_send_endpoint(): string { return self::APP_SEND_ENDPOINT; }
    public static function ippanel_send_endpoint(): string { return self::IPPANEL_SEND_ENDPOINT; }

    /** @return array{ready:bool,code:string} */
    public static function health(): array {
        $ready=self::plugin_file()!=='' && self::VERSION!=='' && self::SCHEMA_VERSION!=='';
        return ['ready'=>$ready,'code'=>$ready?'ok':'config_not_ready'];
    }
}
