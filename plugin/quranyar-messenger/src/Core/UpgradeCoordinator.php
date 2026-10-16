<?php
if (!defined('ABSPATH')) exit;

/**
 * Safe automatic upgrade runner and readiness gate.
 *
 * C2 deliberately separates upgrades from first-install ownership bootstrap:
 * - Fresh/uninitialized installs still require an authenticated administrator.
 * - Once the C1 ledger exists, pending migrations can advance on ordinary
 *   WordPress requests without waiting for an admin_init visit.
 *
 * All public QuranYar surfaces fail closed while a migration is pending,
 * running or failed. The rest of the WordPress site remains available.
 */
final class QYS_Upgrade_Coordinator {
    private const STATUS_OPTION='qys_upgrade_runner_status_v1';
    private const ATTEMPT_OPTION='qys_upgrade_runner_last_attempt_epoch';
    private const AUTO_COOLDOWN_SECONDS=5;
    private const MAX_MIGRATIONS_PER_REQUEST=1;
    private const REQUEST_BUDGET_MS=1500;
    private const RETRY_AFTER_SECONDS=15;

    public static function init(): void {
        // Runs on public, REST, cron and admin requests. This is intentionally
        // independent from admin_init and executes before normal init handlers.
        add_action('init',[__CLASS__,'maybe_run_automatic'],-1000);

        // Central gates. QYS health diagnostics remain available in wp-admin.
        add_filter('rest_pre_dispatch',[__CLASS__,'gate_rest'],1,3);
        add_action('template_redirect',[__CLASS__,'gate_public_request'],-100);
        // Priority 5 lets Installer::maybe_run (priority 1) perform a genuine
        // first-install bootstrap before the admin gate evaluates readiness.
        add_action('admin_init',[__CLASS__,'gate_admin_request'],5);
    }

    public static function ready(): bool {
        return class_exists('QYS_Installer') && QYS_Installer::ready();
    }

    /**
     * Automatic post-baseline migration runner. Never bootstraps a fresh owner.
     */
    public static function maybe_run_automatic(): void {
        if(!class_exists('QYS_Migration_Ledger'))return;

        // Safe skip-version path: an RC18-compatible installation can acquire
        // the ledger baseline automatically. A fresh/pending installation does
        // not satisfy this predicate and still waits for an administrator.
        if(!QYS_Migration_Ledger::initialized()){
            if(!QYS_Installer::automatic_baseline_eligible())return;
            if(!QYS_Migration_Ledger::initialize_baseline('automatic_existing'))return;
        }
        if(self::ready())return;

        $now=time();
        $last=max(0,(int)get_option(self::ATTEMPT_OPTION,0));
        if($last>0 && ($now-$last)<self::AUTO_COOLDOWN_SECONDS)return;
        update_option(self::ATTEMPT_OPTION,$now,false);

        $started=microtime(true);
        $result=QYS_Installer::run_automatic_upgrade(self::MAX_MIGRATIONS_PER_REQUEST,self::REQUEST_BUDGET_MS);
        $duration=(int)round((microtime(true)-$started)*1000);
        self::store_status($result,max(0,$duration));
    }

    public static function status(): array {
        $raw=get_option(self::STATUS_OPTION,[]);
        if(!is_array($raw))$raw=[];
        $last_epoch=max(0,(int)($raw['last_attempt_epoch']??0));
        return [
            'ready'=>self::ready(),
            'automatic_enabled'=>true,
            'last_attempt_utc'=>sanitize_text_field((string)($raw['last_attempt_utc']??'')),
            'last_attempt_age_seconds'=>$last_epoch>0?max(0,time()-$last_epoch):-1,
            'last_result'=>sanitize_key((string)($raw['last_result']??'never')),
            'last_ran'=>max(0,(int)($raw['last_ran']??0)),
            'remaining'=>max(0,(int)($raw['remaining']??0)),
            'last_duration_ms'=>max(0,(int)($raw['last_duration_ms']??0)),
        ];
    }

    /** Block all QuranYar REST application endpoints while storage is not ready. */
    public static function gate_rest($result,$server,$request){
        if(self::ready())return $result;
        $route=is_object($request)&&method_exists($request,'get_route')?(string)$request->get_route():'';
        if(str_starts_with($route,'/qys/v1/')){
            return new WP_Error('qys_upgrade_in_progress','قرآن‌یار در حال تکمیل ارتقای فنی است. چند لحظه دیگر دوباره تلاش کنید.',[
                'status'=>503,'retry_after'=>self::RETRY_AFTER_SECONDS,
            ]);
        }
        return $result;
    }

    /** Protect the standalone university portal and member mini-app. */
    public static function gate_public_request(): void {
        if(self::ready() || !class_exists('QYS_Multitenant'))return;
        $is_qys=QYS_Multitenant::is_portal_request();
        $miniapp_id=(int)get_option(QYS_Multitenant::MINIAPP_PAGE_OPTION,0);
        if(!$is_qys && $miniapp_id>0 && function_exists('is_page'))$is_qys=is_page($miniapp_id);
        if(!$is_qys)return;
        self::service_unavailable();
    }

    /**
     * Fail closed for QuranYar wp-admin/admin-post/AJAX writes while migrations
     * are incomplete. Health Center is intentionally left accessible.
     */
    public static function gate_admin_request(): void {
        if(self::ready())return;
        $action=sanitize_key((string)wp_unslash($_REQUEST['action']??''));

        if(function_exists('wp_doing_ajax') && wp_doing_ajax() && str_starts_with($action,'qys_')){
            wp_send_json_error(['code'=>'qys_upgrade_in_progress','message'=>'قرآن‌یار در حال تکمیل ارتقا است.'],503);
        }

        if($action!=='' && str_starts_with($action,'qys_'))self::service_unavailable();

        $page=sanitize_key((string)wp_unslash($_GET['page']??''));
        if($page!=='' && str_starts_with($page,'qys') && $page!=='qys-health')self::service_unavailable();
    }

    /** Reusable guard for cron workers and shortcode fallbacks. */
    public static function worker_allowed(): bool { return self::ready(); }

    public static function unavailable_markup(): string {
        return '<section class="qys-system-state qys-system-state--warning" dir="rtl"><h2>قرآن‌یار در حال ارتقا است</h2><p>ارتقای فنی سامانه در حال تکمیل است. چند لحظه دیگر دوباره تلاش کنید.</p></section>';
    }

    private static function service_unavailable(): void {
        if(function_exists('status_header'))status_header(503);
        if(function_exists('nocache_headers'))nocache_headers();
        if(!headers_sent())header('Retry-After: '.self::RETRY_AFTER_SECONDS);
        wp_die('قرآن‌یار در حال تکمیل ارتقای فنی است. چند لحظه دیگر دوباره تلاش کنید.','قرآن‌یار — ارتقا در حال انجام',['response'=>503]);
    }

    private static function store_status(array $result,int $duration_ms): void {
        $safe=[
            'last_attempt_utc'=>gmdate('c'),
            'last_attempt_epoch'=>time(),
            'last_result'=>sanitize_key((string)($result['result']??'unknown')),
            'last_ran'=>max(0,(int)($result['ran']??0)),
            'remaining'=>max(0,(int)($result['remaining']??0)),
            'last_duration_ms'=>max(0,$duration_ms),
        ];
        update_option(self::STATUS_OPTION,$safe,false);
    }
}
