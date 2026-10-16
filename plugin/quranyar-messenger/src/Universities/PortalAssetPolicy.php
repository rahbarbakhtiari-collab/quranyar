<?php
if (!defined('ABSPATH')) exit;

/**
 * University portal asset policy (Phase D / D10).
 *
 * The standalone /qys/ shell owns only shared layout assets. Feature modules
 * keep ownership of their own CSS/JS and may enqueue them only when the
 * requested section is both active and authorized for the current campus.
 */
final class QYS_Portal_Asset_Policy {
    private const FEATURE_BUNDLES=[
        'programs'=>['qys-programs'],
        'attendance'=>['qys-attendance-admin','qys-attendance-qr'],
        'targeted'=>['qys-targeted'],
        'excel'=>['qys-excel'],
    ];

    public static function current_section(): string {
        if(!class_exists('QYS_Multitenant',false) || !QYS_Multitenant::is_portal_request())return '';
        $section=sanitize_key(wp_unslash($_GET['section']??'home'));
        if($section==='' || !QYS_Module_Registry::feature_by_section($section))return 'home';
        return $section;
    }

    public static function should_load(string $section): bool {
        $section=sanitize_key($section);
        if($section==='' || self::current_section()!==$section || !is_user_logged_in())return false;
        if(!class_exists('QYS_Tenant_Context',false) || QYS_Tenant_Context::campus()==='')return false;
        $feature=QYS_Module_Registry::feature_by_section($section);
        if(!$feature || !QYS_Module_Manager::module_available((string)$feature['module']))return false;
        $permission=(string)($feature['permission']??'');
        return $permission==='' || QYS_Permission_Service::scope($permission)!==null;
    }

    /** @return string[] */
    public static function feature_handles(string $section): array {
        $section=sanitize_key($section);
        return array_values(self::FEATURE_BUNDLES[$section]??[]);
    }

    /** @return array{ready:bool,shell:string,feature:string,scope:string,bundles:int,eager_feature_bundles:int} */
    public static function contract(): array {
        $registered=true;
        foreach(array_keys(self::FEATURE_BUNDLES) as $section){
            if(!QYS_Module_Registry::feature_by_section($section)){$registered=false;break;}
        }
        return [
            'ready'=>$registered,
            'shell'=>'base',
            'feature'=>'conditional',
            'scope'=>'section_permission',
            'bundles'=>count(self::FEATURE_BUNDLES),
            'eager_feature_bundles'=>0,
        ];
    }
}
