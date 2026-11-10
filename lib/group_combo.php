<?php
/**
 * Combo nhóm (group_rooms.order_type = 'combo').
 *
 * Giỏ nhóm combo dùng lại bảng group_items, nên phần tính tiền / chốt món / thanh toán của đơn nhóm chạy như cũ:
 *   - Dòng combo   selection kind 'combo'       : của chủ nhóm, giá = giá combo, qty luôn 1.
 *   - Dòng nước    selection kind 'combo_drink' : thành viên chọn theo cấu hình combo, giá 0,
 *                  gắn với dòng combo qua combo_item (group_items.id) và nhóm món combo_group (combo_groups.id).
 * Luật chọn (admin/combos.php): mỗi nhóm món chọn từ min_pick đến max_pick ly, cả combo không quá total_cups ly.
 */

/** Thêm cột group_rooms.order_type ('drink' | 'combo') nếu DB chưa có. Gọi từ cuối hyg_schema(). */
function hgc_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (setting('group_combo_schema', '') === '1') {
        return;
    }

    if (!column_exists('group_rooms', 'order_type')) {
        db()->exec("ALTER TABLE group_rooms ADD COLUMN order_type VARCHAR(16) NOT NULL DEFAULT 'drink' AFTER status");
    }

    set_setting('group_combo_schema', '1');
}

function hgc_is_combo(array $room): bool
{
    return ($room['order_type'] ?? 'drink') === 'combo';
}

/** Combo đang bán, hoặc null. */
function hgc_combo(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    return hyg_catalog_one('SELECT * FROM combos WHERE id=? AND active=1', [$id]);
}

/** Các size được chọn của 1 món trong combo: món không size => [''], cấu hình trống => mọi size của món. */
function hgc_allowed_sizes(array $product, string $sizes): array
{
    if (!product_has_sizes($product)) {
        return [''];
    }

    $all = array_keys(product_size_options($product));
    $wanted = array_map('trim', explode(',', strtoupper($sizes)));
    $picked = array_values(array_intersect($all, $wanted));

    return $picked ?: $all;
}

/**
 * Nhóm món của combo kèm các món đang bán:
 * [group_id => ['id','name','min','max','items' => [product_id => ['product' => row, 'sizes' => ['L','XL']]]]]
 */
function hgc_groups(int $comboId): array
{
    static $cache = [];
    if (isset($cache[$comboId])) {
        return $cache[$comboId];
    }

    $groups = [];
    $groupRows = hyg_all('SELECT id, name_vi, name_en, name_zh, min_pick, max_pick FROM combo_groups WHERE combo_id=? ORDER BY sort_order, id', [$comboId]);
    foreach ($groupRows as $g) {
        $groups[(int)$g['id']] = [
            'id' => (int)$g['id'],
            'name' => localized_name($g),
            'min' => (int)$g['min_pick'],
            'max' => (int)$g['max_pick'],
            'items' => [],
        ];
    }

    $itemRows = hyg_all(
        'SELECT ci.group_id AS combo_group_id, ci.cup_size AS combo_sizes, p.*
         FROM combo_items ci
         JOIN products p ON p.id = ci.product_id
         JOIN categories c ON c.id = p.category_id
         WHERE ci.combo_id=? AND p.active=1 AND c.active=1
         ORDER BY p.sort_order, p.id',
        [$comboId]
    );
    foreach ($itemRows as $row) {
        $groupId = (int)$row['combo_group_id'];
        if (!isset($groups[$groupId])) {
            continue;
        }
        $groups[$groupId]['items'][(int)$row['id']] = [
            'product' => $row,
            'sizes' => hgc_allowed_sizes($row, (string)$row['combo_sizes']),
        ];
    }

    // Combo "Tất cả món": nhóm chưa gán món nào thì được chọn mọi món đang bán.
    $combo = hgc_combo($comboId);
    if ($combo && !empty($combo['all_products'])) {
        $allProducts = null;
        foreach ($groups as $groupId => $g) {
            if ($g['items']) {
                continue;
            }
            if ($allProducts === null) {
                $allProducts = hyg_all('SELECT p.* FROM products p JOIN categories c ON c.id = p.category_id WHERE p.active=1 AND c.active=1 ORDER BY p.sort_order, p.id');
            }
            foreach ($allProducts as $product) {
                $groups[$groupId]['items'][(int)$product['id']] = [
                    'product' => $product,
                    'sizes' => hgc_allowed_sizes($product, ''),
                ];
            }
        }
    }

    return $cache[$comboId] = $groups;
}

