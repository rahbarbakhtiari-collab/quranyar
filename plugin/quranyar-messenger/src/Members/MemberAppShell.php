<?php
if (!defined('ABSPATH')) exit;

/**
 * Presentation-only shell for the Eitaa member mini app.
 * Keeps member navigation and app-level destinations out of profile/storage logic.
 */
final class QYS_Member_App_Shell {
    public const NAV = [
        'home'       => 'خانه',
        'profile'    => 'پرونده',
        'attendance' => 'حضور',
        'services'   => 'خدمات',
        'more'       => 'بیشتر',
    ];

    private static function icon(string $name): string {
        $paths=[
            'home'=>'<path d="M3 10.8 12 3l9 7.8v9.7a.5.5 0 0 1-.5.5H15v-6H9v6H3.5a.5.5 0 0 1-.5-.5z"/>',
            'profile'=>'<circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/>',
            'attendance'=>'<path d="M6 3h12v18H6z"/><path d="m9 12 2 2 4-5"/>',
            'services'=>'<path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="8"/>',
            'more'=>'<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        ];
        return '<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">'.($paths[$name]??$paths['more']).'</svg>';
    }

    public static function home_dashboard(): string {
        return '<section class="qys-home-dashboard" aria-label="خلاصه امروز">'
            .'<article id="qys-home-attendance-card" class="qys-home-primary-card">'
            .'<div class="qys-home-card-copy"><span class="qys-section-kicker">حضور من</span><h2 id="qys-home-attendance-title">در حال بررسی جلسه‌های شما</h2><p id="qys-home-attendance-meta">اگر جلسه‌ای برای شما فعال باشد، همین‌جا می‌بینید.</p></div>'
            .'<button type="button" id="qys-home-primary-action" class="qys-home-primary-action" data-qys-go="attendance">رفتن به حضور</button>'
            .'</article>'
            .'<div class="qys-home-mini-grid">'
            .'<article class="qys-home-mini-card"><span class="qys-home-mini-icon">'.self::icon('services').'</span><div><span>پیام‌ها</span><strong id="qys-home-message-state">در حال بررسی</strong></div></article>'
            .'<article class="qys-home-mini-card"><span class="qys-home-mini-icon qys-home-mini-icon--gift" aria-hidden="true">✦</span><div><span>تبریک تولد</span><strong id="qys-home-birthday-state">در حال بررسی</strong></div></article>'
            .'</div>'
            .'<section id="qys-home-programs" class="qys-home-programs" hidden aria-labelledby="qys-home-programs-title">'
            .'<div class="qys-home-programs-head"><div><span class="qys-section-kicker">آخرین برنامه‌ها</span><h2 id="qys-home-programs-title">برنامه‌های جاری</h2></div><button type="button" class="qys-home-programs-all" data-qys-go="programs">مشاهده همه</button></div>'
            .'<div id="qys-home-program-list" class="qys-home-program-list" aria-live="polite"></div>'
            .'</section>'
            .'</section>';
    }

    public static function services_panel(): string {
        return '<section id="qys-view-services" class="qys-member-view" data-qys-view="services" hidden aria-hidden="true" aria-labelledby="qys-services-title">'
            .'<div class="qys-view-heading"><span class="qys-section-kicker">خدمات من</span><h2 id="qys-services-title">خدمات قرآن‌یار</h2><p>از اینجا وضعیت خدمات خود را ببینید و به هر بخش بروید.</p></div>'
            .'<div class="qys-service-list">'
            .'<article id="qys-service-message-card" class="qys-service-card"><span class="qys-service-icon">'.self::icon('services').'</span><div class="qys-service-copy"><div class="qys-service-title-row"><strong>پیام‌های خصوصی</strong><span id="qys-service-message-state" class="qys-service-state is-loading">در حال بررسی</span></div><p>پیام‌های مهم قرآن‌یار را در گفت‌وگوی خصوصی ایتا دریافت می‌کنید.</p><button type="button" class="qys-service-link" data-qys-go="profile">پرونده و دریافت پیام <b aria-hidden="true">‹</b></button></div></article>'
            .'<article id="qys-service-attendance-card" class="qys-service-card"><span class="qys-service-icon">'.self::icon('attendance').'</span><div class="qys-service-copy"><div class="qys-service-title-row"><strong>حضور و غیاب</strong><span id="qys-service-attendance-state" class="qys-service-state is-loading">در حال بررسی</span></div><p>در جلسه‌ها، ورود و خروج خود را با QR یا کد شش‌رقمی ثبت کنید.</p><button type="button" class="qys-service-link" data-qys-go="attendance">مشاهده حضور <b aria-hidden="true">‹</b></button></div></article>'
            .'<article id="qys-service-program-card" class="qys-service-card"><span class="qys-service-icon">'.self::icon('services').'</span><div class="qys-service-copy"><div class="qys-service-title-row"><strong>برنامه‌ها و ثبت‌نام</strong><span id="qys-service-program-state" class="qys-service-state is-loading">در حال بررسی</span></div><p>برای برنامه‌ها ثبت‌نام کنید و نتیجه درخواست‌ها و سوابق خود را ببینید.</p><button type="button" class="qys-service-link" data-qys-go="programs">مشاهده برنامه‌ها <b aria-hidden="true">‹</b></button></div></article>'
            .'<article id="qys-service-birthday-card" class="qys-service-card"><span class="qys-service-icon qys-service-icon--gift" aria-hidden="true">✦</span><div class="qys-service-copy"><div class="qys-service-title-row"><strong>تبریک تولد</strong><span id="qys-service-birthday-state" class="qys-service-state is-loading">در حال بررسی</span></div><p>اگر این گزینه را فعال کنید، روز تولدتان پیام تبریک دریافت می‌کنید.</p><button type="button" class="qys-service-link" data-qys-go="profile">تنظیم تبریک تولد <b aria-hidden="true">‹</b></button></div></article>'
            .'</div><div id="qys-services-note" class="qys-services-note" role="status" aria-live="polite">در حال بررسی وضعیت خدمات شما...</div></section>';
    }

    public static function more_panel(): string {
        $privacy=function_exists('get_privacy_policy_url')?get_privacy_policy_url():'';
        return '<section id="qys-view-more" class="qys-member-view" data-qys-view="more" hidden aria-hidden="true" aria-labelledby="qys-more-title">'
            .'<div class="qys-view-heading"><span class="qys-section-kicker">حساب من</span><h2 id="qys-more-title">بیشتر</h2><p>اطلاعات حساب و تنظیمات اصلی شما اینجاست.</p></div>'
            .'<div class="qys-account-summary"><div class="qys-account-avatar" aria-hidden="true"><span id="qys-more-avatar-letter">ق</span></div><div class="qys-account-summary-copy"><span>حساب قرآن‌یار</span><strong id="qys-more-account-name">همراه قرآنی</strong><small id="qys-more-account-state">در حال بررسی وضعیت حساب</small></div><span id="qys-more-account-badge" class="qys-service-state is-loading">در حال بررسی</span></div>'
            .'<div class="qys-account-card"><div><span>دانشگاه</span><strong id="qys-more-university">—</strong></div><div><span>نوع عضویت</span><strong id="qys-more-role">—</strong></div></div>'
            .'<div class="qys-more-list">'
            .'<button type="button" data-qys-go="profile"><span><strong>پرونده و دریافت پیام</strong><small id="qys-more-consent-state">وضعیت در حال بررسی است</small></span><b aria-hidden="true">‹</b></button>'
            .($privacy?'<a href="'.esc_url($privacy).'" target="_blank" rel="noopener noreferrer"><span><strong>حریم خصوصی</strong><small>نحوه نگهداری و استفاده از اطلاعات</small></span><b aria-hidden="true">‹</b></a>':'')
            .'<button type="button" id="qys-more-refresh"><span><strong>تازه‌سازی اطلاعات</strong><small id="qys-more-sync-state">برای تازه‌کردن اطلاعات بزنید</small></span><b aria-hidden="true">↻</b></button>'
            .'</div></section>';
    }

    public static function bottom_nav(): string {
        $out='<nav class="qys-member-bottomnav" aria-label="ناوبری اصلی برنامک">';
        foreach(self::NAV as $key=>$label){
            $out.='<button type="button" data-qys-nav="'.esc_attr($key).'" aria-controls="qys-view-'.esc_attr($key).'"'.($key==='home'?' aria-current="page"':'').'>'
                .self::icon($key).'<span>'.esc_html($label).'</span></button>';
        }
        return $out.'</nav>';
    }
}
