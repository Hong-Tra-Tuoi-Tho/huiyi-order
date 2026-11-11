<?php
/**
 * Home page banner slider (index.php) managed at admin/banners.php.
 *
 * Replaces the single banner kept in settings (home_banner_active / _image / _link_type / _target_id):
 * the first run copies that banner into home_banners so nothing is lost. Each banner links to a drink,
 * a standalone topping, an internal page of this site, or nowhere. Images live in uploads/ (banner-*.jpg|png|webp).
 */
if (!function_exists('db')) {
    http_response_code(404);
    exit;
}

class HomeBannerError extends RuntimeException {}

const HBN_LINK_TYPES = ['none', 'product', 'topping', 'url'];

function hbn_fail(string $key): never
{
    throw new HomeBannerError($key);
}

function hbn_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $marker = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $marker->execute(['home_banners_schema']);
    if ($marker->fetchColumn() !== '1') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS home_banners (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                image VARCHAR(255) NOT NULL,
                link_type VARCHAR(16) NOT NULL DEFAULT 'none',
                target_id INT UNSIGNED NULL,
                link_url VARCHAR(500) NOT NULL DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_home_banners_order (active, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // One-time copy of the old single banner, only into an empty table.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM home_banners')->fetchColumn();
        $oldImage = basename(trim(setting('home_banner_image', '')));
        if ($count === 0 && $oldImage !== '' && is_file(hbn_upload_dir() . '/' . $oldImage)) {
            $oldType = setting('home_banner_link_type', 'product') === 'topping' ? 'topping' : 'product';
            $oldTarget = (int) setting('home_banner_target_id', '0');
            $pdo->prepare(
                'INSERT INTO home_banners(image, link_type, target_id, link_url, sort_order, active, created_at, updated_at)
                 VALUES(?, ?, ?, \'\', 10, ?, NOW(), NOW())'
            )->execute([
                $oldImage,
                $oldTarget > 0 ? $oldType : 'none',
                $oldTarget > 0 ? $oldTarget : null,
                setting('home_banner_active', '0') === '1' ? 1 : 0,
            ]);
        }

        set_setting('home_banners_schema', '1');
    }
    $done = true;
}

function hbn_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads';
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/** Every banner, for the admin page, in slide order. */
function hbn_all(): array
{
    hbn_schema();
    return db()->query('SELECT * FROM home_banners ORDER BY sort_order, id')->fetchAll();
}

/** Banners shown on the home page, in slide order; each row gets 'url' ('' = not a link). */
function hbn_active(): array
{
    hbn_schema();
    $rows = db()->query('SELECT * FROM home_banners WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
    foreach ($rows as &$row) {
        $row['url'] = hbn_url($row);
    }
    unset($row);
    return $rows;
}

function hbn_get(int $id): ?array
{
    hbn_schema();
    $st = db()->prepare('SELECT * FROM home_banners WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Where a banner leads; '' when it is not a link. */
function hbn_url(array $banner): string
{
    $id = (int) ($banner['target_id'] ?? 0);
    switch ($banner['link_type'] ?? 'none') {
        case 'product':
            return $id > 0 ? base_url('product.php?id=' . $id) : '';
        case 'topping':
            return $id > 0 ? base_url('topping.php?id=' . $id) : '';
        case 'url':
            $path = (string) ($banner['link_url'] ?? '');
            return $path !== '' ? base_url($path) : '';
        default:
            return '';
    }
}

/** Seconds per slide (settings.home_banner_interval), 3–30, default 5. */
function hbn_interval(): int
{
    $seconds = (int) setting('home_banner_interval', '5');
    return ($seconds >= 3 && $seconds <= 30) ? $seconds : 5;
}

/** Image for link previews (og:image): the first visible banner, else the first banner at all. */
function hbn_preview_image(): string
{
    try {
        hbn_schema();
        $image = db()->query('SELECT image FROM home_banners ORDER BY active DESC, sort_order, id LIMIT 1')->fetchColumn();
        return is_string($image) ? $image : '';
    } catch (Throwable $e) {
        return '';
    }
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * A link inside this site, stored relative to base_url() (e.g. "group/" or "game.php").
 * Full URLs are accepted only when they point at this site; other hosts, "//host", "javascript:" etc. are refused.
 */
function hbn_internal_path(string $input): string
{
    $value = trim($input);
    if ($value === '') {
        hbn_fail('link_required');
    }

    $base = rtrim(base_url(''), '/') . '/';
    if (stripos($value, $base) === 0) {
        $value = substr($value, strlen($base));
    } elseif (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|\\\\)~i', $value)) {
        hbn_fail('link_external');
    }

    $value = ltrim($value, '/');
    if (strlen($value) > 500 || preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $value) || str_contains($value, '..')) {
        hbn_fail('link_invalid');
    }
    return $value;
}

/** Saved image name; replaces (and deletes) $current when a new file was sent in $field. */
function hbn_upload(string $field, string $current = ''): string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $current;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        hbn_fail('image_upload_failed');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        hbn_fail('image_too_large');
    }

    $info = getimagesize($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($allowed[$info['mime']])) {
        hbn_fail('image_type_error');
    }

    $dir = hbn_upload_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = 'banner-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        hbn_fail('image_upload_failed');
    }
    hbn_delete_file($current);
    return $name;
}

