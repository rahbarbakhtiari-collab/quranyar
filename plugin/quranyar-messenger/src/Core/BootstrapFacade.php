<?php
if (!defined('ABSPATH')) exit;

/**
 * Single entry boundary for plugin activation + runtime bootstrap.
 * Domain modules no longer need to know how the historical plugin root wires
 * Installer, Config and Module Manager together.
 */
final class QYS_Bootstrap_Facade {
    private static bool $registered=false;

    public static function register(string $plugin_file): void {
        QYS_Config::bootstrap($plugin_file);
        if(!self::$registered){
            register_activation_hook($plugin_file,[__CLASS__,'activate']);
            self::$registered=true;
        }
        self::boot();
    }

    public static function activate(): void { QYS_Installer::activate(); }

    public static function boot(): void {
        QYS_Module_Manager::bootstrap(QYS_Config::plugin_file());
    }

    /** @return array{ready:bool,code:string} */
    public static function health(): array {
        $required=[QYS_Config::class,QYS_Schema_Runtime::class,QYS_Table_Names::class,QYS_Text_Normalizer::class,QYS_Calendar_Service::class,QYS_Token_Store::class];
        foreach($required as $class)if(!class_exists($class,false))return ['ready'=>false,'code'=>'core_service_missing'];
        $config=QYS_Config::health();$tokens=QYS_Token_Store::health();
        if(empty($config['ready']))return ['ready'=>false,'code'=>'config_not_ready'];
        if(empty($tokens['ready']))return ['ready'=>false,'code'=>'token_store_not_ready'];
        return ['ready'=>true,'code'=>'ok'];
    }
}
