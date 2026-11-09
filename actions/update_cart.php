<?php
require __DIR__.'/../lib/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST') exit;
verify_csrf();
hcgs_guard_cart();

$remove = $_POST['remove'] ?? [];
$removeKey = isset($_POST['remove_key']) ? (string)$_POST['remove_key'] : '';

// The dedicated delete button removes the selected row immediately while the
// legacy remove[] handling remains supported for backward compatibility.
if($removeKey !== '' && isset($_SESSION['cart'][$removeKey])) {
    hcc_remove($removeKey);
}

foreach(($_POST['qty']??[]) as $k=>$q){
    if(!isset($_SESSION['cart'][$k])) continue;
    // Combo và ly nước trong combo không sửa số lượng ở giỏ (đi theo luật combo).
    if(hcc_is_combo_row($_SESSION['cart'][$k])) continue;
    $q=max(0,min(order_qty_max(),(int)$q));
    if($q===0 || in_array($k,$remove,true)) unset($_SESSION['cart'][$k]);
    else $_SESSION['cart'][$k]['qty']=$q;
}
redirect(base_url('cart.php'));
