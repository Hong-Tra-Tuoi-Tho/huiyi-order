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
<?php
// Icon ly trà sữa nhiều màu (ống hút, nắp, đá, trân châu): dùng chung cho tiêu đề "Chọn ly" và footer.
$hgcCupSvg = static fn(int $size): string => '<svg class="hgc-cup-svg" viewBox="0 0 32 32" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
    . '<path d="M19.5 9V3.2c0-.7.5-1.2 1.2-1.2H25" fill="none" stroke="#ffb800" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M8.6 12.5 10.3 28a2.2 2.2 0 0 0 2.2 2h7a2.2 2.2 0 0 0 2.2-2l1.7-15.5Z" fill="#fff" stroke="#b3201f" stroke-width="1.6" stroke-linejoin="round"/>'
    . '<path d="M9.4 17.5h13.2L21.5 27.6a1.6 1.6 0 0 1-1.6 1.4h-7.8a1.6 1.6 0 0 1-1.6-1.4Z" fill="#d92b2b"/>'
    . '<rect x="12" y="19.2" width="4.2" height="4.2" rx="1" transform="rotate(-12 14 21.3)" fill="#fff" opacity=".6"/>'
    . '<rect x="17.2" y="21.4" width="3.6" height="3.6" rx="1" transform="rotate(14 19 23.2)" fill="#fff" opacity=".45"/>'
    . '<circle cx="13" cy="27" r="1.3" fill="#5a0f0f"/><circle cx="16" cy="27.4" r="1.3" fill="#5a0f0f"/><circle cx="19" cy="27" r="1.3" fill="#5a0f0f"/>'
    . '<path d="M10.3 14.2l.9 8" stroke="#fff" stroke-width="1.2" stroke-linecap="round" opacity=".7"/>'
    . '<rect x="6.6" y="9" width="18.8" height="3.8" rx="1.9" fill="#b3201f"/>'
    . '<rect x="8.4" y="9.8" width="6" height="1" rx=".5" fill="#fff" opacity=".35"/>'
    . '</svg>';
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/group-combo.css?v=108')) ?>">
<div class="hyg-page hgc-page">
    <?php if (!$combos): ?>
        <a class="back" href="<?= e(hyg_room_url($r['public_id'])) ?>">← <?= e(hgc_t('back_room')) ?></a>
    <?php endif; ?>
    <?php if ($msg !== ''): ?><div class="alert info" role="status"><?= e($msg) ?></div><?php endif; ?>

    <h1 class="hgc-title hgc-sr"><?= e(hgc_t('page_title')) ?></h1>

    <!-- Vùng tự cập nhật mỗi 3 giây (assets/js/group-combo-live.js): tiến độ, ly đã chọn, nhóm đã đủ -->
    <div class="hgc-live" data-live-url="<?= e($liveUrl) ?>" data-signature="<?= e($liveSignature) ?>">

        <?php if ($combos): ?>
            <!-- Tab combo: mỗi tab là một combo (tên + số ly đã chọn); chỉ combo đang chọn hiện ra (group-combo-live.js). -->
            <!-- Banner đầu trang (thẻ hồng có sóng): tiêu đề "Chọn combo" + danh sách combo ngay dưới tiêu đề
                 + 2 ly nước trên bệ sáng bên phải + cành lá xanh + lá viền góc phải trên (CSS .hgc-hero). -->
            <!-- Nút quay lại giỏ nhóm: tách riêng, nằm trên banner -->
            <a class="back hgc-hero-back" href="<?= e(hyg_room_url($r['public_id'])) ?>">← <?= e(hgc_t('back_room')) ?></a>
            <div class="hgc-banner hgc-hero" style="--hgc-hero-waves: url('<?= e(base_url('assets/icons/banner-combo-waves.svg')) ?>'); --hgc-hero-leaf: url('<?= e(base_url('assets/icons/banner-combo-leaf.svg')) ?>'); --hgc-hero-sprig: url('<?= e(base_url('assets/icons/banner-combo-sprig.svg')) ?>')">
                <h2 class="hgc-step-title hgc-hero-title"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m12 3 9 5-9 5-9-5 9-5Z" />
                        <path d="m3 13 9 5 9-5" />
                        <path d="m3 17.5 9 5 9-5" />
                    </svg><span><?= e(hgc_t('step_combo')) ?></span></h2>
                <!-- Danh sách combo ngay dưới tiêu đề "Chọn combo" (nằm trong banner) -->
                <nav class="hgc-tabs hgc-combo-tabs" role="tablist" aria-label="<?= e(hgc_t('page_title')) ?>">
                    <?php foreach ($combos as $ci => $tabCombo): ?>
                        <?php $tabPercent = $tabCombo['total_cups'] > 0 ? min(100, (int)round($tabCombo['picked'] / $tabCombo['total_cups'] * 100)) : 0; ?>
                        <div role="tab" tabindex="0" class="hgc-tab<?= $tabCombo['complete'] ? ' is-complete' : '' ?><?= hyg_host($r) ? ' has-remove' : '' ?>" data-tab-combo="<?= (int)$tabCombo['item_id'] ?>" aria-selected="<?= $ci === 0 ? 'true' : 'false' ?>">
                            <?php if ($tabCombo['image'] !== ''): ?>
                                <img class="hgc-tab-img" src="<?= e(thumb_url((string)$tabCombo['image'], 120)) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span class="hgc-tab-body">
                                <span class="hgc-tab-name"><?= e($tabCombo['name']) ?></span>
                                <span class="hgc-tab-prog">
                                    <span class="hgc-pc-bar"><i style="width: <?= $tabPercent ?>%"></i></span>
                                    <span class="hgc-tab-count"><b><?= (int)$tabCombo['picked'] ?></b>/<?= (int)$tabCombo['total_cups'] ?> <?= e(hgc_t('cups_unit')) ?></span>
                                </span>
                            </span>
                            <?php if (hyg_host($r)): ?>
                                <!-- Chỉ chủ nhóm xoá được combo: thùng rác nằm ngay góc trên bên phải tab của combo đó; xoá luôn các ly nước đã chọn cho combo -->
                                <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-remove-form" data-confirm="<?= e(hgc_t('remove_combo_confirm')) ?>">
                                    <?= hyg_action_fields($r, 'remove') ?>
                                    <input type="hidden" name="item_id" value="<?= (int)$tabCombo['item_id'] ?>">
                                    <input type="hidden" name="item_version" value="<?= (int)$tabCombo['item_version'] ?>">
                                    <input type="hidden" name="back" value="combo">
                                    <button class="hgc-remove-btn" type="submit" aria-label="<?= e(hgc_t('remove_combo')) ?>" title="<?= e(hgc_t('remove_combo')) ?>">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" />
                                        </svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </nav>
                <span class="hgc-hero-sprig" aria-hidden="true"></span>
                <img class="hgc-hero-cups" src="<?= e(base_url('assets/icons/banner-combo-cups.png')) ?>" alt="" width="760" height="442">
            </div>
        <?php endif; ?>

        <?php if (!$combos): ?>
            <p class="hyg-card hyg-muted<?= hyg_host($r) ? '' : ' hgc-wait-host' ?>"><?= e(hyg_host($r) ? hgc_t('empty_combos') : hgc_t('wait_host')) ?></p>
        <?php endif; ?>

        <?php foreach ($combos as $ci => $combo): ?>
            <?php $comboLeft = max(0, $combo['total_cups'] - $combo['picked']); ?>
            <section class="hyg-card hgc-combo<?= $combo['complete'] ? ' is-complete' : '' ?>" id="hgc-combo-<?= (int)$combo['item_id'] ?>" data-combo="<?= (int)$combo['item_id'] ?>" <?= $ci > 0 ? ' hidden' : '' ?>>

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

                <?php if (count($combo['groups']) > 0): ?>
                    <!-- Tab nhóm món của combo này: mỗi tab là một nhóm (tên + số ly đã chọn / tối đa) -->
                    <h2 class="hgc-step-title"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <!-- Icon ly trà sữa: nắp, ống hút, thân ly, vạch trân châu -->
                            <path d="M6 9h12l-1.3 11.1a1.6 1.6 0 0 1-1.6 1.4H8.9a1.6 1.6 0 0 1-1.6-1.4L6 9Z" />
                            <path d="M5 9h14" />
                            <path d="M13.5 9V4.5a1 1 0 0 1 1-1H18" />
                            <path d="M7.2 14.5h9.6" />
                            <circle cx="10" cy="18" r=".6" fill="currentColor" />
                            <circle cx="12" cy="18.4" r=".6" fill="currentColor" />
                            <circle cx="14" cy="18" r=".6" fill="currentColor" />
                        </svg><span><?= e(hgc_t('step_group')) ?></span></h2>
                    <div class="hgc-group-tabs-wrap">
                        <!-- Hai nút ‹ › nhỏ ở hai bên: cuộn hàng tab nhóm qua trái / phải (chỉ hiện khi có nhiều nhóm hơn bề ngang màn hình) -->
                        <button type="button" class="hgc-tabs-arrow" data-tabs-arrows data-tabs-prev aria-label="Previous" hidden>‹</button>
                        <nav class="hgc-tabs hgc-group-tabs" role="tablist">
                            <?php foreach ($combo['groups'] as $gi => $tabGroup): ?>
                                <?php
                                $gPicked = (int)$tabGroup['picked'];
                                $gMax = (int)$tabGroup['max'];
                                $gPercent = $gMax > 0 ? min(100, (int)round($gPicked / $gMax * 100)) : 0;
                                $gState = $gPicked >= $gMax ? ' is-complete' : ($gPicked > 0 ? ' is-partial' : '');
                                ?>
                                <button type="button" role="tab" class="hgc-tab<?= $gState ?>" data-tab-group="<?= (int)$tabGroup['id'] ?>" aria-selected="<?= $gi === 0 ? 'true' : 'false' ?>">
                                    <span class="hgc-tab-name"><?= e($tabGroup['name']) ?></span>
                                    <span class="hgc-tab-count"><?= $gPicked ?>/<?= $gMax ?></span>
                                    <span class="hgc-pc-bar"><i style="width: <?= $gPercent ?>%"></i></span>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                        <button type="button" class="hgc-tabs-arrow" data-tabs-arrows data-tabs-next aria-label="Next" hidden>›</button>
                    </div>
                <?php endif; ?>

                <?php foreach ($combo['groups'] as $gi => $group): ?>
                    <?php
                    $groupLeft = max(0, $group['max'] - $group['picked']);
                    $canAdd = min($groupLeft, $comboLeft);
                    ?>
                    <div class="hgc-group<?= $canAdd < 1 ? ' is-full' : '' ?>" data-group="<?= (int)$group['id'] ?>" <?= $gi > 0 ? ' hidden' : '' ?>>
                        <!-- <div class="hgc-group-head">
                        <strong class="hgc-step-title"><?= $hgcCupSvg(28) ?><span><?= e(hgc_t('step_cups')) ?></span><b class="hgc-count<?= $group['picked'] >= $group['max'] ? ' is-full' : '' ?>"><?= (int)$group['picked'] ?>/<?= (int)$group['max'] ?></b></strong>
                        <span class="hgc-rule hgc-rule-chip<?= $group['picked'] >= $group['max'] ? ' is-full' : '' ?>"><?= e(hgc_t('cups_picked_chip', ['picked' => (int)$group['picked'], 'max' => (int)$group['max']])) ?></span>
                    </div> -->

                        <?php
                        // Ly đã chọn của nhóm món này: hiện ngay dưới tên nhóm (không gom xuống cuối combo).
                        $groupDrinks = array_filter($combo['drinks'], fn($drink) => (int)($drink['_selection']['combo_group'] ?? 0) === (int)$group['id']);
                        ?>
                        <?php if ($groupDrinks): ?>
                            <!-- Mỗi ly là một thẻ cao cố định (ly × N cao N ô) -->
                            <ul class="hgc-picked">
                                <?php foreach ($groupDrinks as $drink): ?>
                                    <?php
                                    $canRemoveDrink = hyg_host($r) || (int)($drink['group_member_id'] ?? 0) === (int)$m['id'];
                                    $qty = max(1, (int)($drink['qty'] ?? 1));
                                    $topNames = array_map(fn($top) => (string)($top['display_name'] ?? $top['name'] ?? ''), $drink['toppings'] ?? []);
                                    $detail = implode(' · ', array_filter(array_merge(
                                        [$drink['group_member_name'] ?? '', $drink['cup_size'] ?? '', $drink['ice'] ?? '', $drink['sugar'] ?? ''],
                                        $topNames
                                    )));
                                    ?>
                                    <li style="--slots: <?= $qty ?>" title="<?= e(($drink['name'] ?? '') . ' × ' . $qty . ' — ' . $detail) ?>">
                                        <span class="hgc-picked-text">
                                            <strong><?= e($drink['name'] ?? '') ?> × <?= $qty ?></strong>
                                            <small><?= e($detail) ?></small>
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
                                            <img src="<?= e(thumb_url((string)$product['image'], 300)) ?>" alt="" width="52" height="52" loading="lazy" decoding="async">
                                        <?php else: ?>
                                            <span class="hgc-drink-placeholder" aria-hidden="true">DRINK</span>
                                        <?php endif; ?>
                                        <span class="hgc-drink-name"><?= e($name) ?></span>
                                        <?php if ($canAdd > 0): ?><span class="hgc-drink-cta" aria-hidden="true"><?= e(hgc_t('pick')) ?></span><?php endif; ?>
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

                                            <!-- Đầu popup chọn món: tên món + nút đóng (group-combo-live.js) -->
                                            <div class="hgc-modal-head">
                                                <?php if (!empty($product['image'])): ?>
                                                    <img class="hgc-modal-cup" src="<?= e(thumb_url((string)$product['image'], 120)) ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
                                                <?php else: ?>
                                                    <span class="hgc-modal-cup hgc-modal-cup-emoji" aria-hidden="true">🥤</span>
                                                <?php endif; ?>
                                                <strong><?= e($name) ?></strong>
                                                <button type="button" class="hgc-modal-close" data-hgc-close aria-label="Close">✕</button>
                                            </div>

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
            // Nhắc mua thêm khi số ly lẻ (sau khi trừ các chục đầy) là 6-9: 6-9, 16-19, 26-29...
            $cupsToNextChance = 10 - ($roomCups % 10);
            $nearGameChance = minigame_enabled() && ($roomCups % 10) >= 6;
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
                    // Số ly còn thiếu của đúng combo mà nút "Chọn tiếp" dẫn tới.
                    $missingNext = max(1, $missing($next));
                    ?>
                    <div class="hgc-bar-row">
                        <div class="hgc-bar-info">
                            <span class="hgc-bar-icon"><?= $hgcCupSvg(34) ?></span>
                            <span class="hgc-bar-text">
                                <strong class="hgc-bar-name hgc-bar-need"><?= e(hgc_t('bar_need_title', ['n' => $missingNext])) ?></strong>
                                <span class="hgc-bar-sub"><?= e(hgc_t('bar_need_sub', ['n' => $missingNext])) ?></span>
                            </span>
                        </div>
                        <a class="hgc-bar-cta" href="#hgc-combo-<?= (int)$next['item_id'] ?>" data-scroll-to="hgc-combo-<?= (int)$next['item_id'] ?>" title="<?= e(hgc_t('bar_cta_todo', ['name' => $next['name']])) ?>">
                            <span><?= e(hgc_t('bar_cta_next')) ?></span>
                            <i aria-hidden="true">→</i>
                        </a>
                    </div>
                <?php else: ?>
                    <?php if ($isHost && $nearGameChance): ?>
                        <div class="hgc-bar-upsell"><?= e($roomCups >= 10
                            ? gt('lock_upsell_next', ['more' => $cupsToNextChance, 'total' => intdiv($roomCups, 10) + 1])
                            : gt('lock_upsell', ['more' => $cupsToNextChance])) ?></div>
                    <?php endif; ?>
                    <div class="hgc-bar-row">
                        <div class="hgc-bar-info">
                            <span class="hgc-bar-text">
                                <strong class="hgc-bar-name"><?= e(hgc_t('bar_done')) ?></strong>
                                <small class="hgc-bar-sub"><?= e($isHost ? hgc_t('bar_done_sub_host', ['n' => $totalPicked]) : hgc_t('bar_done_sub_member', ['n' => $totalPicked])) ?></small>
                            </span>
                        </div>
                        <?php if ($isHost): ?>
                            <?php /* Chốt món ngay tại đây: gửi như nút "Chốt món" ở đơn nhóm (action coupon + lock_after), xong vào thẳng trang thanh toán. Gửi thường (data-native), không qua fetch. */ ?>
                            <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hgc-bar-lock" data-native>
                                <?= csrf_field() ?>
                                <input type="hidden" name="room_id" value="<?= e($r['public_id']) ?>">
                                <input type="hidden" name="action" value="coupon">
                                <input type="hidden" name="lock_after" value="1">
                                <input type="hidden" name="back" value="combo">
                                <?php if (minigame_enabled() && promotion_game_qty($cart) >= 10): ?><input type="hidden" name="promotion_mode" value="draw"><?php endif; ?>
                                <button class="hgc-bar-cta" type="submit" <?= $nearGameChance ? hyg_lock_game_attrs((int)$roomCups, (string)$r['public_id']) : '' ?>>
                                    <span><?= e(hgc_t('bar_cta_host')) ?></span>
                                    <i aria-hidden="true">›</i>
                                </button>
                            </form>
                        <?php else: ?>
                            <a class="hgc-bar-cta is-alt" href="<?= e(hyg_room_url($r['public_id'])) ?>">
                                <span><?= e(hgc_t('bar_cta_member')) ?></span>
                                <i aria-hidden="true">›</i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php } ?>
    </div>
</div>
<script src="<?= e(base_url('assets/js/group-combo-live.js?v=31')) ?>" defer></script>
<?php front_footer(); ?>