/** Dòng combo trong giỏ nhóm (gọi từ hyg_resolve). */
function hgc_resolve_combo(array $s): array
{
    $combo = hgc_combo((int)($s['id'] ?? 0));
    if (!$combo) {
        hyg_fail('combo_unavailable');
    }

    $price = (float)$combo['price'];

    return [
        'combo_id' => (int)$combo['id'],
        'is_combo' => true,
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
        // Luật của MỌI nhóm món (kể cả nhóm chưa ai chọn). Lúc chốt món dòng này được ghi vào snapshot_json,
        // occ_save đọc từ đây để ghi đủ các nhóm vào order_combo_groups.
        'combo_groups_rules' => hgc_group_rules((int)$combo['id']),
        'lang' => current_lang(),
    ];
}

/** Luật các nhóm món của combo theo thứ tự hiển thị: [['id','name','min','max'], ...]. */
function hgc_group_rules(int $comboId): array
{
    $rules = [];
    foreach (hgc_groups($comboId) as $groupId => $group) {
        $rules[] = [
            'id' => (int)$groupId,
            'name' => (string)$group['name'],
            'min' => (int)$group['min'],
            'max' => (int)$group['max'],
        ];
    }

    return $rules;
}

/** Ly nước chọn theo combo (gọi từ hyg_resolve): kiểm tra món + size thuộc nhóm của combo, giá 0, không topping. */
function hgc_resolve_drink(array $s): array
{
    $comboId = (int)($s['combo_id'] ?? 0);
    if (!hgc_combo($comboId)) {
        hyg_fail('combo_unavailable');
    }

    $groupId = (int)($s['combo_group'] ?? 0);
    $groups = hgc_groups($comboId);
    $item = $groups[$groupId]['items'][(int)($s['id'] ?? 0)] ?? null;
    // Admin vừa xoá nhóm / món / size khỏi combo sau khi khách chọn: báo rõ để khách bỏ ly và chọn lại.
    if (!$item || !in_array((string)($s['cup_size'] ?? ''), $item['sizes'], true)) {
        hyg_fail('combo_drink_changed');
    }

    $drink = $s;
    $drink['kind'] = 'drink';
    $row = hyg_resolve($drink);

    // Ly nước nằm trong giá combo (0đ); topping thêm vào thì tính tiền theo giá topping.
    $toppingPrice = 0.0;
    foreach ($row['toppings'] as $topping) {
        $toppingPrice += (float)$topping['price'];
    }
    $row['base_price'] = 0.0;
    $row['unit_price'] = $toppingPrice;
    $row['combo_drink'] = true;
    $row['combo_item'] = (int)($s['combo_item'] ?? 0);
    $row['combo_group'] = $groupId;
    $row['combo_group_name'] = $groups[$groupId]['name'];
    // Luật nhóm món tại thời điểm resolve. Lúc chủ nhóm chốt món, dòng này được ghi vào snapshot_json
    // và từ đó đóng băng: occ_save đọc luật từ đây, không đọc lại cấu hình combo của admin.
    $row['combo_group_rules'] = [
        'name' => (string)$groups[$groupId]['name'],
        'min' => (int)$groups[$groupId]['min'],
        'max' => (int)$groups[$groupId]['max'],
    ];

    return $row;
}

/**
 * Tình trạng các combo trong giỏ nhóm, theo id dòng combo (group_items.id):
 * ['item_id','combo_id','name','price','total_cups','picked','full','complete','groups' => [.. + 'picked'],'drinks' => [rows]]
 */
