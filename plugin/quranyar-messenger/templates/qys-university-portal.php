<?php
/** Standalone campus dashboard. Global administration never renders here. */
if (!defined('ABSPATH')) exit;
$portal_url=QYS_Multitenant::campus_entry_url();
$logged_in=is_user_logged_in();
$campus=$logged_in ? QYS_Tenant_Context::campus() : '';
$section=sanitize_key(wp_unslash($_GET['section']??'home'));
if(!QYS_Module_Registry::feature_by_section($section))$section='home';
$active_primary=QYS_Portal_Feature_Service::primary_section($section);
$label=$campus!==''?QYS_Multitenant::university_label($campus):'پنل دانشگاه';
$primary_features=($logged_in&&$campus!=='')?QYS_Portal_Feature_Service::visible_group('primary'):[];
$icon=static function(string $key): string {
    $paths=[
        'home'=>'<path d="M3 11.5 12 4l9 7.5v8a1.5 1.5 0 0 1-1.5 1.5H15v-6H9v6H4.5A1.5 1.5 0 0 1 3 19.5z"/>',
        'members'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'messages'=>'<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4zM8 9h8M8 13h5"/>',
        'programs'=>'<path d="M5 4h14a2 2 0 0 1 2 2v14H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 2v4M16 2v4M3 9h18M8 13h3M8 17h6"/>',
        'attendance'=>'<path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'more'=>'<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'.($paths[$key]??$paths['more']).'</svg>';
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html($label); ?> | قرآن‌یار</title>
<?php wp_head(); ?>
</head>
<body <?php body_class('qys-tenant-standalone'); ?>>
<?php wp_body_open(); ?>
<div class="qys-tenant-portal" dir="rtl">
  <a class="qys-tenant-skip-link" href="#qys-tenant-main">پرش به محتوای اصلی</a>
  <header class="qys-tenant-header">
    <div class="qys-tenant-header-inner">
      <a class="qys-tenant-brand" href="<?php echo esc_url($portal_url); ?>" aria-label="پنل دانشگاه قرآن‌یار">
        <span class="qys-tenant-brand-symbol" aria-hidden="true">ق</span>
        <span><strong>قرآن‌یار</strong><small><?php echo esc_html($campus!==''?$label:'پنل دانشگاه'); ?></small></span>
      </a>
      <?php if($logged_in && $campus!==''): ?>
      <nav class="qys-tenant-topnav" aria-label="بخش‌های اصلی دانشگاه">
        <?php foreach($primary_features as $feature): ?>
          <a href="<?php echo esc_url(QYS_Multitenant::campus_url($feature['section'])); ?>"<?php echo $active_primary===$feature['section']?' aria-current="page"':''; ?>><?php echo $icon($feature['section']); ?><span><?php echo esc_html($feature['label']); ?></span></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
      <div class="qys-tenant-user">
        <?php if($logged_in): ?><span class="qys-tenant-user-name"><?php echo esc_html(wp_get_current_user()->display_name); ?></span><a href="<?php echo esc_url(wp_logout_url($portal_url)); ?>">خروج</a>
        <?php else: ?><a href="<?php echo esc_url($portal_url); ?>#qys-login-title">ورود نماینده</a><?php endif; ?>
      </div>
    </div>
  </header>

  <div id="qys-tenant-system-state" class="qys-tenant-system-state is-ready" role="status" aria-live="polite" aria-atomic="true" hidden>
    <span class="qys-tenant-system-dot" aria-hidden="true"></span>
    <span id="qys-tenant-system-text"></span>
    <button type="button" id="qys-tenant-system-retry" hidden>تلاش دوباره</button>
  </div>

  <?php if($logged_in && $campus!=='' && $active_primary==='messages' && $section!=='messages'): ?>
  <div class="qys-tenant-contextbar">
    <div><a href="<?php echo esc_url(QYS_Multitenant::campus_url('messages')); ?>">← بازگشت به پیام‌ها</a><span><?php echo esc_html(QYS_Module_Registry::feature_by_section($section)['label']??'پیام‌ها'); ?></span></div>
  </div>
  <?php endif; ?>

  <main id="qys-tenant-main" class="qys-tenant-main" tabindex="-1">
    <?php if($logged_in && $campus!=='' && $section==='home'): ?>
    <div class="qys-tenant-intro"><span class="qys-tenant-eyebrow">پنل دانشگاه</span><h1><?php echo esc_html($label); ?></h1><p>امکانات این پنل متناسب با دسترسی‌های تأییدشده دانشگاه شما نمایش داده می‌شود.</p></div>
    <?php endif; ?>
    <?php echo do_shortcode('[qys_university_portal]'); ?>
  </main>

  <?php if($logged_in && $campus!==''): ?>
  <nav class="qys-tenant-bottomnav" aria-label="ناوبری اصلی موبایل">
    <?php foreach($primary_features as $feature): ?>
      <a href="<?php echo esc_url(QYS_Multitenant::campus_url($feature['section'])); ?>"<?php echo $active_primary===$feature['section']?' aria-current="page"':''; ?>><?php echo $icon($feature['section']); ?><span><?php echo esc_html($feature['label']); ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <footer class="qys-tenant-footer"><span>قرآن‌یار • پنل دانشگاه</span></footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
