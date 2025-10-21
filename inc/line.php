<?php
// Minimal LINE Messaging API helpers
if (!function_exists('line_push')) {
  function line_push($to_user_id, $messages){
    $token = defined('LINE_CHANNEL_ACCESS_TOKEN') ? LINE_CHANNEL_ACCESS_TOKEN : '';
    if (!$token || !$to_user_id) return false;
    $payload = json_encode(['to'=>$to_user_id, 'messages'=>$messages], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch,[CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token], CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); curl_close($ch); return true;
  }
}
if (!function_exists('line_reply')) {
  function line_reply($reply_token, $messages){
    $token = defined('LINE_CHANNEL_ACCESS_TOKEN') ? LINE_CHANNEL_ACCESS_TOKEN : '';
    if (!$token || !$reply_token) return false;
    $payload = json_encode(['replyToken'=>$reply_token, 'messages'=>$messages], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch,[CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token], CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); curl_close($ch); return true;
  }
}
if (!function_exists('line_text')) { function line_text($text){ return ['type'=>'text','text'=>$text]; } }
if (!function_exists('line_verify_signature')) {
  function line_verify_signature($rawBody, $signature){
    $secret = defined('LINE_CHANNEL_SECRET') ? LINE_CHANNEL_SECRET : '';
    if (!$secret || !$signature) return false;
    $hash = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    return hash_equals($hash, $signature);
  }
}
