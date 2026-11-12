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

/** Dữ liệu "Tài khoản của tôi" cho Mini App: giống trang member/index.php nhưng dạng JSON. */
function hym_miniapp_account(int $memberId): array {
    $member=hym_member_row($memberId);
    if(!$member)hym_fail('member_zalo_failed');
    $st=db()->prepare('SELECT points_balance,(SELECT COUNT(*) FROM members c WHERE c.parent_id=m.id) children FROM members m WHERE m.id=?');
    $st->execute([$memberId]);
    $row=$st->fetch()?:[];
    $orders=[];
    try{
        $q=db()->prepare('SELECT order_no,total_amount,status,group_room_id,created_at FROM orders WHERE member_id=? ORDER BY id DESC LIMIT 20');
        $q->execute([$memberId]);
        foreach($q->fetchAll() as $o){
            $created='';
            try{$created=(new DateTimeImmutable((string)$o['created_at']))->format('d/m/Y H:i');}catch(Throwable $e){}
            $orders[]=[
                'order_no'=>(string)$o['order_no'],
                'status'=>(string)$o['status'],
                'group'=>!empty($o['group_room_id']),
                'total'=>(float)$o['total_amount'],
                'created_at'=>$created,
            ];
        }
    }catch(Throwable $e){
        error_log('member_zalo_miniapp orders: '.$e->getMessage());
    }
    return [
        'name'=>(string)($member['display_name']??''),
        'phone'=>(string)($member['phone']??''),
        'group_points'=>(int)($row['points_balance']??0),
        'group_children'=>(int)($row['children']??0),
        'referral_points'=>hym_referral_points(),
        'currency'=>currency(),
        'orders'=>$orders,
    ];
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST, OPTIONS');exit;}
try{
    $data=json_decode((string)file_get_contents('php://input',false,null,0,16385),true);
    if(!is_array($data))hym_fail('member_zalo_failed');
    hym_schema();
    $memberId=hym_zalo_miniapp_member(is_string($data['access_token']??null)?$data['access_token']:'',is_string($data['phone_token']??null)?$data['phone_token']:'');
    // Quét QR từ máy tính: đăng nhập cho máy tính, điện thoại không mở web.
    $qrCode = is_string($data['qr'] ?? null) ? $data['qr'] : '';
    if ($qrCode !== '') {
        hym_qr_login_approve($qrCode, $memberId);
        echo json_encode(['ok' => true, 'mode' => 'desktop']);
        exit;
    }
    // Mini App hiển thị tài khoản ngay trong app (chính sách Zalo 4.1: không mở link ngoài).
    $token=hym_login_token_issue($memberId);
    echo json_encode([
        'ok'=>true,
        'member'=>hym_miniapp_account($memberId),
        // Chỉ để các bản Mini App cũ còn chạy; bản mới không dùng hai link này.
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
