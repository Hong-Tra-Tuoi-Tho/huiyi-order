<?php
/**
 * Combo admin (admin/combos.php, admin/combo_edit.php).
 * Bảng:
 *   combos        cấu hình combo (total_cups = tổng số ly tối đa khách chọn ở tất cả các nhóm)
 *   combo_groups  nhóm món trong combo; khách chọn từ min_pick đến max_pick ly trong mỗi nhóm
 *                 (min_pick = 0: nhóm không bắt buộc chọn). Cột pick_count cũ không còn dùng.
 *   combo_items   món trong nhóm (group_id) + size được chọn
 */

/** Size ly hợp lệ (trùng với product_size_options()). */
const COMBO_SIZES = ['L', 'XL'];

/** Tự cập nhật bảng combo nếu DB chưa có cột / khoá / bảng mới (giống ensure_i18n_schema). */
function ensure_combo_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS combo_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                combo_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                min_pick INT UNSIGNED NOT NULL DEFAULT 0,
                max_pick INT UNSIGNED NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_combo_groups_combo (combo_id),
                CONSTRAINT fk_combo_group_combo FOREIGN KEY (combo_id) REFERENCES combos (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    } catch (Throwable $e) {
        error_log('combo schema: ' . $e->getMessage());
    }

    // Bản trước chỉ có pick_count (chọn đúng N ly): thêm min/max, giữ nguyên nghĩa cũ (min = max = N).
    if (!column_exists('combo_groups', 'max_pick')) {
        try {
            db()->exec('ALTER TABLE combo_groups ADD COLUMN min_pick INT UNSIGNED NOT NULL DEFAULT 0 AFTER name, ADD COLUMN max_pick INT UNSIGNED NOT NULL DEFAULT 1 AFTER min_pick');
            db()->exec('UPDATE combo_groups SET min_pick = pick_count, max_pick = pick_count');
        } catch (Throwable $e) {
            error_log('combo schema: ' . $e->getMessage());
        }
    }

    $changes = [
        ['combos', 'all_products', "TINYINT(1) NOT NULL DEFAULT 0 AFTER total_cups"],
        // Ảnh combo: tên file trong thư mục uploads/ (NULL = chưa có ảnh).
        ['combos', 'image', "VARCHAR(255) NULL DEFAULT NULL AFTER name"],
        // Các size áp dụng cho dòng: '' (món không size), 'L', 'XL' hoặc 'L,XL'.
        ['combo_items', 'cup_size', "VARCHAR(10) NOT NULL DEFAULT '' AFTER product_id"],
        // Nhóm chứa món (combo_groups.id).
        ['combo_items', 'group_id', "INT UNSIGNED NULL DEFAULT NULL AFTER combo_id"],
    ];

    foreach ($changes as [$table, $col, $def]) {
        if (column_exists($table, $col)) {
            continue;
        }
        try {
            db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        } catch (Throwable $e) {
            error_log('combo schema: ' . $e->getMessage());
        }
    }

    try {
        // Bản thử trước từng cho 1 món nhiều dòng theo size với khoá uq_combo_product_size:
        // gộp các dòng đó lại rồi trả khoá về (combo_id, product_id).
        if (combo_index_exists('combo_items', 'uq_combo_product_size')) {
            combo_merge_duplicate_items();
            db()->exec('ALTER TABLE combo_items DROP INDEX uq_combo_product_size, ADD UNIQUE KEY uq_combo_product (combo_id, product_id)');
        }

        // Món chưa thuộc nhóm nào (dữ liệu trước khi có nhóm): gom vào "Nhóm 1", chọn đủ tổng số ly cũ.
        combo_migrate_items_to_groups();

        // 1 món được nằm ở nhiều nhóm: khoá chống trùng chuyển từ (combo, món) sang (nhóm, món).
        // Thêm khoá thường cho combo_id trước, vì khoá ngoại fk_combo_item_combo đang dùng uq_combo_product.
        if (!combo_index_exists('combo_items', 'idx_combo_items_combo')) {
            db()->exec('ALTER TABLE combo_items ADD KEY idx_combo_items_combo (combo_id)');
        }

        if (!combo_index_exists('combo_items', 'uq_group_product')) {
            db()->exec('ALTER TABLE combo_items ADD UNIQUE KEY uq_group_product (group_id, product_id)');
        }

        if (combo_index_exists('combo_items', 'uq_combo_product')) {
            db()->exec('ALTER TABLE combo_items DROP INDEX uq_combo_product');
        }

        if (!combo_constraint_exists('combo_items', 'fk_combo_item_group')) {
            db()->exec('ALTER TABLE combo_items ADD CONSTRAINT fk_combo_item_group FOREIGN KEY (group_id) REFERENCES combo_groups (id) ON DELETE CASCADE');
        }
    } catch (Throwable $e) {
        error_log('combo schema: ' . $e->getMessage());
    }
}

