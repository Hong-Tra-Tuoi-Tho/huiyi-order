<?php
require __DIR__.'/../lib/bootstrap.php'; if($_SERVER['REQUEST_METHOD']!=='POST') exit; verify_csrf();
$id=(int)($_POST['topping_id']??0); $qty=max(1,min(order_qty_max(),(int)($_POST['qty']??1)));
$st=db()->prepare('SELECT * FROM toppings WHERE id=? AND active=1 AND sell_separately=1');$st->execute([$id]);$t=$st->fetch(); if(!$t) exit(e(t('topping_not_found')));
$price=(float)($t['retail_price']??0); $key=bin2hex(random_bytes(8));
$newCartRow=['standalone_topping_id'=>$t['id'],'name'=>localized_name($t),'cup_size'=>null,'base_price'=>$price,'ice'=>'','sugar'=>'','toppings'=>[],'unit_price'=>$price,'qty'=>$qty,'lang'=>current_lang()];
if(!empty($_POST['group_id'])){
    try{
        hyg_csrf();
        $groupQty=filter_var($_POST['qty']??'',FILTER_VALIDATE_INT);
        if($groupQty===false||$groupQty<1||$groupQty>order_qty_max())hyg_fail('invalid_quantity');
        hyg_save_item(hyg_code($_POST['group_id']),$newCartRow,(int)($_POST['group_item_id']??0),(int)($_POST['group_item_version']??0),(string)($_POST['group_request']??''));
        if(!empty($_POST['group_item_id'])){
            $_SESSION['hyg_message']=gt('added');
            header('Location: '.hyg_room_url($_POST['group_id']),true,303);exit;
        }
        $_SESSION['cart_flash']=gt('added');
        header('Location: '.base_url('topping.php?id='.$id.'&group='.$_POST['group_id'].'#customize'),true,303);exit;
    }catch(Throwable $e){hyg_error_page($e);}
}
hcgs_guard_cart_add();
$_SESSION['cart'][$key]=$newCartRow;
$_SESSION['cart_flash']=t('cart_added');
redirect(base_url('topping.php?id='.$id.'#customize'));
