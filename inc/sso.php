<?php
function sso_authorize_url(){
  $params = [
    'response_type' => 'code',
    'client_id'     => SSO_CLIENT_ID,
    'redirect_uri'  => SSO_REDIRECT_URI,
    'scope'         => SSO_SCOPE,
    'state'         => bin2hex(random_bytes(16))
  ];
  $_SESSION['sso_state'] = $params['state'];
  return 'https://sso.kmutnb.ac.th/auth/authorize?' . http_build_query($params);
}
function sso_fetch_token($code){
  $ch = curl_init('https://sso.kmutnb.ac.th/auth/token');
  $basic = base64_encode(SSO_CLIENT_ID.':'.SSO_CLIENT_SECRET);
  $body = http_build_query([
    'grant_type' => 'authorization_code','code'=>$code,'redirect_uri'=>SSO_REDIRECT_URI
  ]);
  curl_setopt_array($ch,[CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json','Authorization: Basic '.$basic], CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
  $res = curl_exec($ch); if($res===false){ throw new Exception('Token request failed: '.curl_error($ch)); } $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if ($http>=400){ throw new Exception('Token HTTP '.$http.': '.$res); }
  $data = json_decode($res,true); if(!$data || empty($data['access_token'])) throw new Exception('Invalid token response'); return $data;
}
function sso_userinfo($access_token){
  $ch = curl_init('https://sso.kmutnb.ac.th/resources/userinfo');
  curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$access_token], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
  $res = curl_exec($ch); if($res===false){ throw new Exception('Userinfo failed: '.curl_error($ch)); } $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if ($http>=400){ throw new Exception('Userinfo HTTP '.$http.': '.$res); }
  $data = json_decode($res,true); if(!$data || empty($data['profile'])) throw new Exception('Invalid userinfo'); return $data;
}