function hbn_delete_file(string $image): void
{
    $image = basename($image);
    if ($image !== '' && str_starts_with($image, 'banner-') && is_file(hbn_upload_dir() . '/' . $image)) {
        @unlink(hbn_upload_dir() . '/' . $image);
    }
}

/** link_type / target_id / link_url from the admin form. */
function hbn_link_from_input(array $input): array
{
    $type = $input['link_type'] ?? 'none';
    if (!is_string($type) || !in_array($type, HBN_LINK_TYPES, true)) {
        hbn_fail('link_invalid');
    }

    if ($type === 'product' || $type === 'topping') {
        $id = (int) ($input[$type . '_id'] ?? 0);
        $table = $type === 'product' ? 'products' : 'toppings';
        $st = db()->prepare("SELECT id FROM $table WHERE id = ?");
        $st->execute([$id]);
        if ($id < 1 || !$st->fetchColumn()) {
            hbn_fail('link_target_missing');
        }
        return ['link_type' => $type, 'target_id' => $id, 'link_url' => ''];
    }

    if ($type === 'url') {
        return ['link_type' => 'url', 'target_id' => null, 'link_url' => hbn_internal_path((string) ($input['link_url'] ?? ''))];
    }

    return ['link_type' => 'none', 'target_id' => null, 'link_url' => ''];
}

// ---------------------------------------------------------------------------
// Writing (admin/banners.php)
// ---------------------------------------------------------------------------