function combo_index_exists(string $table, string $index): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
    $st->execute([DB_NAME, $table, $index]);

    return (bool)$st->fetchColumn();
}

function combo_constraint_exists(string $table, string $name): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $st->execute([DB_NAME, $table, $name]);

    return (bool)$st->fetchColumn();
}

/** Gộp các dòng cùng (combo, món): giữ dòng đầu, size = hợp các size, số ly tối đa = lớn nhất. */
function combo_merge_duplicate_items(): void
{
    $groups = db()->query(
        'SELECT combo_id, product_id, MIN(id) AS keep_id,
                GROUP_CONCAT(cup_size) AS sizes, MAX(max_quantity) AS max_qty
         FROM combo_items
         GROUP BY combo_id, product_id
         HAVING COUNT(*) > 1'
    )->fetchAll();

    foreach ($groups as $g) {
        $sizes = combo_sizes_join(explode(',', (string)$g['sizes']));

        db()->prepare('UPDATE combo_items SET cup_size=?, max_quantity=? WHERE id=?')
            ->execute([$sizes, (int)$g['max_qty'], (int)$g['keep_id']]);
        db()->prepare('DELETE FROM combo_items WHERE combo_id=? AND product_id=? AND id<>?')
            ->execute([(int)$g['combo_id'], (int)$g['product_id'], (int)$g['keep_id']]);
    }
}

/** Mỗi combo còn món chưa có nhóm => tạo "Nhóm 1" (chọn đúng total_cups ly) và chuyển các món đó vào. */
function combo_migrate_items_to_groups(): void
{
    $rows = db()->query(
        'SELECT DISTINCT c.id, c.total_cups
         FROM combo_items ci
         JOIN combos c ON c.id = ci.combo_id
         WHERE ci.group_id IS NULL'
    )->fetchAll();

    foreach ($rows as $row) {
        $comboId = (int)$row['id'];

        $cups = max(1, (int)$row['total_cups']);

        db()->prepare('INSERT INTO combo_groups (combo_id, name, min_pick, max_pick, sort_order) VALUES (?, ?, ?, ?, 0)')
            ->execute([$comboId, cb('group_default', ['no' => 1]), $cups, $cups]);
        $groupId = (int)db()->lastInsertId();

        db()->prepare('UPDATE combo_items SET group_id=? WHERE combo_id=? AND group_id IS NULL')
            ->execute([$groupId, $comboId]);
    }
}

/** ['XL','L','x'] => 'L,XL' (chỉ giữ size hợp lệ, đúng thứ tự, không trùng). */
function combo_sizes_join(array $sizes): string
{
    $sizes = array_map(fn($s) => strtoupper(trim((string)$s)), $sizes);

    return implode(',', array_values(array_intersect(COMBO_SIZES, $sizes)));
}

/** Các size chọn được của 1 món: món có size => ['L','XL'], món không size => [''] */
function combo_product_sizes(array $product): array
{
    return product_has_sizes($product) ? COMBO_SIZES : [''];
}

/** Giá món theo size, dùng để hiển thị trong ô chọn. */
function combo_product_price(array $product, string $size): float
{
    if ($size === '') {
        return (float)$product['price'];
    }

    return (float)(product_size_options($product)[$size] ?? 0);
}

/** "Trà tranh — L/XL" (món không size chỉ có tên). $sizes dạng 'L,XL'. */
function combo_item_name(array $product, string $sizes): string
{
    $sizes = combo_sizes_join(explode(',', $sizes));

    return localized_name($product) . ($sizes !== '' ? ' — ' . str_replace(',', '/', $sizes) : '');
}

