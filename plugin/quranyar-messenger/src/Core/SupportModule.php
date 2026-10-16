<?php
if (!defined('ABSPATH')) exit;

/**
 * Backward-compatible static facade for legacy QYS_Messenger helper calls.
 * Shared logic moved to dedicated C4 core services.
 */
trait QYS_Support_Module {
    public static function table($name){ return QYS_Table_Names::name((string)$name); }
    static function table_exists($table){ return QYS_Table_Names::runtime_exists((string)$table); }
    static function scalar_count($sql){ return QYS_Table_Names::scalar_count((string)$sql); }

    static function chars($s){ return QYS_Text_Normalizer::chars($s); }
    static function clip($s,$n){ return QYS_Text_Normalizer::clip($s,(int)$n); }
    static function lower($s){ return QYS_Text_Normalizer::lower($s); }
    public static function normalize_digits($s){ return QYS_Text_Normalizer::digits($s); }
    static function national_id($s){ return QYS_Text_Normalizer::national_id($s); }
    static function mobile($raw){ return QYS_Text_Normalizer::mobile($raw); }
    static function normalize_header($s){ return QYS_Text_Normalizer::header($s); }
    static function render_template($template,$row){ return QYS_Text_Normalizer::render_template($template,$row); }

    public static function member_roles(){return ['staff'=>'کارمند','faculty'=>'استاد','student'=>'دانشجو','staff_family'=>'خانواده کارکنان'];}

    static function university_name($code){
      return class_exists('QYS_Multitenant')
       ?QYS_Multitenant::university_label((string)$code)
       :((string)$code===QYS_Config::default_university()?QYS_Config::default_university_name():'—');
    }

    public static function jal_cal($jy){ return QYS_Calendar_Service::jal_cal($jy); }
    static function gregorian_jdn($gy,$gm,$gd){ return QYS_Calendar_Service::gregorian_jdn($gy,$gm,$gd); }
    static function jalali_jdn($jy,$jm,$jd){ return QYS_Calendar_Service::jalali_jdn($jy,$jm,$jd); }
    static function birth_jalali($raw){ return QYS_Calendar_Service::birth_jalali($raw); }
}
