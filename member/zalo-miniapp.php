<?php
/** JSON API for the Zalo Mini App: exchanges Zalo tokens for a one-time website sign-in link. */
require_once __DIR__.'/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: application/json; charset=utf-8');
// No cookies are used here; the Zalo tokens are the credential.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST, OPTIONS');exit;}
try{
    $data=json_decode((string)file_get_contents('php://input',false,null,0,16385),true);
    if(!is_array($data))hym_fail('member_zalo_failed');
    hym_schema();
    $memberId=hym_zalo_miniapp_member(is_string($data['access_token']??null)?$data['access_token']:'',is_string($data['phone_token']??null)?$data['phone_token']:'');
    $token=hym_login_token_issue($memberId);
    // browser_url signs in directly, in the phone's browser or in Zalo's webview.
    // login_url (zalo-open.php) is for older Mini App builds: it tries the browser, then signs in inside Zalo.
    echo json_encode([
        'ok'=>true,
        'login_url'=>base_url('member/zalo-open.php?t='.$token),
        'browser_url'=>base_url('member/zalo-login.php?t='.$token),
    ]);
}catch(HymMemberError $e){
    http_response_code($e->getMessage()==='member_rate_limited'?429:400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'message'=>mt($e->getMessage())]);
}catch(Throwable $e){
    error_log('member_zalo_miniapp: '.$e->getMessage());
    http_response_code(500);echo json_encode(['ok'=>false,'error'=>'member_zalo_failed','message'=>mt('member_zalo_failed')]);
}
