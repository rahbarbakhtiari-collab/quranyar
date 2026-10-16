<?php
if (!defined('ABSPATH')) exit;

/** Single authority for representative/global permission decisions. */
final class QYS_Permission_Service {
    public static function scope(string $permission): ?string {
        if(!isset(QYS_Config::PERMISSIONS[$permission])||!QYS_Multitenant::schema_ready())return null;
        $context=QYS_Tenant_Context::current();
        if($context===QYS_Tenant_Context::GLOBAL && QYS_Security_Service::is_global_admin())return '*';
        if($context==='')return null;
        foreach(QYS_Multitenant::assignments() as $assignment){
            if((string)$assignment['university']!==$context)continue;
            $permissions=array_filter(explode(',',(string)$assignment['permissions']));
            return in_array($permission,$permissions,true)?$context:null;
        }
        return null;
    }

    public static function universities(string $permission,$requested=null): ?array {
        $scope=self::scope($permission);if($scope===null)return null;
        if($scope!=='*'){
            if($requested!==null&&$requested!=='all'){
                if(!is_array($requested)||array_values(array_unique($requested))!==[$scope])return null;
            }
            return [$scope];
        }
        $all=QYS_Multitenant::university_codes();
        if($requested===null||$requested==='all')return $all;
        if(!is_array($requested)||!$requested)return null;
        $selected=[];foreach($requested as $code){
            if(!is_string($code)||!in_array($code,$all,true))return null;
            $selected[$code]=true;
        }
        return array_keys($selected);
    }

    public static function campaign_visible(int $campaign_id,string $permission='send_messages'): bool {
        $scope=self::scope($permission);
        if($scope===null||!QYS_Campaign_Repository::targeted_meta_ready())return false;
        return QYS_Campaign_Repository::targeted_visible_in_scope($campaign_id,$scope);
    }

    /** Dispatch-time tenant authorization; prevents queued cross-campus delivery after role changes. */
    public static function can_dispatch_targeted(int $campaign_id,int $member_id): ?bool {
        if(!QYS_Multitenant::owner_id())return null;
        if(!QYS_Multitenant::schema_ready()||!QYS_Campaign_Repository::targeted_meta_ready())return null;
        $meta=QYS_Campaign_Repository::targeted_meta($campaign_id);
        // Security boundary: an active queue row without its tenant metadata must never
        // fail open. Treat orphaned/inconsistent campaigns as unauthorized so the
        // worker cancels the pending row instead of delivering across an unknown scope.
        if(!$meta)return false;
        $member=QYS_Member_Repository::find_by_id($member_id);
        if(!$member)return false;
        $member_uni=(string)$member->university;
        if(!in_array($member_uni,array_filter(explode(',',(string)$meta->audience_universities)),true)||!QYS_Multitenant::university_active($member_uni))return false;
        $creator=(int)$meta->created_by;$manager=(string)$meta->manager_university;
        if($manager==='')return QYS_Security_Service::is_global_admin($creator);
        if($manager!==$member_uni)return false;
        $permissions=QYS_University_Repository::approved_permissions($creator,$manager);
        return is_string($permissions)&&in_array('send_messages',explode(',',$permissions),true);
    }
}