function cb(string $key, array $values = []): string
{
    $copy = [
        'vi' => [
            'size' => 'Size',
            'bulk_search' => 'Tìm món…',
            'bulk_all' => 'Chọn tất cả',
            'bulk_none' => 'Bỏ chọn',
            'bulk_size' => 'Size cho món có L/XL',
            'bulk_both' => 'Cả L và XL',
            'bulk_add' => 'Thêm các món đã chọn',
            'group_add_count' => 'Thêm {count} món',
            'bulk_added' => 'Đã thêm / cập nhật {count} món.',
            'bulk_nothing' => 'Chưa chọn món nào, hoặc các món đã có trong nhóm.',
            'all_products_label' => 'Tất cả món',
            'title' => 'Combo',
            'add' => 'Thêm combo',
            'edit' => 'Sửa combo',
            'info' => 'Mỗi combo có giá cố định và gồm nhiều nhóm món. Trong mỗi nhóm, khách chọn từ số ly tối thiểu đến tối đa đã cấu hình, từ các món của nhóm đó.',
            'name' => 'Tên combo',
            'name_hint' => 'Ví dụ: Combo 49 - 2 ly',
            'price' => 'Giá combo',
            'total_cups' => 'Tổng số ly',
            'max_cups' => 'Tổng số ly tối đa',
            'max_cups_hint' => 'Tổng số ly khách chọn ở tất cả các nhóm cộng lại không vượt quá số này.',
            'groups_range' => 'Các nhóm: {min}–{max} ly',
            'combo_max' => 'Combo tối đa {max} ly',
            'err_max_cups' => 'Tổng số ly tối đa phải từ 1 trở lên.',
            'err_max_cups_min' => 'Tổng số ly tối đa ({total}) phải lớn hơn hoặc bằng tổng số ly tối thiểu các nhóm ({min}).',
            'items' => 'Món trong combo',
            'items_hint' => 'Chia món thành các nhóm. Mỗi nhóm: đặt số ly khách chọn tối thiểu / tối đa (tối thiểu 0 = không bắt buộc), rồi tích các ly nước của nhóm và bấm thêm.',
            'add_group' => 'Thêm nhóm',
            'group_name' => 'Tên nhóm',
            'group_default' => 'Nhóm {no}',
            'pick_count' => 'Số ly (tối thiểu / tối đa):',
            'pick_min' => 'Tối thiểu',
            'pick_max' => 'Tối đa',
            'pick_hint' => 'Tối thiểu 0 = khách không bắt buộc chọn ở nhóm này.',
            'remove_group' => 'Xoá nhóm',
            'confirm_remove_group' => 'Xoá nhóm này cùng các món trong nhóm?',
            'group_add_items' => 'Thêm món vào nhóm',
            'group_empty' => 'Nhóm chưa có món nào. Tích món ở khung "Thêm món vào nhóm" rồi bấm "Thêm món".',
            'groups_empty' => 'Combo chưa có nhóm nào. Bấm "Thêm nhóm" để bắt đầu.',
            'group_label' => '{name} (chọn {count})',
            'group_label_range' => '{name} (chọn {min}–{max})',
            'group_label_optional' => '{name} (tối đa {max}, không bắt buộc)',
            'total_summary' => 'Tổng: {total} ly',
            'total_range' => 'Tổng: {min}–{max} ly',
            'product' => 'Ly nước',
            'remove_item' => 'Xoá',
            'items_count' => '{count} món',
            'sort' => 'Thứ tự',
            'active' => 'Đang bán',
            'inactive' => 'Tạm ẩn',
            'enable' => 'Bật combo',
            'image' => 'Hình ảnh combo',
            'save' => 'Lưu combo',
            'delete' => 'Xoá combo',
            'confirm_delete' => 'Xoá combo này? Các đơn đã mua combo vẫn giữ nguyên thông tin.',
            'not_found' => 'Không tìm thấy combo.',
            'empty' => 'Chưa có combo nào.',
            'product_off' => 'ngừng bán',
            'saved' => 'Đã lưu combo.',
            'deleted' => 'Đã xoá combo.',
            'saved_removed' => 'Đã lưu combo. Đã gỡ {groups} nhóm và {items} món khỏi combo; khách trong {rooms} nhóm đang mở sẽ phải chọn lại các ly bị ảnh hưởng.',
            'deleted_rooms' => 'Đã xoá combo. {rooms} nhóm đang mở có combo này: khách sẽ thấy báo combo không còn bán và phải chọn lại.',
            'open_rooms_warn' => 'Đang có {rooms} nhóm đặt đơn mở dùng combo này. Sửa tên, giá, số ly, thứ tự thì khách không bị ảnh hưởng; xoá nhóm, xoá món hoặc xoá combo thì khách trong các nhóm đó phải chọn lại.',
            'err_name' => 'Vui lòng nhập tên combo.',
            'err_price' => 'Giá combo phải là số lớn hơn hoặc bằng 0.',
            'err_no_groups' => 'Combo cần ít nhất một nhóm món.',
            'err_group_name' => 'Nhóm {no}: vui lòng nhập tên nhóm.',
            'err_group_pick' => 'Nhóm {no}: số ly tối đa phải từ 1 trở lên.',
            'err_group_min' => 'Nhóm {no}: số ly tối thiểu phải từ 0 đến số ly tối đa.',
            'err_group_items' => 'Nhóm {no}: cần ít nhất một ly nước.',
            'err_product' => 'Nhóm {no}, dòng {row}: vui lòng chọn ít nhất một size hợp lệ.',
            'err_duplicate' => 'Nhóm {no}: ly nước "{name}" bị chọn trùng.',
            'err_save' => 'Chưa lưu được combo. Vui lòng thử lại.',
        ],
        'en' => [
            'size' => 'Size',
            'bulk_search' => 'Search drinks…',
            'bulk_all' => 'Select all',
            'bulk_none' => 'Clear',
            'bulk_size' => 'Size for drinks with L/XL',
            'bulk_both' => 'Both L and XL',
            'bulk_add' => 'Add selected drinks',
            'group_add_count' => 'Add {count} drinks',
            'bulk_added' => 'Added / updated {count} drinks.',
            'bulk_nothing' => 'No drinks selected, or they are already in the group.',
            'all_products_label' => 'All drinks',
            'title' => 'Combos',
            'add' => 'Add combo',
            'edit' => 'Edit combo',
            'info' => 'Each combo has a fixed price and several drink groups. In each group, customers must pick exactly the configured number of cups from that group.',
            'name' => 'Combo name',
            'name_hint' => 'Example: Combo 49 - 2 cups',
            'price' => 'Combo price',
            'total_cups' => 'Total cups',
            'max_cups' => 'Max total cups',
            'max_cups_hint' => 'Cups picked across all groups combined cannot exceed this number.',
            'groups_range' => 'Groups: {min}–{max} cups',
            'combo_max' => 'Combo max {max} cups',
            'err_max_cups' => 'Max total cups must be at least 1.',
            'err_max_cups_min' => 'Max total cups ({total}) must be at least the sum of group minimums ({min}).',
            'items' => 'Drinks in combo',
            'items_hint' => 'Split drinks into groups. For each group set how many cups the customer picks, then tick its drinks and add them. A drink may be in several groups.',
            'add_group' => 'Add group',
            'group_name' => 'Group name',
            'group_default' => 'Group {no}',
            'pick_count' => 'Cups (min / max):',
            'pick_min' => 'Min',
            'pick_max' => 'Max',
            'pick_hint' => 'Min 0 = picking from this group is optional.',
            'remove_group' => 'Remove group',
            'confirm_remove_group' => 'Remove this group and its drinks?',
            'group_add_items' => 'Add drinks to group',
            'group_empty' => 'This group has no drinks yet. Tick drinks under "Add drinks to group" and press "Add selected drinks".',
            'groups_empty' => 'This combo has no groups yet. Press "Add group" to start.',
            'group_label' => '{name} (pick {count})',
            'group_label_range' => '{name} (pick {min}–{max})',
            'group_label_optional' => '{name} (up to {max}, optional)',
            'total_summary' => 'Total: {total} cups',
            'total_range' => 'Total: {min}–{max} cups',
            'product' => 'Drink',
            'remove_item' => 'Remove',
            'items_count' => '{count} drinks',
            'sort' => 'Sort',
            'active' => 'On sale',
            'inactive' => 'Hidden',
            'enable' => 'Enable combo',
            'image' => 'Combo image',
            'save' => 'Save combo',
            'delete' => 'Delete combo',
            'confirm_delete' => 'Delete this combo? Orders that already bought it keep their details.',
            'not_found' => 'Combo not found.',
            'empty' => 'No combos yet.',
            'product_off' => 'unavailable',
            'saved' => 'Combo saved.',
            'deleted' => 'Combo deleted.',
            'saved_removed' => 'Combo saved. Removed {groups} group(s) and {items} drink(s); customers in {rooms} open group order(s) must pick the affected cups again.',
            'deleted_rooms' => 'Combo deleted. {rooms} open group order(s) use it: customers will see it is unavailable and must pick again.',
            'open_rooms_warn' => '{rooms} open group order(s) use this combo. Changing name, price, cup counts or order does not affect customers; removing a group, a drink or the combo makes them pick again.',
            'err_name' => 'Please enter a combo name.',
            'err_price' => 'Combo price must be a number of 0 or more.',
            'err_no_groups' => 'A combo needs at least one drink group.',
            'err_group_name' => 'Group {no}: please enter a group name.',
            'err_group_pick' => 'Group {no}: max cups must be at least 1.',
            'err_group_min' => 'Group {no}: min cups must be between 0 and max cups.',
            'err_group_items' => 'Group {no}: needs at least one drink.',
            'err_product' => 'Group {no}, row {row}: please choose at least one valid size.',
            'err_duplicate' => 'Group {no}: drink "{name}" is selected more than once.',
            'err_save' => 'Could not save the combo. Please try again.',
        ],
        'zh' => [
            'size' => '杯型',
            'bulk_search' => '搜尋飲品…',
            'bulk_all' => '全選',
            'bulk_none' => '取消選擇',
            'bulk_size' => '有 L/XL 的飲品杯型',
            'bulk_both' => 'L 與 XL',
            'bulk_add' => '加入已選飲品',
            'group_add_count' => '加入 {count} 款飲品',
            'bulk_added' => '已加入／更新 {count} 款飲品。',
            'bulk_nothing' => '尚未選擇飲品，或飲品已在此組中。',
            'all_products_label' => '全部飲品',
            'title' => '套餐',
            'add' => '新增套餐',
            'edit' => '編輯套餐',
            'info' => '每個套餐有固定價格並包含多個飲品組。顧客須在每組中選滿設定的杯數。',
            'name' => '套餐名稱',
            'name_hint' => '例如：Combo 49 - 2 杯',
            'price' => '套餐價格',
            'total_cups' => '總杯數',
            'max_cups' => '最多總杯數',
            'max_cups_hint' => '顧客在所有組合計選擇的杯數不可超過此數。',
            'groups_range' => '各組：{min}–{max} 杯',
            'combo_max' => '套餐最多 {max} 杯',
            'err_max_cups' => '最多總杯數至少為 1。',
            'err_max_cups_min' => '最多總杯數（{total}）須大於或等於各組最少杯數合計（{min}）。',
            'items' => '套餐飲品',
            'items_hint' => '將飲品分組。每組設定顧客須選的杯數，再勾選該組飲品並加入。同一飲品可在多個組中。',
            'add_group' => '新增組',
            'group_name' => '組名',
            'group_default' => '第 {no} 組',
            'pick_count' => '杯數（最少／最多）：',
            'pick_min' => '最少',
            'pick_max' => '最多',
            'pick_hint' => '最少 0 = 此組可不選。',
            'remove_group' => '刪除組',
            'confirm_remove_group' => '刪除此組及組內飲品？',
            'group_add_items' => '加入飲品到此組',
            'group_empty' => '此組尚無飲品。請在「加入飲品到此組」勾選後按「加入已選飲品」。',
            'groups_empty' => '套餐尚無飲品組，請按「新增組」開始。',
            'group_label' => '{name}（選 {count}）',
            'group_label_range' => '{name}（選 {min}–{max}）',
            'group_label_optional' => '{name}（最多 {max}，可不選）',
            'total_summary' => '合計：{total} 杯',
            'total_range' => '合計：{min}–{max} 杯',
            'product' => '飲品',
            'remove_item' => '移除',
            'items_count' => '{count} 款飲品',
            'sort' => '排序',
            'active' => '販售中',
            'inactive' => '已隱藏',
            'enable' => '啟用套餐',
            'image' => '套餐圖片',
            'save' => '保存套餐',
            'delete' => '刪除套餐',
            'confirm_delete' => '確定刪除此套餐？已購買的訂單資料會保留。',
            'not_found' => '找不到套餐。',
            'empty' => '尚無套餐。',
            'product_off' => '停售',
            'saved' => '套餐已保存。',
            'deleted' => '套餐已刪除。',
            'saved_removed' => '套餐已保存。已移除 {groups} 個飲品組與 {items} 個飲品；{rooms} 個進行中的團購需重新選擇受影響的飲品。',
            'deleted_rooms' => '套餐已刪除。{rooms} 個進行中的團購使用此套餐：顧客將看到套餐已停售並需重新選擇。',
            'open_rooms_warn' => '目前有 {rooms} 個進行中的團購使用此套餐。修改名稱、價格、杯數或順序不影響顧客；刪除飲品組、飲品或套餐則顧客需重新選擇。',
            'err_name' => '請輸入套餐名稱。',
            'err_price' => '套餐價格須為 0 以上的數字。',
            'err_no_groups' => '套餐至少需要一個飲品組。',
            'err_group_name' => '第 {no} 組：請輸入組名。',
            'err_group_pick' => '第 {no} 組：最多杯數至少為 1。',
            'err_group_min' => '第 {no} 組：最少杯數須介於 0 至最多杯數。',
            'err_group_items' => '第 {no} 組：至少需要一款飲品。',
            'err_product' => '第 {no} 組第 {row} 行：請至少選擇一種有效杯型。',
            'err_duplicate' => '第 {no} 組：飲品「{name}」重複選擇。',
            'err_save' => '未能保存套餐，請稍後再試。',
        ],
    ];

    $text = $copy[current_lang()][$key] ?? $copy['vi'][$key] ?? $key;

    foreach ($values as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }

    return $text;
}

