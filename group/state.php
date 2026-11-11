<?php
require __DIR__.'/../lib/group_poll_bootstrap.php';require __DIR__.'/../lib/group_views.php';
hyg_headers();header('Content-Type: application/json; charset=UTF-8');
try{
    hyg_schema();$r=hyg_room(hyg_code($_GET['id']??''));hyg_member($r);csrf_token();
    // Render only member-visible order details. Invitation and QR markup are host-only; no contact, invoice or device hashes.
    $html=hyg_render_room($r);
    $share=hyg_host($r)?hyg_render_share($r):'';
    // Step guide moves from 2 to 3 as soon as anyone in the group adds a drink.
    $steps=$r['status']==='submitted' ? '' : hyg_render_steps(hyg_room_step($r));
    echo json_encode(['ok'=>true,'revision'=>(int)$r['revision'],'html'=>$html,'share_html'=>$share,'steps_html'=>$steps,'server_time'=>time(),'signature'=>hash('sha256',$html)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code($e instanceof GroupOrderError?403:500);echo json_encode(['ok'=>false,'message'=>gt($e instanceof GroupOrderError?$e->getMessage():'server_error')],JSON_UNESCAPED_UNICODE);}
