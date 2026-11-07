<?php
/**
 * Combo trong đơn hàng đã đặt (giỏ thường và đơn nhóm).
 *
 * Mô hình dữ liệu (snapshot, không phụ thuộc combo gốc còn tồn tại):
 *   order_combos        1 dòng / combo trong đơn: combo_id (SET NULL khi xoá combo), combo_name, combo_price, total_cups.
 *   order_combo_groups  1 dòng / nhóm món của combo trong đơn: tên nhóm, min/max lúc đặt, số ly đã chọn.
 *   order_items         vẫn giữ MỌI dòng như trước (dòng combo + từng ly nước), để hệ khác đang đọc order_items
 *                       (Laravel / partner-web) không bị ảnh hưởng. Thêm cột nối:
 *                         order_combo_id        -> order_combos.id
 *                         order_combo_group_id  -> order_combo_groups.id (ly nước)
 *                         combo_role            'combo' (dòng combo) | 'drink' (ly nước trong combo) | NULL (dòng thường)
 *                         combo_group_name      tên nhóm món lúc đặt (ly nước)
 *
 * Mọi màn hình xem đơn đọc qua order_lines_flat(); mọi lần ghi đơn có combo đi qua occ_save().
 * Đơn cũ (không có cột nối) vẫn hiện như trước.
 */

/** Tạo bảng / cột combo của đơn nếu DB chưa có. PHẢI gọi ngoài transaction (DDL tự commit). */
function occ_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (setting('order_combo_schema', '') === '1') {
        $done = true;
        return;
    }

    $pdo = db();
    if ($pdo->inTransaction()) {
        // Đang trong transaction: để lần gọi ngoài transaction làm, đơn vẫn lưu như cũ.
        return;
    }

    try {
        $lock = $pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(),':order-combo-schema'),10)")->fetchColumn();
        if ((int)$lock !== 1) {
            return;
        }

        try {
            // Có thể request khác đã làm xong trong lúc chờ khoá.
            $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
            $st->execute(['order_combo_schema']);
            if ((string)$st->fetchColumn() === '1') {
                $done = true;
                return;
            }

            occ_create_tables($pdo);

            $changes = [
                ['order_items', 'order_combo_id', 'BIGINT UNSIGNED NULL DEFAULT NULL'],
                ['order_items', 'order_combo_group_id', 'BIGINT UNSIGNED NULL DEFAULT NULL'],
                ['order_items', 'combo_role', 'VARCHAR(8) NULL DEFAULT NULL'],
                ['order_items', 'combo_group_name', 'VARCHAR(120) NULL DEFAULT NULL'],
            ];
            foreach ($changes as [$table, $col, $def]) {
                if (!column_exists($table, $col)) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                }
            }

            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            $st->execute(['order_items', 'idx_order_items_combo']);
            if (!(int)$st->fetchColumn()) {
                $pdo->exec('ALTER TABLE order_items ADD KEY idx_order_items_combo (order_combo_id)');
            }

            set_setting('order_combo_schema', '1');
            $done = true;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':order-combo-schema'))");
        }
    } catch (Throwable $e) {
        // Lỗi migration không được làm hỏng đặt hàng: đơn vẫn lưu như cũ, lần sau thử lại.
        error_log('order combo schema: ' . $e->getMessage());
    }
}