/** Tất cả đồ uống để chọn vào combo, kèm cờ active để đánh dấu món ngừng bán. */
function combo_admin_products(): array
{
    $rows = db()->query('SELECT id, name, name_vi, name_en, name_zh, price, has_sizes, price_l, price_xl, active FROM products ORDER BY active DESC, sort_order, id')->fetchAll();

    $list = [];
    foreach ($rows as $row) {
        $list[(int)$row['id']] = $row;
    }

    return $list;
}

/**
 * Nhóm của 1 combo kèm món:
 * [['name'=>..,'min_pick'=>int,'max_pick'=>int,'items'=>[['product_id'=>int,'cup_size'=>'L,XL'], ...]], ...]
 */
function combo_admin_groups(int $comboId): array
{
    $st = db()->prepare('SELECT id, name, min_pick, max_pick FROM combo_groups WHERE combo_id=? ORDER BY sort_order, id');
    $st->execute([$comboId]);

    $groups = [];
    foreach ($st->fetchAll() as $g) {
        $groups[(int)$g['id']] = [
            'id' => (int)$g['id'],
            'name' => (string)$g['name'],
            'min_pick' => (int)$g['min_pick'],
            'max_pick' => (int)$g['max_pick'],
            'items' => [],
        ];
    }

    $st = db()->prepare('SELECT group_id, product_id, cup_size FROM combo_items WHERE combo_id=? ORDER BY id');
    $st->execute([$comboId]);

    foreach ($st->fetchAll() as $item) {
        $gid = (int)$item['group_id'];

        if (!isset($groups[$gid])) {
            continue;
        }

        $groups[$gid]['items'][] = [
            'product_id' => (int)$item['product_id'],
            'cup_size' => (string)$item['cup_size'],
        ];
    }

    return array_values($groups);
}

