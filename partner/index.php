<?php
require __DIR__.'/_common.php';
$partner=require_partner();$storeId=$partner['store_id'];
$status=is_string($_GET['status']??null)&&in_array($_GET['status'],['new','accepted','done','cancelled'],true)?$_GET['status']:'';
$listUrl=hpa_url('index.php'.($status!==''?'?status='.$status:''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(($_POST['action']??'')!=='accept')throw new HpaError('status_transition_error');
        hpa_change_status((int)($_POST['order_id']??0),'accepted',$_POST['status_version']??null,$partner['id']);hp_flash('message',hpa_t('saved'));
    }catch(Throwable $error){hp_flash('error',hpa_error($error));}
    redirect($listUrl);
}
$flash=hp_take_flash();
$counts=['new'=>0,'accepted'=>0,'done'=>0,'cancelled'=>0];
foreach(hyg_all('SELECT status,COUNT(*) c FROM orders WHERE dispatch_store_id=? GROUP BY status',[$storeId]) as $row)if(isset($counts[$row['status']]))$counts[$row['status']]=(int)$row['c'];
$orders=hyg_all('SELECT id,order_no,customer_name,phone,total_amount,payment_method,status,status_version,created_at FROM orders WHERE dispatch_store_id=?'.($status!==''?' AND status=?':'').' ORDER BY id DESC LIMIT 100',$status!==''?[$storeId,$status]:[$storeId]);
hp_header($partner,hpa_t('orders'),'hp-wide'); ?>
<?php if(!empty($flash['message'])):?><div class="hds-notice" role="status"><?=e($flash['message'])?></div><?php endif;?><?php if(!empty($flash['error'])):?><div class="hds-notice danger" role="alert"><?=e($flash['error'])?></div><?php endif;?>
<section class="ma-dashboard-head"><div><span class="ma-kicker">STORE DESK</span><h1><?=e(hpa_t('orders'))?></h1></div><a class="ma-refresh" href="<?=e($listUrl)?>">↻ <?=e(hpa_t('refresh'))?></a></section>
<nav class="ma-status-tabs" data-hp-live="tabs"><a class="<?=$status==='new'?'active':''?>" href="?status=new"><b><?=$counts['new']?></b><span><?=e(hpa_t('new'))?></span></a><a class="<?=$status==='accepted'?'active':''?>" href="?status=accepted"><b><?=$counts['accepted']?></b><span><?=e(hpa_t('accepted'))?></span></a><a class="<?=$status==='done'?'active':''?>" href="?status=done"><b><?=$counts['done']?></b><span><?=e(hpa_t('done'))?></span></a><a class="<?=$status===''?'active':''?>" href="index.php"><b><?=array_sum($counts)?></b><span><?=e(hpa_t('all'))?></span></a></nav>
<!-- data-hp-live parts are swapped in place by assets/js/partner-orders.js when the store's orders change. -->
<div data-hp-live="list"><?php if(!$orders):?><div class="ma-empty"><?=e(hpa_t('empty'))?></div><?php else:?><div class="hp-table-wrap"><table class="hp-table">
<thead><tr><th><?=e(hpa_t('order_no'))?></th><th><?=e(hpa_t('time'))?></th><th><?=e(hpa_t('customer'))?></th><th><?=e(hpa_t('phone'))?></th><th><?=e(hpa_t('payment'))?></th><th class="num"><?=e(hpa_t('total'))?></th><th><?=e(hpa_t('status'))?></th><th></th></tr></thead>
<tbody><?php foreach($orders as $o):$url=hpa_url('order.php?id='.(int)$o['id']);?><tr class="<?=$o['status']==='new'?'is-new':''?>">
<td><a class="hp-order-no" href="<?=e($url)?>">#<?=e($o['order_no'])?></a></td>
<td class="muted"><?=e($o['created_at'])?></td>
<td><b><?=e($o['customer_name'])?></b></td>
<td><a href="tel:<?=e($o['phone'])?>"><?=e($o['phone'])?></a></td>
<td><?=e(payment_label((string)$o['payment_method']))?></td>
<td class="num"><b><?=e(currency())?><?=money($o['total_amount'])?></b></td>
<td><span class="ma-status <?=e($o['status'])?>"><?=e(hp_status_label($o['status']))?></span></td>
<td class="hp-row-actions"><?php if($o['status']==='new'):?><form method="post"><?=csrf_field()?><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="status_version" value="<?=(int)$o['status_version']?>"><button class="ma-btn accept" name="action" value="accept" type="submit">✓ <?=e(hpa_t('accept'))?></button></form><?php endif;?><a class="ma-btn" href="<?=e($url)?>"><?=e(hpa_t('view'))?></a></td>
</tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
<?php hp_footer(); ?>
