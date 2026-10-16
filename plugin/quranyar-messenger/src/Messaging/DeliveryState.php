<?php
if (!defined('ABSPATH')) exit;

/**
 * Canonical delivery-state vocabulary for outbound private messages.
 *
 * B1 introduced explicit delivery outcomes. B2 adds a lease around `sending`
 * claims, but does not automatically retry failed/uncertain outcomes and does
 * not mutate Welcome/Activation logs; those remain separate Phase B steps.
 */
final class QYS_Delivery_State {
    public const PENDING='pending';
    public const SENDING='sending';
    public const SENT='sent';
    public const RETRYABLE_FAILED='retryable_failed';
    public const DEFINITIVE_FAILED='definitive_failed';
    public const UNCERTAIN='uncertain';
    public const CANCELLED='cancelled';
    /** Pre-B1 queue value; accepted only for migration/read compatibility. */
    public const LEGACY_FAILED='failed';

    public static function queue_states(bool $include_legacy=true): array {
        $states=[self::PENDING,self::SENDING,self::SENT,self::RETRYABLE_FAILED,self::DEFINITIVE_FAILED,self::UNCERTAIN,self::CANCELLED];
        if($include_legacy)$states[]=self::LEGACY_FAILED;
        return $states;
    }

    public static function attempt_outcomes(): array {
        return [self::SENT,self::RETRYABLE_FAILED,self::DEFINITIVE_FAILED,self::UNCERTAIN];
    }

    public static function failure_states(bool $include_legacy=true): array {
        $states=[self::RETRYABLE_FAILED,self::DEFINITIVE_FAILED,self::UNCERTAIN];
        if($include_legacy)$states[]=self::LEGACY_FAILED;
        return $states;
    }

    public static function is_failure(string $state): bool {
        return in_array($state,self::failure_states(true),true);
    }

    public static function label(string $state): string {
        return [
            self::PENDING=>'در صف',
            self::SENDING=>'در حال ارسال',
            self::SENT=>'پذیرفته‌شده توسط درگاه',
            self::RETRYABLE_FAILED=>'خطای موقت / قابل تلاش مجدد',
            self::DEFINITIVE_FAILED=>'ارسال ناموفق قطعی',
            self::UNCERTAIN=>'نتیجه تحویل نامشخص',
            self::CANCELLED=>'لغوشده',
            self::LEGACY_FAILED=>'خطای قدیمی',
        ][$state]??'وضعیت نامشخص';
    }

    /**
     * Classify a sanitized gateway failure reason conservatively.
     * `uncertain` means the request may have crossed the network boundary and
     * must not be retried blindly without an idempotency/reconciliation policy.
     */
    public static function from_failure_reason(string $reason): string {
        $reason=trim($reason);
        if($reason==='' || $reason==='unknown' || $reason==='network_failure')return self::UNCERTAIN;
        if(in_array($reason,['app_token_not_configured','invalid_private_message'],true))return self::DEFINITIVE_FAILED;

        if(preg_match('/\AHTTP ([1-5][0-9]{2}); reason=([a-z_]{1,30})(?:; code=[0-9]{1,6})?\z/D',$reason,$m)){
            $http=(int)$m[1];$code=(string)$m[2];
            if($http===429 || $code==='rate_limited')return self::RETRYABLE_FAILED;
            if($http===408 || $http>=500 || $code==='invalid_response')return self::UNCERTAIN;
            if($http>=400 && $http<500)return self::DEFINITIVE_FAILED;
            if(in_array($code,['invalid_token','chat_not_found','permission_denied','api_rejected'],true))return self::DEFINITIVE_FAILED;
            return self::UNCERTAIN;
        }

        // Compatibility with very old sanitized diagnostics.
        if(preg_match('/\AAPI result HTTP ([1-5][0-9]{2})\z/D',$reason,$m)){
            $http=(int)$m[1];
            if($http===429)return self::RETRYABLE_FAILED;
            if($http===408 || $http>=500)return self::UNCERTAIN;
            if($http>=400 && $http<500)return self::DEFINITIVE_FAILED;
            return self::UNCERTAIN;
        }

        // Unknown legacy failures are intentionally not promoted to a
        // definitive failure because their actual delivery outcome is unknown.
        return self::UNCERTAIN;
    }

    /** Preserve the old ok/reason contract while guaranteeing a state field. */
    public static function normalize_result(array $result): array {
        $ok=!empty($result['ok']);
        $reason=$ok?'':(string)($result['reason']??'unknown');
        $state=(string)($result['state']??'');
        if($ok)$state=self::SENT;
        elseif(!in_array($state,[self::RETRYABLE_FAILED,self::DEFINITIVE_FAILED,self::UNCERTAIN],true))$state=self::from_failure_reason($reason);
        return ['ok'=>$ok,'state'=>$state,'reason'=>$reason];
    }
}