function occ_create_tables(PDO $pdo): void
{
    // Cấu trúc order_combos khớp bảng đã có sẵn trên DB (combo_name, combo_price, total_cups).
    $combos = 'CREATE TABLE IF NOT EXISTS order_combos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        combo_id INT UNSIGNED NULL DEFAULT NULL,
        combo_name VARCHAR(160) NOT NULL,
        combo_price DECIMAL(10,2) NOT NULL,
        total_cups INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY fk_order_combo_order (order_id),
        KEY fk_order_combo_combo (combo_id)';
    $groups = 'CREATE TABLE IF NOT EXISTS order_combo_groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_combo_id BIGINT UNSIGNED NOT NULL,
        combo_group_id INT UNSIGNED NULL DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        min_pick INT NOT NULL DEFAULT 0,
        max_pick INT NOT NULL DEFAULT 0,
        picked INT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_order_combo_groups_combo (order_combo_id)';
    $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    $fkOrder = ', CONSTRAINT fk_order_combo_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE';
    $fkCombo = ', CONSTRAINT fk_order_combo_combo FOREIGN KEY (combo_id) REFERENCES combos (id) ON DELETE SET NULL';

    // Thử đủ khoá ngoại trước; thiếu bảng tham chiếu thì bỏ dần khoá ngoại, bảng vẫn dùng được.
    foreach ([$fkOrder . $fkCombo, $fkOrder, ''] as $fk) {
        try {
            $pdo->exec($combos . $fk . $tail);
            break;
        } catch (Throwable $e) {
            error_log('order combo schema (order_combos): ' . $e->getMessage());
        }
    }

    try {
        $pdo->exec($groups . ', CONSTRAINT fk_order_combo_group_combo FOREIGN KEY (order_combo_id) REFERENCES order_combos (id) ON DELETE CASCADE' . $tail);
    } catch (Throwable $e) {
        error_log('order combo schema (order_combo_groups): ' . $e->getMessage());
        $pdo->exec($groups . $tail);
    }
}

/**
 * Ghi chi tiết combo của đơn, gọi NGAY SAU khi đã ghi order_items của đơn (trong cùng transaction).
 *
 * @param array $cart    giỏ đã dùng để ghi order_items (giỏ thường hoặc giỏ nhóm)
 * @param array $coupon  kết quả giảm giá (giữ cho đồng bộ chữ ký với lúc ghi order_items)
 * @param array $itemIds key dòng giỏ => id dòng order_items vừa ghi
 *
 * Chạy trong SAVEPOINT: lỗi bất kỳ thì bỏ phần combo mới, đơn vẫn lưu như trước (order_items đã đủ dữ liệu).
 */
