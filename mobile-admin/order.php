<?php
require __DIR__.'/_common.php'; ma_require_admin();
require_once __DIR__.'/../lib/group_payment_views.php';
require_once __DIR__.'/../lib/dispatch_views.php';
require_once __DIR__.'/../lib/order_workflow.php';
$id=(int)($_GET['id']??0); if($id<=0) redirect(ma_url('index.php'));
hds_view_require_staff(ma_url('login.php?return_order='.$id));
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=$_POST['action']??null;if(!is_string($action))throw new InvalidArgumentException('invalid_action');
        if(in_array($action,['dispatch_refresh','dispatch_assign','dispatch_adopt','dispatch_clear'],true)){
            $_SESSION['dispatch_order_flash']=['id'=>$id,'key'=>hds_view_order_action($id,$action,$_POST,(int)$_SESSION['admin_id'])];
        }elseif($action==='confirm_merchant_payment'){
            htp_confirm($id,$_POST['payment_version']??null,(int)$_SESSION['admin_id']);$_SESSION['transfer_staff_flash']=$id;
        }elseif(in_array($action,['payment_receive','payment_correct','payment_reject','payment_refund'],true)){
            htp_staff_action($id,$action,$_POST,(int)$_SESSION['admin_id']);$_SESSION['payment_action_flash']=$id;
        }elseif($action==='status'){
            htow_change($id,$_POST['status']??null,$_POST['status_version']??null,$_POST['status_reason']??null,(int)$_SESSION['admin_id']);$_SESSION['order_status_flash']=$id;
        }else throw new InvalidArgumentException('invalid_action');
        redirect(ma_url('order.php?id='.$id));
    }catch(Throwable $exception){$error=htow_error($exception);}
}
$dispatchFlash=$_SESSION['dispatch_order_flash']??null;unset($_SESSION['dispatch_order_flash']);
if(is_array($dispatchFlash)&&(int)($dispatchFlash['id']??0)===$id&&in_array($dispatchFlash['key']??'',['dispatch_saved','dispatch_refreshed'],true))$message=dt($dispatchFlash['key']);
if(($_SESSION['transfer_staff_flash']??0)===$id){$message=htp('staff_saved');unset($_SESSION['transfer_staff_flash']);}
if(($_SESSION['payment_action_flash']??0)===$id){$message=pwf('action_saved');unset($_SESSION['payment_action_flash']);}
if(($_SESSION['order_status_flash']??0)===$id){$message=pwf('status_saved');unset($_SESSION['order_status_flash']);}
$st=db()->prepare('SELECT * FROM orders WHERE id=?');$st->execute([$id]);$o=$st->fetch(); if(!$o){http_response_code(404);exit('Order not found');}
$items=order_lines_flat($id);
ma_header('#'.$o['order_no']); ?>
<a class="ma-back" href="<?=e(ma_url('index.php'))?>">← <?=e(ma_t('back'))?></a>
<?php if($message):?><div class="hds-notice" role="status"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="hds-notice danger" role="alert"><?=e($error)?></div><?php endif;?>
<section class="ma-detail-hero"><div><span class="ma-kicker"><?=e(ma_t('time'))?> · <?=e($o['created_at'])?></span><h1>#<?=e($o['order_no'])?></h1><span class="ma-status <?=e($o['status'])?>"><?=e(ma_status_label($o['status']))?></span></div><strong><?=e(currency())?><?=money($o['total_amount'])?></strong></section>
<section class="ma-panel"><h2><?=e(ma_t('customer'))?></h2><div class="ma-fields"><div><span><?=e(ma_t('customer'))?></span><b><?=e($o['customer_name'])?></b></div><div><span><?=e(ma_t('phone'))?></span><b><a href="tel:<?=e($o['phone'])?>"><?=e($o['phone'])?></a></b></div><div class="full"><span><?=e(ma_t('address'))?></span><b><?=nl2br(e($o['address']))?></b></div><div><span><?=e(ma_t('payment'))?></span><b><?=e(payment_label($o['payment_method']))?></b></div><div><span><?=e(ma_t('invoice'))?></span><b><?=$o['invoice_required']?e(ma_t('yes')):e(ma_t('no'))?></b></div><?php if(!empty($o['order_note'])):?><div class="full note"><span><?=e(ma_t('note'))?></span><b><?=nl2br(e($o['order_note']))?></b></div><?php endif;?></div></section>
<section class="ma-panel"><h2><?=e(ma_t('status'))?></h2><form method="post" class="ma-status-form"><?=csrf_field()?><input type="hidden" name="status_version" value="<?=(int)$o['status_version']?>"><select name="status"><option value="<?=e($o['status'])?>"><?=e(ma_status_label($o['status']))?></option><?php foreach(htow_targets($o['status']) as $nextStatus):?><option value="<?=e($nextStatus)?>"><?=e(ma_status_label($nextStatus))?></option><?php endforeach;?></select><label><?=e(pwf('status_reason'))?><input name="status_reason" maxlength="1000"></label><button class="ma-btn primary" type="submit" name="action" value="status" <?=htow_targets($o['status'])?'':'disabled'?>><?=e(ma_t('update'))?></button></form></section>
<?=htow_render_history($o)?>
<?=hds_render_order_dispatch($o)?>
<?=htp_render_staff($o)?>
<?php $groupPayment=hyg_order_payment_summary($o);if($groupPayment):?><section class="ma-panel"><?=hyg_render_payment_overview($groupPayment)?></section><?php endif;?>
<section class="ma-panel"><h2><?=e(ma_t('details'))?></h2><div class="ma-item-list"><?php foreach($items as $i):?><article class="ma-item"><div class="ma-item-title"><strong><?=e($i['product_name'])?><?php if(($i['_kind']??'item')!=='item'):?><small style="display:block;font-size:12px;margin-top:5px"><?=e(occ_label($i))?></small><?php endif;?><?php if(!empty($i['group_member_name'])):?><small style="display:block;font-size:12px;margin-top:5px"><?=e(gt('ordered_by'))?>：<?=e($i['group_member_name'])?></small><?php endif;?></strong><b>×<?=$i['qty']?></b></div><div class="ma-item-options"><?php if(!empty($i['cup_size'])):?><span><?=e(ma_t('size'))?>：<?=e($i['cup_size'])?></span><?php endif;?><?php if(!empty($i['ice_choice'])):?><span><?=e(ma_t('ice'))?>：<?=e($i['ice_choice'])?></span><?php endif;?><?php if(!empty($i['sugar_choice'])):?><span><?=e(ma_t('sugar'))?>：<?=e($i['sugar_choice'])?></span><?php endif;?><?php if(!empty($i['toppings_text'])):?><span class="full"><?=e(ma_t('toppings'))?>：<?=e($i['toppings_text'])?></span><?php endif;?></div><div class="ma-item-price"><?=e(currency())?><?=money($i['line_total'])?></div></article><?php endforeach;?></div><div class="ma-totals"><?php if(!empty($o['coupon_code'])):?><div><span>Coupon</span><b><?=e($o['coupon_code'])?></b></div><?php endif;?><?php if((float)($o['subtotal_amount']??0)>0):?><div><span><?=e(ma_t('subtotal'))?></span><b><?=e(currency())?><?=money($o['subtotal_amount'])?></b></div><?php endif;?><?php if((float)($o['discount_amount']??0)>0):?><div class="discount"><span><?=e(ma_t('discount'))?></span><b>-<?=e(currency())?><?=money($o['discount_amount'])?></b></div><?php endif;?><div class="grand"><span><?=e(ma_t('total'))?></span><b><?=e(currency())?><?=money($o['total_amount'])?></b></div></div></section>
<?php ma_footer(); ?>
