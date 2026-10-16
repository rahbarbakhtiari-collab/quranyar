<?php
if (!defined('ABSPATH')) exit;

/** Canonical QuranYar database table naming/lookup boundary. */
final class QYS_Table_Names {
    private static array $exists_cache=[];
    private static array $columns_cache=[];
    private static array $indexes_cache=[];

    public static function name(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix.'qys_'.$suffix;
    }

    /** Hot-path gate: never queries INFORMATION_SCHEMA / SHOW*. */
    public static function runtime_exists(string $table): bool {
        return $table!=='' && QYS_Schema_Runtime::ready();
    }

    /**
     * Physical existence probe for installer/migration/explicit diagnostics.
     * Kept under the historical method name for compatibility, but cached for
     * the current request. Runtime code should use runtime_exists().
     */
    public static function exists(string $table): bool { return self::diagnostic_exists($table); }

    public static function diagnostic_exists(string $table): bool {
        global $wpdb;
        if($table===''||!preg_match('/^[A-Za-z0-9_]+$/D',$table))return false;
        if(array_key_exists($table,self::$exists_cache))return self::$exists_cache[$table];
        QYS_Schema_Runtime::mark_diagnostic_query();
        return self::$exists_cache[$table]=((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table);
    }

    /** @return array<string,array<string,mixed>> keyed by column name */
    public static function diagnostic_columns(string $table): array {
        global $wpdb;
        if(isset(self::$columns_cache[$table]))return self::$columns_cache[$table];
        if(!self::diagnostic_exists($table))return self::$columns_cache[$table]=[];
        QYS_Schema_Runtime::mark_diagnostic_query();
        $rows=(array)$wpdb->get_results('SHOW COLUMNS FROM `'.str_replace('`','',$table).'`',ARRAY_A);
        $out=[];
        foreach($rows as $row){$field=(string)($row['Field']??'');if($field!=='')$out[$field]=$row;}
        return self::$columns_cache[$table]=$out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function diagnostic_indexes(string $table): array {
        global $wpdb;
        if(isset(self::$indexes_cache[$table]))return self::$indexes_cache[$table];
        if(!self::diagnostic_exists($table))return self::$indexes_cache[$table]=[];
        QYS_Schema_Runtime::mark_diagnostic_query();
        return self::$indexes_cache[$table]=(array)$wpdb->get_results('SHOW INDEX FROM `'.str_replace('`','',$table).'`',ARRAY_A);
    }

    public static function diagnostic_has_columns(string $table,array $required): bool {
        $columns=self::diagnostic_columns($table);
        foreach($required as $column)if(!isset($columns[(string)$column]))return false;
        return true;
    }

    /** Installer/migration helper: invalidate request-local physical schema caches after DDL. */
    public static function clear_diagnostic_cache(?string $table=null): void {
        if($table===null || $table===''){
            self::$exists_cache=[];
            self::$columns_cache=[];
            self::$indexes_cache=[];
            return;
        }
        unset(self::$exists_cache[$table],self::$columns_cache[$table],self::$indexes_cache[$table]);
    }

    public static function scalar_count(string $sql): int {
        global $wpdb;
        return max(0,(int)$wpdb->get_var($sql));
    }
}
