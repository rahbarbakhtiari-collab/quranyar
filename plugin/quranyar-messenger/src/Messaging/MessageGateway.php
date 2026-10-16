<?php
if (!defined('ABSPATH')) exit;

/** The only class allowed to perform an outbound private-message HTTP request. */
final class QYS_Message_Gateway {
    public static function send_private(string $eitaa_id,string $text): array {
        $token=QYS_Token_Store::secret('webapp');
        if($token==='')return ['ok'=>false,'state'=>QYS_Delivery_State::DEFINITIVE_FAILED,'reason'=>'app_token_not_configured'];
        $text=trim(wp_strip_all_tags($text));
        if(!preg_match('/\A[0-9]{1,20}\z/D',$eitaa_id)||$text===''||QYS_Text_Normalizer::chars($text)>4000)return ['ok'=>false,'state'=>QYS_Delivery_State::DEFINITIVE_FAILED,'reason'=>'invalid_private_message'];
        $response=wp_remote_post(QYS_Config::app_send_endpoint(),[
            'timeout'=>15,'redirection'=>0,'reject_unsafe_urls'=>true,'limit_response_size'=>65536,
            'headers'=>['Accept'=>'application/json'],
            'body'=>['token'=>$token,'chat_id'=>$eitaa_id,'text'=>$text]
        ]);
        unset($token);
        if(is_wp_error($response))return ['ok'=>false,'state'=>QYS_Delivery_State::UNCERTAIN,'reason'=>'network_failure'];
        $status=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        $ok=$status>=200&&$status<300&&is_array($body)&&($body['ok']??null)===true
            &&is_string($body['result']??null)&&strtolower(trim($body['result']))==='success';
        if($ok)return ['ok'=>true,'state'=>QYS_Delivery_State::SENT,'reason'=>''];
        $reason=QYS_Messenger::failure_reason($response,$body);
        return ['ok'=>false,'state'=>QYS_Delivery_State::from_failure_reason($reason),'reason'=>$reason];
    }
}
