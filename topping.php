<?php
require __DIR__.'/lib/bootstrap.php'; require __DIR__.'/lib/layout.php';
try{$groupContext=hyg_context();}catch(Throwable $e){hyg_error_page($e);}
$id=(int)($_GET['id']??0); $st=db()->prepare('SELECT * FROM toppings WHERE id=? AND active=1 AND sell_separately=1');$st->execute([$id]);$topping=$st->fetch();
if(!$topping){http_response_code(404);exit(e(t('topping_not_found')));} $name=localized_name($topping); $desc=localized_name($topping,'description');
try{$groupEdit=hyg_edit_item($groupContext,'topping',$id);}catch(Throwable $e){hyg_error_page($e);}
front_header($name); ?>
<a class="back" href="<?=e(hyg_url('index.php'))?>">← <?=e(t('back_menu'))?></a>
<div class="detail-card topping-detail-card"><div class="detail-media"><?php if(!empty($topping['image'])):?><img src="<?=e(base_url('uploads/'.$topping['image']))?>" alt="<?=e($name)?>"><?php else:?><div class="placeholder large">TOPPING</div><?php endif;?></div>
<div class="detail-body"><span class="eyebrow"><?=e(t('standalone_topping'))?></span><h1><?=e($name)?></h1><div class="price"><?=e(currency())?><?=money($topping['retail_price'])?></div><?php if($desc!==''):?><div class="product-description"><?=nl2br(e($desc))?></div><?php endif;?>
<form action="<?=e(base_url('actions/add_topping_to_cart.php'))?>" method="post" class="option-form" id="customize"><?=csrf_field()?><?=hyg_form_fields($groupContext,$groupEdit)?><input type="hidden" name="topping_id" value="<?=$topping['id']?>"><div class="qty-row"><label><?=e(t('quantity'))?> <input type="number" name="qty" min="1" max="<?=order_qty_max()?>" value="<?=(int)($groupEdit['selection']['qty']??1)?>"></label><button class="btn primary grow" type="submit"><?=e($groupContext?gt($groupEdit?'save_item':'add_group'):t('add_cart'))?></button></div></form></div></div>
<?php front_footer(); ?>
