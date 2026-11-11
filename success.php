<?php
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/promotion_views.php';
require_once __DIR__.'/lib/game_entry.php';
require_once __DIR__.'/lib/order_detail_view.php';
header('Cache-Control: private, no-store');
$no=is_string($_SESSION['last_order_no']??null)?$_SESSION['last_order_no']:'';
$promotion=$_SESSION['last_order_promotion']??null;
$savedOrder=hge_last_order();
$gameEntry=hge_order_state($savedOrder);
if($gameEntry['state']==='ready'){header('Location: '.$gameEntry['url'],true,303);exit;}
// Each order shows the popup once; reloading this page does not bring it back.
$showOrderPopup=$no!==''&&($_SESSION['order_popup_seen']??'')!==$no;
if($showOrderPopup){
    $_SESSION['order_popup_seen']=$no;
}
front_header(t('order_sent'));
?>
<div class="success-card"><div class="success-icon">✓</div><span class="eyebrow">ORDER SENT</span><h1><?=e(t('order_sent'))?></h1><p><?=e(t('order_sent_desc'))?></p><?php if($no): ?><div class="order-number"><?=e(t('order_number'))?><br><strong><?=e($no)?></strong></div><?php endif; ?>
<?php if($savedOrder):?><?=order_detail_render($savedOrder)?><?=htp_render_customer($savedOrder)?><?php endif;?>
<?=htp_render_session_orders()?>
<?php if(is_array($promotion)):?><?=promotion_summary($promotion)?><?php endif;?>
<?=hge_render($gameEntry,base_url('actions/game_status.php'))?>
<a class="btn primary" href="<?=e(base_url('index.php'))?>"><?=e(t('return_menu'))?></a></div>
<?php if($showOrderPopup)require __DIR__.'/lib/order_success_popup.php';?>
<?php front_footer(); ?>
