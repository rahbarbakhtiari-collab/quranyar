<?php
if (!defined('ABSPATH')) exit;

/**
 * Canonical QuranYar module + product feature registry (Phase C / C7).
 *
 * Lifecycle modules own their exposed portal features. Runtime navigation no
 * longer depends on Multitenant implementation details, which removes the last
 * FeatureRegistry <-> Multitenant cycle from the Phase C dependency graph.
 */
final class QYS_Module_Registry {
    /** @var array<string,QYS_Module_Contract> */
    private static array $modules=[];
    /** @var array<string,array<string,mixed>> */
    private static array $features=[];
    /** @var array<string,string> */
    private static array $sections=[];

    public static function normalize_id(string $id): string {
        $id=strtolower(trim($id));
        return preg_replace('/[^a-z0-9_-]+/','',$id)??'';
    }

    public static function register(QYS_Module_Contract $module): string {
        $id=self::normalize_id($module->id());
        if($id==='' || isset(self::$modules[$id]))throw new InvalidArgumentException('Invalid/duplicate QuranYar module');
        self::$modules[$id]=$module;
        foreach($module->features() as $feature)self::register_feature($id,$feature);
        return $id;
    }

    public static function module(string $id): ?QYS_Module_Contract {
        $id=self::normalize_id($id);
        return $id!==''?(self::$modules[$id]??null):null;
    }

    /** @return array<string,QYS_Module_Contract> */
    public static function modules(): array { return self::$modules; }
    /** @return string[] */
    public static function module_ids(): array { return array_keys(self::$modules); }

    private static function register_feature(string $module_id,array $feature): void {
        $required=['id','section','label','permission','handler','nav_group','nav_order'];
        foreach($required as $key)if(!array_key_exists($key,$feature))throw new InvalidArgumentException('QYS module feature missing '.$key);
        $id=sanitize_key((string)$feature['id']);
        $section=sanitize_key((string)$feature['section']);
        if($id==='' || $section==='' || isset(self::$features[$id]) || isset(self::$sections[$section])){
            throw new InvalidArgumentException('Invalid/duplicate QYS module feature');
        }
        $permission=(string)$feature['permission'];
        if($permission!=='' && !isset(QYS_Config::PERMISSIONS[$permission]))throw new InvalidArgumentException('Unknown QYS permission '.$permission);
        if(!is_callable($feature['handler']))throw new InvalidArgumentException('QYS module feature handler is not callable: '.$id);
        self::$features[$id]=[
            'id'=>$id,
            'section'=>$section,
            'label'=>sanitize_text_field((string)$feature['label']),
            'module'=>$module_id,
            'permission'=>$permission,
            'handler'=>$feature['handler'],
            'nav_group'=>sanitize_key((string)$feature['nav_group']),
            'nav_order'=>(int)$feature['nav_order'],
            'docs'=>(string)($feature['docs']??''),
            'tests'=>(array)($feature['tests']??[]),
        ];
        self::$sections[$section]=$id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function features(): array {
        $items=array_values(self::$features);
        usort($items,static fn($a,$b)=>$a['nav_order']<=>$b['nav_order']);
        return $items;
    }

    public static function feature_by_section(string $section): ?array {
        $section=sanitize_key($section);
        $id=self::$sections[$section]??'';
        return $id!==''?(self::$features[$id]??null):null;
    }

    /** @return string[] */
    public static function sections(): array { return array_column(self::features(),'section'); }

    public static function primary_section(string $section): string {
        $feature=self::feature_by_section($section);
        if(!$feature)return 'home';
        if($feature['nav_group']==='primary')return (string)$feature['section'];
        if($feature['nav_group']==='messages')return 'messages';
        return 'more';
    }

    /** @return array{ready:bool,code:string,module_count:int,feature_count:int} */
    public static function health(): array {
        $features=self::features();
        $ready=count(self::$modules)>0 && count($features)===10;
        if($ready){
            foreach($features as $feature){
                if(!isset(self::$modules[(string)$feature['module']])){$ready=false;break;}
            }
        }
        return [
            'ready'=>$ready,
            'code'=>$ready?'ok':'module_registry_incomplete',
            'module_count'=>count(self::$modules),
            'feature_count'=>count($features),
        ];
    }
}
