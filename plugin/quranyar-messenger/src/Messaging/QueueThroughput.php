<?php
if (!defined('ABSPATH')) exit;

/**
 * Adaptive, bounded Queue Worker policy (Phase D / D6).
 *
 * The policy deliberately owns only execution-budget decisions and aggregate
 * telemetry. Delivery state, tenant authorization and network semantics remain
 * with their existing owners. No member/campaign IDs or message text are stored.
 */
final class QYS_Queue_Throughput {
    private const OPTION='qys_queue_throughput';
    private const MODE='adaptive';
    private const TIME_BUDGET_MS=20000;
    private const SAFETY_RESERVE_MS=2000;
    private const MAX_MESSAGES_PER_RUN=30;
    private const INITIAL_MESSAGE_MS=1500;
    private const MIN_ESTIMATE_MS=100;
    private const MAX_ESTIMATE_MS=15000;
    private const MIN_SEND_GAP_MS=150;

    public static function contract(): array {
        return [
            'ready'=>true,
            'mode'=>self::MODE,
            'budget_ms'=>self::TIME_BUDGET_MS,
            'safety_reserve_ms'=>self::SAFETY_RESERVE_MS,
            'max_messages'=>self::MAX_MESSAGES_PER_RUN,
            'min_send_gap_ms'=>self::MIN_SEND_GAP_MS,
            'rate_limit'=>'stop',
            'uncertain'=>'stop',
        ];
    }

    public static function state(): array {
        $state=get_option(self::OPTION,[]);
        return is_array($state)?$state:[];
    }

    /**
     * Choose this run's cap from the previous observed network-message cost.
     * The cap can grow when the provider is fast and shrinks automatically when
     * responses are slow, while always staying inside a hard per-run ceiling.
     */
    public static function plan(): array {
        $state=self::state();
        $estimate=(int)($state['ema_message_ms']??self::INITIAL_MESSAGE_MS);
        $estimate=max(self::MIN_ESTIMATE_MS,min(self::MAX_ESTIMATE_MS,$estimate));
        $usable=max(1000,self::TIME_BUDGET_MS-self::SAFETY_RESERVE_MS);
        $cap=(int)floor($usable/$estimate);
        $cap=max(1,min(self::MAX_MESSAGES_PER_RUN,$cap));
        return [
            'mode'=>self::MODE,
            'budget_ms'=>self::TIME_BUDGET_MS,
            'usable_ms'=>$usable,
            'cap'=>$cap,
            'ema_message_ms'=>$estimate,
            'min_send_gap_ms'=>self::MIN_SEND_GAP_MS,
        ];
    }

    public static function elapsed_ms(float $started_micro): int {
        return max(0,(int)round((microtime(true)-$started_micro)*1000));
    }

    public static function within_budget(float $started_micro): bool {
        return self::elapsed_ms($started_micro)<(self::TIME_BUDGET_MS-self::SAFETY_RESERVE_MS);
    }

    /** Provider-friendly pacing without assuming a vendor-specific rate. */
    public static function pace(float $last_send_started_micro): void {
        if($last_send_started_micro<=0)return;
        $elapsed=(microtime(true)-$last_send_started_micro)*1000;
        $remaining=self::MIN_SEND_GAP_MS-$elapsed;
        if($remaining>0)usleep((int)ceil($remaining*1000));
    }

    /**
     * Persist aggregate operational telemetry only. The EMA is based on actual
     * outbound attempts, not rows cancelled before network I/O.
     */
    public static function record(array $run): void {
        $previous=self::state();
        $attempted=max(0,(int)($run['attempted']??0));
        $duration=max(0,(int)($run['duration_ms']??0));
        $prev_ema=max(self::MIN_ESTIMATE_MS,min(self::MAX_ESTIMATE_MS,(int)($previous['ema_message_ms']??self::INITIAL_MESSAGE_MS)));
        $ema=$prev_ema;
        if($attempted>0){
            $sample=max(self::MIN_ESTIMATE_MS,min(self::MAX_ESTIMATE_MS,(int)round($duration/$attempted)));
            // 70% history / 30% current run prevents one unusually fast/slow
            // cron from immediately jumping between the minimum and maximum cap.
            $ema=(int)round(($prev_ema*0.70)+($sample*0.30));
        }
        $state=[
            'version'=>1,
            'mode'=>self::MODE,
            'last_run_utc'=>gmdate('c'),
            'last_cap'=>max(1,min(self::MAX_MESSAGES_PER_RUN,(int)($run['cap']??1))),
            'last_scanned'=>max(0,(int)($run['scanned']??0)),
            'last_attempted'=>$attempted,
            'last_sent'=>max(0,(int)($run['sent']??0)),
            'last_retryable_failed'=>max(0,(int)($run['retryable_failed']??0)),
            'last_definitive_failed'=>max(0,(int)($run['definitive_failed']??0)),
            'last_uncertain'=>max(0,(int)($run['uncertain']??0)),
            'last_cancelled'=>max(0,(int)($run['cancelled']??0)),
            'last_duration_ms'=>$duration,
            'last_stop'=>self::safe_stop((string)($run['stop']??'complete')),
            'ema_message_ms'=>$ema,
        ];
        if(get_option(self::OPTION,null)===null)add_option(self::OPTION,$state,'','no');
        else update_option(self::OPTION,$state,false);
    }

    public static function status(): array {
        $contract=self::contract();
        $state=self::state();
        $plan=self::plan();
        return array_merge($contract,[
            'planned_cap'=>(int)$plan['cap'],
            'ema_message_ms'=>(int)$plan['ema_message_ms'],
            'last_run_utc'=>(string)($state['last_run_utc']??''),
            'last_attempted'=>max(0,(int)($state['last_attempted']??0)),
            'last_sent'=>max(0,(int)($state['last_sent']??0)),
            'last_duration_ms'=>max(0,(int)($state['last_duration_ms']??0)),
            'last_stop'=>self::safe_stop((string)($state['last_stop']??'never')),
        ]);
    }

    private static function safe_stop(string $stop): string {
        $allowed=['complete','empty','budget','rate_limited','uncertain','tenant_hold','tables_not_ready','missing_token','locked','schema_not_ready','recovered_stale_lock','recovered_stale_sending','never'];
        return in_array($stop,$allowed,true)?$stop:'complete';
    }
}