function hgc_state(array $cart): array
{
    $combos = [];
    foreach ($cart as $row) {
        if (empty($row['is_combo'])) {
            continue;
        }

        $groups = [];
        foreach (hgc_groups((int)$row['combo_id']) as $groupId => $g) {
            $g['picked'] = 0;
            $groups[$groupId] = $g;
        }

        $itemId = (int)$row['_item_id'];
        $combos[$itemId] = [
            'item_id' => $itemId,
            'item_version' => (int)$row['_item_version'],
            'combo_id' => (int)$row['combo_id'],
            'name' => (string)$row['name'],
            'image' => (string)($row['image'] ?? ''),
            'price' => (float)$row['unit_price'],
            'total_cups' => (int)($row['total_cups'] ?? 0),
            'picked' => 0,
            'invalid' => !empty($row['_invalid']),
            'groups' => $groups,
            'drinks' => [],
            // Ly nước không còn hợp lệ (admin đã đổi combo): không tính vào số ly, khách phải bỏ đi chọn lại.
            'stale' => [],
        ];
    }

    foreach ($cart as $row) {
        $selection = $row['_selection'] ?? [];
        if (($selection['kind'] ?? '') !== 'combo_drink') {
            continue;
        }

        $parent = (int)($selection['combo_item'] ?? 0);
        if (!isset($combos[$parent])) {
            continue;
        }

        if (!empty($row['_invalid'])) {
            $combos[$parent]['stale'][] = $row;
            continue;
        }

        $qty = (int)($row['qty'] ?? 0);
        $groupId = (int)($selection['combo_group'] ?? 0);
        $combos[$parent]['picked'] += $qty;
        if (isset($combos[$parent]['groups'][$groupId])) {
            $combos[$parent]['groups'][$groupId]['picked'] += $qty;
        }
        $combos[$parent]['drinks'][] = $row;
    }

    foreach ($combos as &$combo) {
        // Đủ = chọn đủ số ly của combo (total_cups); nếu các nhóm gộp lại không chứa nổi total_cups thì đủ khi các nhóm đã đầy.
        // Mức tối thiểu của từng nhóm không còn chặn: khi tổng đã đủ mọi ly đều bị khoá nên khách không thể bù thêm vào nhóm thiếu.
        $maxSum = array_sum(array_map(fn($g) => (int)$g['max'], $combo['groups']));
        $need = min((int)$combo['total_cups'], $maxSum);
        $complete = !$combo['invalid'] && !$combo['stale'] && $combo['picked'] > 0 && $combo['picked'] <= $combo['total_cups'] && $combo['picked'] >= $need;
        foreach ($combo['groups'] as $g) {
            if ($g['picked'] > $g['max']) {
                $complete = false;
            }
        }
        $combo['complete'] = $complete;
        $combo['full'] = $combo['picked'] >= $combo['total_cups'];
    }
    unset($combo);

    return $combos;
}

/** Giỏ nhóm có ít nhất 1 combo không. */
function hgc_has_combo(array $cart): bool
{
    foreach ($cart as $row) {
        if (!empty($row['is_combo'])) {
            return true;
        }
    }

    return false;
}

/**
 * Chốt món đơn nhóm khi giỏ có combo: mọi combo phải chọn đủ theo luật, không còn ly nước combo mồ côi.
 * Nước lẻ (kind 'drink' / 'topping') để tự do, đơn nhóm chỉ cần có ít nhất 1 dòng.
 */
function hgc_validate_lock(array $cart): void
{
    $state = hgc_state($cart);

    foreach ($state as $combo) {
        if (!$combo['complete']) {
            hyg_fail('combo_incomplete');
        }
    }

    foreach ($cart as $row) {
        $selection = $row['_selection'] ?? [];
        if (($selection['kind'] ?? '') === 'combo_drink' && !isset($state[(int)($selection['combo_item'] ?? 0)])) {
            hyg_fail('combo_incomplete');
        }
    }
}