function occ_save(int $orderId, array $cart, array $coupon, array $itemIds): void
{
    $hasCombo = false;
    foreach ($cart as $row) {
        if (!empty($row['is_combo'])) {
            $hasCombo = true;
            break;
        }
    }
    if (!$hasCombo) {
        return;
    }

    $pdo = db();
    if (!$pdo->inTransaction() || !column_exists('order_items', 'order_combo_id') || !occ_tables_exist()) {
        return;
    }

    try {
        $pdo->exec('SAVEPOINT occ_save');

        $insertCombo = $pdo->prepare('INSERT INTO order_combos (order_id, combo_id, combo_name, combo_price, total_cups) VALUES (?,?,?,?,?)');
        // combo_id có khoá ngoại về combos: combo đã bị xoá sau khi đặt thì ghi NULL.
        $comboExists = $pdo->prepare('SELECT id FROM combos WHERE id=?');
        $markCombo = $pdo->prepare("UPDATE order_items SET order_combo_id=?, combo_role='combo' WHERE id=? AND order_id=?");
        $comboIds = [];
        // Danh sách nhóm món của từng combo trong đơn: [key dòng combo => [group_id => info]].
        $groupsByCombo = [];

        foreach ($cart as $key => $row) {
            if (empty($row['is_combo']) || !isset($itemIds[$key])) {
                continue;
            }

            $groupsByCombo[(string)$key] = occ_combo_group_list($row);

            $qty = max(1, (int)($row['qty'] ?? 1));
            $comboId = (int)($row['combo_id'] ?? 0);
            $comboExists->execute([$comboId]);
            $insertCombo->execute([
                $orderId,
                ($comboId && $comboExists->fetchColumn()) ? $comboId : null,
                (string)$row['name'],
                (float)$row['unit_price'] * $qty,
                (int)($row['total_cups'] ?? 0),
            ]);
            $comboIds[(string)$key] = (int)$pdo->lastInsertId();
            $markCombo->execute([$comboIds[(string)$key], (int)$itemIds[$key], $orderId]);
        }

        // Cộng ly nước vào nhóm món của combo cha.
        foreach ($cart as $key => $row) {
            if (empty($row['combo_drink']) || !isset($itemIds[$key])) {
                continue;
            }

            $parent = isset($row['combo_parent']) ? (string)$row['combo_parent'] : ('g' . (int)($row['combo_item'] ?? 0));
            if (!isset($comboIds[$parent])) {
                continue;
            }

            $groupId = (int)($row['combo_group'] ?? 0);
            if (!isset($groupsByCombo[$parent][$groupId])) {
                // Nhóm không có trong danh sách của dòng combo: dùng luật đóng băng trên chính dòng ly.
                $rules = is_array($row['combo_group_rules'] ?? null) ? $row['combo_group_rules'] : [];
                $groupsByCombo[$parent][$groupId] = [
                    'name' => (string)($rules['name'] ?? ($row['combo_group_name'] ?? '')),
                    'min' => (int)($rules['min'] ?? 0),
                    'max' => isset($rules['max']) ? (int)$rules['max'] : null,
                    'picked' => 0,
                    'items' => [],
                ];
            }

            $groupsByCombo[$parent][$groupId]['picked'] += max(0, (int)($row['qty'] ?? 0));
            $groupsByCombo[$parent][$groupId]['items'][] = (int)$itemIds[$key];
        }

        $insertGroup = $pdo->prepare('INSERT INTO order_combo_groups (order_combo_id, combo_group_id, name, min_pick, max_pick, picked, sort_order) VALUES (?,?,?,?,?,?,?)');
        $markDrink = $pdo->prepare("UPDATE order_items SET order_combo_id=?, order_combo_group_id=?, combo_role='drink', combo_group_name=? WHERE id=? AND order_id=?");

        // Ghi MỌI nhóm món của combo, kể cả nhóm 0 ly (picked = 0).
        foreach ($groupsByCombo as $parent => $groups) {
            $sort = 0;
            foreach ($groups as $groupId => $info) {
                $insertGroup->execute([
                    $comboIds[$parent],
                    $groupId ?: null,
                    $info['name'],
                    $info['min'],
                    // Nhóm không còn luật (admin đã xoá, snapshot cũ): max = số ly đã chọn.
                    $info['max'] ?? $info['picked'],
                    $info['picked'],
                    $sort++,
                ]);
                $orderGroupId = (int)$pdo->lastInsertId();

                foreach ($info['items'] as $itemId) {
                    $markDrink->execute([
                        $comboIds[$parent],
                        $orderGroupId,
                        $info['name'] !== '' ? $info['name'] : null,
                        $itemId,
                        $orderId,
                    ]);
                }
            }
        }

        // Đối soát: giá combo ở bảng mới phải bằng tổng giá các dòng combo đã ghi ở order_items (trước giảm giá).
        $sumNew = $pdo->prepare('SELECT COALESCE(SUM(combo_price),0) FROM order_combos WHERE order_id=?');
        $sumNew->execute([$orderId]);
        $sumOld = $pdo->prepare("SELECT COALESCE(SUM(unit_price*qty),0) FROM order_items WHERE order_id=? AND combo_role='combo'");
        $sumOld->execute([$orderId]);
        if (round((float)$sumNew->fetchColumn(), 2) !== round((float)$sumOld->fetchColumn(), 2)) {
            throw new RuntimeException('order_combo_total_mismatch');
        }

        $pdo->exec('RELEASE SAVEPOINT occ_save');
    } catch (Throwable $e) {
        error_log('order combo save #' . $orderId . ': ' . $e->getMessage());
        try {
            $pdo->exec('ROLLBACK TO SAVEPOINT occ_save');
        } catch (Throwable $ignored) {
        }
    }
}

/**
 * Danh sách nhóm món của 1 dòng combo để ghi vào order_combo_groups: [group_id => ['name','min','max','picked'=>0,'items'=>[]]].
 * Đơn nhóm: đọc 'combo_groups_rules' đã đóng băng trong snapshot lúc chốt món.
 * Giỏ thường (thanh toán ngay, đã kiểm tra theo combo hiện tại) và snapshot cũ chưa có luật: đọc combo hiện tại.
 */
