<?php
/**
 * Tracking log: ghi thao tác chính của khách, thống kê theo ngày, tra cứu cho admin.
 *
 * - 2 bảng: app_tracking_events (log thô) và tracking_daily_stats (thống kê ngày).
 * - Cấu hình nằm ở settings.tracking_config (JSON), không set cứng theo chức năng.
 * - Mốc thời gian lưu UTC. "Ngày" thống kê tính theo giờ Việt Nam (UTC+7).
 * - Sau mỗi lô event, các dòng thống kê bị ảnh hưởng được đếm lại từ log thô (không cần cron để tổng hợp).
 */

const HTK_VN_OFFSET = 25200; // UTC+7, giây
const HTK_MAX_BATCH = 100;
const HTK_MAX_AGE_SECONDS = 604800; // event cũ hơn 7 ngày bị coi như vừa phát sinh

/**
 * Quy tắc mặc định. kind: view (xem trang), click (bấm phần tử khớp selector),
 * minigame (lượt lật thẻ đầu tiên, tracker.js tự nhận), order (server ghi khi tạo đơn).
 */
function htk_default_rules(): array
{
    return [
        ['code' => 'v_menu', 'label' => 'Xem menu nước', 'url' => '/index', 'kind' => 'view', 'selector' => ''],
        ['code' => 'v_cart', 'label' => 'Xem giỏ hàng', 'url' => '/cart', 'kind' => 'view', 'selector' => ''],
        ['code' => 'v_checkout', 'label' => 'Xem trang thanh toán', 'url' => '/checkout', 'kind' => 'view', 'selector' => ''],
        ['code' => 'v_minigame', 'label' => 'Xem mini-game', 'url' => '/minigame', 'kind' => 'view', 'selector' => ''],
        ['code' => 'c_add', 'label' => 'Thêm nước vào giỏ', 'url' => '/product', 'kind' => 'click', 'selector' => '#customize .qty-row button[type="submit"]'],
        ['code' => 'c_cart', 'label' => 'Bấm chốt món', 'url' => '/cart', 'kind' => 'click', 'selector' => 'button[name="continue_to_checkout"]'],
        ['code' => 'c_send', 'label' => 'Bấm gửi đơn hàng', 'url' => '/checkout', 'kind' => 'click', 'selector' => '.checkout-submit'],
        ['code' => 'play_minigame', 'label' => 'Chơi mini-game', 'url' => '/minigame', 'kind' => 'minigame', 'selector' => ''],
        ['code' => 'order_success', 'label' => 'Đặt hàng thành công', 'url' => '/success', 'kind' => 'order', 'selector' => ''],
    ];
}

function htk_default_config(): array
{
    return [
        'enabled' => true,
        'retention_days' => 90,
        'max_events_per_device_per_min' => 60,
        'dedupe_seconds' => 60,
        'exit_warn_percent' => 30,
        'live_refresh_seconds' => 10,
        'rules' => htk_default_rules(),
        'funnels' => [
            ['id' => 'f_order', 'name' => 'Đặt hàng', 'steps' => ['v_menu', 'c_add', 'c_cart', 'c_send', 'order_success']],
        ],
    ];
}

/**
 * Làm sạch danh sách phễu: mỗi phễu có id, tên và các bước (mã thao tác còn tồn tại).
 * @param string[] $knownCodes mã các thao tác đang có
 */
