<?php
require __DIR__.'/_common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
$partner=hpa_current();
if(!$partner){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'login_required']);exit;}
try{
    $storeId=$partner['store_id'];
    $new=hyg_all("SELECT id,order_no,customer_name,total_amount,created_at FROM orders WHERE dispatch_store_id=? AND status='new' ORDER BY id DESC LIMIT 50",[$storeId]);
    // Changes whenever an order arrives, leaves (handed back / re-dispatched) or changes status, so the list knows to refresh.
    $sig=hyg_one('SELECT COUNT(*) c,COALESCE(MAX(id),0) m,COALESCE(SUM(status_version),0) v,COALESCE(SUM(dispatch_revision),0) r FROM orders WHERE dispatch_store_id=?',[$storeId]);
    echo json_encode(['ok'=>true,'signature'=>implode('.',array_map('intval',$sig)),'new_orders'=>array_map(fn($o)=>[
        'id'=>(int)$o['id'],'order_no'=>(string)$o['order_no'],'customer'=>(string)$o['customer_name'],
        'total'=>currency().money($o['total_amount']),'time'=>(string)$o['created_at'],'url'=>hpa_url('order.php?id='.(int)$o['id']),
    ],$new)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false]);}
