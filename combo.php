<?php
/**
 * Chọn nước cho 1 combo trong giỏ hàng thường (đặt lẻ).
 * Giao diện dùng lại của combo nhóm (assets/css/group-combo.css); form gửi về actions/combo_cart.php.
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
hcgs_guard_cart();

$comboKey = (string)($_GET['key'] ?? '');
$combo = hcc_state()[$comboKey] ?? null;
if (!$combo) {
    redirect(base_url('cart.php'));
}

$allToppings = db()->query('SELECT * FROM toppings WHERE active=1 ORDER BY sort_order, id')->fetchAll();
$recommendedByProduct = [];
foreach (db()->query('SELECT product_id, topping_id FROM product_recommended_toppings ORDER BY sort_order')->fetchAll() as $link) {
    $recommendedByProduct[(int)$link['product_id']][(int)$link['topping_id']] = true;
}

$msg = (string)($_SESSION['hcc_message'] ?? '');
$err = (string)($_SESSION['hcc_error'] ?? '');
unset($_SESSION['hcc_message'], $_SESSION['hcc_error']);

$comboLeft = max(0, $combo['total_cups'] - $combo['picked']);

front_header(hgc_t('page_title'));
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/group-combo.css?v=16')) ?>">
<div class="hyg-page hgc-page">
    <a class="back" href="<?= e(base_url('cart.php')) ?>">← <?= e(hcc_t('back_cart')) ?></a>
    <?php if ($msg !== ''): ?><div class="alert info" role="status"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="alert danger" role="alert"><?= e($err) ?></div><?php endif; ?>

    <h1 class="hgc-title"><?= e(hgc_t('page_title')) ?></h1>

    <section class="hyg-card hgc-combo<?= $combo['complete'] ? ' is-complete' : '' ?>">
        <header class="hgc-combo-head">
            <?php if ($combo['image'] !== ''): ?>
                <img class="hgc-combo-thumb" src="<?= e(base_url('uploads/' . $combo['image'])) ?>" alt="" loading="lazy">
            <?php endif; ?>
            <div class="hgc-combo-heading">
                <span class="hyg-combo-badge"><?= e(hgc_t('badge')) ?></span>
                <h2><?= e($combo['name']) ?></h2>
                <p class="hgc-combo-progress"><?= e(hgc_t('progress', ['picked' => $combo['picked'], 'total' => $combo['total_cups']])) ?></p>
            </div>
        </header>

        <?php foreach ($combo['groups'] as $group): ?>
            <?php
            $groupLeft = max(0, $group['max'] - $group['picked']);
            $canAdd = min($groupLeft, $comboLeft);
            $groupDrinks = array_filter($combo['drinks'], fn($drink) => (int)($drink['combo_group'] ?? 0) === (int)$group['id']);
            ?>
            <div class="hgc-group<?= $canAdd < 1 ? ' is-full' : '' ?>">
                <div class="hgc-group-head">
                    <strong><?= e($group['name']) ?></strong>
                    <span class="hgc-rule">- <?= e(hgc_t('picked_label')) ?> <b class="hgc-count<?= $group['picked'] >= $group['max'] ? ' is-full' : '' ?>"><?= (int)$group['picked'] ?>/<?= (int)$group['max'] ?></b></span>
                    <?php if ($groupLeft < 1): ?><span class="hgc-full"><?= e(hgc_t('group_full_label')) ?></span><?php endif; ?>
                </div>

                <?php if ($groupDrinks): ?>
                    <ul class="hgc-picked">
                        <?php foreach ($groupDrinks as $drinkKey => $drink): ?>
                            <li>
                                <span class="hgc-picked-text">
                                    <strong><?= e($drink['name'] ?? '') ?> × <?= (int)($drink['qty'] ?? 0) ?></strong>
                                    <small><?= e(implode(' · ', array_filter([$drink['cup_size'] ?? '', $drink['ice'] ?? '', $drink['sugar'] ?? '']))) ?></small>
                                    <?php if (!empty($drink['toppings'])): ?>
                                        <details class="hgc-picked-toppings">
                                            <summary><?= e(t('toppings')) ?> (<?= count($drink['toppings']) ?>) · +<?= e(currency()) ?><?= money($drink['unit_price']) ?></summary>
                                            <ul>
                                                <?php foreach ($drink['toppings'] as $topping): ?>
                                                    <li><?= e($topping['display_name'] ?? $topping['name']) ?> <b>+<?= e(currency()) ?><?= money($topping['price']) ?></b></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </span>
                                <form method="post" action="<?= e(base_url('actions/combo_cart.php')) ?>" class="hgc-drink-remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_drink">
                                    <input type="hidden" name="combo_key" value="<?= e($comboKey) ?>">
                                    <input type="hidden" name="drink_key" value="<?= e((string)$drinkKey) ?>">
                                    <button type="submit" class="hgc-drink-remove-btn" aria-label="<?= e(hgc_t('remove_drink')) ?>" title="<?= e(hgc_t('remove_drink')) ?>">✕</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!$group['items']): ?>
                    <p class="hyg-muted"><?= e(hgc_t('no_drinks')) ?></p>
                <?php endif; ?>

                <div class="hgc-drinks">
                    <?php foreach ($group['items'] as $productId => $item): ?>
                        <?php
                        $product = $item['product'];
                        $name = localized_name($product);
                        $ices = option_profile('ice', (string)$product['ice_profile']);
                        $sugars = option_profile('sugar', (string)$product['sugar_profile']);
                        ?>
                        <details class="hgc-drink" data-drink="<?= (int)$productId ?>" <?= $canAdd < 1 ? 'data-disabled' : '' ?>>
                            <summary>
                                <?php if (!empty($product['image'])): ?>
                                    <img src="<?= e(base_url('uploads/' . $product['image'])) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="hgc-drink-placeholder" aria-hidden="true">DRINK</span>
                                <?php endif; ?>
                                <span class="hgc-drink-name"><?= e($name) ?></span>
                                <?php if ($item['sizes'] !== ['']): ?><small><?= e(implode(' / ', $item['sizes'])) ?></small><?php endif; ?>
                                <?php if ($canAdd > 0): ?><span class="hgc-drink-cta" aria-hidden="true"><?= e(hgc_t('pick')) ?><i></i></span><?php endif; ?>
                            </summary>

                            <?php if ($canAdd > 0): ?>
                                <form method="post" action="<?= e(base_url('actions/combo_cart.php')) ?>" class="hgc-drink-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="combo_key" value="<?= e($comboKey) ?>">
                                    <input type="hidden" name="combo_group" value="<?= (int)$group['id'] ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">

                                    <?php if ($item['sizes'] !== ['']): ?>
                                        <fieldset class="hgc-options">
                                            <legend><?= e(hgc_t('size')) ?></legend>
                                            <?php foreach ($item['sizes'] as $index => $size): ?>
                                                <label class="choice"><input type="radio" name="cup_size" value="<?= e($size) ?>" <?= $index === 0 ? 'checked' : '' ?>><span><?= e($size) ?></span></label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    <?php endif; ?>

                                    <fieldset class="hgc-options">
                                        <legend><?= e(t('ice')) ?></legend>
                                        <?php foreach ($ices as $index => $label): ?>
                                            <label class="choice"><input type="radio" name="ice_index" value="<?= (int)$index ?>" <?= $index === 0 ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                                        <?php endforeach; ?>
                                    </fieldset>

                                    <fieldset class="hgc-options">
                                        <legend><?= e(t('sugar')) ?></legend>
                                        <?php foreach ($sugars as $index => $label): ?>
                                            <label class="choice"><input type="radio" name="sugar_index" value="<?= (int)$index ?>" <?= $index === 0 ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                                        <?php endforeach; ?>
                                    </fieldset>

                                    <?php if ($allToppings): ?>
                                        <?php
                                        $recommended = $recommendedByProduct[(int)$productId] ?? [];
                                        $orderedToppings = array_merge(
                                            array_filter($allToppings, fn($top) => isset($recommended[(int)$top['id']])),
                                            array_filter($allToppings, fn($top) => !isset($recommended[(int)$top['id']]))
                                        );
                                        ?>
                                        <div class="hgc-options hgc-toppings">
                                            <strong class="hgc-toppings-label"><?= e(t('toppings')) ?> <small><?= e(hgc_t('topping_note')) ?></small></strong>
                                            <details class="multi-check" data-multi-check>
                                                <summary data-placeholder="<?= e(hgc_t('topping_placeholder')) ?>"><?= e(hgc_t('topping_placeholder')) ?></summary>
                                                <div class="multi-check-list">
                                                    <?php foreach ($orderedToppings as $top): ?>
                                                        <label class="multi-check-item">
                                                            <input type="checkbox" name="toppings[]" value="<?= (int)$top['id'] ?>" data-label="<?= e(localized_name($top)) ?>">
                                                            <span><?= e(localized_name($top)) ?></span>
                                                            <b>+<?= e(currency()) ?><?= money($top['price']) ?></b>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        </div>
                                    <?php endif; ?>

                                    <div class="hgc-submit">
                                        <label><?= e(t('quantity')) ?>
                                            <input type="number" name="qty" min="1" max="<?= (int)$canAdd ?>" value="1" inputmode="numeric">
                                        </label>
                                        <button class="btn primary" type="submit"><?= e(hgc_t('add_to_combo')) ?></button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="cart-actions"><a class="btn primary" href="<?= e(base_url('cart.php')) ?>"><?= e(hcc_t('done')) ?></a></div>
</div>
<?php front_footer(); ?>