/** Cộng số ly tối thiểu ($field = 'min_pick') hoặc tối đa ('max_pick') của các nhóm. */
function combo_groups_sum(array $groups, string $field): int
{
    $total = 0;

    foreach ($groups as $group) {
        $total += max(0, (int)$group[$field]);
    }

    return $total;
}

/** Nhãn nhóm: "Nhóm 1 (chọn 2)", "Nhóm 1 (chọn 1–2)" hoặc "Nhóm 1 (tối đa 2, không bắt buộc)". */
function combo_group_label(string $name, int $min, int $max): string
{
    if ($min === $max) {
        return cb('group_label', ['name' => $name, 'count' => $max]);
    }

    if ($min === 0) {
        return cb('group_label_optional', ['name' => $name, 'max' => $max]);
    }

    return cb('group_label_range', ['name' => $name, 'min' => $min, 'max' => $max]);
}

/**
 * Kiểm tra dữ liệu form. Trả về danh sách lỗi (rỗng = hợp lệ).
 * $groups đã được chuẩn hoá như combo_admin_groups().
 */
function combo_admin_validate(array $combo, array $groups, array $products): array
{
    $errors = [];

    if ($combo['name'] === '') {
        $errors[] = cb('err_name');
    }

    if ($combo['price'] === '' || !is_numeric($combo['price']) || (float)$combo['price'] < 0) {
        $errors[] = cb('err_price');
    }

    // Tổng số ly tối đa cả combo (combos.total_cups): khách chọn cộng ở mọi nhóm không quá số này.
    $maxCups = (int)$combo['total_cups'];

    if ($maxCups < 1) {
        $errors[] = cb('err_max_cups');
    }

    if (!$groups) {
        $errors[] = cb('err_no_groups');
        return $errors;
    }

    foreach ($groups as $g => $group) {
        $no = $g + 1;

        if ($group['name'] === '') {
            $errors[] = cb('err_group_name', ['no' => $no]);
        }

        if ($group['max_pick'] < 1) {
            $errors[] = cb('err_group_pick', ['no' => $no]);
        } elseif ($group['min_pick'] < 0 || $group['min_pick'] > $group['max_pick']) {
            $errors[] = cb('err_group_min', ['no' => $no]);
        }

        if (!$group['items']) {
            $errors[] = cb('err_group_items', ['no' => $no]);
            continue;
        }

        $seen = [];

        foreach ($group['items'] as $i => $item) {
            $pid = $item['product_id'];
            $sizes = $item['cup_size'];

            // Size phải khớp món: món có L/XL thì chọn ít nhất 1 size, món không size thì để trống.
            $sizeOk = isset($products[$pid]) && (
                product_has_sizes($products[$pid])
                    ? $sizes !== '' && $sizes === combo_sizes_join(explode(',', $sizes))
                    : $sizes === ''
            );

            if (!$pid || !$sizeOk) {
                $errors[] = cb('err_product', ['no' => $no, 'row' => $i + 1]);
                continue;
            }

            if (isset($seen[$pid])) {
                $errors[] = cb('err_duplicate', ['no' => $no, 'name' => localized_name($products[$pid])]);
            }

            $seen[$pid] = true;
        }
    }

    // Các nhóm bắt buộc chọn cộng lại không được vượt tổng tối đa, nếu không khách không thể chọn hợp lệ.
    $minSum = combo_groups_sum($groups, 'min_pick');

    if ($maxCups >= 1 && $minSum > $maxCups) {
        $errors[] = cb('err_max_cups_min', ['total' => $maxCups, 'min' => $minSum]);
    }

    return $errors;
}

