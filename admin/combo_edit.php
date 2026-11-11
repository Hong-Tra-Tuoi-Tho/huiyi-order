<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/combo_admin.php';
require_admin();
ensure_combo_schema();

$products = combo_admin_products();
$errors = [];

$id = (int)($_GET['id'] ?? 0);
$combo = [
    'name_vi' => '',
    'name_en' => '',
    'name_zh' => '',
    'image' => '',
    'price' => '',
    'total_cups' => 1,
    'all_products' => 0,
    'sort_order' => 0,
    'active' => 1,
];
// Combo mới: sẵn 1 nhóm trống, chọn đúng 1 ly.
$groups = [
    [
        'name_vi' => cb('group_default', ['no' => 1]),
        'name_en' => '',
        'name_zh' => '',
        'min_pick' => 1,
        'max_pick' => 1,
        'items' => [],
    ],
];

if ($id) {
    $st = db()->prepare('SELECT * FROM combos WHERE id=?');
    $st->execute([$id]);
    $found = $st->fetch();

    if (!$found) {
        http_response_code(404);
        exit(e(cb('not_found')));
    }

    $combo = $found;
    $combo['image'] = (string)($found['image'] ?? '');
    $groups = combo_admin_groups($id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['act'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);

    if ($act === 'delete' && $id) {
        // combo_groups, combo_items xoá theo (CASCADE); order_combos.combo_id về NULL, đơn cũ giữ snapshot.
        // Đếm trước khi xoá: các nhóm đang mở có combo này sẽ báo "không còn bán" cho khách.
        $openRooms = combo_open_rooms($id);
        db()->prepare('DELETE FROM combos WHERE id=?')->execute([$id]);
        combo_delete_image($combo['image']);
        redirect('combos.php?msg=deleted&rooms=' . $openRooms);
    }

    // Ảnh hiện tại lấy từ DB (không tin giá trị gửi lên từ form).
    $currentImage = $combo['image'];

    $combo = [
        'name_vi' => trim((string)($_POST['name_vi'] ?? '')),
        'name_en' => trim((string)($_POST['name_en'] ?? '')),
        'name_zh' => trim((string)($_POST['name_zh'] ?? '')),
        'image' => $currentImage,
        'price' => trim((string)($_POST['price'] ?? '')),
        // Tổng số ly tối đa khách chọn ở tất cả các nhóm.
        'total_cups' => (int)($_POST['total_cups'] ?? 0),
        // Công tắc "tất cả món" đang ẩn nên luôn tắt.
        'all_products' => 0,
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
        'active' => isset($_POST['active']) ? 1 : 0,
    ];

    // groups[key][name], groups[key][min_pick], groups[key][max_pick], groups[key][product_id][], groups[key][cup_size][]
    // (key chỉ để gom ô của cùng 1 nhóm; thứ tự nhóm theo thứ tự trên form).
    $groups = [];

    foreach ((array)($_POST['groups'] ?? []) as $posted) {
        if (!is_array($posted)) {
            continue;
        }

        $items = [];
        $postedProducts = (array)($posted['product_id'] ?? []);
        $postedSizes = (array)($posted['cup_size'] ?? []);

        foreach ($postedProducts as $i => $pid) {
            $pid = (int)$pid;

            if (!$pid) {
                continue;
            }

            $items[] = [
                'product_id' => $pid,
                'cup_size' => combo_sizes_join(explode(',', (string)($postedSizes[$i] ?? ''))),
            ];
        }

        $groups[] = [
            // id nhóm cũ (0 = nhóm mới): để lưu kiểu UPSERT, giữ id cho các ly khách đã chọn.
            'id' => (int)($posted['id'] ?? 0),
            'name_vi' => trim((string)($posted['name_vi'] ?? '')),
            'name_en' => trim((string)($posted['name_en'] ?? '')),
            'name_zh' => trim((string)($posted['name_zh'] ?? '')),
            'min_pick' => (int)($posted['min_pick'] ?? 0),
            'max_pick' => (int)($posted['max_pick'] ?? 0),
            'items' => $items,
        ];
    }

    $errors = combo_admin_validate($combo, $groups, $products);

    // Chỉ xử lý ảnh khi dữ liệu combo hợp lệ, tránh để lại file rác.
    $newImage = '';

    if (!$errors) {
        [$newImage, $imageError] = combo_upload_image();

        if ($imageError !== '') {
            $errors[] = $imageError;
        } elseif ($newImage !== '') {
            $combo['image'] = $newImage;
        } elseif (isset($_POST['remove_image'])) {
            $combo['image'] = '';
        }
    }

    if (!$errors) {
        try {
            $removed = [];
            $savedId = combo_admin_save($id, $combo, $groups, $removed);

            // Lưu xong mới xoá ảnh cũ (khi đã thay ảnh mới hoặc tích bỏ ảnh).
            if ($currentImage !== '' && $combo['image'] !== $currentImage) {
                combo_delete_image($currentImage);
            }

            $url = 'combos.php?msg=saved';

            // Có gỡ nhóm/món mà đang có nhóm mở dùng combo: báo admin để biết khách sẽ phải chọn lại.
            if (!empty($removed['groups']) || !empty($removed['items'])) {
                $rooms = combo_open_rooms($savedId);

                if ($rooms > 0) {
                    $url = 'combos.php?msg=saved_removed&groups=' . (int)$removed['groups'] . '&items=' . (int)$removed['items'] . '&rooms=' . $rooms;
                }
            }

            redirect($url);
        } catch (Throwable $e) {
            error_log('combo save failed: ' . $e->getMessage());
            $errors[] = cb('err_save');

            // Lưu lỗi: bỏ ảnh vừa tải lên, giữ ảnh cũ.
            if ($newImage !== '') {
                combo_delete_image($newImage);
            }

            $combo['image'] = $currentImage;
        }
    }
}

/** Tên món hiển thị trên dòng: "Trà tranh — L ₫23,000 · XL ₫26,000 (ngừng bán)". */
function combo_edit_product_label(array $products, int $pid): string
{
    if (!isset($products[$pid])) {
        return '#' . $pid;
    }

    $p = $products[$pid];
    $label = localized_name($p) . ' — ' . product_price_text($p);

    if (empty($p['active'])) {
        $label .= ' (' . cb('product_off') . ')';
    }

    return $label;
}

// Dữ liệu món cho JS: id => { label, sizes: { 'L': giá, 'XL': giá } hoặc { '': giá } }
$productsJs = [];
foreach ($products as $pid => $p) {
    $sizes = [];
    foreach (combo_product_sizes($p) as $size) {
        $sizes[$size] = currency() . money(combo_product_price($p, $size));
    }

    $productsJs[$pid] = [
        'label' => combo_edit_product_label($products, $pid),
        'sizes' => $sizes,
    ];
}

/**
 * 1 dòng món trong nhóm (món được thêm từ khung "Thêm món vào nhóm", không đổi món tại dòng).
 * Nút size (bật/tắt, được bật cả L và XL) do JS dựng theo món; giá trị nằm ở input ẩn cup_size[] dạng 'L,XL'.
 */
function combo_edit_row(array $products, string $key, int $productId, string $size): string
{
    $prefix = 'groups[' . $key . ']';

    ob_start();
    ?>
    <div class="combo-item-row" data-combo-row>
        <span class="combo-item-no" data-combo-no></span>
        <span class="combo-item-name" data-combo-name><?= $productId ? e(combo_edit_product_label($products, $productId)) : '' ?></span>
        <input type="hidden" name="<?= e($prefix) ?>[product_id][]" value="<?= $productId ?: '' ?>" data-combo-product>
        <div class="combo-sizes" data-combo-sizes></div>
        <input type="hidden" name="<?= e($prefix) ?>[cup_size][]" value="<?= e($size) ?>" data-combo-size>
        <button class="btn small danger" type="button" data-combo-remove><?= e(cb('remove_item')) ?></button>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** 1 khung nhóm: tên nhóm, số ly khách chọn, khung tích thêm món, danh sách món đã thêm. */
function combo_edit_group(array $products, string $key, array $group): string
{
    $prefix = 'groups[' . $key . ']';

    ob_start();
    ?>
    <div class="combo-group" data-group data-group-key="<?= e($key) ?>">
        <!-- id nhóm đang lưu trong DB (0 = nhóm mới) -->
        <input type="hidden" name="<?= e($prefix) ?>[id]" value="<?= (int)($group['id'] ?? 0) ?>">
        <div class="combo-group-head">
            <span class="combo-group-no" data-group-no></span>
            <label class="combo-group-name">
                <span class="field-hint"><?= e(cb('group_name')) ?></span>
                <input name="<?= e($prefix) ?>[name_vi]" maxlength="120" value="<?= e($group['name_vi']) ?>" placeholder="Tiếng Việt" required data-group-name>
                <input name="<?= e($prefix) ?>[name_en]" maxlength="120" value="<?= e($group['name_en'] ?? '') ?>" placeholder="English">
                <input name="<?= e($prefix) ?>[name_zh]" maxlength="120" value="<?= e($group['name_zh'] ?? '') ?>" placeholder="繁體中文">
            </label>
            <!-- Số ly khách chọn trong nhóm: tối thiểu / tối đa (tối thiểu 0 = không bắt buộc). -->
            <!-- Gom 1 hàng: "Số ly (tối thiểu / tối đa): [0] / [2]" -->
            <div class="combo-group-pick">
                <span class="combo-group-pick-label"><?= e(cb('pick_count')) ?></span>
                <input name="<?= e($prefix) ?>[min_pick]" type="number" min="0" step="1" value="<?= e((string)$group['min_pick']) ?>" required title="<?= e(cb('pick_min')) ?>" placeholder="<?= e(cb('pick_min')) ?>" data-group-min>
                <span>/</span>
                <input name="<?= e($prefix) ?>[max_pick]" type="number" min="1" step="1" value="<?= e((string)$group['max_pick']) ?>" required title="<?= e(cb('pick_max')) ?>" placeholder="<?= e(cb('pick_max')) ?>" data-group-max>
            </div>
            <button class="btn small danger" type="button" data-group-remove><?= e(cb('remove_group')) ?></button>
        </div>
        <div class="field-hint"><?= e(cb('pick_hint')) ?></div>

        <!-- Các ô trong khung này không có name nên không gửi lên server. Nhóm chưa có món thì mở sẵn. -->
        <details class="combo-bulk" data-combo-bulk <?= $group['items'] ? '' : 'open' ?>>
            <summary><?= e(cb('group_add_items')) ?></summary>

            <div class="combo-bulk-tools">
                <input type="search" placeholder="<?= e(cb('bulk_search')) ?>" data-bulk-search>
                <button class="btn small" type="button" data-bulk-all><?= e(cb('bulk_all')) ?></button>
                <button class="btn small" type="button" data-bulk-none><?= e(cb('bulk_none')) ?></button>
            </div>

            <div class="combo-bulk-list">
                <?php foreach ($products as $pid => $p): ?>
                    <label data-bulk-item data-name="<?= e(mb_strtolower(localized_name($p))) ?>">
                        <input type="checkbox" value="<?= $pid ?>" data-bulk-check>
                        <span>
                            <?= e(localized_name($p)) ?>
                            <small><?= product_has_sizes($p) ? 'L/XL' : '' ?><?= empty($p['active']) ? ' · ' . e(cb('product_off')) : '' ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="combo-bulk-options">
                <div class="combo-bulk-size">
                    <!-- Chữ "Size cho món có L/XL" bên trái, nút "Thêm N món" cùng hàng bên phải -->
                    <div class="combo-bulk-size-head">
                        <span class="field-hint"><?= e(cb('bulk_size')) ?></span>
                        <button class="btn primary small" type="button" data-bulk-add disabled><?= e(cb('bulk_add')) ?></button>
                    </div>
                    <div class="inline">
                        <label><input type="radio" name="bulk_size_<?= e($key) ?>" value="L" checked data-bulk-mode> L</label>
                        <label><input type="radio" name="bulk_size_<?= e($key) ?>" value="XL" data-bulk-mode> XL</label>
                        <label><input type="radio" name="bulk_size_<?= e($key) ?>" value="both" data-bulk-mode> <?= e(cb('bulk_both')) ?></label>
                    </div>
                </div>
            </div>
            <div class="combo-bulk-msg" data-bulk-msg></div>
        </details>

        <div class="field-hint" data-combo-empty hidden><?= e(cb('group_empty')) ?></div>

        <div class="combo-items-head" data-combo-head>
            <span>#</span>
            <span><?= e(cb('product')) ?></span>
            <span><?= e(cb('size')) ?></span>
            <span></span>
        </div>

        <div class="combo-items" data-combo-items>
            <?php foreach ($group['items'] as $item): ?>
                <?= combo_edit_row($products, $key, (int)$item['product_id'], (string)$item['cup_size']) ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

admin_header($id ? cb('edit') : cb('add'));
?>
<style>
    .combo-groups { display: grid; gap: 16px; }
    .combo-group { display: grid; gap: 12px; padding: 14px; border: 1px solid #e3e6eb; border-radius: 12px; background: #fff; }
    .combo-group-head { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
    .combo-group-no { align-self: center; min-width: 28px; height: 28px; border-radius: 50%; background: #1f2937; color: #fff; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
    .combo-group-name { flex: 1 1 220px; display: grid; gap: 4px; margin: 0; }
    .combo-group-pick { display: flex; gap: 8px; align-items: center; margin: 0; }
    .combo-group-pick-label { white-space: nowrap; font-weight: 600; font-size: 14px; }
    .admin-shell .admin-container .combo-group-pick input { width: 80px; min-width: 0; }
    .combo-group-head .btn { margin-left: auto; }

    .combo-items { display: grid; gap: 10px; }
    .combo-items-head,
    .combo-item-row { display: grid; grid-template-columns: 32px minmax(0, 1fr) 104px auto; gap: 10px; align-items: center; }
    .combo-items-head { font-size: .85em; opacity: .75; }
    .combo-item-no { font-weight: 600; text-align: center; opacity: .7; }
    .combo-item-name { font-weight: 600; overflow-wrap: anywhere; }
    .combo-items-head[hidden] { display: none; }
    .combo-sizes { display: flex; gap: 6px; }
    .combo-size-btn { min-width: 44px; padding: 7px 10px; border: 1px solid #cfd4dc; border-radius: 8px; background: #fff; color: inherit; font-weight: 600; cursor: pointer; }
    .combo-size-btn.active { background: #1f2937; border-color: #1f2937; color: #fff; }
    .combo-size-none { opacity: .5; }
    .combo-sum { font-size: .9em; font-weight: 600; }
    .combo-sum.bad { color: #c0392b; }
    .combo-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .combo-footer-actions {
        display: flex;
        gap: 8px;
        margin-left: auto;
    }

    .combo-bulk { border: 1px dashed #cfd4dc; border-radius: 10px; padding: 10px 12px; }
    .combo-bulk summary { cursor: pointer; font-weight: 600; }
    .combo-bulk[open] summary { margin-bottom: 10px; }
    .combo-bulk-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 10px; }
    .combo-bulk-tools input[type="search"] { flex: 1 1 200px; }
    .combo-bulk-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr)); gap: 4px 12px; max-height: 360px; overflow: auto; padding: 4px; border: 1px solid #eef0f3; border-radius: 8px; }
    .combo-bulk-list label { display: flex; gap: 8px; align-items: center; padding: 4px 2px; cursor: pointer; }
    .combo-bulk-list label small { opacity: .6; }
    /* display:flex ở trên đè thuộc tính hidden, nên phải ẩn lại rõ ràng. */
    .combo-bulk-list label[hidden] { display: none; }
    .admin-shell .admin-container .combo-bulk :is(input[type="checkbox"], input[type="radio"]) {
        width: 15px;
        height: 15px;
        min-height: 0;
        margin: 0;
        padding: 0;
        accent-color: #c8962e; /* vàng đất */
        cursor: pointer;
    }
    .combo-bulk-options { display: flex; flex-wrap: wrap; gap: 14px; align-items: end; margin-top: 10px; }
    .combo-bulk-size { flex: 1 1 100%; min-width: 0; }
    .combo-bulk-size-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .combo-bulk-size-head .btn { flex: 0 0 auto; white-space: nowrap; }
    .combo-bulk-size-head .btn:disabled { opacity: .45; cursor: not-allowed; }
    .combo-bulk-options .inline { display: flex; gap: 14px; align-items: center; min-height: 44px; }
    .combo-bulk-options .inline label { display: inline-flex; gap: 6px; align-items: center; margin: 0; cursor: pointer; white-space: nowrap; }
    .combo-bulk-msg { margin-top: 8px; font-size: .9em; }

    /* Chữ to hơn chữ admin mặc định (14px) 5px: danh sách tích món + các dòng món trong combo. */
    .combo-bulk-list label,
    .combo-item-row,
    .combo-size-btn { font-size: 19px; }
    .admin-shell .admin-container .combo-bulk-list input[type="checkbox"] { width: 18px; height: 18px; min-width: 18px; }
    /* Admin tự vẽ checkbox (app.css: nền đen khi tích), nên đổi màu bằng background thay vì accent-color. */
    .admin-shell .admin-container .combo-bulk input[type="checkbox"]:checked {
        background-color: #c8962e;
        border-color: #c8962e;
    }
    .admin-shell .admin-container .combo-bulk input[type="checkbox"]:hover { border-color: #c8962e; }

    /* Nút nổi góc phải dưới màn hình: Thêm món (khi đang tích) + Thêm nhóm */
    .combo-fab {
        position: fixed;
        right: 20px;
        bottom: 20px;
        /* Nằm trên mọi thứ có thể che nó, kể cả khung "Có đơn hàng mới" (.order-alert-toast, z-index 200). */
        z-index: 1000;
        /* "Thêm nhóm" + "Lưu combo" nằm ngang; nút "Thêm N món…" (khi hiện) chiếm riêng hàng trên */
        display: flex;
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
        gap: 10px;
        max-width: calc(100vw - 40px);
    }
    .combo-fab .btn {
        box-shadow: 0 6px 18px rgba(17, 24, 39, .18);
        border-radius: 999px;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .combo-fab .btn[hidden] { display: none; }
    /* Chừa chỗ cuối trang để nút nổi không che nút Lưu / Xoá */
    .combo-box { padding-bottom: 130px; }

    /* Thứ tự + Tổng số ly tối đa: 2 cột bằng nhau, chú thích nằm dưới cả hàng */
    .combo-pair {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 8px 12px;
        align-items: end;
    }
    .combo-pair label {
        display: grid;
        gap: 8px;
        margin: 0;
        min-width: 0;
    }
    .combo-pair-hint {
        grid-column: 1 / -1;
        font-size: 12px;
        color: var(--ui-muted, #6b7280);
    }

    /* Cho mọi khung lồng nhau được co theo màn hình (mặc định phần tử lưới không co nhỏ hơn nội dung). */
    .combo-box,
    .combo-groups,
    .combo-group,
    .combo-group > *,
    .combo-bulk,
    .combo-bulk-list label,
    .combo-items,
    .combo-item-row { min-width: 0; }

    @media (max-width: 640px) {
        .combo-items-head { display: none; }
        /* Dòng món: tên chiếm cả hàng, hàng dưới là nút size bên trái + nút xoá bên phải */
        .combo-item-row { grid-template-columns: 24px minmax(0, 1fr) auto; row-gap: 6px; }
        .combo-item-name { grid-column: 2 / -1; }
        .combo-sizes { grid-column: 2; }
        .combo-item-row .btn { grid-column: 3; }

        /* Khung nhóm sát lề hơn */
        .admin-shell .admin-container .combo-box { padding: 12px 12px 130px; }
        .combo-group { padding: 12px 10px; gap: 10px; }

        /* Đầu nhóm: số nhóm + tên nhóm 1 hàng, số ly 1 hàng, nút xoá nhóm nằm phải */
        .combo-group-head { gap: 10px; align-items: center; }
        .combo-group-name { flex: 1 1 0; min-width: 0; }
        .combo-group-pick { flex: 1 1 100%; flex-wrap: wrap; gap: 6px 8px; }
        .combo-group-pick-label { flex: 1 1 100%; font-size: 13px; }
        .admin-shell .admin-container .combo-group-pick input { flex: 1 1 0; width: auto; }
        .combo-group-head .btn { margin-left: auto; }

        /* Ô tìm món chiếm cả hàng, 2 nút chọn / bỏ chọn chia đôi hàng dưới */
        .combo-bulk { padding: 10px 8px; }
        .combo-bulk-tools input[type="search"] { flex: 1 1 100%; }
        .combo-bulk-tools .btn { flex: 1 1 0; }
        .combo-bulk-options { gap: 10px; }
        .combo-bulk-options .inline { flex-wrap: wrap; gap: 10px 14px; min-height: 0; }
        .combo-fab { right: 12px; bottom: 12px; max-width: calc(100vw - 24px); }

        /* Chữ 19px quá to với điện thoại */
        .combo-bulk-list label,
        .combo-item-row,
        .combo-size-btn { font-size: 16px; }

        /* Hàng cuối: tổng số ly 1 hàng, các nút chia đều hàng dưới */
        .combo-footer-actions { flex: 1 1 100%; flex-wrap: wrap; }
        .admin-shell .admin-container .combo-footer-actions .btn { flex: 1 1 0; white-space: nowrap; padding-left: 10px; padding-right: 10px; }
    }

    /* Tiêu đề "Món trong combo" + công tắc bật combo nằm bên phải */
    .combo-items-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .admin-shell .admin-container .combo-items-title .toggle-row {
        width: auto;
        min-height: 0;
        margin: 0;
        padding: 0;
        border: 0;
        background: transparent;
        box-shadow: none;
        gap: 10px !important;
    }
</style>

<section class="panel narrow">
    <?php $openRooms = $id ? combo_open_rooms($id) : 0; ?>
    <?php if ($openRooms > 0): ?>
        <div class="alert info"><?= e(cb('open_rooms_warn', ['rooms' => $openRooms])) ?></div>
    <?php endif; ?>
    <?php if (($_GET['msg'] ?? '') === 'duplicated' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="alert success"><?= e(cb('duplicated')) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert danger">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid ui-form-grid" data-combo-form>
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" value="<?= $id ?>">

        <!-- Nút nổi góc phải dưới màn hình: Thêm món (chỉ hiện khi đang tích món ở 1 nhóm), Thêm nhóm, Lưu combo.
             Đặt đầu form để "Lưu combo" là nút submit đầu tiên: bấm Enter trong ô nhập sẽ lưu, không bấm nhầm nút Xoá. -->
        <div class="combo-fab">
            <button class="btn" type="button" data-group-add>＋ <?= e(cb('add_group')) ?></button>
            <button class="btn primary" type="submit"><?= e(cb('save')) ?></button>
        </div>

        <label>
            <?= e(cb('name')) ?>
            <input name="name_vi" maxlength="160" value="<?= e($combo['name_vi']) ?>" placeholder="Tiếng Việt — <?= e(cb('name_hint')) ?>" required>
            <input name="name_en" maxlength="160" value="<?= e($combo['name_en'] ?? '') ?>" placeholder="English">
            <input name="name_zh" maxlength="160" value="<?= e($combo['name_zh'] ?? '') ?>" placeholder="繁體中文">
        </label>

        <label>
            <?= e(cb('price')) ?>
            <input name="price" type="number" min="0" step="1" value="<?= e((string)$combo['price']) ?>" required>
        </label>

        <!-- Thứ tự + Tổng số ly tối đa luôn nằm cùng 1 hàng (kể cả trên điện thoại). -->
        <div class="combo-pair ui-full">
            <label>
                <?= e(cb('sort')) ?>
                <input name="sort_order" type="number" value="<?= e((string)$combo['sort_order']) ?>">
            </label>

            <label>
                <?= e(cb('max_cups')) ?>
                <input name="total_cups" type="number" min="1" step="1" value="<?= e((string)$combo['total_cups']) ?>" required data-combo-max>
            </label>

            <small class="combo-pair-hint"><?= e(cb('max_cups_hint')) ?></small>
        </div>

        <label class="ui-full">
            <?= e(cb('image')) ?>
            <input name="image" type="file" accept="image/jpeg,image/png,image/webp">
            <small><?= e(t('image_help')) ?></small>
        </label>

        <?php if ($combo['image'] !== ''): ?>
            <div class="ui-full">
                <img class="edit-preview" src="<?= e(base_url('uploads/' . $combo['image'])) ?>" alt="">
                <label class="check-row">
                    <input type="checkbox" name="remove_image">
                    <span><?= e(t('remove_current_image')) ?></span>
                </label>
            </div>
        <?php endif; ?>

        <?php /* Công tắc "Áp dụng tất cả món đang bán" (cột combos.all_products) tạm ẩn theo yêu cầu. */ ?>

        <div class="multilang-box combo-box ui-full">
            <div class="combo-items-title">
                <strong><?= e(cb('items')) ?></strong>
                <label class="toggle-row">
                    <input type="checkbox" name="active" <?= !empty($combo['active']) ? 'checked' : '' ?>>
                    <span><strong><?= e(cb('enable')) ?></strong></span>
                </label>
            </div>
            <div class="field-hint"><?= e(cb('items_hint')) ?></div>

            <div class="field-hint" data-groups-empty hidden><?= e(cb('groups_empty')) ?></div>

            <div class="combo-groups" data-groups>
                <?php foreach ($groups as $g => $group): ?>
                    <?= combo_edit_group($products, 'g' . $g, $group) ?>
                <?php endforeach; ?>
            </div>

            <template data-group-template>
                <?= combo_edit_group($products, '__KEY__', ['name_vi' => '', 'name_en' => '', 'name_zh' => '', 'min_pick' => 1, 'max_pick' => 1, 'items' => []]) ?>
            </template>

            <template data-row-template>
                <?= combo_edit_row($products, '__KEY__', 0, '') ?>
            </template>

            <!-- Dòng tổng số ly + nút Xoá combo (nút Lưu nằm ở nút nổi góc phải dưới). -->
            <div class="combo-footer">
                <div class="combo-sum" data-combo-sum></div>
                <div class="combo-footer-actions">
                    <?php if ($id): ?>
                        <button class="btn danger" type="submit" name="act" value="delete" formnovalidate onclick="return confirm(<?= e(json_encode(cb('confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>)"><?= e(cb('delete')) ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</section>

<script>
(function () {
    var form = document.querySelector('[data-combo-form]');
    if (!form) return;

    var PRODUCTS = <?= json_encode($productsJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var TEXT = {
        total: <?= json_encode(cb('total_summary'), JSON_UNESCAPED_UNICODE) ?>,
        totalRange: <?= json_encode(cb('total_range'), JSON_UNESCAPED_UNICODE) ?>,
        groupsRange: <?= json_encode(cb('groups_range'), JSON_UNESCAPED_UNICODE) ?>,
        comboMax: <?= json_encode(cb('combo_max'), JSON_UNESCAPED_UNICODE) ?>,
        groupDefault: <?= json_encode(cb('group_default'), JSON_UNESCAPED_UNICODE) ?>,
        confirmRemoveGroup: <?= json_encode(cb('confirm_remove_group'), JSON_UNESCAPED_UNICODE) ?>,
        added: <?= json_encode(cb('bulk_added'), JSON_UNESCAPED_UNICODE) ?>,
        addCount: <?= json_encode(cb('group_add_count'), JSON_UNESCAPED_UNICODE) ?>,
        addNone: <?= json_encode(cb('bulk_add'), JSON_UNESCAPED_UNICODE) ?>,
        nothing: <?= json_encode(cb('bulk_nothing'), JSON_UNESCAPED_UNICODE) ?>
    };

    var groupsBox = form.querySelector('[data-groups]');
    var groupTemplate = form.querySelector('[data-group-template]').innerHTML;
    var rowTemplate = form.querySelector('[data-row-template]').innerHTML;
    var groupsEmpty = form.querySelector('[data-groups-empty]');
    var sumBox = form.querySelector('[data-combo-sum]');
    var maxCupsInput = form.querySelector('[data-combo-max]');
    var nextKey = 0;

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    function groups() {
        return toArray(groupsBox.querySelectorAll('[data-group]'));
    }

    function rows(group) {
        return toArray(group.querySelectorAll('[data-combo-row]'));
    }

    // Dựng phần tử từ HTML template, thay __KEY__ bằng khoá nhóm.
    function build(html, key) {
        var box = document.createElement('div');
        box.innerHTML = html.split('__KEY__').join(key).trim();
        return box.firstElementChild;
    }

    // Các size của 1 món: ['L','XL'] hoặc [''] (món không size).
    function sizesOf(pid) {
        return PRODUCTS[pid] ? Object.keys(PRODUCTS[pid].sizes) : [];
    }

    // Size của 1 dòng: '' => [], 'L,XL' => ['L','XL'].
    function rowSizes(row) {
        var value = row.querySelector('[data-combo-size]').value;
        return value ? value.split(',') : [];
    }

    // Ghi size cho dòng, luôn theo thứ tự L, XL.
    function setRowSizes(row, sizes) {
        var ordered = ['L', 'XL'].filter(function (s) {
            return sizes.indexOf(s) !== -1;
        });
        row.querySelector('[data-combo-size]').value = ordered.join(',');
    }

    // Dòng của 1 món trong nhóm (mỗi món 1 dòng / nhóm), không có thì null.
    function rowOf(group, pid) {
        return rows(group).filter(function (row) {
            return row.querySelector('[data-combo-product]').value === String(pid);
        })[0] || null;
    }

    // Dựng nút size bật/tắt cho 1 dòng. Món có L/XL bật được 1 hoặc cả 2 size; món không size hiện "—".
    function renderSizes(row) {
        var box = row.querySelector('[data-combo-sizes]');
        var pid = row.querySelector('[data-combo-product]').value;
        var sizes = sizesOf(pid);

        box.innerHTML = '';

        if (!pid || sizes[0] === '') {
            row.querySelector('[data-combo-size]').value = '';
            if (pid) {
                box.innerHTML = '<span class="combo-size-none">—</span>';
            }
            return;
        }

        // Bỏ size không hợp lệ; dữ liệu cũ chưa có size thì mặc định L.
        var active = rowSizes(row).filter(function (s) {
            return sizes.indexOf(s) !== -1;
        });
        if (!active.length) {
            active = [sizes[0]];
        }
        setRowSizes(row, active);

        sizes.forEach(function (size) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'combo-size-btn' + (active.indexOf(size) !== -1 ? ' active' : '');
            btn.textContent = size;
            btn.title = PRODUCTS[pid].sizes[size];
            btn.dataset.size = size;
            box.appendChild(btn);
        });
    }

    function bulkMode(group) {
        var checked = group.querySelector('[data-bulk-mode]:checked');
        return checked ? checked.value : 'L';
    }

    // Size sẽ thêm cho 1 món theo lựa chọn L / XL / cả hai (món không size => ['']).
    function bulkWantedSizes(pid, mode) {
        if (sizesOf(pid)[0] === '') {
            return [''];
        }
        return mode === 'both' ? ['L', 'XL'] : [mode];
    }

    // Món đã có dòng trong nhóm và dòng đó đã bật đủ các size muốn thêm.
    function alreadyHas(group, pid, wanted) {
        var row = rowOf(group, pid);
        if (!row) {
            return false;
        }

        var current = rowSizes(row);
        return wanted.every(function (size) {
            return size === '' || current.indexOf(size) !== -1;
        });
    }

    // Id các món đã có ở những nhóm đứng trước nhóm này: { '12': true, ... }
    function productsInEarlierGroups(group) {
        var used = {};
        var list = groups();
        var index = list.indexOf(group);

        list.slice(0, index).forEach(function (earlier) {
            rows(earlier).forEach(function (row) {
                used[row.querySelector('[data-combo-product]').value] = true;
            });
        });

        return used;
    }

    // Ẩn món không khớp ô tìm, đã có ở nhóm trước, hoặc đã có trong nhóm này đủ các size đang chọn.
    // Món bị ẩn thì bỏ tích.
    function updateBulkList(group) {
        var q = group.querySelector('[data-bulk-search]').value.trim().toLowerCase();
        var mode = bulkMode(group);
        var usedBefore = productsInEarlierGroups(group);

        toArray(group.querySelectorAll('[data-bulk-item]')).forEach(function (item) {
            var check = item.querySelector('[data-bulk-check]');
            var pid = check.value;
            var taken = usedBefore[pid] || alreadyHas(group, pid, bulkWantedSizes(pid, mode));

            item.hidden = taken || (q !== '' && item.dataset.name.indexOf(q) === -1);
            if (taken) {
                check.checked = false;
            }
        });
    }

    // Đánh số nhóm / dòng, dựng nút size, cập nhật khung tích món và dòng tổng số ly.
    function refresh() {
        var totalMin = 0;
        var totalMax = 0;
        var list = groups();

        list.forEach(function (group, g) {
            group.querySelector('[data-group-no]').textContent = g + 1;
            group.querySelector('[data-group-name]').placeholder = TEXT.groupDefault.replace('{no}', g + 1);

            var groupRows = rows(group);
            groupRows.forEach(function (row, i) {
                row.querySelector('[data-combo-no]').textContent = i + 1;
                renderSizes(row);
            });

            // Nhóm chưa có món: ẩn tiêu đề cột, hiện hướng dẫn.
            group.querySelector('[data-combo-head]').hidden = groupRows.length === 0;
            group.querySelector('[data-combo-empty]').hidden = groupRows.length !== 0;

            updateBulkList(group);

            // Tối thiểu không được vượt tối đa: trình duyệt chặn khi bấm Lưu.
            var minInput = group.querySelector('[data-group-min]');
            var maxInput = group.querySelector('[data-group-max]');
            var max = Math.max(0, parseInt(maxInput.value, 10) || 0);
            var min = Math.min(max, Math.max(0, parseInt(minInput.value, 10) || 0));

            minInput.max = max > 0 ? max : '';
            totalMin += min;
            totalMax += max;
        });

        groupsEmpty.hidden = list.length !== 0;

        // "Các nhóm: 1–5 ly · Combo tối đa 3 ly"; đỏ khi tổng tối thiểu các nhóm vượt tổng tối đa combo.
        var comboMax = parseInt(maxCupsInput.value, 10) || 0;

        sumBox.textContent = TEXT.groupsRange.replace('{min}', totalMin).replace('{max}', totalMax)
            + ' · ' + TEXT.comboMax.replace('{max}', comboMax);
        sumBox.classList.toggle('bad', comboMax < 1 || totalMin > comboMax);

        list.forEach(updateAddButton);
    }

    // Nút "Thêm 3 món" của 1 nhóm: hiện số món đang tích, mờ đi khi chưa tích món nào.
    function updateAddButton(group) {
        var btn = group.querySelector('[data-bulk-add]');
        var count = group.querySelectorAll('[data-bulk-check]:checked').length;

        btn.disabled = count === 0;
        btn.textContent = count
            ? TEXT.addCount.replace('{count}', count)
            : TEXT.addNone;
    }

    // Khung "Có đơn hàng mới" nằm sát đáy màn hình (điện thoại): đẩy cụm nút nổi lên ngay phía trên nó.
    // Máy tính khung nằm góc trên nên cụm nút giữ nguyên chỗ.
    var fabBox = form.querySelector('.combo-fab');
    var orderToast = document.getElementById('order-alert-toast');

    function placeFab() {
        fabBox.style.bottom = '';

        if (!orderToast || orderToast.hidden) {
            return;
        }

        var rect = orderToast.getBoundingClientRect();
        var nearBottom = rect.height > 0 && rect.bottom > window.innerHeight - 120;

        if (nearBottom) {
            fabBox.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
        }
    }

    if (orderToast) {
        new MutationObserver(placeFab).observe(orderToast, {
            attributes: true,
            attributeFilter: ['hidden', 'class', 'style'],
        });

        if (window.ResizeObserver) {
            new ResizeObserver(placeFab).observe(orderToast);
        }
    }

    window.addEventListener('resize', placeFab);
    placeFab();


    // Thêm nhóm mới vào cuối danh sách, đặt sẵn tên "Nhóm N" rồi cuộn tới.
    form.querySelector('[data-group-add]').addEventListener('click', function () {
        var group = build(groupTemplate, 'n' + (nextKey++));

        groupsBox.appendChild(group);
        group.querySelector('[data-group-name]').value = TEXT.groupDefault.replace('{no}', groups().length);
        refresh();

        group.scrollIntoView({ behavior: 'smooth', block: 'start' });
        group.querySelector('[data-group-name]').focus({ preventScroll: true });
    });

    groupsBox.addEventListener('click', function (event) {
        var group = event.target.closest('[data-group]');
        if (!group) return;

        var sizeBtn = event.target.closest('.combo-size-btn');
        if (sizeBtn) {
            var row = sizeBtn.closest('[data-combo-row]');
            var active = rowSizes(row);
            var size = sizeBtn.dataset.size;

            if (active.indexOf(size) === -1) {
                active.push(size);
            } else if (active.length > 1) {
                // Luôn giữ ít nhất 1 size bật.
                active = active.filter(function (s) {
                    return s !== size;
                });
            }

            setRowSizes(row, active);
            refresh();
            return;
        }

        if (event.target.closest('[data-combo-remove]')) {
            event.target.closest('[data-combo-row]').remove();
            refresh();
            return;
        }

        if (event.target.closest('[data-group-remove]')) {
            if (rows(group).length && !confirm(TEXT.confirmRemoveGroup)) {
                return;
            }
            group.remove();
            refresh();
            return;
        }

        // "Chọn tất cả" chỉ tích các món đang hiện (theo ô tìm).
        if (event.target.closest('[data-bulk-all]')) {
            toArray(group.querySelectorAll('[data-bulk-item]')).forEach(function (item) {
                if (!item.hidden) {
                    item.querySelector('[data-bulk-check]').checked = true;
                }
            });
            updateAddButton(group);
            return;
        }

        if (event.target.closest('[data-bulk-none]')) {
            toArray(group.querySelectorAll('[data-bulk-check]')).forEach(function (check) {
                check.checked = false;
            });
            updateAddButton(group);
            return;
        }

        if (event.target.closest('[data-bulk-add]')) {
            addSelected(group);
        }
    });

    // Thêm các món đã tích vào nhóm. Món đã có trong nhóm => gộp size mới vào dòng cũ.
    function addSelected(group) {
        var mode = bulkMode(group);
        var key = group.dataset.groupKey;
        var list = group.querySelector('[data-combo-items]');
        var added = 0;

        toArray(group.querySelectorAll('[data-bulk-check]')).forEach(function (check) {
            if (!check.checked) return;

            var pid = check.value;
            var wanted = bulkWantedSizes(pid, mode).filter(function (s) {
                return s !== '';
            });
            var row = rowOf(group, pid);

            check.checked = false;

            if (!row) {
                row = build(rowTemplate, key);
                row.querySelector('[data-combo-name]').textContent = PRODUCTS[pid].label;
                row.querySelector('[data-combo-product]').value = pid;
                row.querySelector('[data-combo-size]').value = wanted.join(',');
                list.appendChild(row);
                added++;
                return;
            }

            if (!alreadyHas(group, pid, wanted)) {
                setRowSizes(row, rowSizes(row).concat(wanted));
                added++;
            }
        });

        group.querySelector('[data-bulk-msg]').textContent = added
            ? TEXT.added.replace('{count}', added)
            : TEXT.nothing;
        refresh();
    }

    // Gõ tìm món chỉ lọc khung của nhóm đó; đổi size / số ly thì tính lại.
    groupsBox.addEventListener('input', function (event) {
        var group = event.target.closest('[data-group]');

        if (group && event.target.matches('[data-bulk-search]')) {
            updateBulkList(group);
            return;
        }

        refresh();
    });
    groupsBox.addEventListener('change', refresh);
    maxCupsInput.addEventListener('input', refresh);

    refresh();
})();
</script>
<?php admin_footer(); ?>
