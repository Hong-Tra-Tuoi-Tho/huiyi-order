<?php
/**
 * Nhóm combo: mọi thành viên chọn nước cho các combo chủ nhóm đã thêm vào giỏ nhóm.
 * Mỗi combo chia theo nhóm món (admin/combos.php); số ly mỗi nhóm và cả combo bị giới hạn theo cấu hình.
 * Gửi form về group/action.php (action combo_drink) -> hgc_add_drink().
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/group_views.php';

try {
    hyg_headers();
    hyg_schema();
    $r = hyg_room(hyg_code($_GET['id'] ?? ''));
    $m = hyg_member($r);
    if ($r['status'] !== 'open' || strtotime($r['expires_at'] . ' UTC') <= time()) {
        redirect(hyg_room_url($r['public_id']));
    }
    $cart = hyg_cart($r, false);
    $combos = hgc_state($cart);
    // Topping chọn thêm cho từng ly: gợi ý của món lên trước, sau đó các topping còn lại (giống trang món).
    $allToppings = hyg_all('SELECT * FROM toppings WHERE active=1 ORDER BY sort_order, id');
    $recommendedByProduct = [];
    foreach (hyg_all('SELECT product_id, topping_id FROM product_recommended_toppings ORDER BY sort_order') as $link) {
        $recommendedByProduct[(int)$link['product_id']][(int)$link['topping_id']] = true;
    }
} catch (Throwable $e) {
    hyg_error_page($e);
}

// Lần tải ngầm (live=1) để tự cập nhật không được "ăn" mất thông báo sau thao tác.
$isLive = ($_GET['live'] ?? '') === '1';
$msg = '';
if (!$isLive) {
    $msg = (string)($_SESSION['hyg_message'] ?? '');
    unset($_SESSION['hyg_message']);
}

// Dấu vân tay trạng thái combo: trình duyệt so sánh để biết có cần cập nhật giao diện không.
$liveSignature = hash('sha256', json_encode($combos, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE));
$liveUrl = base_url('group/combo.php?id=' . $r['public_id'] . '&live=1');

front_header(hgc_t('page_title'));
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/group-combo.css?v=31')) ?>">
<div class="hyg-page hgc-page">
    <a class="back" href="<?= e(hyg_room_url($r['public_id'])) ?>">← <?= e(hgc_t('back_room')) ?></a>
    <?php if ($msg !== ''): ?><div class="alert info" role="status"><?= e($msg) ?></div><?php endif; ?>

    <h1 class="hgc-title"><?= e(hgc_t('page_title')) ?></h1>

    <!-- Vùng tự cập nhật mỗi 3 giây (assets/js/group-combo-live.js): tiến độ, ly đã chọn, nhóm đã đủ -->
    <div class="hgc-live" data-live-url="<?= e($liveUrl) ?>" data-signature="<?= e($liveSignature) ?>">

    <?php if (!$combos): ?>
        <p class="hyg-card hyg-muted<?= hyg_host($r) ? '' : ' hgc-wait-host' ?>"><?= e(hyg_host($r) ? hgc_t('empty_combos') : hgc_t('wait_host')) ?></p>
    <?php endif; ?>

    <?php foreach ($combos as $combo): ?>
        <?php $comboLeft = max(0, $combo['total_cups'] - $combo['picked']); ?>
        <section class="hyg-card hgc-combo<?= $combo['complete'] ? ' is-complete' : '' ?>" id="hgc-combo-<?= (int)$combo['item_id'] ?>" data-combo="<?= (int)$combo['item_id'] ?>">
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
            <?php if (hyg_host($r)): ?>
                <!-- Chỉ chủ nhóm xoá được combo (nút thùng rác góc phải trên); xoá luôn các ly nước đã chọn cho combo đó -->
                <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-remove-form" data-confirm="<?= e(hgc_t('remove_combo_confirm')) ?>">
                    <?= hyg_action_fields($r, 'remove') ?>
                    <input type="hidden" name="item_id" value="<?= (int)$combo['item_id'] ?>">
                    <input type="hidden" name="item_version" value="<?= (int)$combo['item_version'] ?>">
                    <input type="hidden" name="back" value="combo">
                    <button class="hgc-remove-btn" type="submit" aria-label="<?= e(hgc_t('remove_combo')) ?>" title="<?= e(hgc_t('remove_combo')) ?>">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" />
                        </svg>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($combo['invalid']): ?>
                <!-- Admin đã tắt / xoá combo khi nhóm đang chọn -->
                <p class="alert danger" role="alert"><?= e(hgc_t('combo_unavailable')) ?></p>
            <?php endif; ?>

            <?php if ($combo['stale']): ?>
                <!-- Admin vừa xoá nhóm / món khỏi combo: các ly này không còn hợp lệ, khách bỏ đi và chọn lại -->
                <div class="alert danger hgc-stale" role="alert">
                    <p><?= e(hgc_t('combo_changed_notice')) ?></p>
                    <ul class="hgc-picked">
                        <?php foreach ($combo['stale'] as $drink): ?>
                            <li>
                                <span class="hgc-picked-text">
                                    <strong><?= e($drink['name'] ?? '') ?> × <?= (int)($drink['qty'] ?? 0) ?></strong>
                                    <small><?= e($drink['group_member_name'] ?? '') ?></small>
                                </span>
                                <?php if (hyg_host($r) || (int)($drink['group_member_id'] ?? 0) === (int)$m['id']): ?>
                                    <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-drink-remove">
                                        <?= hyg_action_fields($r, 'remove') ?>
                                        <input type="hidden" name="item_id" value="<?= (int)$drink['_item_id'] ?>">
                                        <input type="hidden" name="item_version" value="<?= (int)$drink['_item_version'] ?>">
                                        <input type="hidden" name="back" value="combo">
                                        <input type="hidden" name="removed" value="drink">
                                        <button type="submit" class="hgc-drink-remove-btn" aria-label="<?= e(hgc_t('remove_drink')) ?>" title="<?= e(hgc_t('remove_drink')) ?>">✕</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php foreach ($combo['groups'] as $group): ?>
                <?php
                $groupLeft = max(0, $group['max'] - $group['picked']);
                $canAdd = min($groupLeft, $comboLeft);
                ?>
                <div class="hgc-group<?= $canAdd < 1 ? ' is-full' : '' ?>" data-group="<?= (int)$group['id'] ?>">
                    <div class="hgc-group-head">
                        <strong><?= e($group['name']) ?></strong>
                        <span class="hgc-rule">- <?= e(hgc_t('picked_label')) ?> <b class="hgc-count<?= $group['picked'] >= $group['max'] ? ' is-full' : '' ?>"><?= (int)$group['picked'] ?>/<?= (int)$group['max'] ?></b></span>
                        <?php if ($groupLeft < 1): ?><span class="hgc-full"><?= e(hgc_t('group_full_label')) ?></span><?php endif; ?>
                    </div>

                    <?php
                    // Ly đã chọn của nhóm món này: hiện ngay dưới tên nhóm (không gom xuống cuối combo).
                    $groupDrinks = array_filter($combo['drinks'], fn($drink) => (int)($drink['_selection']['combo_group'] ?? 0) === (int)$group['id']);
                    ?>
                    <?php if ($groupDrinks): ?>
                        <ul class="hgc-picked">
                            <?php foreach ($groupDrinks as $drink): ?>
                                <?php $canRemoveDrink = hyg_host($r) || (int)($drink['group_member_id'] ?? 0) === (int)$m['id']; ?>
                                <li>
                                    <span class="hgc-picked-text">
                                        <strong><?= e($drink['name'] ?? '') ?> × <?= (int)($drink['qty'] ?? 0) ?></strong>
                                        <small><?= e(implode(' · ', array_filter([$drink['group_member_name'] ?? '', $drink['cup_size'] ?? '', $drink['ice'] ?? '', $drink['sugar'] ?? '']))) ?></small>
                                        <?php if (!empty($drink['toppings'])): ?>
                                            <!-- Topping của ly: gấp lại, bấm để sổ xuống danh sách -->
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
                                    <?php if ($canRemoveDrink): ?>
                                        <!-- Mỗi người xoá được ly của mình (chủ nhóm xoá được mọi ly) để chọn lại, kể cả khi nhóm đã đủ ly -->
                                        <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-drink-remove">
                                            <?= hyg_action_fields($r, 'remove') ?>
                                            <input type="hidden" name="item_id" value="<?= (int)$drink['_item_id'] ?>">
                                            <input type="hidden" name="item_version" value="<?= (int)$drink['_item_version'] ?>">
                                            <input type="hidden" name="back" value="combo">
                                            <input type="hidden" name="removed" value="drink">
                                            <button type="submit" class="hgc-drink-remove-btn" aria-label="<?= e(hgc_t('remove_drink')) ?>" title="<?= e(hgc_t('remove_drink')) ?>">✕</button>
                                        </form>
                                    <?php endif; ?>
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
                            $formId = 'hgc-' . $combo['item_id'] . '-' . $group['id'] . '-' . $productId;
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
                                    <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-drink-form" id="<?= e($formId) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="room_id" value="<?= e($r['public_id']) ?>">
                                        <input type="hidden" name="action" value="combo_drink">
                                        <input type="hidden" name="combo_item" value="<?= (int)$combo['item_id'] ?>">
                                        <input type="hidden" name="combo_group" value="<?= (int)$group['id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                                        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">

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
                                            <!-- Danh sách chọn nhiều topping: dùng lại dropdown multi-check của trang món (assets/js/app.js) -->
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
                                                <input type="number" name="qty" min="1" max="<?= (int)$canAdd ?>" value="1" inputmode="numeric" data-msg-max="<?= e(hgc_t('qty_max', ['max' => (int)$canAdd])) ?>" data-msg-min="<?= e(hgc_t('qty_min')) ?>" oninvalid="this.setCustomValidity(this.validity.rangeOverflow ? this.dataset.msgMax : this.dataset.msgMin)" oninput="this.setCustomValidity('')">
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
    <?php endforeach; ?>

    <?php
    // Thanh hành động dưới đáy: cho biết combo nào còn thiếu hoặc đã đủ, và nút đi tiếp theo vai trò.
    // Nằm trong vùng .hgc-live nên tự cập nhật theo vòng làm mới.
    if ($combos) {
        $todo = array_values(array_filter($combos, fn($c) => empty($c['complete'])));
        $totalPicked = array_sum(array_map(fn($c) => (int)$c['picked'], $combos));
        $isHost = hyg_host($r);
        // Tổng ly nước của cả phòng (ly combo + ly mua lẻ) để xét ưu đãi mini-game 10 ly.
        $roomCups = promotion_game_qty($cart) + array_sum(array_map(fn($c) => max(0, (int)$c['total_cups'] - (int)$c['picked']), $combos));
    ?>
    <div class="hgc-bar" data-bar="<?= $todo ? 'todo' : 'done' ?>">
        <?php if ($todo): ?>
            <?php
            // Số ly còn thiếu của 1 combo: đủ = min(tổng số ly, tổng tối đa các nhóm), trừ số ly đã chọn.
            $missing = static function (array $c): int {
                $need = min((int)$c['total_cups'], array_sum(array_map(fn($g) => (int)$g['max'], $c['groups'])));
                return max(0, $need - (int)$c['picked']);
            };
            $next = $todo[0];
            $missingAll = array_sum(array_map($missing, $todo));
            ?>
            <div class="hgc-bar-msg"><span class="hgc-bar-tick is-todo"><?= count($todo) ?></span><?= str_replace('@@', '<strong class="hgc-missing">' . e(hgc_t('bar_missing', ['n' => $missingAll])) . '</strong>', e(hgc_t('bar_todo', ['name' => implode(', ', array_map(fn($c) => $c['name'], $todo)), 'cups' => '@@']))) ?></div>
            <a class="hgc-bar-cta" href="#hgc-combo-<?= (int)$next['item_id'] ?>" data-scroll-to="hgc-combo-<?= (int)$next['item_id'] ?>"><?= e(hgc_t('bar_cta_todo', ['name' => $next['name']])) ?> ↓</a>
        <?php else: ?>
            <div class="hgc-bar-msg"><span class="hgc-bar-tick">✓</span><?= e(hgc_t('bar_done')) ?></div>
            <?php if (!$isHost): ?><div class="hgc-bar-sub"><?= e(hgc_t('bar_done_sub_member', ['n' => $totalPicked])) ?></div><?php endif; ?>
            <?php if ($isHost && minigame_enabled() && $roomCups >= 5 && $roomCups <= 9): ?>
                <div class="hgc-bar-upsell"><?= e(gt('lock_upsell', ['more' => 10 - $roomCups])) ?></div>
            <?php endif; ?>
            <?php if ($isHost): ?>
                <?php /* Chốt món ngay tại đây: gửi như nút "Chốt món" ở đơn nhóm (action coupon + lock_after), xong vào thẳng trang thanh toán. Gửi thường (data-native), không qua fetch. */ ?>
                <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-bar-lock" data-native>
                    <?= csrf_field() ?>
                    <input type="hidden" name="room_id" value="<?= e($r['public_id']) ?>">
                    <input type="hidden" name="action" value="coupon">
                    <input type="hidden" name="lock_after" value="1">
                    <input type="hidden" name="back" value="combo">
                    <?php if (minigame_enabled() && promotion_game_qty($cart) >= 10): ?><input type="hidden" name="promotion_mode" value="draw"><?php endif; ?>
                    <button class="hgc-bar-cta" type="submit" <?= (minigame_enabled() && $roomCups >= 5 && $roomCups <= 9) ? 'data-confirm="' . e(gt('lock_game_confirm', ['n' => $roomCups, 'more' => 10 - $roomCups])) . '" data-cancel-url="' . e(base_url('index.php?group=' . $r['public_id'])) . '"' : '' ?>><?= e(hgc_t('bar_cta_host')) ?> →</button>
                </form>
            <?php else: ?>
                <a class="hgc-bar-cta is-alt" href="<?= e(hyg_room_url($r['public_id'])) ?>"><?= e(hgc_t('bar_cta_member')) ?> →</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php } ?>
    </div>
</div>
<script src="<?= e(base_url('assets/js/group-combo-live.js?v=16')) ?>" defer></script>
<?php front_footer(); ?>
