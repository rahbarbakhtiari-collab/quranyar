<?php
if (!defined('ABSPATH')) exit;

/**
 * Member-app experience layer: loading/offline/error feedback and accessibility
 * helpers. No storage, permission, tenant or messaging decisions live here.
 */
final class QYS_Member_Experience {
    /** Phase E / E6: async member surfaces must never collapse errors into empty results. */
    public static function async_state_contract(): array {
        return [
            'ready'=>true,
            'loading'=>'visible',
            'empty'=>'distinct',
            'error'=>'visible',
            'retry'=>'explicit',
            'scopes'=>'home_programs_feature_loads',
        ];
    }

    /** RC52 maintenance: member-facing typography, contrast and plain-language copy. */
    public static function readability_contract(): array {
        return [
            'ready'=>true,
            'base_text'=>'16px',
            'secondary_text'=>'13px_typical',
            'compact_text'=>'12.5px_min',
            'contrast'=>'aa',
            'copy'=>'plain_fa',
            'technical_details'=>'hidden',
        ];
    }

    public static function skip_link(): string {
        return '<a class="qys-skip-link" href="#qys-member-content">پرش به محتوای اصلی</a>';
    }

    public static function system_state(): string {
        return '<div id="qys-app-system-state" class="qys-app-system-state is-loading" role="status" aria-live="polite" aria-atomic="true">'
            .'<span class="qys-app-state-spinner" aria-hidden="true"></span>'
            .'<span id="qys-app-system-text">در حال آماده‌کردن قرآن‌یار...</span>'
            .'<button type="button" id="qys-app-retry" class="qys-app-state-retry" hidden>تلاش دوباره</button>'
            .'</div>';
    }
}