function hgc_lock_ready(array $cart): bool
{
    try {
        hgc_validate_lock($cart);
    } catch (GroupOrderError $e) {
        return false;
    }

    return true;
}

/** Chủ nhóm bấm "Đặt ngay" ở trang chọn combo: thêm 1 combo vào giỏ nhóm. */
function hgc_add_combo(string $public, array $data): void
{
    $comboId = hyg_positive_int($data['combo_id'] ?? null);
    $request = (string)($data['request_key'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D', $request)) {
        hyg_fail('expired_form');
    }

    hyg_tx(function () use ($public, $comboId, $request) {
        $r = hyg_room($public, true);
        hyg_require_host($r);
        hyg_open($r);
        $m = hyg_member($r);
        if (hyg_one('SELECT id FROM group_items WHERE room_id=? AND member_id=? AND request_key=?', [$r['id'], $m['id'], $request])) {
            return;
        }

        $count = hyg_one('SELECT COUNT(*) n FROM group_items WHERE room_id=?', [$r['id']]);
        if ((int)$count['n'] >= 200) {
            hyg_fail('room_full');
        }

        $selection = ['kind' => 'combo', 'id' => $comboId, 'qty' => 1];
        $resolved = hgc_resolve_combo($selection);
        hyg_insert_item((int)$r['id'], (int)$m['id'], $selection, $resolved, $request);
        hyg_touch((int)$r['id']);
    });
}

/** Thành viên chọn 1 ly nước cho 1 nhóm món của 1 combo trong giỏ nhóm. */
function hgc_add_drink(string $public, array $data): void
{
    $parentId = hyg_positive_int($data['combo_item'] ?? null);
    $groupId = hyg_positive_int($data['combo_group'] ?? null);
    $productId = hyg_positive_int($data['product_id'] ?? null);
    $qty = hyg_positive_int($data['qty'] ?? 1);
    if ($qty > order_qty_max()) {
        hyg_fail('invalid_quantity');
    }

    $request = (string)($data['request_key'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D', $request)) {
        hyg_fail('expired_form');
    }

    $iceIndex = filter_var($data['ice_index'] ?? '', FILTER_VALIDATE_INT);
    $sugarIndex = filter_var($data['sugar_index'] ?? '', FILTER_VALIDATE_INT);
    if ($iceIndex === false || $sugarIndex === false) {
        hyg_fail('options_changed');
    }

    $size = $data['cup_size'] ?? '';
    $size = is_string($size) ? strtoupper(trim($size)) : '';

    // Topping khách tích thêm (toppings[] = id): chỉ nhận số nguyên dương, hyg_resolve kiểm tra topping còn bán.
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

    hyg_tx(function () use ($public, $parentId, $groupId, $productId, $qty, $request, $iceIndex, $sugarIndex, $size, $toppingIds) {
        $r = hyg_room($public, true);
        $m = hyg_member($r);
        hyg_open($r);
        if (hyg_one('SELECT id FROM group_items WHERE room_id=? AND member_id=? AND request_key=?', [$r['id'], $m['id'], $request])) {
            return;
        }

        $parent = hyg_one('SELECT * FROM group_items WHERE id=? AND room_id=?', [$parentId, $r['id']]);
        $parentSelection = $parent ? json_decode((string)$parent['selection_json'], true) : null;
        if (!is_array($parentSelection) || ($parentSelection['kind'] ?? '') !== 'combo') {
            hyg_fail('combo_unavailable');
        }

        $comboId = (int)$parentSelection['id'];
        $item = hgc_groups($comboId)[$groupId]['items'][$productId] ?? null;
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
            'combo_id' => $comboId,
            'combo_item' => $parentId,
            'combo_group' => $groupId,
        ];
        $resolved = hyg_resolve($selection);

        // Luật số ly: tính trên giỏ hiện tại (đang giữ khoá phòng nên không bị chọn chồng).
        $combo = hgc_state(hyg_cart($r, false))[$parentId] ?? null;
        if (!$combo) {
            hyg_fail('combo_unavailable');
        }
        if ($combo['picked'] + $qty > $combo['total_cups']) {
            hyg_fail('combo_full');
        }
        $group = $combo['groups'][$groupId] ?? null;
        if (!$group || $group['picked'] + $qty > $group['max']) {
            hyg_fail('combo_group_full');
        }

        $count = hyg_one('SELECT COUNT(*) n FROM group_items WHERE room_id=?', [$r['id']]);
        if ((int)$count['n'] >= 200) {
            hyg_fail('room_full');
        }

        hyg_insert_item((int)$r['id'], (int)$m['id'], $selection, $resolved, $request);
        hyg_touch((int)$r['id']);
    });
}

/** Xoá 1 combo khỏi giỏ nhóm thì xoá luôn các ly nước đã chọn cho combo đó. */
function hgc_delete_children(int $roomId, int $comboItemId): void
{
    hyg_exec(
        "DELETE FROM group_items WHERE room_id=? AND JSON_UNQUOTE(JSON_EXTRACT(selection_json, '$.kind'))='combo_drink' AND CAST(JSON_EXTRACT(selection_json, '$.combo_item') AS UNSIGNED)=?",
        [$roomId, $comboItemId]
    );
}

/** "Chọn 1–3 ly" / "Chọn 2 ly" / "Tối đa 10 ly, không bắt buộc". */
function hgc_group_rule(array $group): string
{
    if ($group['min'] <= 0) {
        return hgc_t('rule_optional', ['max' => $group['max']]);
    }
    if ($group['min'] === $group['max']) {
        return hgc_t('rule_exact', ['n' => $group['max']]);
    }

    return hgc_t('rule_range', ['min' => $group['min'], 'max' => $group['max']]);
}

function hgc_t(string $key, array $vars = []): string
{
    static $map = [
        'vi' => [
            'choose_combo' => 'Chọn combo đồ uống',
            'pick_drinks' => 'Chọn nước trong combo',
            'wait_host' => 'Chờ chủ nhóm chọn combo, sau đó mọi người vào chọn nước.',
            'choose_hint' => 'Bấm "Đặt ngay" ở combo muốn thêm vào giỏ nhóm.',
            'badge' => 'Combo',
            'included' => 'Trong combo',
            'combo_of' => 'Combo {name} · {group}',
            'progress' => 'Đã chọn {picked}/{total} ly',
            'group_progress' => 'Đã chọn {picked}/{max}',
            'picked_label' => 'Đã chọn',
            'combos_count' => '{n} combo',
            'rule_exact' => 'Chọn {n} ly',
            'rule_range' => 'Chọn {min}–{max} ly',
            'rule_optional' => 'Tối đa {max} ly, không bắt buộc',
            'page_title' => 'Chọn nước trong combo',
            'back_room' => 'Quay lại giỏ nhóm',
            'add_to_combo' => 'Thêm vào combo',
            'qty_max' => 'Chỉ còn chọn được tối đa {max} ly.',
            'qty_min' => 'Số ly tối thiểu là 1.',
            'size' => 'Size',
            'empty_combos' => 'Giỏ nhóm chưa có combo nào.',
            'group_full_label' => 'Đã đủ',
            'combo_full_label' => 'Combo đã đủ ly',
            'no_drinks' => 'Nhóm này chưa có món đang bán.',
            'lock_hint' => 'Chọn đủ nước cho mọi combo thì mới chốt món được.',
            'combo_added' => 'Đã thêm combo vào giỏ nhóm.',
            'pick' => 'Chọn',
            'topping_note' => '(tính thêm tiền, không bắt buộc)',
            'topping_placeholder' => 'Chọn topping',
            'remove_drink' => 'Bỏ ly này để chọn lại',
            'drink_removed' => 'Đã bỏ ly nước, bạn có thể chọn lại.',
            'remove_combo' => 'Xoá combo',
            'remove_combo_confirm' => 'Xoá combo này cùng các ly nước đã chọn trong combo?',
            'combo_removed' => 'Đã xoá combo khỏi giỏ nhóm.',
            'drink_added' => 'Đã thêm nước vào combo.',
            'combo_complete' => 'Đã chọn đủ nước trong combo',
            'bar_done' => 'Đã chọn đủ nước',
            'bar_done_sub_member' => '{n} ly · Chờ trưởng nhóm chốt món',
            'bar_cta_host' => 'Chốt món',
            'bar_cta_member' => 'Xem đơn nhóm',
            'bar_todo' => '{name} chưa đủ nước · {cups}',
            'bar_missing' => 'Thiếu {n} ly',
            'bar_cta_todo' => 'Chọn tiếp combo {name}',
            'combo_unavailable' => 'Combo này không còn bán.',
            'combo_drink_not_allowed' => 'Món hoặc size này không thuộc nhóm món của combo.',
            'combo_drink_changed' => 'Combo vừa được cập nhật, ly này không còn trong combo. Vui lòng bỏ ly này và chọn lại.',
            'combo_changed_notice' => 'Combo vừa được cập nhật. Các ly dưới đây không còn hợp lệ, hãy bấm ✕ bỏ ly rồi chọn lại.',
            'combo_full' => 'Combo đã đủ số ly tối đa.',
            'combo_group_full' => 'Nhóm món này đã chọn đủ số ly tối đa.',
            'combo_need' => 'Chủ nhóm cần chọn ít nhất 1 combo.',
            'combo_incomplete' => 'Còn combo chưa chọn đủ nước theo yêu cầu.',
            'combo_room_only' => 'Nhóm combo chỉ chọn nước theo combo.',
        ],
        'en' => [
            'choose_combo' => 'Choose a drink combo',
            'pick_drinks' => 'Pick drinks for the combo',
            'wait_host' => 'Waiting for the host to choose a combo, then everyone can pick drinks.',
            'choose_hint' => 'Press "Order now" on a combo to add it to the group cart.',
            'badge' => 'Combo',
            'included' => 'In combo',
            'combo_of' => 'Combo {name} · {group}',
            'progress' => '{picked}/{total} cups picked',
            'group_progress' => 'Picked {picked}/{max}',
            'picked_label' => 'Picked',
            'combos_count' => '{n} combos',
            'rule_exact' => 'Pick {n} cups',
            'rule_range' => 'Pick {min}–{max} cups',
            'rule_optional' => 'Up to {max} cups, optional',
            'page_title' => 'Pick drinks for the combo',
            'back_room' => 'Back to group cart',
            'add_to_combo' => 'Add to combo',
            'qty_max' => 'You can only add up to {max} more cups.',
            'qty_min' => 'The minimum is 1 cup.',
            'size' => 'Size',
            'empty_combos' => 'The group cart has no combo yet.',
            'group_full_label' => 'Full',
            'combo_full_label' => 'Combo is full',
            'no_drinks' => 'No drinks available in this group.',
            'lock_hint' => 'Pick enough drinks for every combo before closing the order.',
            'combo_added' => 'Combo added to the group cart.',
            'pick' => 'Pick',
            'topping_note' => '(extra charge, optional)',
            'topping_placeholder' => 'Choose toppings',
            'remove_drink' => 'Remove this drink to pick again',
            'drink_removed' => 'Drink removed, you can pick again.',
            'remove_combo' => 'Remove combo',
            'remove_combo_confirm' => 'Remove this combo and all drinks picked for it?',
            'combo_removed' => 'Combo removed from the group cart.',
            'drink_added' => 'Drink added to the combo.',
            'combo_complete' => 'All drinks in the combo are picked',
            'bar_done' => 'All combos have their drinks',
            'bar_done_sub_member' => '{n} drinks · Waiting for the host to close the order',
            'bar_cta_host' => 'Close ordering',
            'bar_cta_member' => 'View the group order',
            'bar_todo' => '{name} needs more drinks · {cups}',
            'bar_missing' => '{n} short',
            'bar_cta_todo' => 'Continue with combo {name}',
            'combo_unavailable' => 'This combo is no longer available.',
            'combo_drink_not_allowed' => 'This drink or size is not part of the combo group.',
            'combo_drink_changed' => 'The combo was just updated and this drink is no longer part of it. Please remove it and pick again.',
            'combo_changed_notice' => 'The combo was just updated. The drinks below are no longer valid: press ✕ to remove them, then pick again.',
            'combo_full' => 'The combo already has its maximum cups.',
            'combo_group_full' => 'This drink group already has its maximum cups.',
            'combo_need' => 'The host needs to choose at least one combo.',
            'combo_incomplete' => 'A combo still needs more drinks.',
            'combo_room_only' => 'In a group combo, drinks are picked through the combo.',
        ],
        'zh' => [
            'choose_combo' => '選擇飲品套餐',
            'pick_drinks' => '為套餐選飲品',
            'wait_host' => '等待團主選擇套餐，之後大家即可選飲品。',
            'choose_hint' => '在想加入團購購物車的套餐上按「立即訂購」。',
            'badge' => '套餐',
            'included' => '套餐內',
            'combo_of' => '套餐 {name} · {group}',
            'progress' => '已選 {picked}/{total} 杯',
            'group_progress' => '已選 {picked}/{max}',
            'picked_label' => '已選',
            'combos_count' => '{n} 個套餐',
            'rule_exact' => '選 {n} 杯',
            'rule_range' => '選 {min}–{max} 杯',
            'rule_optional' => '最多 {max} 杯，可不選',
            'page_title' => '為套餐選飲品',
            'back_room' => '返回團購購物車',
            'add_to_combo' => '加入套餐',
            'qty_max' => '最多只能再選 {max} 杯。',
            'qty_min' => '至少需選 1 杯。',
            'size' => '尺寸',
            'empty_combos' => '團購購物車還沒有套餐。',
            'group_full_label' => '已滿',
            'combo_full_label' => '套餐已滿',
            'no_drinks' => '此組目前沒有可選飲品。',
            'lock_hint' => '每個套餐都選足飲品後才能結單。',
            'combo_added' => '已將套餐加入團購購物車。',
            'pick' => '選擇',
            'topping_note' => '（需加價，可不選）',
            'topping_placeholder' => '選擇配料',
            'remove_drink' => '移除此飲品以重新選擇',
            'drink_removed' => '已移除飲品，可重新選擇。',
            'remove_combo' => '刪除套餐',
            'remove_combo_confirm' => '刪除此套餐及其已選飲品？',
            'combo_removed' => '已從團購購物車刪除套餐。',
            'drink_added' => '已將飲品加入套餐。',
            'combo_complete' => '套餐飲品已選足',
            'bar_done' => '套餐飲品已選足',
            'bar_done_sub_member' => '{n} 杯 · 等待團主結單',
            'bar_cta_host' => '截止收單',
            'bar_cta_member' => '查看團購訂單',
            'bar_todo' => '{name} 飲品未選足 · {cups}',
            'bar_missing' => '還差 {n} 杯',
            'bar_cta_todo' => '繼續選擇套餐 {name}',
            'combo_unavailable' => '此套餐已停售。',
            'combo_drink_not_allowed' => '此飲品或尺寸不屬於套餐的飲品組。',
            'combo_drink_changed' => '套餐剛更新，此飲品已不在套餐內。請移除後重新選擇。',
            'combo_changed_notice' => '套餐剛更新，以下飲品已失效，請按 ✕ 移除後重新選擇。',
            'combo_full' => '套餐杯數已達上限。',
            'combo_group_full' => '此飲品組杯數已達上限。',
            'combo_need' => '團主需至少選擇 1 個套餐。',
            'combo_incomplete' => '仍有套餐未選足飲品。',
            'combo_room_only' => '套餐團購只能透過套餐選飲品。',
        ],
    ];

    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    $text = $map[$lang][$key] ?? $map['vi'][$key] ?? $key;

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }

    return $text;
}
