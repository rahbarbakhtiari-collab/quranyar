<?php
if (!defined('ABSPATH')) exit;

/**
 * Fast runtime schema readiness gate introduced in Phase D / D1.
 *
 * Ordinary requests must trust the durable install/schema/ledger contract and
 * must not issue SHOW TABLES / SHOW COLUMNS probes on hot paths. Physical
 * introspection is reserved for installer/migration verification and explicit
 * Health Center diagnostics through QYS_Table_Names::diagnostic_* helpers.
 */
final class QYS_Schema_Runtime {
    private static ?bool $ready_cache=null;
    private static int $runtime_checks=0;
    private static int $diagnostic_queries=0;

    public static function ready(): bool {
        self::$runtime_checks++;
        if(self::$ready_cache!==null)return self::$ready_cache;
        $ready=(string)get_option('qys_install_state','')==='ready'
            && (string)get_option('qys_schema_version','')===QYS_Config::SCHEMA_VERSION;
        if(!$ready)return self::$ready_cache=false;
        if(class_exists('QYS_Migration_Ledger',false)){
            if(!QYS_Migration_Ledger::initialized()){
                return self::$ready_cache=!QYS_Migration_Ledger::required();
            }
            if(!QYS_Migration_Ledger::ready())return self::$ready_cache=false;
        }
        return self::$ready_cache=true;
    }

    public static function mark_diagnostic_query(): void { self::$diagnostic_queries++; }

    /** @return array{ready:bool,mode:string,runtime_checks:int,diagnostic_queries:int} */
    public static function telemetry(): array {
        return [
            'ready'=>self::ready(),
            'mode'=>'version_ledger',
            'runtime_checks'=>max(0,self::$runtime_checks),
            'diagnostic_queries'=>max(0,self::$diagnostic_queries),
        ];
    }

    /** Test-only/request-local reset; no persistent state is changed. */
    public static function reset_request_cache(): void {
        self::$ready_cache=null;
        self::$runtime_checks=0;
        self::$diagnostic_queries=0;
    }
}