function htk_clean_funnels($funnels, array $knownCodes): array
{
    $clean = [];
    $used = [];
    foreach (is_array($funnels) ? $funnels : [] as $item) {
        if (!is_array($item) || count($clean) >= 20) {
            continue;
        }
        $id = (string)($item['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $id) !== 1 || isset($used[$id])) {
            $id = 'f' . bin2hex(random_bytes(5));
        }
        $used[$id] = true;
        $name = trim((string)($item['name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 60) : 'Phễu mới';
        $steps = [];
        foreach (is_array($item['steps'] ?? null) ? $item['steps'] : [] as $code) {
            if (is_string($code) && in_array($code, $knownCodes, true) && count($steps) < 20) {
                $steps[] = $code;
            }
        }
        $clean[] = ['id' => $id, 'name' => $name, 'steps' => $steps];
    }
    return $clean;
}

/** Tạo 2 bảng + dòng cấu hình mặc định (chạy một lần, sau đó chỉ tốn 1 truy vấn đọc setting đã cache). */
function htk_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (setting('tracking_schema_version') === '2') {
        return;
    }
    if (db()->inTransaction()) {
        return;
    }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS app_tracking_events (
            id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            device_id   BINARY(16)       NOT NULL,
            member_id   BIGINT UNSIGNED  DEFAULT NULL,
            event_type  TINYINT UNSIGNED NOT NULL,
            page_code   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            action_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            event_key   BINARY(16)       NOT NULL,
            occurred_at DATETIME(3)      NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_event_key (event_key),
            KEY idx_time (occurred_at),
            KEY idx_device_time (device_id, occurred_at),
            KEY idx_member_time (member_id, occurred_at),
            CONSTRAINT chk_action CHECK (
                (event_type = 1 AND action_code IS NULL) OR
                (event_type = 2 AND action_code IS NOT NULL)
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS tracking_daily_stats (
            stat_date      DATE             NOT NULL,
            event_type     TINYINT UNSIGNED NOT NULL,
            page_code      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            action_code    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            total_events   BIGINT UNSIGNED  NOT NULL,
            unique_devices INT UNSIGNED     NOT NULL,
            unique_members INT UNSIGNED     NOT NULL,
            PRIMARY KEY (stat_date, event_type, page_code, action_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $saved = json_decode(setting('tracking_config'), true);
        if (!is_array($saved) || !isset($saved['rules'])) {
            // Chưa có cấu hình, hoặc cấu hình cũ (pages/actions) → dùng bộ quy tắc mặc định.
            set_setting('tracking_config', json_encode(htk_default_config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        set_setting('tracking_schema_version', '2');
    } catch (Throwable $error) {
        // Không để lỗi tracking làm hỏng trang đặt hàng.
    }
}

/**
 * Chuẩn hóa đường dẫn trang về dạng so khớp: bỏ domain, thư mục cài đặt, query, .php và /index cuối.
 * '/' hoặc '/index.php' → '/index'; '/group/' → '/group'; 'cart.php' → '/cart'.
 */
function htk_normalize_path(string $input): string
{
    $path = (string)parse_url(trim($input), PHP_URL_PATH);
    $base = rtrim(defined('APP_BASE_URL') ? (string)parse_url(APP_BASE_URL, PHP_URL_PATH) : '', '/');
    if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim(str_replace('\\', '/', $path), '/');
    $path = preg_replace('/\.php$/i', '', $path);
    $path = preg_replace('#/index$#', '', $path);
    if ($path === '' || $path === '/') {
        return '/index';
    }
    return substr($path, 0, 64);
}

/** Tách ô phần tử (mỗi dòng một selector) thành danh sách không trống, không trùng. */
function htk_selector_list(string $text): array
{
    $list = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line !== '' && !in_array($line, $list, true)) {
            $list[] = $line;
        }
    }
    return $list;
}

/** Làm sạch danh sách quy tắc đọc từ JSON đã lưu. */
function htk_clean_rules($list): array
{
    $rules = [];
    foreach (is_array($list) ? $list : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = (string)($item['code'] ?? '');
        $kind = (string)($item['kind'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $code) !== 1 || !in_array($kind, ['view', 'click', 'minigame', 'order'], true)) {
            continue;
        }
        $rules[] = [
            'code' => $code,
            'label' => (string)($item['label'] ?? $code),
            'url' => (string)($item['url'] ?? '/index'),
            'kind' => $kind,
            'selector' => (string)($item['selector'] ?? ''),
            'skip_dedupe' => !empty($item['skip_dedupe']),
        ];
    }
    return $rules;
}

/** Đọc cấu hình, bổ sung giá trị mặc định cho khóa thiếu. */
function htk_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $default = htk_default_config();
    $data = json_decode(setting('tracking_config'), true);
    if (!is_array($data)) {
        $data = [];
    }
    // Cấu hình cũ chỉ có một phễu ('funnel'): chuyển thành phễu đầu tiên.
    if (!isset($data['funnels']) && isset($data['funnel']) && is_array($data['funnel'])) {
        $data['funnels'] = [['id' => 'f_order', 'name' => 'Phễu mặc định', 'steps' => $data['funnel']]];
    }
    unset($data['funnel']);
    $config = array_merge($default, $data);
    $config['enabled'] = !empty($config['enabled']);
    $config['retention_days'] = max(1, min(3650, (int)$config['retention_days']));
    $config['max_events_per_device_per_min'] = max(1, min(10000, (int)$config['max_events_per_device_per_min']));
    $config['dedupe_seconds'] = max(0, min(86400, (int)$config['dedupe_seconds']));
    $config['exit_warn_percent'] = max(0, min(100, (int)$config['exit_warn_percent']));
    $refresh = (int)$config['live_refresh_seconds'];
    $config['live_refresh_seconds'] = $refresh <= 0 ? 0 : max(5, min(3600, $refresh));
    $config['rules'] = htk_clean_rules($config['rules']);
    $codes = array_column($config['rules'], 'code');
    $config['funnels'] = htk_clean_funnels($config['funnels'], $codes);
    return $config;
}

function htk_enabled(): bool
{
    return htk_config()['enabled'];
}

function htk_rules(): array
{
    return htk_config()['rules'];
}

function htk_rule(string $code): ?array
{
    foreach (htk_rules() as $rule) {
        if ($rule['code'] === $code) {
            return $rule;
        }
    }
    return null;
}

/** 1 = xem trang, 2 = thao tác (bấm nút, chơi game, đặt hàng). */
function htk_rule_type(array $rule): int
{
    return $rule['kind'] === 'view' ? 1 : 2;
}

/** Tên hiển thị của một dòng log/thống kê (event_type, page_code, action_code). */
function htk_event_label(array $row): string
{
    $type = (int)$row['event_type'];
    foreach (htk_rules() as $rule) {
        if ($type === 1 && $rule['kind'] === 'view' && $rule['url'] === (string)$row['page_code']) {
            return $rule['label'];
        }
        if ($type === 2 && $rule['kind'] !== 'view' && $rule['code'] === (string)$row['action_code']) {
            return $rule['label'];
        }
    }
    return $type === 1 ? (string)$row['page_code'] : (string)$row['action_code'] . ' (' . (string)$row['page_code'] . ')';
}

/** Kiểm tra JSON cấu hình. Trả về chuỗi lỗi, rỗng nếu hợp lệ. */
function htk_validate_config(string $json): string
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return 'Cấu hình không hợp lệ.';
    }
    $days = (int)($data['retention_days'] ?? 0);
    if ($days < 1 || $days > 3650) {
        return 'Số ngày giữ log phải từ 1 đến 3650.';
    }
    $perMinute = (int)($data['max_events_per_device_per_min'] ?? 0);
    if ($perMinute < 1 || $perMinute > 10000) {
        return 'Giới hạn event mỗi phút phải từ 1 đến 10000.';
    }
    $dedupe = (int)($data['dedupe_seconds'] ?? -1);
    if ($dedupe < 0 || $dedupe > 86400) {
        return 'Số giây chống ghi lặp phải từ 0 đến 86400.';
    }
    $warn = (int)($data['exit_warn_percent'] ?? 30);
    if ($warn < 0 || $warn > 100) {
        return 'Ngưỡng cảnh báo tỷ lệ thoát phải từ 0 đến 100.';
    }
    $refresh = (int)($data['live_refresh_seconds'] ?? 10);
    if ($refresh !== 0 && ($refresh < 5 || $refresh > 3600)) {
        return 'Chu kỳ tự cập nhật phải là 0 (tắt) hoặc từ 5 đến 3600 giây.';
    }
    if (!isset($data['rules']) || !is_array($data['rules'])) {
        return 'Thiếu danh sách thao tác.';
    }
    $codes = [];
    $viewUrls = [];
    $gameUrls = [];
    $hasOrder = false;
    foreach ($data['rules'] as $rule) {
        $label = is_array($rule) ? trim((string)($rule['label'] ?? '')) : '';
        $name = '«' . $label . '»';
        if ($label === '' || mb_strlen($label) > 100) {
            return 'Mỗi thao tác cần có mô tả (tối đa 100 ký tự).';
        }
        $kind = (string)($rule['kind'] ?? '');
        $code = (string)($rule['code'] ?? '');
        $url = (string)($rule['url'] ?? '');
        if (!in_array($kind, ['view', 'click', 'minigame', 'order'], true) || preg_match('/^[A-Za-z0-9_]{1,32}$/', $code) !== 1 || isset($codes[$code])) {
            return 'Thao tác ' . $name . ' không hợp lệ.';
        }
        $codes[$code] = true;
        if (preg_match('#^/[!-~]{0,63}$#', $url) !== 1 || $url === '') {
            return 'Thao tác ' . $name . ': URL không hợp lệ.';
        }
        if ($kind === 'click' && (trim((string)($rule['selector'] ?? '')) === '' || strlen((string)$rule['selector']) > 1000)) {
            return 'Thao tác ' . $name . ': chưa chọn phần tử (hoặc danh sách phần tử dài quá 1000 ký tự).';
        }
        if ($kind === 'order') {
            if ($hasOrder) {
                return 'Chỉ cần một thao tác "Đặt hàng thành công".';
            }
            $hasOrder = true;
        }
        if ($kind === 'minigame') {
            if (isset($gameUrls[$url])) {
                return 'Trang ' . $url . ' đã có thao tác "Chơi mini-game", mỗi trang chỉ cần một dòng.';
            }
            $gameUrls[$url] = true;
        }
        if ($kind === 'view') {
            if (isset($viewUrls[$url])) {
                return 'Trang ' . $url . ' đã có thao tác xem trang khác, mỗi trang chỉ cần một dòng.';
            }
            $viewUrls[$url] = true;
        }
    }
    foreach ((is_array($data['funnels'] ?? null) ? $data['funnels'] : []) as $funnel) {
        foreach ((is_array($funnel['steps'] ?? null) ? $funnel['steps'] : []) as $code) {
            if (!is_string($code) || !isset($codes[$code])) {
                return 'Bước phễu trỏ tới thao tác không tồn tại.';
            }
        }
    }
    return '';
}

/**
 * Dựng cấu hình từ form admin. Dòng trống bị bỏ; URL được chuẩn hóa;
 * thao tác mới (chưa có mã) tự sinh mã. Admin có thể xóa/sửa mọi dòng.
 */
function htk_config_from_form(array $post): array
{
    $field = function (string $key) use ($post): array {
        return is_array($post[$key] ?? null) ? array_values($post[$key]) : [];
    };
    $codes = $field('rules_code');
    $labels = $field('rules_label');
    $urls = $field('rules_url');
    $kinds = $field('rules_kind');
    $selectors = $field('rules_selector');
    $dedupes = $field('rules_dedupe');
    $rules = [];
    $used = [];
    $count = max(count($codes), count($labels), count($urls), count($selectors));
    for ($i = 0; $i < $count; $i++) {
        $label = trim((string)($labels[$i] ?? ''));
        $url = trim((string)($urls[$i] ?? ''));
        $selector = implode("\n", htk_selector_list((string)($selectors[$i] ?? '')));
        if ($label === '' && $url === '' && $selector === '') {
            continue;
        }
        $code = trim((string)($codes[$i] ?? ''));
        $kind = (string)($kinds[$i] ?? '');
        if (!in_array($kind, ['view', 'click', 'minigame', 'order'], true)) {
            $kind = 'view';
        }
        if ($kind !== 'click') {
            $selector = '';
        }
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $code) !== 1 || isset($used[$code])) {
            $code = 'r' . bin2hex(random_bytes(5));
        }
        $used[$code] = true;
        $rules[] = [
            'code' => $code,
            'label' => $label !== '' ? $label : $url,
            'url' => $url !== '' ? htk_normalize_path($url) : '',
            'kind' => $kind,
            'selector' => $selector,
            'skip_dedupe' => (string)($dedupes[$i] ?? '1') === '0',
        ];
    }
    // Form này không sửa phễu: giữ các phễu đang lưu, chỉ bỏ bước của thao tác đã xóa.
    $funnels = htk_clean_funnels(htk_config()['funnels'], array_keys($used));
    return [
        'enabled' => isset($post['enabled']),
        'retention_days' => (int)($post['retention_days'] ?? 0),
        'max_events_per_device_per_min' => (int)($post['max_events_per_device_per_min'] ?? 0),
        'dedupe_seconds' => (int)($post['dedupe_seconds'] ?? 0),
        'exit_warn_percent' => (int)($post['exit_warn_percent'] ?? 30),
        'live_refresh_seconds' => (int)($post['live_refresh_seconds'] ?? 10),
        'rules' => $rules,
        'funnels' => $funnels,
    ];
}

// ---------------------------------------------------------------------------
// Định danh
// ---------------------------------------------------------------------------

function htk_hex_ok($value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1;
}

/** UUID dạng có hoặc không có dấu gạch → 32 ký tự hex thường, hoặc '' nếu sai. */
function htk_normalize_uuid($value): string
{
    if (!is_string($value)) {
        return '';
    }
    $hex = strtolower(str_replace('-', '', $value));
    return htk_hex_ok($hex) ? $hex : '';
}

/** Đường dẫn trang hiện tại đã chuẩn hóa, ví dụ index.php → /index, minigame/index.php → /minigame. */
function htk_current_page_code(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    return $script === '' ? '' : htk_normalize_path($script);
}

function htk_session_member_id(): ?int
{
    $id = (int)($_SESSION['member_id'] ?? 0);
    return $id > 0 ? $id : null;
}

// ---------------------------------------------------------------------------
// Ghi log
// ---------------------------------------------------------------------------

/** Chuyển mili-giây từ web thành DATETIME(3) UTC; ngoài khoảng hợp lý thì dùng giờ server. */
function htk_time_string($millis, float $now): string
{
    $seconds = $now;
    if (is_int($millis) || is_float($millis)) {
        $candidate = $millis / 1000;
        if ($candidate <= $now + 300 && $candidate >= $now - HTK_MAX_AGE_SECONDS) {
            $seconds = $candidate;
        }
    }
    $whole = (int)floor($seconds);
    $ms = (int)round(($seconds - $whole) * 1000);
    if ($ms > 999) {
        $ms = 999;
    }
    return gmdate('Y-m-d H:i:s', $whole) . '.' . str_pad((string)$ms, 3, '0', STR_PAD_LEFT);
}

/**
 * Ghi một lô event từ web. member_id do server lấy từ session.
 * @param array[] $events mỗi phần tử: key, type, page, action, at(ms)
 * @return string[] các event_key đã xử lý xong (web được xóa khỏi hàng đợi)
 */
function htk_ingest(string $deviceHex, array $events): array
{
    $handled = [];
    if (!htk_enabled() || !htk_hex_ok($deviceHex)) {
        foreach ($events as $event) {
            $key = htk_normalize_uuid($event['key'] ?? '');
            if ($key !== '') {
                $handled[] = $key;
            }
        }
        return $handled;
    }
    $config = htk_config();
    $viewByUrl = [];
    $actionByCode = [];
    foreach (htk_rules() as $rule) {
        if ($rule['kind'] === 'view') {
            $viewByUrl[$rule['url']] = $rule;
        } elseif ($rule['kind'] === 'click' || $rule['kind'] === 'minigame') {
            $actionByCode[$rule['code']] = $rule; // order_success chỉ do server ghi nên không nằm ở đây
        }
    }
    $memberId = htk_session_member_id();
    $now = microtime(true);
    $rows = [];
    $seen = [];
    foreach (array_slice($events, 0, HTK_MAX_BATCH) as $event) {
        if (!is_array($event)) {
            continue;
        }
        $key = htk_normalize_uuid($event['key'] ?? '');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $type = (int)($event['type'] ?? 0);
        $page = (string)($event['page'] ?? '');
        $action = (string)($event['action'] ?? '');
        $valid = false;
        if ($type === 1 && isset($viewByUrl[$page])) {
            $valid = true;
            $action = '';
        } elseif ($type === 2 && isset($actionByCode[$action]) && $actionByCode[$action]['url'] === $page) {
            $valid = true;
        }
        if (!$valid) {
            $handled[] = $key;
            continue;
        }
        $rows[] = [
            'key' => $key,
            'type' => $type,
            'page' => $page,
            'action' => $type === 2 ? $action : null,
            'at' => htk_time_string($event['at'] ?? null, $now),
        ];
    }
    if (!$rows) {
        return $handled;
    }
    // Chống ghi lặp: cùng thiết bị + cùng thao tác trong N giây chỉ giữ một lượt.
    if ($config['dedupe_seconds'] > 0) {
        [$rows, $repeated] = htk_drop_repeats($deviceHex, $rows, $config['dedupe_seconds']);
        foreach ($repeated as $key) {
            $handled[] = $key;
        }
        if (!$rows) {
            return $handled;
        }
    }
    // Giới hạn số event của một thiết bị trong 1 phút.
    $limit = $config['max_events_per_device_per_min'];
    $st = db()->prepare('SELECT COUNT(*) FROM app_tracking_events WHERE device_id=? AND occurred_at>=?');
    $st->execute([hex2bin($deviceHex), gmdate('Y-m-d H:i:s', (int)$now - 60)]);
    $room = max(0, $limit - (int)$st->fetchColumn());
    if ($room < count($rows)) {
        $rows = array_slice($rows, 0, $room);
    }
    foreach (htk_insert_rows($deviceHex, $memberId, $rows) as $key) {
        $handled[] = $key;
    }
    return $handled;
}

/** Giây (có phần lẻ) của mốc 'Y-m-d H:i:s.v' UTC. */
function htk_time_float(string $utc): float
{
    return strtotime(substr($utc, 0, 19) . ' UTC') + (int)substr($utc, 20, 3) / 1000;
}

/**
 * Bỏ các lượt lặp: cùng thiết bị + cùng thao tác (loại, trang, mã) cách nhau dưới $window giây,
 * so với log đã có và với các lượt khác trong cùng lô.
 * @return array{0: array[], 1: string[]} [các dòng giữ lại, các event_key bị bỏ]
 */
function htk_drop_repeats(string $deviceHex, array $rows, int $window): array
{
    usort($rows, function (array $a, array $b): int {
        return strcmp($a['at'], $b['at']);
    });
    $since = gmdate('Y-m-d H:i:s', (int)htk_time_float($rows[0]['at']) - $window);
    $st = db()->prepare('SELECT event_type,page_code,action_code,MAX(occurred_at) AS last_at FROM app_tracking_events
        WHERE device_id=? AND occurred_at>=? GROUP BY event_type,page_code,action_code');
    $st->execute([hex2bin($deviceHex), $since]);
    $last = [];
    foreach ($st->fetchAll() as $row) {
        $last[$row['event_type'] . '|' . $row['page_code'] . '|' . (string)$row['action_code']] = htk_time_float((string)$row['last_at']);
    }
    $kept = [];
    $dropped = [];
    foreach ($rows as $row) {
        $id = $row['type'] . '|' . $row['page'] . '|' . (string)$row['action'];
        $time = htk_time_float($row['at']);
        $rule = (int)$row['type'] === 2 ? htk_rule((string)$row['action']) : null;
        if ($rule !== null && !empty($rule['skip_dedupe'])) {
            $kept[] = $row; // thao tác được admin tắt chống trùng: luôn ghi
            continue;
        }
        if (isset($last[$id]) && abs($time - $last[$id]) < $window) {
            $dropped[] = $row['key'];
            continue;
        }
        $last[$id] = $time;
        $kept[] = $row;
    }
    return [$kept, $dropped];
}

/**
 * INSERT nhiều dòng bằng một câu, rồi đếm lại thống kê của các dòng bị ảnh hưởng.
 * @return string[] event_key của các dòng đã xử lý
 */
function htk_insert_rows(string $deviceHex, ?int $memberId, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $sql = 'INSERT IGNORE INTO app_tracking_events(device_id,member_id,event_type,page_code,action_code,event_key,occurred_at) VALUES ';
    $marks = [];
    $args = [];
    $affected = [];
    $keys = [];
    $device = hex2bin($deviceHex);
    foreach ($rows as $row) {
        $marks[] = '(?,?,?,?,?,?,?)';
        array_push($args, $device, $memberId, $row['type'], $row['page'], $row['action'], hex2bin($row['key']), $row['at']);
        $keys[] = $row['key'];
        $date = htk_stat_date($row['at']);
        $affected[$date . '|' . $row['type'] . '|' . $row['page'] . '|' . (string)$row['action']] = [$date, $row['type'], $row['page'], (string)$row['action']];
    }
    db()->prepare($sql . implode(',', $marks))->execute($args);
    foreach ($affected as $target) {
        htk_refresh_stat($target[0], $target[1], $target[2], $target[3]);
    }
    return $keys;
}

/** Ngày Việt Nam của một mốc UTC dạng 'Y-m-d H:i:s.v'. */
function htk_stat_date(string $utc): string
{
    $time = strtotime(substr($utc, 0, 19) . ' UTC');
    return gmdate('Y-m-d', $time + HTK_VN_OFFSET);
}

/** Khoảng UTC [từ, đến) của một ngày Việt Nam. */
function htk_day_bounds(string $date): array
{
    $start = strtotime($date . ' 00:00:00 UTC') - HTK_VN_OFFSET;
    return [gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $start + 86400)];
}

/** Đếm lại một dòng thống kê từ log thô rồi ghi đè. */
function htk_refresh_stat(string $date, int $type, string $page, string $action): void
{
    [$from, $to] = htk_day_bounds($date);
    $sql = 'SELECT COUNT(*) AS total, COUNT(DISTINCT device_id) AS devices, COUNT(DISTINCT member_id) AS members
        FROM app_tracking_events WHERE occurred_at>=? AND occurred_at<? AND event_type=? AND page_code=?';
    $args = [$from, $to, $type, $page];
    if ($type === 2) {
        $sql .= ' AND action_code=?';
        $args[] = $action;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    $count = $st->fetch();
    $upsert = db()->prepare('INSERT INTO tracking_daily_stats(stat_date,event_type,page_code,action_code,total_events,unique_devices,unique_members)
        VALUES(?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE total_events=VALUES(total_events), unique_devices=VALUES(unique_devices), unique_members=VALUES(unique_members)');
    $upsert->execute([$date, $type, $page, $type === 2 ? $action : '', (int)$count['total'], (int)$count['devices'], (int)$count['members']]);
}

/**
 * Ghi một event từ phía server cho thao tác loại 'order' (đặt hàng thành công) nếu admin có cấu hình.
 * Thiết bị lấy từ cookie htk_did do tracker.js đặt; không có thì suy ra từ session.
 */
function htk_server_event(string $kind, ?int $memberId = null): void
{
    try {
        $rule = null;
        foreach (htk_rules() as $candidate) {
            if ($candidate['kind'] === $kind) {
                $rule = $candidate;
                break;
            }
        }
        if (!htk_enabled() || $rule === null || $kind !== 'order') {
            return;
        }
        $page = $rule['url'];
        $action = $rule['code'];
        $device = htk_normalize_uuid($_COOKIE['htk_did'] ?? '');
        if ($device === '') {
            $device = md5('session|' . session_id());
        }
        $now = microtime(true);
        htk_insert_rows($device, $memberId ?? htk_session_member_id(), [[
            'key' => bin2hex(random_bytes(16)),
            'type' => 2,
            'page' => $page,
            'action' => $action,
            'at' => htk_time_string(null, $now),
        ]]);
    } catch (Throwable $error) {
        // Tracking lỗi không được làm hỏng việc đặt hàng.
    }
}

// ---------------------------------------------------------------------------
// Dọn log cũ
// ---------------------------------------------------------------------------

/** Xóa log thô quá hạn giữ. Thống kê ngày giữ vĩnh viễn. Trả về số dòng đã xóa. */
function htk_cleanup(): int
{
    $days = htk_config()['retention_days'];
    $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $total = 0;
    do {
        $st = db()->prepare('DELETE FROM app_tracking_events WHERE occurred_at<? LIMIT 5000');
        $st->execute([$cut]);
        $count = $st->rowCount();
        $total += $count;
    } while ($count === 5000);
    set_setting('tracking_last_cleanup', gmdate('Y-m-d'));
    return $total;
}

/** Dọn tối đa một lần mỗi ngày khi admin mở dashboard (dự phòng nếu chưa cài cron). */
function htk_cleanup_daily(): void
{
    if (setting('tracking_last_cleanup') !== gmdate('Y-m-d')) {
        htk_cleanup();
    }
}

// ---------------------------------------------------------------------------
// Đọc cho dashboard
// ---------------------------------------------------------------------------

/** Số liệu từng dòng thống kê trong khoảng ngày (cộng total_events qua các ngày). */
function htk_range_totals(string $fromDate, string $toDate): array
{
    $st = db()->prepare('SELECT event_type,page_code,action_code,SUM(total_events) AS total
        FROM tracking_daily_stats WHERE stat_date BETWEEN ? AND ? GROUP BY event_type,page_code,action_code ORDER BY total DESC');
    $st->execute([$fromDate, $toDate]);
    return $st->fetchAll();
}

/** Số thiết bị / thành viên khác nhau trong cả khoảng, đếm từ log thô. */
function htk_range_unique(string $fromDate, string $toDate): array
{
    [$from] = htk_day_bounds($fromDate);
    [, $to] = htk_day_bounds($toDate);
    $st = db()->prepare('SELECT COUNT(DISTINCT device_id) AS devices, COUNT(DISTINCT member_id) AS members
        FROM app_tracking_events WHERE occurred_at>=? AND occurred_at<?');
    $st->execute([$from, $to]);
    $row = $st->fetch();
    return ['devices' => (int)$row['devices'], 'members' => (int)$row['members']];
}

/** Từng ngày: tổng event và số thiết bị (cộng mọi dòng xem trang + bấm nút của ngày là tổng lượt). */
function htk_daily_series(string $fromDate, string $toDate): array
{
    $st = db()->prepare('SELECT stat_date,
            SUM(CASE WHEN event_type=1 THEN total_events ELSE 0 END) AS page_views,
            SUM(CASE WHEN event_type=2 THEN total_events ELSE 0 END) AS clicks
        FROM tracking_daily_stats WHERE stat_date BETWEEN ? AND ? GROUP BY stat_date ORDER BY stat_date DESC');
    $st->execute([$fromDate, $toDate]);
    return $st->fetchAll();
}

/**
 * Các phễu theo hành trình thật của từng thiết bị. Duyệt log theo thời gian: một hành trình bắt đầu ở bước 1 và chỉ
 * tiến lên khi gặp đúng bước kế tiếp; lặp lại bước đang đứng (bấm thêm nước nhiều lần, xem lại trang) hoặc nhảy cóc thì
 * bỏ qua; đi hết bước cuối thì hành trình đóng lại và lần sau bắt đầu hành trình mới.
 * Mỗi bước trả về:
 *   events    số hành trình đã đi tới bước này (đơn vị chung cho cột biểu đồ, tỷ lệ thoát, chuyển đổi),
 *   raw       tổng số lượt thật của thao tác (bấm thêm nước 5 lần là 5), để biết khách bấm bao nhiêu lần,
 *   devices   số thiết bị khác nhau có hành trình đi tới bước này,
 *   percent   devices so với bước đầu, conversion / missed: events so với bước trước.
 * Nên số ở bước sau không bao giờ lớn hơn bước trước.
 */
function htk_funnel(string $fromDate, string $toDate): array
{
    [$from] = htk_day_bounds($fromDate);
    [, $to] = htk_day_bounds($toDate);
    $funnels = [];
    foreach (htk_config()['funnels'] as $funnel) {
        $ruleList = [];
        foreach ($funnel['steps'] as $code) {
            $rule = htk_rule($code);
            if ($rule !== null) {
                $ruleList[] = $rule;
            }
        }
        $count = count($ruleList);
        $steps = [];
        if ($count > 0) {
            $map = [];
            $pages = [];
            $actions = [];
            foreach ($ruleList as $index => $rule) {
                $type = htk_rule_type($rule);
                $value = $type === 1 ? $rule['url'] : $rule['code'];
                $map[$type . '|' . $value][] = $index;
                if ($type === 1) {
                    $pages[$value] = true;
                } else {
                    $actions[$value] = true;
                }
            }
            $conditions = [];
            $args = [$from, $to];
            if ($pages) {
                $conditions[] = '(event_type=1 AND page_code IN (' . implode(',', array_fill(0, count($pages), '?')) . '))';
                $args = array_merge($args, array_keys($pages));
            }
            if ($actions) {
                $conditions[] = '(event_type=2 AND action_code IN (' . implode(',', array_fill(0, count($actions), '?')) . '))';
                $args = array_merge($args, array_keys($actions));
            }
            $st = db()->prepare('SELECT device_id,event_type,page_code,action_code FROM app_tracking_events
                WHERE occurred_at>=? AND occurred_at<? AND (' . implode(' OR ', $conditions) . ')
                ORDER BY device_id,occurred_at,id');
            $st->execute($args);
            $reach = array_fill(0, $count, 0);
            $raw = [];
            $deviceSets = array_fill(0, $count, []);
            $current = null;
            $pos = -1;
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                if ($row[0] !== $current) {
                    $current = $row[0];
                    $pos = -1;
                }
                $type = (int)$row[1];
                $key = $type . '|' . ($type === 1 ? $row[2] : $row[3]);
                if (!isset($map[$key])) {
                    continue;
                }
                $raw[$key] = ($raw[$key] ?? 0) + 1; // mọi lượt thật của thao tác, không phụ thuộc thứ tự bước
                if (!in_array($pos + 1, $map[$key], true)) {
                    continue;
                }
                $pos++;
                $reach[$pos]++;
                $deviceSets[$pos][$row[0]] = true;
                if ($pos === $count - 1) {
                    $pos = -1; // đi hết phễu: hành trình sau là hành trình mới
                }
            }
            $first = count($deviceSets[0]);
            foreach ($ruleList as $index => $rule) {
                $devices = count($deviceSets[$index]);
                $previous = $index > 0 ? $reach[$index - 1] : null;
                $rawType = htk_rule_type($rule);
                $steps[] = [
                    'code' => $rule['code'],
                    'label' => $rule['label'],
                    'kind' => $rule['kind'],
                    'raw' => $raw[$rawType . '|' . ($rawType === 1 ? $rule['url'] : $rule['code'])] ?? 0,
                    'devices' => $devices,
                    'events' => $reach[$index],
                    'percent' => $first > 0 ? round($devices * 100 / $first, 1) : 0,
                    'conversion' => ($previous !== null && $previous > 0) ? round($reach[$index] * 100 / $previous, 1) : null,
                    'missed' => $previous !== null ? max(0, $previous - $reach[$index]) : null,
                ];
            }
        }
        $funnels[] = ['id' => $funnel['id'], 'name' => $funnel['name'], 'steps' => $steps];
    }
    return $funnels;
}

/** Thao tác gần nhất của một thiết bị hoặc một tài khoản (dòng mới nhất trong log). */
function htk_lookup(string $deviceHex, int $memberId, int $limit = 50): array
{
    if (htk_hex_ok($deviceHex)) {
        $st = db()->prepare('SELECT HEX(device_id) AS device_hex, member_id, event_type, page_code, action_code, occurred_at
            FROM app_tracking_events WHERE device_id=? ORDER BY occurred_at DESC, id DESC LIMIT ' . (int)$limit);
        $st->execute([hex2bin($deviceHex)]);
        return $st->fetchAll();
    }
    if ($memberId > 0) {
        $st = db()->prepare('SELECT HEX(device_id) AS device_hex, member_id, event_type, page_code, action_code, occurred_at
            FROM app_tracking_events WHERE member_id=? ORDER BY occurred_at DESC, id DESC LIMIT ' . (int)$limit);
        $st->execute([$memberId]);
        return $st->fetchAll();
    }
    return [];
}

/** Giờ UTC trong DB → giờ Việt Nam để hiển thị. */
function htk_vn_time(string $utc): string
{
    $time = strtotime(substr($utc, 0, 19) . ' UTC');
    return gmdate('d/m/Y H:i:s', $time + HTK_VN_OFFSET);
}

/** Cấu hình truyền cho tracker.js: chỉ gồm các quy tắc áp dụng cho trang đang mở. */
function htk_js_config(): array
{
    $page = htk_current_page_code();
    $view = false;
    $clicks = [];
    $minigame = '';
    foreach (htk_rules() as $rule) {
        if ($rule['url'] !== $page) {
            continue;
        }
        if ($rule['kind'] === 'view') {
            $view = true;
        } elseif ($rule['kind'] === 'click') {
            $clicks[] = ['code' => $rule['code'], 'selectors' => htk_selector_list($rule['selector'])];
        } elseif ($rule['kind'] === 'minigame') {
            $minigame = $rule['code'];
        }
    }
    return [
        'endpoint' => base_url('actions/track.php'),
        'page' => $page,
        'view' => $view,
        'clicks' => $clicks,
        'minigame' => $minigame,
    ];
}

/**
 * URL thật để mở công cụ chọn phần tử cho một đường dẫn admin nhập (có thể kèm query, ví dụ /product?id=3).
 * Trả về '' nếu không có trang tương ứng trên website.
 */
function htk_picker_target(string $raw): string
{
    $normalized = htk_normalize_path($raw);
    $relative = $normalized === '/index' ? 'index' : ltrim($normalized, '/');
    if (preg_match('#^[A-Za-z0-9_/-]+$#', $relative) !== 1 || preg_match('#^(admin|actions|lib|bin|mobile-admin)(/|$)#', $relative) === 1) {
        return '';
    }
    $dir = (string)realpath(__DIR__ . '/..');
    if (is_file($dir . '/' . $relative . '.php')) {
        $file = $relative . '.php';
    } elseif (is_file($dir . '/' . $relative . '/index.php')) {
        $file = $relative . '/';
    } else {
        return '';
    }
    parse_str((string)parse_url(trim($raw), PHP_URL_QUERY), $query);
    if ($normalized === '/product' && empty($query['id'])) {
        // Trang sản phẩm bắt buộc có id: admin không gõ thì lấy một sản phẩm đang bán để mở thử.
        $productId = (int)db()->query('SELECT id FROM products WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($productId > 0) {
            $query['id'] = $productId;
        }
    }
    $query['tracking_picker'] = '1';
    return base_url($file) . '?' . http_build_query($query);
}

/** Admin đã đăng nhập mở trang web với ?tracking_picker=1 → bật công cụ chọn phần tử (và tắt ghi log). */
function htk_picker_active(): bool
{
    return !empty($_GET['tracking_picker']) && function_exists('admin_logged_in') && admin_logged_in();
}

htk_schema();
