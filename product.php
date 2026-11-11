<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
try {
    $groupContext = hyg_context();
} catch (Throwable $e) {
    hyg_error_page($e);
}
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT p.*,c.name category_name,c.name_vi category_name_vi,c.name_en category_name_en,c.name_zh category_name_zh FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    exit(e(t('not_found_drink')));
}
$ices = option_profile('ice', $p['ice_profile']);
$sugars = option_profile('sugar', $p['sugar_profile']);
$st = db()->prepare('SELECT t.* FROM product_recommended_toppings pr JOIN toppings t ON t.id=pr.topping_id WHERE pr.product_id=? AND t.active=1 ORDER BY pr.sort_order,t.sort_order,t.id');
$st->execute([$id]);
$recommended = $st->fetchAll();
$recommendedIds = array_map(fn($r) => (int)$r['id'], $recommended);
if ($recommendedIds) {
    $ph = implode(',', array_fill(0, count($recommendedIds), '?'));
    $st = db()->prepare("SELECT * FROM toppings WHERE active=1 AND id NOT IN ($ph) ORDER BY sort_order,id");
    $st->execute($recommendedIds);
    $otherToppings = $st->fetchAll();
} else $otherToppings = db()->query('SELECT * FROM toppings WHERE active=1 ORDER BY sort_order,id')->fetchAll();
$sizes = product_size_options($p);
$pn = localized_name($p);
$category = localized_name($p, 'category_name');
try {
    $groupEdit = hyg_edit_item($groupContext, 'drink', $id);
} catch (Throwable $e) {
    hyg_error_page($e);
}
$chosen = $groupEdit['selection'] ?? [];
$initialPrice = $sizes ? reset($sizes) : (float)$p['price'];
$soldQty = show_product_sales_count() ? product_sold_qty($id) : 0;
if (!empty($chosen['cup_size']) && isset($sizes[$chosen['cup_size']])) $initialPrice = $sizes[$chosen['cup_size']];
front_header($pn);
?>
<a class="back" href="<?= e(hyg_url('index.php')) ?>">← <?= e(t('back_menu')) ?></a>
<div class="detail-card">
    <div class="detail-media"><?php if ($p['image']): ?><img src="<?= e(base_url('uploads/' . $p['image'])) ?>" alt="<?= e($pn) ?>"><?php else: ?><div class="placeholder large">DRINK</div><?php endif; ?></div>
    <div class="detail-body">
        <span class="eyebrow"><?= e($category) ?></span>
        <h1><?= e($pn) ?></h1>
        <div class="price" data-dynamic-price data-currency="<?= e(currency()) ?>"><?php if ($sizes): ?><?= e($chosen['cup_size'] ?? 'L') ?> · <?= e(currency()) ?><?= money($initialPrice) ?><?php else: ?><?= e(currency()) ?><?= money($initialPrice) ?><?php endif; ?></div>
        <?php if (show_product_sales_count()): ?><div class="sold-count detail-sold-count"><?= e(t('sold_cups', ['qty' => number_format($soldQty)])) ?></div><?php endif; ?>
        <form action="<?= e(base_url('actions/add_to_cart.php')) ?>" method="post" class="option-form" id="customize"><?= csrf_field() ?><?= hyg_form_fields($groupContext, $groupEdit) ?><input type="hidden" name="product_id" value="<?= $p['id'] ?>">
            <?php if ($sizes): ?><div class="option-group size-option-group">
                    <h3><?= e(t('choose_size')) ?></h3>
                    <div class="choice-grid size-choice-grid">
                        <?php foreach ($sizes as $size => $price): ?><label class="choice size-choice"><input type="radio" name="cup_size" value="<?= e($size) ?>" data-price="<?= e((string)$price) ?>" <?= $size === ($chosen['cup_size'] ?? 'L') ? 'checked' : '' ?>><span><b><?= e($size) ?></b><em><?= e(currency()) ?><?= money($price) ?></em></span></label><?php endforeach; ?>
                    </div>
                </div><?php endif; ?>
            <div class="option-group ice-option-group">
                <h3><?= e(t('ice')) ?></h3>
                <div class="choice-grid ice-choice-grid"><?php foreach ($ices as $i => $v): ?><label class="choice"><input type="radio" name="ice" value="<?= e($v) ?>" <?= $i === (int)($chosen['ice_index'] ?? 0) ? 'checked' : '' ?>><span><?= e($v) ?></span></label><?php endforeach; ?></div>
            </div>
            <div class="option-group sugar-option-group">
                <h3><?= e(t('sugar')) ?></h3>
                <div class="choice-grid sugar-choice-grid"><?php foreach ($sugars as $i => $v): ?><label class="choice"><input type="radio" name="sugar" value="<?= e($v) ?>" <?= $i === (int)($chosen['sugar_index'] ?? 0) ? 'checked' : '' ?>><span><?= e($v) ?></span></label><?php endforeach; ?></div>
            </div>
            <?php if ($recommended): ?><div class="option-group recommended-topping-group">
                    <h3><?= e(t('recommended_toppings')) ?></h3>
                    <div class="choice-grid toppings recommended-toppings"><?php foreach ($recommended as $top): ?><label class="choice recommended-choice"><input type="checkbox" name="toppings[]" value="<?= $top['id'] ?>" <?= in_array((int)$top['id'], $chosen['topping_ids'] ?? [], true) ? 'checked' : '' ?>><span><img class="recommended-card-thumb" src="<?= e(base_url('assets/icons/thumb-up.png?v=18.12')) ?>" alt="" aria-hidden="true"><?= e(localized_name($top)) ?> <b>+<?= e(currency()) ?><?= money($top['price']) ?></b></span></label><?php endforeach; ?></div>
                </div><?php endif; ?>
            <?php if ($otherToppings): ?><div class="option-group">
                    <h3><?= e($recommended ? t('other_toppings') : t('toppings')) ?> <small><?= e(t('shared_all')) ?></small></h3>
                    <details class="multi-check" data-multi-check>
                        <summary data-placeholder="<?= e($recommended ? t('other_toppings') : t('toppings')) ?>"><?= e($recommended ? t('other_toppings') : t('toppings')) ?></summary>
                        <div class="multi-check-list">
                            <?php foreach ($otherToppings as $top): ?>
                                <label class="multi-check-item"><input type="checkbox" name="toppings[]" value="<?= $top['id'] ?>" data-label="<?= e(localized_name($top)) ?>" <?= in_array((int)$top['id'], $chosen['topping_ids'] ?? [], true) ? 'checked' : '' ?>>
                                    <span><?= e(localized_name($top)) ?></span>
                                    <b>+<?= e(currency()) ?><?= money($top['price']) ?></b>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div><?php endif; ?>
            <div class="qty-row"><label><?= e(t('quantity')) ?> <input type="number" name="qty" min="1" max="<?= order_qty_max() ?>" value="<?= (int)($chosen['qty'] ?? 1) ?>"></label><button class="btn primary grow" type="submit"><?= e($groupContext ? gt($groupEdit ? 'save_item' : 'add_group') : t('add_cart')) ?></button></div>
        </form>
    </div>
</div>
<?php front_footer(); ?>