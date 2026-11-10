<?php
/**
 * Combo trong giỏ hàng thường (session $_SESSION['cart']), chế độ đặt lẻ ở index.php.
 *
 * Giỏ chứa 2 loại dòng, cùng cấu trúc dòng giỏ cũ để tính tiền / lưu order_items chạy như thường:
 *   - Dòng combo : is_combo = true, giá = giá combo, qty luôn 1. Không có product_id nên không dính khuyến mãi.
 *   - Dòng nước  : combo_drink = true, combo_parent = key dòng combo, combo_group = id nhóm món,
 *                  giá 0 (chỉ cộng tiền topping), qty = số ly.
 * Luật chọn giống combo nhóm (lib/group_combo.php): mỗi nhóm món min_pick..max_pick ly, cả combo tối đa total_cups ly.
 */

/** Dòng giỏ có thuộc combo (dòng combo hoặc ly nước của combo) không. */
function hcc_is_combo_row(array $row): bool
{
    return !empty($row['is_combo']) || !empty($row['combo_drink']);
}

/** Thêm 1 combo vào giỏ, trả về key dòng combo. Ném GroupOrderError nếu combo không còn bán. */
function hcc_add_combo(int $comboId): string
{
    $combo = hgc_combo($comboId);
    if (!$combo) {
        hyg_fail('combo_unavailable');
    }

    $key = 'c' . bin2hex(random_bytes(8));
    $price = (float)$combo['price'];

    $_SESSION['cart'][$key] = [
        'is_combo' => true,
        'combo_id' => (int)$combo['id'],
        'name' => localized_name($combo),
        'image' => (string)($combo['image'] ?? ''),
        'cup_size' => null,
        'base_price' => $price,
        'ice' => '',
        'sugar' => '',
        'toppings' => [],
        'unit_price' => $price,
        'qty' => 1,
        'total_cups' => (int)$combo['total_cups'],
        'lang' => current_lang(),
    ];

    return $key;
}

/**
 * Tình trạng từng combo trong giỏ, theo key dòng combo:
 * ['key','combo_id','name','image','price','total_cups','picked','full','complete','groups' => [.. + 'picked'],'drinks' => [key => row]]
 */
function hcc_state(?array $cart = null): array
{
    $cart = $cart ?? ($_SESSION['cart'] ?? []);
    $combos = [];

    foreach ($cart as $key => $row) {
        if (empty($row['is_combo'])) {
            continue;
        }

        $groups = [];
        try {
            foreach (hgc_groups((int)$row['combo_id']) as $groupId => $group) {
                $group['picked'] = 0;
                $groups[$groupId] = $group;
            }
        } catch (Throwable $e) {
            error_log('cart combo: ' . $e->getMessage());
        }

        $combos[(string)$key] = [
            'key' => (string)$key,
            'combo_id' => (int)$row['combo_id'],
            'name' => (string)$row['name'],
            'image' => (string)($row['image'] ?? ''),
            'price' => (float)$row['unit_price'],
            'total_cups' => (int)($row['total_cups'] ?? 0),
            'picked' => 0,
            'groups' => $groups,
            'drinks' => [],
        ];
    }

    foreach ($cart as $key => $row) {
        if (empty($row['combo_drink'])) {
            continue;
        }

        $parent = (string)($row['combo_parent'] ?? '');
        if (!isset($combos[$parent])) {
            continue;
        }

        $qty = (int)($row['qty'] ?? 0);
        $groupId = (int)($row['combo_group'] ?? 0);
        $combos[$parent]['picked'] += $qty;
        if (isset($combos[$parent]['groups'][$groupId])) {
            $combos[$parent]['groups'][$groupId]['picked'] += $qty;
        }
        $combos[$parent]['drinks'][(string)$key] = $row;
    }

    foreach ($combos as &$combo) {
        // Đủ = chọn đủ số ly của combo (total_cups); nếu các nhóm gộp lại không chứa nổi total_cups thì đủ khi các nhóm đã đầy.
        // Mức tối thiểu của từng nhóm không còn chặn: khi tổng đã đủ mọi ly đều bị khoá nên khách không thể bù thêm vào nhóm thiếu.
        $maxSum = array_sum(array_map(fn($g) => (int)$g['max'], $combo['groups']));
        $need = min((int)$combo['total_cups'], $maxSum);
        $complete = $combo['picked'] > 0 && $combo['picked'] <= $combo['total_cups'] && $combo['picked'] >= $need;
        foreach ($combo['groups'] as $group) {
            if ($group['picked'] > $group['max']) {
                $complete = false;
            }
        }
        $combo['complete'] = $complete;
        $combo['full'] = $combo['picked'] >= $combo['total_cups'];
    }
    unset($combo);

    return $combos;
}

/** Mọi combo trong giỏ đã chọn đủ nước theo luật (giỏ không có combo => true). */
function hcc_all_complete(?array $cart = null): bool
{
    foreach (hcc_state($cart) as $combo) {
        if (!$combo['complete']) {
            return false;
        }
    }

    return true;
}

