<?php
require_once __DIR__.'/admin_accounts.php';
require_once __DIR__.'/partner.php';
function admin_logged_in(): bool { return haa_current()!==null; }
function admin_is_operator(): bool {return (haa_current()['role']??'')==='operator';}
function haa_order_route_allowed(string $path): bool {
 if(!preg_match('~(?:^|/)(admin|mobile-admin)/([^/]+)$~',$path,$m))return true;
 return in_array($m[2],['index.php','order.php','api_new_orders.php','push_subscription.php','login.php','logout.php'],true);
}
function haa_enforce_route(): void {
 if(admin_is_operator()&&!haa_order_route_allowed((string)($_SERVER['SCRIPT_NAME']??''))){http_response_code(403);header('Cache-Control: private, no-store');echo e(haa_t('order_only'));exit;}
}
function require_admin(): void {
 if(!admin_logged_in()){$id=basename($_SERVER['SCRIPT_NAME']??'')==='order.php'?max(0,(int)($_GET['id']??0)):0;redirect(base_url('admin/login.php'.($id?'?return_order='.$id:'')));}
 haa_enforce_route();
}
