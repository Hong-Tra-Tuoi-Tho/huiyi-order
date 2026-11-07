<?php
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}

/** Saved order lines and totals: drinks, toppings, Mini Game gift lines and every discount taken off. */
function order_detail_render(array $order): string {
    $items=order_lines_flat((int)$order['id']);if(!$items)return '';
    $drinks=$toppings=$gifts=$combos=0;
    foreach($items as $item){
        if(($item['_kind']??'item')==='combo')$combos+=(int)$item['qty']; // dòng combo không phải ly nước hay topping
        elseif(!empty($item['game_reward_code']))$gifts+=(int)$item['qty'];
        elseif(!empty($item['product_id']))$drinks+=(int)$item['qty'];
        else $toppings+=(int)$item['qty'];
    }
    ob_start(); ?>
  <div class="order-detail">
    <h3>Chi tiết đơn hàng</h3>
    <p class="order-detail-counts"><?= $drinks ?> ly nước<?php if ($combos): ?> · <?= $combos ?> combo<?php endif; ?><?php if ($toppings): ?> · <?= $toppings ?> phần topping<?php endif; ?><?php if ($gifts): ?> · 🎁 <?= $gifts ?> quà Mini Game<?php endif; ?></p>
    <ul>
      <?php foreach ($items as $item): ?><li>
          <div><?php if (($item['_kind'] ?? 'item') !== 'item'): ?><small><?= e(occ_label($item)) ?></small><?php endif; ?><strong><?= e($item['product_name']) ?><?php if (!empty($item['cup_size'])): ?> · <?= e($item['cup_size']) ?><?php endif; ?> × <?= (int)$item['qty'] ?></strong>
            <?php if (!empty($item['toppings_text'])): ?><small>Topping: <?= e($item['toppings_text']) ?></small><?php endif; ?>
            <?php if (!empty($item['ice_choice']) || !empty($item['sugar_choice'])): ?><small><?= e(trim(($item['ice_choice'] ?? '') . ' · ' . ($item['sugar_choice'] ?? ''), ' ·')) ?></small><?php endif; ?>
            <?php if (!empty($item['group_member_name'])): ?><small><?= e(gt('ordered_by')) ?>：<?= e($item['group_member_name']) ?></small><?php elseif (!empty($item['game_reward_code'])): ?><small>Mã quà: <?= e($item['game_reward_code']) ?></small><?php endif; ?>
          </div>
          <b><?= e(currency()) ?><?= money($item['line_total']) ?></b>
        </li><?php endforeach; ?>
    </ul>
    <div class="order-detail-line"><span><?= e(t('subtotal')) ?></span><b><?= e(currency()) ?><?= money($order['subtotal_amount']) ?></b></div>
    <?php if ((float)$order['discount_amount'] > 0): ?><div class="order-detail-line discount"><span><?= e(t('coupon_discount')) ?><?php if (!empty($order['coupon_code'])): ?> · <?= e($order['coupon_code']) ?><?php endif; ?></span><b>−<?= e(currency()) ?><?= money($order['discount_amount']) ?></b></div><?php endif; ?>
    <div class="order-detail-line total"><span><?= e(t('total')) ?></span><b><?= e(currency()) ?><?= money($order['total_amount']) ?></b></div>
    <?php if (!empty($order['payment_method'])): ?><div class="order-detail-line"><span><?= e(t('payment_method')) ?></span><b><?= e(payment_label((string)$order['payment_method'])) ?></b></div><?php endif; ?>
  </div>
  <style>
    .order-detail{margin:16px 0;text-align:left}
    .order-detail h3{margin:0 0 4px}
    .order-detail-counts{margin:0;opacity:.75}
    .order-detail ul{list-style:none;margin:8px 0;padding:0}
    .order-detail li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed rgba(0,0,0,.12)}
    .order-detail li small{display:block;opacity:.75}
    .order-detail-line{display:flex;justify-content:space-between;gap:12px;padding:4px 0}
    .order-detail-line.discount b{color:#b91f29}
    .order-detail-line.total{font-size:1.1em;font-weight:800}
  </style>
<?php return (string)ob_get_clean();
}
