<?php
if (!defined('ABSPATH')) exit;

/**
 * Backward-compatibility facade only.
 *
 * C7 moved the canonical product feature registry into QYS_Module_Registry so
 * modules own their portal capabilities. Core runtime code no longer depends on
 * this class; it remains only for extensions that used the historical API.
 */
final class QYS_Feature_Registry {
    public static function bootstrap_defaults(): void { /* Features are module-owned from C7. */ }
    public static function all(): array { return QYS_Module_Registry::features(); }
    public static function by_section(string $section): ?array { return QYS_Module_Registry::feature_by_section($section); }
    public static function sections(): array { return QYS_Module_Registry::sections(); }
    public static function visible_for_current_user(): array { return QYS_Portal_Feature_Service::visible_for_current_user(); }
    public static function visible_group(string $group): array { return QYS_Portal_Feature_Service::visible_group($group); }
    public static function primary_section(string $section): string { return QYS_Module_Registry::primary_section($section); }
    public static function dispatch(string $section): void { QYS_Portal_Feature_Service::dispatch($section); }
}
