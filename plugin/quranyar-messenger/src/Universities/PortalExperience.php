<?php
if (!defined('ABSPATH')) exit;

/**
 * Presentation-only helpers for the university portal.
 * No storage queries, tenant decisions or permission checks live here.
 */
final class QYS_Portal_Experience {
    public static function section_head(string $eyebrow,string $title,string $description,string $meta=''): string {
        $out='<div class="qys-tenant-section-head qys-tenant-section-head--polished">'
            .'<div><span class="qys-tenant-eyebrow qys-tenant-eyebrow--soft">'.esc_html($eyebrow).'</span>'
            .'<h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p></div>';
        if($meta!=='')$out.='<span class="qys-tenant-head-meta">'.esc_html($meta).'</span>';
        return $out.'</div>';
    }

    public static function metric(string $label,int $value,string $tone='default'): string {
        $tone=in_array($tone,['default','good','attention','muted','info'],true)?$tone:'default';
        return '<article class="qys-tenant-metric is-'.esc_attr($tone).'"><span>'.esc_html($label).'</span><strong>'.number_format_i18n(max(0,$value)).'</strong></article>';
    }

    public static function status_chip(string $label,string $tone='neutral'): string {
        $tone=in_array($tone,['good','attention','neutral','info'],true)?$tone:'neutral';
        return '<span class="qys-tenant-status-chip is-'.esc_attr($tone).'">'.esc_html($label).'</span>';
    }

    public static function dashboard_notice(string $title,string $description,string $url='',string $action=''): string {
        $out='<article class="qys-tenant-dashboard-notice"><div><strong>'.esc_html($title).'</strong><span>'.esc_html($description).'</span></div>';
        if($url!==''&&$action!=='')$out.='<a href="'.esc_url($url).'">'.esc_html($action).'<b aria-hidden="true">‹</b></a>';
        return $out.'</article>';
    }

    public static function empty_state(string $title,string $description,string $url='',string $action=''): string {
        $out='<section class="qys-tenant-state qys-tenant-state--empty" role="status"><span class="qys-tenant-state-icon" aria-hidden="true">○</span><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p>';
        if($url!==''&&$action!=='')$out.='<a class="qys-tenant-btn" href="'.esc_url($url).'">'.esc_html($action).'</a>';
        return $out.'</section>';
    }

    public static function alert_state(string $title,string $description): string {
        return '<section class="qys-tenant-state qys-tenant-state--error" role="alert"><span class="qys-tenant-state-icon" aria-hidden="true">!</span><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p></section>';
    }
}
