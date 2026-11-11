<?php
require __DIR__.'/_common.php';
$partner=require_partner();
$id=max(0,(int)($_GET['id']??0));$self=hpa_url('order.php?id='.$id);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=$_POST['action']??null;
        if($action==='accept'||$action==='complete'){hpa_change_status($id,$action==='accept'?'accepted':'done',$_POST['status_version']??null,$partner['id']);hp_flash('message',hpa_t('saved'));redirect($self);}
        if($action==='return'){hpa_return_order($id,$_POST['dispatch_revision']??null,$_POST['reason']??null,$partner['id']);hp_flash('message',hpa_t('returned'));redirect(hpa_url('index.php'));}
        throw new HpaError('status_transition_error');
    }catch(Throwable $error){hp_flash('error',hpa_error($error));redirect($self);}
}
$o=hpa_order($id,$partner['store_id']);
if(!$o){hp_flash('error',hpa_t('not_found'));redirect(hpa_url('index.php'));}
$items=order_lines_flat($id);$flash=hp_take_flash();
hp_header($partner,'#'.$o['order_no']); ?>
<a class="ma-back" href="<?=e(hpa_url('index.php'))?>">← <?=e(hpa_t('back'))?></a>
<?php if(!empty($flash['message'])):?><div class="hds-notice" role="status"><?=e($flash['message'])?></div><?php endif;?><?php if(!empty($flash['error'])):?><div class="hds-notice danger" role="alert"><?=e($flash['error'])?></div><?php endif;?>
<section class="ma-detail-hero"><div><span class="ma-kicker"><?=e(hpa_t('time'))?> · <?=e($o['created_at'])?></span><h1>#<?=e($o['order_no'])?></h1><span class="ma-status <?=e($o['status'])?>"><?=e(hp_status_label($o['status']))?></span></div><strong><?=e(currency())?><?=money($o['total_amount'])?></strong></section>
<section class="ma-panel"><?php if($o['status']==='new'):?><div class="hp-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="status_version" value="<?=(int)$o['status_version']?>"><button class="ma-btn accept wide" type="submit" name="action" value="accept">✓ <?=e(hpa_t('accept'))?></button></form>
  <details class="hp-return"><summary><?=e(hpa_t('return'))?></summary><form method="post" data-confirm="<?=e(hpa_t('return_confirm'))?>"><?=csrf_field()?><input type="hidden" name="dispatch_revision" value="<?=(int)($o['dispatch_revision']??0)?>"><p><?=e(hpa_t('return_help'))?></p><label><?=e(hpa_t('return_reason'))?><textarea name="reason" maxlength="1000" rows="3" required></textarea></label><button class="ma-btn danger wide" type="submit" name="action" value="return"><?=e(hpa_t('return'))?></button></form></details></div>
<?php elseif($o['status']==='accepted'):?><form method="post" data-confirm="<?=e(hpa_t('complete_confirm'))?>"><?=csrf_field()?><input type="hidden" name="status_version" value="<?=(int)$o['status_version']?>"><button class="ma-btn accept wide" type="submit" name="action" value="complete">✓ <?=e(hpa_t('complete'))?></button></form>
<?php else:?><p class="ma-order-meta"><span><?=e(hpa_t('final'))?></span></p><?php endif;?></section>
<section class="ma-panel"><h2><?=e(hpa_t('customer'))?></h2><div class="ma-fields"><div><span><?=e(hpa_t('customer'))?></span><b><?=e($o['customer_name'])?></b></div><div><span><?=e(hpa_t('phone'))?></span><b><a href="tel:<?=e($o['phone'])?>"><?=e($o['phone'])?></a></b></div><div class="full"><span><?=e(hpa_t('address'))?></span><b><?=nl2br(e($o['address']))?></b></div><div><span><?=e(hpa_t('payment'))?></span><b><?=e(payment_label((string)$o['payment_method']))?></b></div><?php if(!empty($o['order_note'])):?><div class="full note"><span><?=e(hpa_t('note'))?></span><b><?=nl2br(e($o['order_note']))?></b></div><?php endif;?></div></section>
<section class="ma-panel"><div class="ma-item-list"><?php foreach($items as $i):?><article class="ma-item"><div class="ma-item-title"><strong><?=e($i['product_name'])?><?php if(($i['_kind']??'item')!=='item'):?><small style="display:block;font-size:12px;margin-top:5px"><?=e(occ_label($i))?></small><?php endif;?><?php if(!empty($i['group_member_name'])):?><small style="display:block;font-size:12px;margin-top:5px"><?=e(gt('ordered_by'))?>：<?=e($i['group_member_name'])?></small><?php endif;?></strong><b>×<?=(int)$i['qty']?></b></div><div class="ma-item-options"><?php if(!empty($i['cup_size'])):?><span>Size：<?=e($i['cup_size'])?></span><?php endif;?><?php if(!empty($i['ice_choice'])):?><span><?=e(t('ice'))?>：<?=e($i['ice_choice'])?></span><?php endif;?><?php if(!empty($i['sugar_choice'])):?><span><?=e(t('sugar'))?>：<?=e($i['sugar_choice'])?></span><?php endif;?><?php if(!empty($i['toppings_text'])):?><span class="full">Topping：<?=e($i['toppings_text'])?></span><?php endif;?></div><div class="ma-item-price"><?=e(currency())?><?=money($i['line_total'])?></div></article><?php endforeach;?></div><div class="ma-totals"><?php if((float)($o['discount_amount']??0)>0):?><div><span><?=e(t('subtotal'))?></span><b><?=e(currency())?><?=money($o['subtotal_amount'])?></b></div><div class="discount"><span><?=e(t('coupon_discount'))?></span><b>-<?=e(currency())?><?=money($o['discount_amount'])?></b></div><?php endif;?><div class="grand"><span><?=e(t('total'))?></span><b><?=e(currency())?><?=money($o['total_amount'])?></b></div></div></section>
<script>document.querySelectorAll('form[data-confirm]').forEach(function(f){f.addEventListener('submit',function(e){if(!confirm(f.dataset.confirm))e.preventDefault();});});</script>
<?php hp_footer(); ?>