/**
 * Số nhóm đặt đơn đang mở có combo này trong giỏ (khách ở đó đang chọn nước).
 * Nhóm đã chốt dùng bản chụp giá nên không bị ảnh hưởng khi sửa combo.
 */
function combo_open_rooms(int $comboId): int
{
    if ($comboId < 1) {
        return 0;
    }

    try {
        $st = db()->prepare(
            "SELECT COUNT(DISTINCT i.room_id)
             FROM group_items i
             JOIN group_rooms r ON r.id = i.room_id
             WHERE r.status = 'open' AND r.expires_at > UTC_TIMESTAMP()
               AND JSON_UNQUOTE(JSON_EXTRACT(i.selection_json, '$.kind')) = 'combo'
               AND CAST(JSON_EXTRACT(i.selection_json, '$.id') AS UNSIGNED) = ?"
        );
        $st->execute([$comboId]);

        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // Chưa có bảng nhóm đặt đơn (chưa dùng tính năng này) thì coi như không có nhóm nào.
        return 0;
    }
}

/**
 * Lưu combo + nhóm + món trong 1 transaction. Trả về id combo.
 *
 * Nhóm món được UPSERT theo id (form gửi groups[key][id]): sửa tên, số ly, thứ tự, thêm/bớt món
 * vẫn giữ id nhóm nên các ly nước khách đã chọn trong nhóm đặt đơn còn hiệu lực.
 * Chỉ nhóm bị xoá khỏi form (hoặc nhóm mới) mới đổi/mất id. $removed trả số nhóm / món đã gỡ để cảnh báo admin.
 */
