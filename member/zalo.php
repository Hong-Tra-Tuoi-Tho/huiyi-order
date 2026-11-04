<?php
require_once __DIR__.'/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
$returnOrder=$_GET['return_order']??'';
$returnOrder=is_string($returnOrder)&&preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$returnOrder)?$returnOrder:'';
$isCallback=isset($_GET['state'])||isset($_GET['code'])||isset($_GET['error']);
try{
    hym_schema();
    if(!$isCallback){
        $callbackHost=parse_url(hym_zalo_redirect_uri(),PHP_URL_HOST);
        if(is_string($callbackHost)&&strcasecmp($callbackHost,(string)($_SERVER['HTTP_X_FORWARDED_HOST']??$_SERVER['HTTP_HOST']??''))!==0)
            redirect(preg_replace('#/[^/]*$#','/zalo.php',hym_zalo_redirect_uri()).($returnOrder!==''?'?return_order='.rawurlencode($returnOrder):''));
        redirect(hym_zalo_authorize_url($returnOrder));
    }
    $zalo=hym_zalo_callback($_GET);$returnOrder=$zalo['return_order'];
    $memberId=hym_zalo_member_id($zalo['id']);
    $current=hym_current_member();
    if($memberId<1&&$current){
        // A signed-in customer links Zalo to the account they already use.
        hym_zalo_link($current['id'],$zalo['id'],$zalo['name']);
        $_SESSION['member_flash']=['type'=>'success','key'=>'member_zalo_linked'];
        redirect(base_url('member/index.php'));
    }
    if($memberId<1&&hym_zalo_only()){
        // Only the Mini App shares the phone number the new account needs.
        $_SESSION['member_flash']=['type'=>'info','key'=>'member_zalo_need_miniapp'];
        redirect(base_url('member/index.php'));
    }
    if($memberId<1){
        // Zalo does not share the phone number, so the customer completes a local account first.
        $_SESSION['zalo_pending']=['id'=>$zalo['id'],'name'=>$zalo['name'],'created_at'=>time()];
        $_SESSION['member_flash']=['type'=>'info','key'=>'member_zalo_need_account'];
        redirect(base_url('member/register.php'));
    }
    hym_session_login($memberId);
    $_SESSION['member_flash']=['type'=>'success','key'=>'member_logged_in'];
    if($returnOrder!=='')try{
        $paymentOrder=htp_customer_order($returnOrder);
        if(!empty($paymentOrder['member_id'])&&(int)$paymentOrder['member_id']===$memberId)redirect(base_url('payment.php?order='.rawurlencode($returnOrder)));
    }catch(TransferPaymentError $error){}
}catch(HymMemberError $e){$_SESSION['member_flash']=['type'=>'error','key'=>$e->getMessage()];}
catch(Throwable $e){error_log('member_zalo: '.$e->getMessage());$_SESSION['member_flash']=['type'=>'error','key'=>'member_zalo_failed'];}
redirect(base_url('member/index.php'));
