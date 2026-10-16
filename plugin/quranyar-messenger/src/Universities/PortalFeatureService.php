<?php
if (!defined('ABSPATH')) exit;

/**
 * University-portal feature access boundary.
 *
 * C7 keeps QYS_Module_Registry as pure module/feature metadata. Tenant
 * authorization and runtime module availability live here, so the registry no
 * longer depends back on Multitenant/PermissionService.
 */
final class QYS_Portal_Feature_Service {
    /** @return array<int,array<string,mixed>> */
    public static function visible_for_current_user(): array {
        return array_values(array_filter(QYS_Module_Registry::features(),static function($feature){
            if(!QYS_Module_Manager::module_available((string)$feature['module']))return false;
            return $feature['permission']==='' || QYS_Permission_Service::scope((string)$feature['permission'])!==null;
        }));
    }

    /** @return array<int,array<string,mixed>> */
    public static function visible_group(string $group): array {
        $group=sanitize_key($group);
        return array_values(array_filter(self::visible_for_current_user(),static fn($feature)=>$feature['nav_group']===$group));
    }

    public static function primary_section(string $section): string {
        return QYS_Module_Registry::primary_section($section);
    }

    public static function dispatch(string $section): void {
        $feature=QYS_Module_Registry::feature_by_section($section)??QYS_Module_Registry::feature_by_section('home');
        if(!$feature)return;
        if(!QYS_Module_Manager::module_available((string)$feature['module'])){
            echo '<section class="qys-tenant-state"><h2>این بخش موقتاً در دسترس نیست</h2><p>یکی از ماژول‌های این قابلیت در اجرای فعلی ایزوله شده است.</p></section>';
            return;
        }
        if($feature['permission']!=='' && QYS_Permission_Service::scope((string)$feature['permission'])===null){
            echo '<section class="qys-tenant-state"><h2>دسترسی این بخش برای شما فعال نیست</h2></section>';
            return;
        }
        call_user_func($feature['handler']);
    }
}
