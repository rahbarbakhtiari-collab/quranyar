<?php
if (!defined('ABSPATH')) exit;

/** Jalali/Gregorian calculations and Iran-local calendar boundary. */
final class QYS_Calendar_Service {
    public static function iran_now(): DateTimeImmutable {
        return new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
    }

    public static function jal_cal($jy): array {
        $jy=(int)$jy;
        $breaks=[-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
        $gy=$jy+621;$leapJ=-14;$jp=$breaks[0];$jump=0;$n=0;
        for($i=1;$i<count($breaks);$i++){
            $jm=$breaks[$i];$jump=$jm-$jp;
            if($jy<$jm)break;
            $leapJ+=intdiv($jump,33)*8+intdiv($jump%33,4);
            $jp=$jm;
        }
        $n=$jy-$jp;
        $leapJ+=intdiv($n,33)*8+intdiv(($n%33)+3,4);
        if($jump%33===4&&$jump-$n===4)$leapJ++;
        $leapG=intdiv($gy,4)-intdiv((intdiv($gy,100)+1)*3,4)-150;
        $march=20+$leapJ-$leapG;
        if($jump-$n<6)$n=$n-$jump+intdiv($jump+4,33)*33;
        $leap=(($n+1)%33-1)%4;
        if($leap===-1)$leap=4;
        return ['leap'=>$leap,'march'=>$march,'gy'=>$gy];
    }

    public static function gregorian_jdn($gy,$gm,$gd): int {
        $gy=(int)$gy;$gm=(int)$gm;$gd=(int)$gd;
        $a=intdiv(14-$gm,12);$y=$gy+4800-$a;$m=$gm+12*$a-3;
        return $gd+intdiv(153*$m+2,5)+365*$y+intdiv($y,4)-intdiv($y,100)+intdiv($y,400)-32045;
    }

    public static function jalali_jdn($jy,$jm,$jd): int {
        $jy=(int)$jy;$jm=(int)$jm;$jd=(int)$jd;
        $cal=self::jal_cal($jy);
        return self::gregorian_jdn($cal['gy'],3,$cal['march'])+($jm<=6?($jm-1)*31:186+($jm-7)*30)+$jd-1;
    }

    public static function birth_jalali($raw): string {
        if(!is_string($raw))return '';
        $s=trim(QYS_Text_Normalizer::digits($raw));
        if(!preg_match('~\A(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})\z~D',$s,$m))return '';
        $y=(int)$m[1];$mo=(int)$m[2];$day=(int)$m[3];
        if($y<1300||$y>1499||$mo<1||$mo>12||$day<1)return '';
        $max=$mo<=6?31:($mo<=11?30:(self::jal_cal($y)['leap']===0?30:29));
        if($day>$max)return '';
        $today=getdate(time());
        if(self::jalali_jdn($y,$mo,$day)>self::gregorian_jdn($today['year'],$today['mon'],$today['mday']))return '';
        return sprintf('%04d/%02d/%02d',$y,$mo,$day);
    }

    public static function jalali_from_date(DateTimeInterface $dt): string {
        $gy=(int)$dt->format('Y');
        $jdn=self::gregorian_jdn($gy,(int)$dt->format('n'),(int)$dt->format('j'));
        $jy=$gy-621;
        if($jdn<self::jalali_jdn($jy,1,1))$jy--;
        elseif($jdn>=self::jalali_jdn($jy+1,1,1))$jy++;
        $offset=$jdn-self::jalali_jdn($jy,1,1);
        $month=$offset<186?intdiv($offset,31)+1:intdiv($offset-186,30)+7;
        $day=$offset<186?$offset%31+1:($offset-186)%30+1;
        return sprintf('%04d/%02d/%02d',$jy,$month,$day);
    }
}