function combo_admin_save(int $id, array $combo, array $groups, array &$removed = []): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $removed = ['groups' => 0, 'items' => 0];
    // id nhóm đang có của combo => danh sách product_id trong nhóm.
    $oldItems = [];

    try {
        $params = [
            $combo['name'],
            $combo['image'] !== '' ? $combo['image'] : null,
            (float)$combo['price'],
            (int)$combo['total_cups'],
            (int)$combo['all_products'],
            (int)$combo['active'],
            (int)$combo['sort_order'],
        ];

        if ($id) {
            $params[] = $id;
            $pdo->prepare('UPDATE combos SET name=?, image=?, price=?, total_cups=?, all_products=?, active=?, sort_order=? WHERE id=?')
                ->execute($params);

            $st = $pdo->prepare('SELECT id FROM combo_groups WHERE combo_id=? FOR UPDATE');
            $st->execute([$id]);

            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oldGroupId) {
                $oldItems[(int)$oldGroupId] = [];
            }

            $st = $pdo->prepare('SELECT group_id, product_id FROM combo_items WHERE combo_id=? AND group_id IS NOT NULL');
            $st->execute([$id]);

            foreach ($st->fetchAll() as $row) {
                $oldItems[(int)$row['group_id']][] = (int)$row['product_id'];
            }

            // Dòng món cũ chưa thuộc nhóm nào: không còn dùng.
            $pdo->prepare('DELETE FROM combo_items WHERE combo_id=? AND group_id IS NULL')
                ->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO combos (name, image, price, total_cups, all_products, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute($params);
            $id = (int)$pdo->lastInsertId();
        }

        $insertGroup = $pdo->prepare('INSERT INTO combo_groups (combo_id, name, min_pick, max_pick, sort_order) VALUES (?, ?, ?, ?, ?)');
        $updateGroup = $pdo->prepare('UPDATE combo_groups SET name=?, min_pick=?, max_pick=?, sort_order=? WHERE id=? AND combo_id=?');
        $clearItems = $pdo->prepare('DELETE FROM combo_items WHERE group_id=?');
        // max_quantity không còn dùng (chỉ giới hạn theo nhóm); ghi bằng số ly tối đa của nhóm cho cột NOT NULL.
        $insertItem = $pdo->prepare('INSERT INTO combo_items (combo_id, group_id, product_id, cup_size, max_quantity) VALUES (?, ?, ?, ?, ?)');

        $kept = [];

        foreach ($groups as $sort => $group) {
            $groupId = (int)($group['id'] ?? 0);

            if ($groupId && isset($oldItems[$groupId]) && !isset($kept[$groupId])) {
                // Nhóm cũ: giữ nguyên id, chỉ cập nhật; danh sách món ghi lại, đếm món bị gỡ để cảnh báo.
                $updateGroup->execute([$group['name'], $group['min_pick'], $group['max_pick'], $sort, $groupId, $id]);
                $clearItems->execute([$groupId]);
                $removed['items'] += count(array_diff($oldItems[$groupId], array_column($group['items'], 'product_id')));
            } else {
                $insertGroup->execute([$id, $group['name'], $group['min_pick'], $group['max_pick'], $sort]);
                $groupId = (int)$pdo->lastInsertId();
            }

            $kept[$groupId] = true;

            foreach ($group['items'] as $item) {
                $insertItem->execute([$id, $groupId, $item['product_id'], $item['cup_size'], $group['max_pick']]);
            }
        }

        // Nhóm bị xoá khỏi form: xoá nhóm cùng toàn bộ món của nhóm.
        foreach ($oldItems as $oldGroupId => $oldProducts) {
            if (isset($kept[$oldGroupId])) {
                continue;
            }

            $removed['groups']++;
            $removed['items'] += count($oldProducts);
            $clearItems->execute([$oldGroupId]);
            $pdo->prepare('DELETE FROM combo_groups WHERE id=? AND combo_id=?')->execute([$oldGroupId, $id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $id;
}

/** Đường dẫn file ảnh combo trong uploads/. */
function combo_image_path(string $file): string
{
    return __DIR__ . '/../uploads/' . basename($file);
}

/** Xoá file ảnh combo (nếu có). */
function combo_delete_image(string $file): void
{
    if ($file === '') {
        return;
    }

    $path = combo_image_path($file);

    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Lưu ảnh tải lên từ $_FILES['image'] vào uploads/ (JPG/PNG/WebP, tối đa 5MB).
 * Trả về [tên file mới, lỗi]. Không có file tải lên thì trả về ['', ''].
 */
function combo_upload_image(): array
{
    $f = $_FILES['image'] ?? null;

    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return ['', ''];
    }

    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['', t('image_upload_failed')];
    }

    if ($f['size'] > 5 * 1024 * 1024) {
        return ['', t('image_too_large')];
    }

    $allow = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $info = @getimagesize($f['tmp_name']);

    if (!$info || !isset($allow[$info['mime']])) {
        return ['', t('image_type_error')];
    }

    $dir = __DIR__ . '/../uploads';

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['', t('image_upload_failed')];
    }

    $fn = 'combo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allow[$info['mime']];

    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fn)) {
        return ['', t('image_upload_failed')];
    }

    return [$fn, ''];
}
