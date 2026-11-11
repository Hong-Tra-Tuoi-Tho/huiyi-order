<?php
require __DIR__.'/../lib/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST') exit;
verify_csrf();
$id=(int)($_POST['product_id']??0);
$qty=max(1,min(order_qty_max(),(int)($_POST['qty']??1)));
$st=db()->prepare('SELECT * FROM products WHERE id=? AND active=1');
$st->execute([$id]);
$p=$st->fetch();
if(!$p) exit(e(t('product_not_found')));

$cupSize=null;
$basePrice=(float)$p['price'];
if(product_has_sizes($p)){
    $cupSize=strtoupper(trim((string)($_POST['cup_size']??'')));
    $sizes=product_size_options($p);
    if(!isset($sizes[$cupSize])) exit(e(t('invalid_option')));
    $basePrice=(float)$sizes[$cupSize];
}

$ice=(string)($_POST['ice']??'');
$sugar=(string)($_POST['sugar']??'');
$iceOptions=option_profile('ice',$p['ice_profile']);
$sugarOptions=option_profile('sugar',$p['sugar_profile']);
$iceIndex=array_search($ice,$iceOptions,true);
$sugarIndex=array_search($sugar,$sugarOptions,true);
if($iceIndex===false || $sugarIndex===false) exit(e(t('invalid_option')));

$ids=array_values(array_unique(array_map('intval',$_POST['toppings']??[])));
$tops=[];
$topTotal=0;
if($ids){
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=db()->prepare("SELECT * FROM toppings WHERE active=1 AND id IN ($ph) ORDER BY sort_order,id");
    $st->execute($ids);
    foreach($st->fetchAll() as $top){
        $tops[]=['id'=>$top['id'],'name'=>$top['name'],'display_name'=>localized_name($top),'price'=>$top['price']];
        $topTotal+=(float)$top['price'];
    }
}
if(!empty($_POST['group_id'])&&count($tops)!==count($ids)){hyg_error_page(new GroupOrderError('item_unavailable'));}
$key=bin2hex(random_bytes(8));
$newCartRow=[
    'product_id'=>$p['id'],'name'=>localized_name($p),'cup_size'=>$cupSize,
    'base_price'=>$basePrice,'ice'=>$ice,'ice_profile'=>$p['ice_profile'],'ice_index'=>(int)$iceIndex,
    'sugar'=>$sugar,'sugar_profile'=>$p['sugar_profile'],'sugar_index'=>(int)$sugarIndex,
    'toppings'=>$tops,'unit_price'=>$basePrice+$topTotal,'qty'=>$qty,'lang'=>current_lang()
];
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
        header('Location: '.base_url('product.php?id='.$id.'&group='.$_POST['group_id'].'#customize'),true,303);exit;
    }catch(Throwable $e){hyg_error_page($e);}
}
hcgs_guard_cart_add();
$_SESSION['cart'][$key]=$newCartRow;
$_SESSION['cart_flash']=t('cart_added');
redirect(base_url('product.php?id='.$id.'#customize'));
