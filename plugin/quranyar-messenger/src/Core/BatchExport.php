<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared bounded CSV streaming boundary introduced in Phase D / D8.
 *
 * This service intentionally owns only transport mechanics: response headers,
 * UTF-8 BOM, CSV serialization, chunk sizing and periodic flushing. Domain
 * authorization, tenant scoping and row shaping remain with the owning feature.
 */
final class QYS_Batch_Export {
    public const CHUNK_SIZE=250;

    /** @return array{ready:bool,mode:string,chunk:int,cursor:string,memory:string} */
    public static function contract(): array {
        return [
            'ready'=>true,
            'mode'=>'streaming',
            'chunk'=>self::CHUNK_SIZE,
            'cursor'=>'keyset',
            'memory'=>'bounded',
        ];
    }

    public static function chunk_size(): int { return self::CHUNK_SIZE; }

    /**
     * Open a CSV response stream and write BOM/header.
     * Domain code must complete all permission checks before calling this method.
     *
     * @return resource
     */
    public static function open_csv(string $filename,array $headers){
        $filename=trim($filename);
        if(function_exists('sanitize_file_name'))$filename=sanitize_file_name($filename);
        else $filename=preg_replace('/[^A-Za-z0-9._-]+/','-',$filename)?:'quranyar-export.csv';
        if($filename==='' || !str_ends_with(strtolower($filename),'.csv'))$filename=($filename?:'quranyar-export').'.csv';
        if(function_exists('nocache_headers'))nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        $handle=fopen('php://output','wb');
        if(!$handle)throw new RuntimeException('Unable to open export stream');
        fwrite($handle,"\xEF\xBB\xBF");
        self::write_csv_row($handle,$headers);
        return $handle;
    }

    public static function write_csv_row($handle,array $row): void {
        if(!is_resource($handle))throw new InvalidArgumentException('Invalid export handle');
        if(fputcsv($handle,$row,',','"','')===false)throw new RuntimeException('Unable to write export row');
    }

    public static function flush_chunk($handle): void {
        if(is_resource($handle))fflush($handle);
        if(function_exists('ob_get_level') && ob_get_level()>0)@ob_flush();
        if(function_exists('flush'))@flush();
    }

    public static function close($handle): void {
        if(is_resource($handle))fclose($handle);
    }
}