/** Chọn 1 ly nước cho 1 nhóm món của 1 combo trong giỏ. Ném GroupOrderError nếu sai luật. */
function hcc_add_drink(array $data): void
{
    $comboKey = (string)($data['combo_key'] ?? '');
    $combo = hcc_state()[$comboKey] ?? null;
    if (!$combo) {
        hyg_fail('combo_unavailable');
    }

    $groupId = hyg_positive_int($data['combo_group'] ?? null);
    $productId = hyg_positive_int($data['product_id'] ?? null);
    $qty = hyg_positive_int($data['qty'] ?? 1);
    if ($qty > order_qty_max()) {
        hyg_fail('invalid_quantity');
    }

    $iceIndex = filter_var($data['ice_index'] ?? '', FILTER_VALIDATE_INT);
    $sugarIndex = filter_var($data['sugar_index'] ?? '', FILTER_VALIDATE_INT);
    if ($iceIndex === false || $sugarIndex === false) {
        hyg_fail('options_changed');
    }

    $size = $data['cup_size'] ?? '';
    $size = is_string($size) ? strtoupper(trim($size)) : '';

    $toppingIds = [];
    foreach ((array)($data['toppings'] ?? []) as $toppingId) {
        $toppingId = filter_var($toppingId, FILTER_VALIDATE_INT);
        if ($toppingId === false || $toppingId < 1) {
            hyg_fail('invalid_input');
        }
        $toppingIds[] = $toppingId;
    }
    $toppingIds = array_values(array_unique($toppingIds));
    if (count($toppingIds) > 50) {
        hyg_fail('invalid_input');
    }

    $item = hgc_groups($combo['combo_id'])[$groupId]['items'][$productId] ?? null;
    if (!$item) {
        hyg_fail('combo_drink_not_allowed');
    }

    $product = $item['product'];
    if (!product_has_sizes($product)) {
        $size = '';
    }

    $selection = [
        'kind' => 'combo_drink',
        'id' => $productId,
        'qty' => $qty,
        'cup_size' => $size,
        'ice_profile' => (string)$product['ice_profile'],
        'ice_index' => (int)$iceIndex,
        'sugar_profile' => (string)$product['sugar_profile'],
        'sugar_index' => (int)$sugarIndex,
        'ice_sig' => hyg_profile_signature('ice', (string)$product['ice_profile']),
        'sugar_sig' => hyg_profile_signature('sugar', (string)$product['sugar_profile']),
        'topping_ids' => $toppingIds,
        'combo_id' => $combo['combo_id'],
        'combo_group' => $groupId,
    ];
    $row = hgc_resolve_drink($selection);

    if ($combo['picked'] + $qty > $combo['total_cups']) {
        hyg_fail('combo_full');
    }
    $group = $combo['groups'][$groupId] ?? null;
    if (!$group || $group['picked'] + $qty > $group['max']) {
        hyg_fail('combo_group_full');
    }

    $row['combo_parent'] = $comboKey;
    $_SESSION['cart']['d' . bin2hex(random_bytes(8))] = $row;
}

/** Xoá 1 dòng khỏi giỏ; xoá dòng combo thì xoá luôn các ly nước đã chọn cho combo đó. */
function hcc_remove(string $key): void
{
    $row = $_SESSION['cart'][$key] ?? null;
    if ($row === null) {
        return;
    }

    if (!empty($row['is_combo'])) {
        foreach ($_SESSION['cart'] as $otherKey => $other) {
            if (!empty($other['combo_drink']) && (string)($other['combo_parent'] ?? '') === $key) {
                unset($_SESSION['cart'][$otherKey]);
            }
        }
    }

    unset($_SESSION['cart'][$key]);
}

/** Thông báo lỗi của luật combo theo ngôn ngữ hiện tại. */
function hcc_error_text(Throwable $e): string
{
    $text = hgc_t($e->getMessage());
    if ($text === $e->getMessage() && function_exists('gt')) {
        $text = gt($e->getMessage());
    }

    return $text;
}

function hcc_t(string $key, array $vars = []): string
{
    static $map = [
        'vi' => [
            'pick_drinks' => 'Chọn nước',
            'change_drinks' => 'Sửa nước đã chọn',
            'need_drinks' => 'Chọn đủ nước cho combo trước khi thanh toán.',
            'back_cart' => 'Quay lại giỏ hàng',
            'done' => 'Xong, về giỏ hàng',
            'combo_label' => 'Combo',
            'remove_combo' => 'Xoá combo',
        ],
        'en' => [
            'pick_drinks' => 'Pick drinks',
            'change_drinks' => 'Edit drinks',
            'need_drinks' => 'Pick enough drinks for the combo before checkout.',
            'back_cart' => 'Back to cart',
            'done' => 'Done, back to cart',
            'combo_label' => 'Combo',
            'remove_combo' => 'Remove combo',
        ],
        'zh' => [
            'pick_drinks' => '選擇飲品',
            'change_drinks' => '修改飲品',
            'need_drinks' => '結帳前請先為套餐選足飲品。',
            'back_cart' => '返回購物車',
            'done' => '完成，回購物車',
            'combo_label' => '套餐',
            'remove_combo' => '刪除套餐',
        ],
    ];

    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    $text = $map[$lang][$key] ?? $map['vi'][$key] ?? $key;

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }

    return $text;
}
