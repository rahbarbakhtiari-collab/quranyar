<?php
if (!defined('ABSPATH')) exit;

/** Shared text and identifier normalization boundary. */
final class QYS_Text_Normalizer {
    public static function chars($value): int {
        $value=(string)$value;
        return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
    }

    public static function clip($value,int $length): string {
        $value=(string)$value;$length=max(0,$length);
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length*4);
    }

    public static function lower($value): string {
        $value=(string)$value;
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    public static function digits($value): string {
        return strtr((string)$value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    public static function national_id($value): string {
        $value=preg_replace('/\D/','',self::digits($value));
        if(strlen($value)!==10||preg_match('/^(\d)\1{9}$/',$value))return '';
        $sum=0;for($i=0;$i<9;$i++)$sum+=(int)$value[$i]*(10-$i);
        $r=$sum%11;$check=$r<2?$r:11-$r;
        return $check===(int)$value[9]?$value:'';
    }

    public static function mobile($raw): string {
        if(!is_string($raw))return '';
        $phone=preg_replace('/[\s\-\(\)]/u','',self::digits(trim($raw)));
        if(str_starts_with($phone,'+98'))$phone='0'.substr($phone,3);
        elseif(str_starts_with($phone,'0098'))$phone='0'.substr($phone,4);
        elseif(str_starts_with($phone,'98')&&strlen($phone)===12)$phone='0'.substr($phone,2);
        return preg_match('/\A09[0-9]{9}\z/D',$phone)===1?$phone:'';
    }

    public static function header($value): string {
        return preg_replace('/[\s_\-\x{200c}]+/u','',self::lower(trim((string)$value)))??'';
    }

    public static function render_template($template,$row): ?string {
        $missing=false;
        $out=preg_replace_callback('/\{([^{}]+)\}/u',function($m)use($row,&$missing){
            $key=self::header($m[1]);
            foreach((array)$row as $col=>$value)if(self::header($col)===$key)return trim((string)$value);
            $missing=true;return '';
        },(string)$template);
        if(!is_string($out))return null;
        return $missing||self::chars($out)>4000?null:$out;
    }
}
