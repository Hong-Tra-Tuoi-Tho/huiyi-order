<?php
require __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/promotion_views.php';
if($_SERVER['REQUEST_METHOD']!=='POST') exit;
verify_csrf();
hcgs_guard_cart();
$action=$_POST['action']??'apply';
if($action==='remove'){
    clear_coupon();
    $_SESSION['coupon_message']=t('coupon_removed');
    redirect(base_url('cart.php'));
}
try{
    if($action!=='apply')throw new RuntimeException('promotion_invalid');
    $choice=promotion_selection_from_post($_POST);

    // "Mua 4 tặng 1" chỉ áp dụng khi giỏ đủ số ly tối thiểu (giao diện cũng khóa lựa chọn này).
    $drinkQty = promotion_drink_qty($_SESSION['cart'] ?? []);
    if ($choice['mode'] === 'buy4get1' && $drinkQty < promotion_buy4_min_qty()) {
        $_SESSION['coupon_error'] = pt('buy4_need_qty', ['min' => promotion_buy4_min_qty(), 'qty' => $drinkQty]);
        redirect(base_url('cart.php'));
    }

    $_SESSION['promotion_mode']=$choice['mode'];
    if($choice['code']!=='')$_SESSION['coupon_code']=$choice['code'];else unset($_SESSION['coupon_code']);
    if(($_POST['continue_to_checkout']??'')==='1'){
        unset($_SESSION['coupon_message'],$_SESSION['coupon_error']);
        $_SESSION['hcgs_allow_open']=true; // Checkout may open a new Mini Game session only from here.
        redirect(base_url('checkout.php'));
    }
    $_SESSION['coupon_message']=pt('selection_saved');
}catch(Throwable $e){
    $_SESSION['coupon_error']=t('coupon_invalid');
}
redirect(base_url('cart.php'));
