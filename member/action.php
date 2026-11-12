<?php
require_once __DIR__.'/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');exit;}
function hym_post(string $key): string {
    $value=$_POST[$key]??'';if(!is_string($value))hym_fail('member_invalid_profile');return $value;
}
$registrationRequest=is_string($_POST['action']??null)&&$_POST['action']==='zalo_register';
$actionCompleted=false;
$returnOrder=$_POST['return_order']??'';
$returnOrder=is_string($returnOrder)&&preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$returnOrder)?$returnOrder:'';
try{
    hym_csrf();$action=hym_post('action');$type='success';
    // Đăng nhập chỉ qua Zalo: đăng nhập / đăng ký bằng mật khẩu đóng với cả POST trực tiếp.
    if(in_array($action,['register','login'],true))hym_fail('member_zalo_only');
    switch($action){
        case 'zalo_register':hym_zalo_register(hym_post('phone'),hym_post('display_name'));$key='member_registered';break;
        case 'logout':hym_logout();$key='member_logged_out';break;
        case 'save_profile':$member=hym_require_member();hym_save_profile($member['id'],hym_post('display_name'),hym_post('delivery_address'));$key='member_profile_saved';break;
        case 'sync':$member=hym_require_member();$state=hym_sync_member($member['id'],true);$key=$state['message_key'];$type=$state['status']==='synced'?'success':'error';break;
        default:hym_fail('member_invalid_action');
    }
    $_SESSION['member_flash']=['type'=>$type,'key'=>$key];
    $actionCompleted=true;
}catch(HymMemberError $e){$_SESSION['member_flash']=['type'=>'error','key'=>$e->getMessage()];}
catch(Throwable $e){$_SESSION['member_flash']=['type'=>'error','key'=>'member_save_failed'];}
if($actionCompleted&&($action??'')==='login'&&$returnOrder!==''){
    // Only the saved member owner may resume this order; guest capabilities were cleared at login.
    try{$paymentOrder=htp_customer_order($returnOrder);if((int)($paymentOrder['member_id']??0)===(int)($_SESSION['member_id']??0)&&!empty($paymentOrder['member_id']))redirect(base_url('payment.php?order='.rawurlencode($returnOrder)));}catch(TransferPaymentError $error){}
}
$returnSuffix=(!$actionCompleted&&($action??'')==='login'&&$returnOrder!=='')?'?return_order='.rawurlencode($returnOrder):'';
redirect(base_url(($registrationRequest&&!$actionCompleted?'member/register.php':'member/index.php').$returnSuffix));
