<?php
require_once __DIR__ . '/game_config.php';
require_once __DIR__ . '/game_media.php';
function hgra_text(string $key): string
{
    $copy = [
        'settings' => ['小遊戲設定', 'Cài đặt trò chơi', 'Game settings'],
        'hint' => ['設定新開局的主題、圖案與獎項。已開始的牌局及已發出的優惠券沿用原設定。', 'Chọn giao diện, hình thẻ và quà cho lượt mới. Lượt đang chơi và phiếu đã phát giữ cài đặt cũ.', 'Choose the theme, card pictures and prizes for new games. Games in progress and issued coupons keep their original settings.'],
        'appearance' => ['主題與卡片', 'Giao diện và thẻ', 'Theme and cards'],
        'memory' => ['童年回憶', 'Ký ức tuổi thơ', 'Childhood memories'],
        'garden' => ['茶香花園', 'Vườn trà', 'Tea garden'],
        'night' => ['星夜茶會', 'Đêm trà', 'Tea under the stars'],
        'back' => ['卡背圖片', 'Hình mặt sau', 'Card back'],
        'pair' => ['配對圖案', 'Hình cặp', 'Pair picture'],
        'media_hint' => ['JPEG、PNG 或 WebP，最大 2 MiB，長寬不超過 4096 像素。未上傳時使用預設圖案。', 'JPEG, PNG hoặc WebP; tối đa 2 MiB và 4096 pixel mỗi cạnh. Không tải lên thì dùng hình mặc định.', 'JPEG, PNG or WebP; up to 2 MiB and 4096 pixels per side. Leave empty to use the default artwork.'],
        'upload' => ['選擇圖片', 'Chọn hình', 'Choose picture'],
        'remove' => ['移除圖片', 'Bỏ hình', 'Remove picture'],
        'prizes' => ['獎項與機率', 'Quà và tỷ lệ', 'Prizes and odds'],
        'prize' => ['獎項', 'Quà', 'Prize'],
        'prize_hint' => ['最多 12 個獎項。所有啟用獎項的機率合計須為 100.00%。', 'Tối đa 12 quà. Tổng tỷ lệ của các quà đang bật phải bằng 100,00%.', 'Add up to 12 prizes. Enabled prize odds must total 100.00%.'],
        'add' => ['新增獎項', 'Thêm quà', 'Add prize'],
        'delete' => ['刪除獎項', 'Xóa quà', 'Delete prize'],
        'enabled' => ['啟用', 'Bật', 'Enabled'],
        'kind' => ['獎項類型', 'Loại quà', 'Prize type'],
        'topping' => ['免費加料', 'Topping miễn phí', 'Free topping'],
        'tea' => ['免費飲品', 'Đồ uống miễn phí', 'Free drink'],
        'discount' => ['金額折抵', 'Giảm tiền', 'Amount discount'],
        'item' => ['贈送品項', 'Món tặng', 'Gift item'],
        'size' => ['贈送杯型', 'Cỡ ly tặng', 'Gift cup size'],
        'gift_qty' => ['贈送數量（杯／份）', 'Số lượng tặng (ly/phần)', 'Gift quantity (cups/portions)'],
        'single' => ['單一杯型', 'Một cỡ ly', 'Single size'],
        'select' => ['請選擇', 'Vui lòng chọn', 'Select'],
        'chosen' => ['已選 {n} 項', 'Đã chọn {n} món', '{n} items selected'],
        'unavailable' => ['已停用或不存在的品項', 'Món đã tắt hoặc không còn', 'Unavailable item'],
        'amount' => ['折抵金額', 'Số tiền giảm', 'Discount amount'],
        'weight' => ['中獎機率（%）', 'Tỷ lệ trúng (%)', 'Odds (%)'],
        'min_spend' => ['最低訂單金額', 'Tổng tiền đơn tối thiểu', 'Minimum order subtotal'],
        'min_cups' => ['最低付費杯數', 'Số ly trả phí tối thiểu', 'Minimum paid drinks'],
        'days' => ['有效天數', 'Số ngày hiệu lực', 'Valid days'],
        'names' => ['獎項名稱（可留白自動產生）', 'Tên quà (để trống để tự tạo)', 'Prize names (leave blank to generate)'],
        'total' => ['啟用機率合計', 'Tổng tỷ lệ đang bật', 'Enabled odds total'],
        'save' => ['儲存小遊戲設定', 'Lưu cài đặt trò chơi', 'Save game settings'],
        'saved' => ['小遊戲設定已儲存。', 'Đã lưu cài đặt trò chơi.', 'Game settings saved.'],
        'stale' => ['設定已被其他人更新。請重新整理後再套用你的修改。', 'Cài đặt đã được người khác thay đổi. Tải lại trang rồi áp dụng chỉnh sửa.', 'Someone else changed these settings. Refresh the page before applying your changes.'],
        'invalid' => ['請檢查品項、杯型、金額與條件，並確認啟用機率合計為 100.00%。', 'Kiểm tra món, cỡ ly, số tiền, điều kiện và tổng tỷ lệ đang bật là 100,00%.', 'Check items, sizes, amounts and conditions, and ensure enabled odds total 100.00%.'],
        'weight_invalid' => ['機率須為 0 至 100%，最多兩位小數。', 'Tỷ lệ từ 0 đến 100%, tối đa hai chữ số thập phân.', 'Odds must be between 0 and 100%, with up to two decimal places.'],
        'upload_again' => ['尚未儲存。請修正設定，並重新選取剛上傳的圖片。', 'Chưa lưu. Sửa cài đặt và chọn lại hình vừa tải lên.', 'Not saved. Correct the settings and select any new pictures again.'],
        'save_failed' => ['目前無法儲存，請稍後重試。', 'Chưa thể lưu. Vui lòng thử lại sau.', 'Unable to save right now. Please try again shortly.'],
        'game_media_size' => ['圖片不可超過 2 MiB。', 'Hình không được vượt quá 2 MiB.', 'Pictures must not exceed 2 MiB.'],
        'game_media_type' => ['只接受真實 JPEG、PNG 或 WebP 圖片。', 'Chỉ nhận hình JPEG, PNG hoặc WebP hợp lệ.', 'Only valid JPEG, PNG or WebP pictures are accepted.'],
        'game_media_dimensions' => ['圖片長寬不可超過 4096 像素。', 'Mỗi cạnh của hình không được vượt quá 4096 pixel.', 'Picture dimensions must not exceed 4096 pixels per side.'],
        'game_media_invalid' => ['圖片資料無效，請重新選取。', 'Dữ liệu hình không hợp lệ. Hãy chọn lại.', 'Picture data is invalid. Select it again.'],
        'game_media_upload' => ['圖片上傳未完成，請重新選取。', 'Hình chưa tải lên xong. Hãy chọn lại.', 'The picture upload did not complete. Select it again.'],
        'file_error' => ['請選擇 JPEG、PNG 或 WebP，最大 2 MiB。', 'Chọn hình JPEG, PNG hoặc WebP, tối đa 2 MiB.', 'Choose a JPEG, PNG or WebP picture up to 2 MiB.'],
        'limit' => ['最多可設定 12 個獎項。', 'Tối đa 12 quà.', 'A maximum of 12 prizes is allowed.'],
        'luck' => ['祝你下次好運', 'Chúc bạn may mắn lần sau', 'Better luck next time'],
        'share_title' => ['分享 Facebook 的獎項', 'Quà khi khách chia sẻ Facebook', 'Facebook share prizes'],
        'share_hint' => ['客人抽到「祝你下次好運」後，把網站分享到 Facebook，系統會依機率從下列金額抽出一個折抵。至少需設定一個金額，且機率合計須為 100%。', 'Khi khách trúng "Chúc bạn may mắn lần sau" rồi chia sẻ web lên Facebook, hệ thống quay ngẫu nhiên một mức giảm trong danh sách này theo tỷ lệ. Cần ít nhất 1 mức, tổng tỷ lệ bằng 100%.', 'After a "better luck next time" result, a customer who shares the site on Facebook gets one discount drawn from this list by odds. At least one amount is required and the odds must total 100%.'],
        'share_add' => ['新增金額', 'Thêm mức giá', 'Add amount'],
        'share_amount' => ['折抵金額', 'Số tiền giảm', 'Discount amount'],
        'share_invalid' => ['分享獎項：至少一個金額，金額須為正整數，且機率合計須為 100.00%。', 'Quà chia sẻ: cần ít nhất 1 mức, số tiền phải là số nguyên dương và tổng tỷ lệ phải bằng 100,00%.', 'Share prizes: amounts must be positive whole numbers and the odds must total 100.00%.'],
    ];
    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    $index = ['zh' => 0, 'vi' => 1, 'en' => 2][$lang] ?? 1;
    return $copy[$key][$index] ?? $key;
}
function hgra_scalar(array $values, string $key, string $default = ''): string
{
    $value = $values[$key] ?? $default;
    return is_string($value) || is_int($value) || is_float($value) ? (string)$value : $default;
}
function hgra_integer(array $row, string $key, int $default = 0): int
{
    $value = hgra_scalar($row, $key, (string)$default);
    if (!preg_match('/\A[0-9]{1,10}\z/D', $value)) throw new InvalidArgumentException('invalid');
    return (int)$value;
}
/** Selected gift items from a multi-select; older forms post a single item_id. */
function hgra_item_ids(array $row): array
{
    $list = $row['item_ids'] ?? null;
    if ($list === null) return hgra_integer($row, 'item_id') > 0 ? [hgra_integer($row, 'item_id')] : [];
    if (!is_array($list) || count($list) > 50) throw new InvalidArgumentException('invalid');
    $ids = [];
    foreach ($list as $value) {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,10}\z/D', $value)) throw new InvalidArgumentException('invalid');
        if ((int)$value > 0) $ids[(int)$value] = (int)$value;
    }
    return array_values($ids);
}
/** Cup size posted for each selected gift drink. */
function hgra_item_sizes(array $row, array $ids): array
{
    $posted = is_array($row['item_sizes'] ?? null) ? $row['item_sizes'] : [];
    $sizes = [];
    foreach ($ids as $id) $sizes[$id] = hgra_scalar($posted, (string)$id, hgra_scalar($row, 'cup_size'));
    return $sizes;
}
function hgra_weight(string $value): int
{
    if (!preg_match('/\A([0-9]{1,3})(?:\.([0-9]{1,2}))?\z/D', $value, $parts)) throw new InvalidArgumentException('weight_invalid');
    $basis = (int)$parts[1] * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
    if ($basis > 10000) throw new InvalidArgumentException('weight_invalid');
    return $basis;
}
function hgra_candidate(array $post, array $base): array
{
    $rows = $post['prizes'] ?? null;
    if (!is_array($rows) || count($rows) < 1 || count($rows) > 12) throw new InvalidArgumentException('invalid');
    $prizes = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('invalid');
        $enabled = hgra_scalar($row, 'enabled', '0');
        if (!in_array($enabled, ['0', '1'], true)) throw new InvalidArgumentException('invalid');
        $names = is_array($row['name'] ?? null) ? $row['name'] : [];
        $prizes[] = ['id' => hgra_scalar($row, 'id'), 'kind' => hgra_scalar($row, 'kind'), 'enabled' => $enabled === '1', 'weight' => hgra_weight(hgra_scalar($row, 'weight_percent')), 'item_ids' => $itemIds = hgra_item_ids($row), 'item_sizes' => hgra_item_sizes($row, $itemIds), 'gift_qty' => hgra_integer($row, 'gift_qty', 1), 'cup_size' => hgra_scalar($row, 'cup_size'), 'amount' => hgra_integer($row, 'amount'), 'min_spend' => hgra_integer($row, 'min_spend'), 'min_paid_cups' => hgra_integer($row, 'min_paid_cups', 1), 'valid_days' => hgra_integer($row, 'valid_days', 7), 'name' => array_combine(['vi', 'zh', 'en'], array_map(fn($lang) => hgra_scalar($names, $lang), ['vi', 'zh', 'en']))];
    }
    // "Chúc bạn may mắn lần sau" lưu như quà giảm tiền 0đ: toàn bộ luồng game (không phát phiếu, mở lượt chia sẻ Facebook) đã dựa trên quà 0đ.
    foreach ($prizes as &$luckPrize) {
        if ($luckPrize['kind'] !== 'luck') continue;
        $luckPrize['kind'] = 'discount';
        $luckPrize['item_ids'] = [];
        $luckPrize['item_sizes'] = [];
        $luckPrize['gift_qty'] = 1;
        $luckPrize['cup_size'] = '';
        $luckPrize['amount'] = 0;
        $luckPrize['min_spend'] = 0;
        $luckPrize['min_paid_cups'] = 1;
        $luckPrize['valid_days'] = 7;
    }
    unset($luckPrize);
    $faces = array_values($base['faces']);
    $back = $base['back_image'];
    if (hgra_scalar($post, 'back_remove') === '1') $back = '';
    for ($i = 0; $i < 6; $i++) if (hgra_scalar($post, 'face_remove_' . $i) === '1') $faces[$i] = '';
    return ['theme' => hgra_scalar($post, 'theme'), 'back_image' => $back, 'faces' => $faces, 'prizes' => $prizes];
}
/** Danh sách quà chia sẻ Facebook từ form: [['amount' => int, 'weight' => basis 10000], ...]; rỗng = dùng mặc định. */
function hgra_share_candidate(array $post): array
{
    $rows = $post['share'] ?? [];
    if (!is_array($rows) || count($rows) < 1 || count($rows) > 10) throw new InvalidArgumentException('share_invalid');
    $list = [];
    $total = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('share_invalid');
        try {
            $amount = hgra_integer($row, 'amount');
            $weight = hgra_weight(hgra_scalar($row, 'weight_percent'));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('share_invalid');
        }
        if ($amount < 1 || $amount > 100000000) throw new InvalidArgumentException('share_invalid');
        $list[] = ['amount' => $amount, 'weight' => $weight];
        $total += $weight;
    }
    if ($total !== 10000) throw new InvalidArgumentException('share_invalid');
    return $list;
}
function hgra_clear_cache(): void
{
    foreach (['hgr_game_config', 'hgr_topping_id', 'hgr_drink_id', 'hgr_drink_size', 'hgr_share_prizes'] as $key) unset($GLOBALS['__drink_order_setting_cache'][$key]);
}
function hgr_admin_save(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'reward_settings') return hgra_save_legacy();
    if ($action !== 'game_settings') {
        http_response_code(400);
        return hgra_text('invalid');
    }
    $GLOBALS['__hgra_failed_post'] = $_POST;
    $created = [];
    $pdo = db();
    try {
        $revision = hgra_scalar($_POST, 'revision');
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $revision) || !hash_equals(hgr_config_revision(), $revision)) throw new InvalidArgumentException('stale');
        $candidate = hgr_validate_config(hgra_candidate($_POST, hgr_config()));
        $shareList = hgra_share_candidate($_POST);
        $uploads = [];
        foreach (array_merge(['back_upload'], array_map(fn($i) => 'face_upload_' . $i, range(0, 5))) as $field) {
            if (isset($_FILES[$field]) && !is_array($_FILES[$field])) throw new InvalidArgumentException('game_media_invalid');
            $checked = hgr_media_inspect_upload($_FILES[$field] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if ($checked) $uploads[$field] = $checked;
        }
        foreach ($uploads as $field => $checked) {
            $path = hgr_media_store($checked, $created);
            if ($field === 'back_upload') $candidate['back_image'] = $path;
            else $candidate['faces'][(int)substr($field, -1)] = $path;
        }
        $candidate = hgr_validate_config($candidate);
        $pdo->beginTransaction();
        // Serialize first saves as well as later edits with the same singleton setting lock.
        $pdo->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)')->execute(['hgr_game_config', '']);
        $pdo->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)')->execute(['hgr_share_prizes', '']);
        $keys = ['hgr_game_config', 'hgr_topping_id', 'hgr_drink_id', 'hgr_drink_size'];
        $lock = $pdo->prepare('SELECT setting_key,setting_value FROM settings WHERE setting_key IN (?,?,?,?) ORDER BY setting_key FOR UPDATE');
        $lock->execute($keys);
        $raw = $lock->fetchAll();
        hgra_clear_cache();
        foreach (['hgr_game_config' => '', 'hgr_topping_id' => '0', 'hgr_drink_id' => '0', 'hgr_drink_size' => 'L'] as $key => $default) $GLOBALS['__drink_order_setting_cache'][$key] = $default;
        foreach ($raw as $row) $GLOBALS['__drink_order_setting_cache'][$row['setting_key']] = (string)$row['setting_value'];
        if (!hash_equals(hgr_config_revision(), $revision)) throw new InvalidArgumentException('stale');
        $candidate = hgr_validate_config($candidate);
        $json = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 60000) throw new InvalidArgumentException('invalid');
        set_setting('hgr_game_config', $json);
        set_setting('hgr_share_prizes', $shareList ? json_encode($shareList, JSON_THROW_ON_ERROR) : '');
        $pdo->commit();
        unset($GLOBALS['__hgra_failed_post']);
        return hgra_text('saved');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        hgra_clear_cache();
        hgr_media_cleanup($created);
        if ($error instanceof InvalidArgumentException) {
            $key = $error->getMessage();
            $known = ['stale', 'share_invalid', 'weight_invalid', 'game_media_size', 'game_media_type', 'game_media_dimensions', 'game_media_invalid', 'game_media_upload'];
            $message = hgra_text(in_array($key, $known, true) ? $key : 'invalid');
        } else {
            error_log('minigame admin: unable to save settings');
            $message = hgra_text('save_failed');
        }
        return $message . ' ' . hgra_text('upload_again');
    }
}
function hgra_media_field(string $slot, string $path, string $label): void
{
    $isBack = $slot === 'back';
    $position = $isBack ? 0 : (int)$slot;
    $upload = $isBack ? 'back_upload' : 'face_upload_' . $position;
    $remove = $isBack ? 'back_remove' : 'face_remove_' . $position;
    $checked = hgra_scalar($GLOBALS['__hgra_failed_post'] ?? [], $remove) === '1';
    $valid = hgr_media_relative_valid($path);
    $symbols = ['✈', '◉', '○', '◇', '♧', '♡'];
?><div class="hgra-media" data-media-field>
        <div class="hgra-media-preview <?= $isBack ? 'hgra-back-preview' : '' ?>"><img data-media-preview <?= $valid ? 'src="' . e(base_url($path)) . '"' : '' ?> alt="<?= e($label) ?>" <?= $valid && !$checked ? '' : 'hidden' ?>><span data-media-default <?= $valid && !$checked ? 'hidden' : '' ?>><?= $isBack ? '✦' : $symbols[$position] ?></span></div><strong><?= e($label) ?></strong><label class="hgra-upload-label"><?= e(hgra_text('upload')) ?><input type="file" name="<?= e($upload) ?>" accept="image/jpeg,image/png,image/webp" data-media-upload></label><label class="hgra-check"><input type="checkbox" name="<?= e($remove) ?>" value="1" data-media-remove <?= $checked ? 'checked' : '' ?>><?= e(hgra_text('remove')) ?></label><small data-media-error role="status"></small>
    </div><?php
        }
        function hgra_share_row(string $index, string $amount, string $weight): void
        {
            $prefix = 'share[' . $index . ']';
            ?><div class="hgra-share-row" data-share-row>
        <label><?= e(hgra_text('share_amount')) ?><input type="number" name="<?= e($prefix) ?>[amount]" min="1" max="100000000" step="1" value="<?= e($amount) ?>" required inputmode="numeric"></label>
        <label><?= e(hgra_text('weight')) ?><input type="number" name="<?= e($prefix) ?>[weight_percent]" min="0" max="100" step="0.01" value="<?= e($weight) ?>" required data-share-weight inputmode="decimal"></label>
        <button type="button" class="btn small" data-share-delete><?= e(hgra_text('delete')) ?></button>
    </div><?php
        }
        function hgra_prize_form(string $index, array $prize, array $tops, array $teas): void
        {
            $prefix = 'prizes[' . $index . ']';
            $kind = hgra_scalar($prize, 'kind', 'discount');
            // Quà giảm tiền 0đ chính là "Chúc bạn may mắn lần sau".
            if ($kind === 'discount' && (int)hgra_scalar($prize, 'amount', '0') === 0) $kind = 'luck';
            $id = hgra_scalar($prize, 'id', 'prize_' . bin2hex(random_bytes(8)));
            $enabled = in_array($prize['enabled'] ?? true, [true, 1, '1'], true);
            $items = is_array($prize['item_ids'] ?? null) ? $prize['item_ids'] : [hgra_scalar($prize, 'item_id', '0')];
            $items = array_values(array_filter(array_map(fn($v) => is_scalar($v) ? (string)(int)$v : '0', $items), fn($v) => $v !== '0'));
            $size = hgra_scalar($prize, 'cup_size');
            // Cup size per gift drink; older settings only had one prize-wide cup_size.
            $itemSizes = [];
            foreach ($items as $itemId) $itemSizes[$itemId] = is_array($prize['item_sizes'] ?? null) ? hgra_scalar($prize['item_sizes'], $itemId, $size) : $size;
            $weight = hgra_scalar($prize, 'weight_percent', number_format(((int)($prize['weight'] ?? 0)) / 100, 2, '.', ''));
            ?><article class="hgra-prize" data-prize>
        <div class="hgra-prize-head">
            <h3><?= e(hgra_text('prize')) ?> <span data-prize-number></span></h3>
            <div><input type="hidden" name="<?= e($prefix) ?>[id]" value="<?= e($id) ?>"><input type="hidden" name="<?= e($prefix) ?>[enabled]" value="0"><label class="hgra-check"><input type="checkbox" name="<?= e($prefix) ?>[enabled]" value="1" data-prize-enabled <?= $enabled ? 'checked' : '' ?>><?= e(hgra_text('enabled')) ?></label><button type="button" class="btn small" data-prize-delete><?= e(hgra_text('delete')) ?></button></div>
        </div>
        <div class="hgra-prize-fields">
            <label><?= e(hgra_text('kind')) ?><select name="<?= e($prefix) ?>[kind]" data-prize-kind><?php foreach (['topping', 'tea', 'discount', 'luck'] as $value): ?><option value="<?= $value ?>" <?= $kind === $value ? 'selected' : '' ?>><?= e(hgra_text($value)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(hgra_text('weight')) ?><input type="number" name="<?= e($prefix) ?>[weight_percent]" min="0" max="100" step="0.01" value="<?= e($weight) ?>" required data-prize-weight inputmode="decimal"></label>
            <div class="hgra-field" data-prize-item-field><span><?= e(hgra_text('item')) ?></span>
                <select name="<?= e($prefix) ?>[item_ids][]" multiple size="6" data-prize-item data-selected="<?= e(json_encode($items)) ?>" data-item-sizes="<?= e(json_encode((object)$itemSizes)) ?>">
                    <?php $found = [];

                    foreach (['topping' => $tops, 'tea' => $teas] as $optionKind => $rows) :
                        foreach ($rows as $row): $selected = $optionKind === $kind && in_array((string)(int)$row['id'], $items, true);
                            if ($selected) $found[] = (string)(int)$row['id'];
                            $sizes = $optionKind === 'tea' ? (product_has_sizes($row) ? array_keys(product_size_options($row)) : ['']) : ['']; ?>
                            <option value="<?= (int)$row['id'] ?>" data-kind="<?= $optionKind ?>" data-sizes="<?= e(json_encode($sizes)) ?>"
                                <?= $selected ? 'selected' : '' ?>><?= e(localized_name($row)) ?>
                            </option>
                        <?php endforeach;
                    endforeach;

                    foreach (array_diff($items, $found) as $missing): ?>
                        <option value="<?= e($missing) ?>" data-kind="<?= e($kind) ?>" data-sizes="<?= e(json_encode([$size])) ?>" selected>
                            <?= e(hgra_text('unavailable')) ?> #<?= e($missing) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label data-prize-qty-field><?= e(hgra_text('gift_qty')) ?><input type="number" name="<?= e($prefix) ?>[gift_qty]" min="1" max="20" step="1" value="<?= e(hgra_scalar($prize, 'gift_qty', '1') ?: '1') ?>" inputmode="numeric"></label>
            <label data-prize-amount-field><?= e(hgra_text('amount')) ?><input type="number" name="<?= e($prefix) ?>[amount]" min="0" max="100000000" step="1" value="<?= e(hgra_scalar($prize, 'amount', '0')) ?>" inputmode="numeric"></label>
            <label data-prize-cond><?= e(hgra_text('min_spend')) ?><input type="number" name="<?= e($prefix) ?>[min_spend]" min="0" max="100000000" step="1" value="<?= e(hgra_scalar($prize, 'min_spend', '0')) ?>" required inputmode="numeric"></label>
            <label data-prize-cond><?= e(hgra_text('min_cups')) ?><input type="number" name="<?= e($prefix) ?>[min_paid_cups]" min="1" max="100" step="1" value="<?= e(hgra_scalar($prize, 'min_paid_cups', '1')) ?>" required inputmode="numeric"></label>
            <label data-prize-cond><?= e(hgra_text('days')) ?><input type="number" name="<?= e($prefix) ?>[valid_days]" min="1" max="365" step="1" value="<?= e(hgra_scalar($prize, 'valid_days', '7')) ?>" required inputmode="numeric"></label>
        </div>
        <details class="hgra-names">
            <summary><?= e(hgra_text('names')) ?></summary>
            <div class="hgra-name-grid"><?php $names = is_array($prize['name'] ?? null) ? $prize['name'] : [];
                                        foreach (['vi' => 'Tiếng Việt', 'zh' => '繁體中文', 'en' => 'English'] as $lang => $label): ?><label><?= $label ?><input name="<?= e($prefix) ?>[name][<?= $lang ?>]" maxlength="160" value="<?= e(hgra_scalar($names, $lang)) ?>"></label><?php endforeach; ?></div>
        </details>
    </article><?php
            }
            function hgr_admin_form(): void
            {
                $config = hgr_config();
                $post = $GLOBALS['__hgra_failed_post'] ?? null;
                $theme = is_array($post) ? hgra_scalar($post, 'theme', $config['theme']) : $config['theme'];
                $prizes = is_array($post) && is_array($post['prizes'] ?? null) ? array_values($post['prizes']) : $config['prizes'];
                $prizes = array_filter(array_slice($prizes, 0, 12), 'is_array');
                $revision = is_array($post) ? hgra_scalar($post, 'revision', hgr_config_revision()) : hgr_config_revision();
                // Quà chia sẻ Facebook: lấy từ form vừa gửi lỗi, nếu không thì từ cài đặt đã lưu.
                $shareRows = [];
                $shareSource = is_array($post) && is_array($post['share'] ?? null) ? array_values($post['share']) : array_map(fn($c) => ['amount' => $c['amount'], 'weight_percent' => number_format($c['weight'] / 100, 2, '.', '')], hymg_share_saved());
                foreach ($shareSource as $row) if (is_array($row)) $shareRows[] = ['amount' => hgra_scalar($row, 'amount'), 'weight_percent' => hgra_scalar($row, 'weight_percent')];
                $tops = db()->query('SELECT * FROM toppings WHERE active=1 AND price>0 ORDER BY sort_order,id')->fetchAll();
                $teas = db()->query('SELECT p.* FROM products p JOIN categories c ON c.id=p.category_id WHERE p.active=1 AND c.active=1 ORDER BY p.sort_order,p.id')->fetchAll();
                ?><section class="panel hgra-admin">
        <h2><?= e(hgra_text('settings')) ?></h2>
        <p class="field-hint"><?= e(hgra_text('hint')) ?></p><?php if (!hgr_ready()): ?><div class="alert"><?= e(hgr_text('prepare')) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" data-game-admin data-file-error="<?= e(hgra_text('file_error')) ?>" data-limit-error="<?= e(hgra_text('limit')) ?>" data-weight-error="<?= e(hgra_text('weight_invalid')) ?>" data-total-error="<?= e(hgra_text('prize_hint')) ?>" data-single="<?= e(hgra_text('single')) ?>" data-select="<?= e(hgra_text('select')) ?>" data-size-label="<?= e(hgra_text('size')) ?>" data-chosen="<?= e(hgra_text('chosen')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="game_settings"><input type="hidden" name="revision" value="<?= e($revision) ?>">
            <fieldset class="hgra-section">
                <legend><?= e(hgra_text('appearance')) ?></legend>
                <div class="hgra-themes"><?php foreach (['memory', 'garden', 'night'] as $value): ?><label class="hgra-theme hgra-theme-<?= $value ?>"><input type="radio" name="theme" value="<?= $value ?>" <?= $theme === $value ? 'checked' : '' ?> required><span class="hgra-theme-sample"><i></i><i></i><i></i></span><strong><?= e(hgra_text($value)) ?></strong></label><?php endforeach; ?></div>
                <p class="field-hint"><?= e(hgra_text('media_hint')) ?></p>
                <div class="hgra-media-grid"><?php hgra_media_field('back', $config['back_image'], hgra_text('back'));
                                                for ($i = 0; $i < 6; $i++) hgra_media_field((string)$i, $config['faces'][$i], hgra_text('pair') . ' ' . ($i + 1)); ?></div>
            </fieldset>
            <fieldset class="hgra-section">
                <legend><?= e(hgra_text('prizes')) ?></legend>
                <p class="field-hint"><?= e(hgra_text('prize_hint')) ?></p>
                <div data-prize-list><?php foreach ($prizes as $i => $prize) hgra_prize_form((string)$i, $prize, $tops, $teas); ?></div>
                <div class="hgra-prize-toolbar"><button class="btn" type="button" data-prize-add>＋ <?= e(hgra_text('add')) ?></button><output data-prize-total><?= e(hgra_text('total')) ?>: <strong data-prize-total-number>0.00%</strong></output></div>
                <p data-prize-error class="hgra-form-error" role="status" hidden></p>
            </fieldset>
            <fieldset class="hgra-section" data-share-section>
                <legend><?= e(hgra_text('share_title')) ?></legend>
                <p class="field-hint"><?= e(hgra_text('share_hint')) ?></p>
                <div data-share-list><?php foreach ($shareRows as $i => $shareRow) hgra_share_row((string)$i, $shareRow['amount'], $shareRow['weight_percent']); ?></div>
                <div class="hgra-prize-toolbar"><button class="btn" type="button" data-share-add>＋ <?= e(hgra_text('share_add')) ?></button><output data-share-total><?= e(hgra_text('total')) ?>: <strong data-share-total-number>0.00%</strong></output></div>
                <p data-share-error class="hgra-form-error" role="status" hidden><?= e(hgra_text('share_invalid')) ?></p>
                <template data-share-template><?php hgra_share_row('__SINDEX__', '', ''); ?></template>
            </fieldset><button class="btn primary" type="submit"><?= e(hgra_text('save')) ?></button>
            <template data-prize-template><?php hgra_prize_form('__INDEX__', ['id' => '__ID__', 'kind' => 'discount', 'enabled' => true, 'weight' => 0, 'amount' => 5000, 'min_spend' => 50000, 'min_paid_cups' => 1, 'valid_days' => 7], $tops, $teas); ?></template>
        </form>
    </section><?php
            }
            function hgra_save_legacy(): string
            {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
                verify_csrf();
                if (($_POST['action'] ?? '') !== 'reward_settings') {
                    http_response_code(400);
                    return hgr_text('invalid');
                }
                $top = filter_var($_POST['topping_id'] ?? null, FILTER_VALIDATE_INT);
                $tea = filter_var($_POST['drink_id'] ?? null, FILTER_VALIDATE_INT);
                $size = $_POST['drink_size'] ?? '';
                if (!$top || !$tea || !is_string($size) || !in_array($size, ['', 'L', 'XL'], true)) return hgr_text('invalid');
                $topRow = hym_reward_one('SELECT id FROM toppings WHERE id=? AND active=1 AND price>0', [$top]);
                $teaRow = hym_reward_one('SELECT p.* FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1 AND c.active=1', [$tea]);
                if (!$topRow || !$teaRow) return hgr_text('invalid');
                $prices = product_has_sizes($teaRow) ? product_size_options($teaRow) : ['' => (float)$teaRow['price']];
                if (!product_has_sizes($teaRow)) $size = '';
                if (!isset($prices[$size]) || $prices[$size] <= 0) return hgr_text('invalid');
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)')->execute(['hgr_game_config', '']);
                    $lock = $pdo->prepare('SELECT setting_key FROM settings WHERE setting_key IN (?,?,?,?) ORDER BY setting_key FOR UPDATE');
                    $lock->execute(['hgr_game_config', 'hgr_topping_id', 'hgr_drink_id', 'hgr_drink_size']);
                    $lock->fetchAll();
                    set_setting('hgr_topping_id', (string)$top);
                    set_setting('hgr_drink_id', (string)$tea);
                    set_setting('hgr_drink_size', $size);
                    $pdo->commit();
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    foreach (['hgr_topping_id', 'hgr_drink_id', 'hgr_drink_size'] as $key) unset($GLOBALS['__drink_order_setting_cache'][$key]);
                    throw $error;
                }
                return hgr_text('saved');
            }
