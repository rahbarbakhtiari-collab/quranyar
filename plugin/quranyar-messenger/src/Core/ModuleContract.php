<?php
if (!defined('ABSPATH')) exit;

/**
 * Lifecycle boundary shared by every QuranYar runtime module.
 *
 * Introduced in C3 and retained as the stable lifecycle contract. C4 extracted
 * shared services, C5 added one-way feature dependency boundaries and C6
 * enforces canonical repository ownership. C7 consolidates product capabilities
 * into the canonical module registry.
 */
interface QYS_Module_Contract {
    public function id(): string;
    /** @return string[] */
    public function dependencies(): array;
    public function critical(): bool;
    /** @return array<int,array<string,mixed>> */
    public function features(): array;
    /**
     * @return array<int,array{id:string,callback:callable,meta?:array}>
     */
    public function migrations(): array;
    public function boot(): void;
    /** @return array{ready:bool,code:string} */
    public function health(): array;
}

/**
 * Compatibility adapter that lets legacy static modules participate in the
 * lifecycle contract without a risky all-at-once rewrite.
 */
final class QYS_Callback_Module implements QYS_Module_Contract {
    private string $module_id;
    private array $depends;
    private bool $is_critical;
    private $boot_callback;
    private $health_callback;
    private array $migration_definitions;
    private array $feature_definitions;

    public function __construct(
        string $id,
        callable $boot_callback,
        array $dependencies=[],
        bool $critical=false,
        array $migrations=[],
        ?callable $health_callback=null,
        array $features=[]
    ) {
        $this->module_id=QYS_Module_Registry::normalize_id($id);
        $this->boot_callback=$boot_callback;
        $this->depends=array_values(array_unique(array_filter(array_map([QYS_Module_Manager::class,'normalize_id'],$dependencies))));
        $this->is_critical=$critical;
        $this->migration_definitions=$migrations;
        $this->health_callback=$health_callback;
        $this->feature_definitions=$features;
    }

    public function id(): string { return $this->module_id; }
    public function dependencies(): array { return $this->depends; }
    public function critical(): bool { return $this->is_critical; }
    public function features(): array { return $this->feature_definitions; }
    public function migrations(): array { return $this->migration_definitions; }

    public function boot(): void {
        call_user_func($this->boot_callback);
    }

    public function health(): array {
        if($this->health_callback===null)return ['ready'=>true,'code'=>'ok'];
        $result=call_user_func($this->health_callback);
        if(is_bool($result))return ['ready'=>$result,'code'=>$result?'ok':'not_ready'];
        if(!is_array($result))return ['ready'=>false,'code'=>'invalid_health_result'];
        return [
            'ready'=>!empty($result['ready']),
            'code'=>QYS_Module_Manager::safe_code((string)($result['code']??(!empty($result['ready'])?'ok':'not_ready'))),
        ];
    }
}