function occ_combo_group_list(array $comboRow): array
{
    $rules = $comboRow['combo_groups_rules'] ?? null;
    if (!is_array($rules)) {
        $comboId = (int)($comboRow['combo_id'] ?? 0);
        $rules = $comboId ? hgc_group_rules($comboId) : [];
    }

    $list = [];
    foreach ($rules as $rule) {
        $list[(int)$rule['id']] = [
            'name' => (string)$rule['name'],
            'min' => (int)$rule['min'],
            'max' => (int)$rule['max'],
            'picked' => 0,
            'items' => [],
        ];
    }

    return $list;
}

function occ_tables_exist(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }

    try {
        $st = db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('order_combos','order_combo_groups')");
        $ok = (int)$st->fetchColumn() === 2;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/**
 * Các dòng order_items của đơn theo thứ tự hiển thị: dòng combo, ngay sau đó các ly nước của nó, rồi dòng thường.
 * Mỗi dòng có thêm: '_kind' = 'combo' | 'combo_drink' | 'item', '_combo_name' và '_group_name' (ly nước).
 * Đơn cũ hoặc lỗi bất kỳ: trả đúng như SELECT * FROM order_items ORDER BY id (mọi dòng '_kind' = 'item').
 *
 * @param array|null $rows các dòng order_items đã đọc sẵn (null = tự đọc)
 */
function order_lines_flat(int $orderId, ?array $rows = null): array
{
    if ($rows === null) {
        $st = db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
        $st->execute([$orderId]);
        $rows = $st->fetchAll();
    }

    try {
        $byCombo = [];
        $comboRows = [];
        foreach ($rows as $row) {
            $comboId = (int)($row['order_combo_id'] ?? 0);
            if ($comboId && ($row['combo_role'] ?? '') === 'combo') {
                $comboRows[$comboId] = $row;
            } elseif ($comboId && ($row['combo_role'] ?? '') === 'drink') {
                $byCombo[$comboId][] = $row;
            }
        }

        if (!$comboRows) {
            foreach ($rows as &$row) {
                $row['_kind'] = 'item';
            }
            unset($row);

            return $rows;
        }

        $out = [];
        foreach ($rows as $row) {
            $comboId = (int)($row['order_combo_id'] ?? 0);
            $role = (string)($row['combo_role'] ?? '');

            if ($comboId && $role === 'drink' && isset($comboRows[$comboId])) {
                continue; // in cùng dòng combo của nó
            }

            if ($comboId && $role === 'combo') {
                $row['_kind'] = 'combo';
                $out[] = $row;
                $drinks = $byCombo[$comboId] ?? [];
                usort($drinks, fn($a, $b) => [(int)$a['order_combo_group_id'], (int)$a['id']] <=> [(int)$b['order_combo_group_id'], (int)$b['id']]);
                foreach ($drinks as $drink) {
                    $drink['_kind'] = 'combo_drink';
                    $drink['_combo_name'] = (string)$row['product_name'];
                    $drink['_group_name'] = (string)($drink['combo_group_name'] ?? '');
                    $out[] = $drink;
                }
                continue;
            }

            $row['_kind'] = $comboId ? 'combo_drink' : 'item';
            if ($comboId) {
                // Ly nước mà dòng combo không còn: vẫn hiện, không mất dòng nào.
                $row['_combo_name'] = '';
                $row['_group_name'] = (string)($row['combo_group_name'] ?? '');
            }
            $out[] = $row;
        }

        return count($out) === count($rows) ? $out : $rows;
    } catch (Throwable $e) {
        return $rows;
    }
}

/** Nhãn ngắn cho 1 dòng đơn: "Combo" cho dòng combo, "Combo X · Nhóm" cho ly nước trong combo, '' cho dòng thường. */
function occ_label(array $item): string
{
    $kind = $item['_kind'] ?? 'item';

    if ($kind === 'combo') {
        return hgc_t('badge');
    }

    if ($kind === 'combo_drink') {
        return hgc_t('combo_of', ['name' => (string)($item['_combo_name'] ?? ''), 'group' => (string)($item['_group_name'] ?? '')]);
    }

    return '';
}