function hbn_create(array $input): int
{
    hbn_schema();
    $link = hbn_link_from_input($input);
    $image = hbn_upload('image');
    if ($image === '') {
        hbn_fail('image_required');
    }

    $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM home_banners')->fetchColumn();
    db()->prepare(
        'INSERT INTO home_banners(image, link_type, target_id, link_url, sort_order, active, created_at, updated_at)
         VALUES(?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([$image, $link['link_type'], $link['target_id'], $link['link_url'], $next, !empty($input['active']) ? 1 : 0]);
    return (int) db()->lastInsertId();
}

function hbn_update(int $id, array $input): void
{
    $banner = hbn_get($id);
    if (!$banner) {
        hbn_fail('missing');
    }
    $link = hbn_link_from_input($input);
    $image = hbn_upload('image', (string) $banner['image']);

    db()->prepare(
        'UPDATE home_banners
            SET image = ?, link_type = ?, target_id = ?, link_url = ?, active = ?, updated_at = NOW()
          WHERE id = ?'
    )->execute([$image, $link['link_type'], $link['target_id'], $link['link_url'], !empty($input['active']) ? 1 : 0, $id]);
}

function hbn_delete(int $id): void
{
    $banner = hbn_get($id);
    if (!$banner) {
        hbn_fail('missing');
    }
    db()->prepare('DELETE FROM home_banners WHERE id = ?')->execute([$id]);
    hbn_delete_file((string) $banner['image']);
}

/** Swaps a banner with its neighbour ($direction -1 = earlier slide, +1 = later), then renumbers 10, 20, 30… */
function hbn_move(int $id, int $direction): void
{
    $ids = array_map('intval', array_column(hbn_all(), 'id'));
    $index = array_search($id, $ids, true);
    if ($index === false) {
        hbn_fail('missing');
    }
    $swap = $index + ($direction < 0 ? -1 : 1);
    if (isset($ids[$swap])) {
        [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
    }

    $st = db()->prepare('UPDATE home_banners SET sort_order = ?, updated_at = NOW() WHERE id = ?');
    foreach ($ids as $position => $bannerId) {
        $st->execute([($position + 1) * 10, $bannerId]);
    }
}

function hbn_set_interval(mixed $seconds): void
{
    $value = filter_var($seconds, FILTER_VALIDATE_INT);
    if ($value === false || $value < 3 || $value > 30) {
        hbn_fail('interval_invalid');
    }
    set_setting('home_banner_interval', (string) $value);
}

// ---------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------

function hbn_t(string $key, array $vars = []): string
{
    static $map = [
        'vi' => [
            'title' => 'Banner trang chủ',
            'menu' => 'Banner',
            'intro' => 'Các banner đang bật sẽ tự chạy dạng slider ở đầu trang chủ. Tỉ lệ ảnh 16:9 (ví dụ 1920×1080), JPG/PNG/WebP, tối đa 8MB.',
            'interval' => 'Thời gian mỗi slide (giây)',
            'interval_help' => 'Từ 3 đến 30 giây. Slider dừng khi khách rê chuột vào hoặc khi máy bật giảm chuyển động.',
            'save_interval' => 'Lưu thời gian',
            'add' => 'Thêm banner',
            'image' => 'Ảnh banner',
            'image_replace' => 'Đổi ảnh (bỏ trống để giữ ảnh cũ)',
            'link' => 'Khi bấm vào banner',
            'link_none' => 'Không mở trang nào',
            'link_product' => 'Mở một đồ uống',
            'link_topping' => 'Mở một topping',
            'link_url' => 'Mở một trang trong web',
            'product' => 'Đồ uống',
            'topping' => 'Topping',
            'link_url_label' => 'Đường dẫn trong web',
            'link_url_help' => 'Ví dụ: group/ (đặt nhóm), game.php (mini game). Chỉ nhận trang thuộc web này.',
            'active' => 'Hiển thị trên trang chủ',
            'save' => 'Lưu',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
            'delete_confirm' => 'Xóa banner này? Ảnh cũng bị xóa.',
            'up' => 'Lên trước',
            'down' => 'Xuống sau',
            'order' => 'Thứ tự',
            'status' => 'Trạng thái',
            'on' => 'Đang hiện',
            'off' => 'Đang ẩn',
            'empty' => 'Chưa có banner nào.',
            'count' => '{shown} / {total} banner đang hiện',
            'saved' => 'Đã lưu banner.',
            'deleted' => 'Đã xóa banner.',
            'interval_saved' => 'Đã lưu thời gian chuyển slide.',
            'missing' => 'Banner không còn tồn tại.',
            'image_required' => 'Hãy chọn ảnh banner.',
            'image_too_large' => 'Ảnh lớn hơn 8MB.',
            'image_type_error' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
            'image_upload_failed' => 'Tải ảnh lên không được, hãy thử lại.',
            'link_invalid' => 'Đường dẫn không hợp lệ.',
            'link_required' => 'Hãy nhập đường dẫn.',
            'link_external' => 'Chỉ được link tới trang thuộc web này.',
            'link_target_missing' => 'Hãy chọn đồ uống / topping cần mở.',
            'interval_invalid' => 'Thời gian phải từ 3 đến 30 giây.',
            'storage' => 'Không lưu được, hãy thử lại.',
            'prev' => 'Banner trước',
            'next' => 'Banner sau',
            'go_to' => 'Xem banner {n}',
        ],
        'en' => [
            'title' => 'Home banners',
            'menu' => 'Banners',
            'intro' => 'Visible banners play as an auto slider at the top of the home page. Use 16:9 images (e.g. 1920×1080), JPG/PNG/WebP, up to 8MB.',
            'interval' => 'Seconds per slide',
            'interval_help' => '3 to 30 seconds. The slider pauses on hover and when the device asks for reduced motion.',
            'save_interval' => 'Save timing',
            'add' => 'Add banner',
            'image' => 'Banner image',
            'image_replace' => 'Replace image (leave empty to keep it)',
            'link' => 'When the banner is tapped',
            'link_none' => 'Do nothing',
            'link_product' => 'Open a drink',
            'link_topping' => 'Open a topping',
            'link_url' => 'Open a page of this site',
            'product' => 'Drink',
            'topping' => 'Topping',
            'link_url_label' => 'Page path',
            'link_url_help' => 'E.g. group/ (group order), game.php (mini game). Only pages of this site.',
            'active' => 'Show on the home page',
            'save' => 'Save',
            'edit' => 'Edit',
            'delete' => 'Delete',
            'delete_confirm' => 'Delete this banner? Its image is deleted too.',
            'up' => 'Move earlier',
            'down' => 'Move later',
            'order' => 'Order',
            'status' => 'Status',
            'on' => 'Visible',
            'off' => 'Hidden',
            'empty' => 'No banners yet.',
            'count' => '{shown} / {total} banners visible',
            'saved' => 'Banner saved.',
            'deleted' => 'Banner deleted.',
            'interval_saved' => 'Slide timing saved.',
            'missing' => 'This banner no longer exists.',
            'image_required' => 'Choose a banner image.',
            'image_too_large' => 'The image is larger than 8MB.',
            'image_type_error' => 'Only JPG, PNG or WebP images.',
            'image_upload_failed' => 'Upload failed, please try again.',
            'link_invalid' => 'Invalid link.',
            'link_required' => 'Enter a link.',
            'link_external' => 'Only pages of this site can be linked.',
            'link_target_missing' => 'Choose the drink / topping to open.',
            'interval_invalid' => 'Timing must be 3 to 30 seconds.',
            'storage' => 'Could not save, please try again.',
            'prev' => 'Previous banner',
            'next' => 'Next banner',
            'go_to' => 'Show banner {n}',
        ],
        'zh' => [
            'title' => '首頁橫幅',
            'menu' => '首頁橫幅',
            'intro' => '啟用的橫幅會在首頁上方自動輪播。建議 16:9（如 1920×1080），JPG/PNG/WebP，8MB 以內。',
            'interval' => '每張停留秒數',
            'interval_help' => '3～30 秒。滑鼠移入或裝置要求減少動態時會暫停。',
            'save_interval' => '儲存秒數',
            'add' => '新增橫幅',
            'image' => '橫幅圖片',
            'image_replace' => '更換圖片（留空保留原圖）',
            'link' => '點擊橫幅時',
            'link_none' => '不開啟頁面',
            'link_product' => '開啟飲品',
            'link_topping' => '開啟加料',
            'link_url' => '開啟本站頁面',
            'product' => '飲品',
            'topping' => '加料',
            'link_url_label' => '本站路徑',
            'link_url_help' => '例如 group/（團購）、game.php（小遊戲）。僅限本站頁面。',
            'active' => '在首頁顯示',
            'save' => '儲存',
            'edit' => '編輯',
            'delete' => '刪除',
            'delete_confirm' => '刪除此橫幅？圖片也會一併刪除。',
            'up' => '往前',
            'down' => '往後',
            'order' => '順序',
            'status' => '狀態',
            'on' => '顯示中',
            'off' => '已隱藏',
            'empty' => '尚無橫幅。',
            'count' => '顯示 {shown} / {total} 張',
            'saved' => '橫幅已儲存。',
            'deleted' => '橫幅已刪除。',
            'interval_saved' => '輪播秒數已儲存。',
            'missing' => '此橫幅已不存在。',
            'image_required' => '請選擇橫幅圖片。',
            'image_too_large' => '圖片超過 8MB。',
            'image_type_error' => '僅支援 JPG、PNG、WebP。',
            'image_upload_failed' => '上傳失敗，請再試一次。',
            'link_invalid' => '連結無效。',
            'link_required' => '請輸入連結。',
            'link_external' => '僅能連結本站頁面。',
            'link_target_missing' => '請選擇要開啟的飲品／加料。',
            'interval_invalid' => '秒數須為 3～30。',
            'storage' => '無法儲存，請再試一次。',
            'prev' => '上一張',
            'next' => '下一張',
            'go_to' => '第 {n} 張',
        ],
    ];
    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    $text = $map[$lang][$key] ?? $map['vi'][$key] ?? $key;
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}
