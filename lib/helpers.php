<?php
if (!function_exists('dd')) {

    // -- helpers hỗ trợ debug => hiển thị dữ liệu lên khi muốn xem ở BE 
    // -- Cách dùng dd($abc, $xyz, ....); | dd('value', $value,....) 
    function dd(...$vars)
    {
        echo '<pre style="background: #18171B; color: #FF2D20; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 14px; line-height: 1.5; z-index: 999999; position: relative; overflow: auto;">';
        foreach ($vars as $var) {
            var_dump($var);
            echo "\n----------------------------------------\n";
        }
        echo '</pre>';
        die();
    }
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Highest quantity of one cart / group line. There is no business limit any more (it used to be 20);
 * this only guards against typos and keeps totals inside the DECIMAL(12,2) money columns.
 */
function order_qty_max(): int
{
    return 9999;
}
function money($v): string
{
    return number_format((float)$v, 0);
}
function currency(): string
{
    return setting('currency', '₫');
}
function theme_scheme(): string
{
    $scheme = setting('theme_scheme', 'default');
    return in_array($scheme, ['default', 'deep_red'], true) ? $scheme : 'default';
}
function theme_body_class(): string
{
    return 'theme-' . str_replace('_', '-', theme_scheme());
}
function theme_primary_color(): string
{
    return theme_scheme() === 'deep_red' ? '#721C2B' : '#111827';
}
function base_url(string $path = ''): string
{
    $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
    return $base . '/' . ltrim($path, '/');
}
/** Cloudflare edge ranges (https://www.cloudflare.com/ips/); update when Cloudflare publishes new ones. */
function cloudflare_ip_ranges(): array
{
    return [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr) + [1 => ''];
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    if (strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;
    if ($bits % 8 === 0) return true;
    $mask = chr((0xFF << (8 - $bits % 8)) & 0xFF);
    return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
}
/**
 * The visitor's IP. Behind Cloudflare every request arrives from an edge IP, so CF-Connecting-IP is used,
 * but only when the request really came from Cloudflare; anyone else could send that header themselves.
 */
function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $forwarded = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
        foreach (cloudflare_ip_ranges() as $range) if (ip_in_cidr($remote, $range)) return $forwarded;
    }
    return $remote !== '' ? $remote : 'local';
}
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
function setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['__drink_order_setting_cache']) || !is_array($GLOBALS['__drink_order_setting_cache'])) {
        $GLOBALS['__drink_order_setting_cache'] = [];
    }
    $cache = &$GLOBALS['__drink_order_setting_cache'];
    if (array_key_exists($key, $cache)) return (string)$cache[$key];
    try {
        $st = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $cache[$key] = ($v !== false ? (string)$v : $default);
    } catch (Throwable $e) {
        return $default;
    }
}
function set_setting(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $st->execute([$key, $value]);
    if (!isset($GLOBALS['__drink_order_setting_cache']) || !is_array($GLOBALS['__drink_order_setting_cache'])) {
        $GLOBALS['__drink_order_setting_cache'] = [];
    }
    $GLOBALS['__drink_order_setting_cache'][$key] = $value;
}
function column_exists(string $table, string $column): bool
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (($cache[$k] ?? false) === true) return true;
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([DB_NAME, $table, $column]);
        $exists = (bool)$st->fetchColumn();
        if ($exists) $cache[$k] = true;
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}
function ensure_i18n_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $changes = [
        ['categories', 'name_vi', 'VARCHAR(120) NULL AFTER name'],
        ['categories', 'name_en', 'VARCHAR(120) NULL AFTER name_vi'],
        ['categories', 'name_zh', 'VARCHAR(120) NULL AFTER name_en'],
        ['products', 'name_vi', 'VARCHAR(160) NULL AFTER name'],
        ['products', 'name_en', 'VARCHAR(160) NULL AFTER name_vi'],
        ['products', 'name_zh', 'VARCHAR(160) NULL AFTER name_en'],
        ['products', 'has_sizes', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER price'],
        ['products', 'price_l', 'DECIMAL(10,2) NULL AFTER has_sizes'],
        ['products', 'price_xl', 'DECIMAL(10,2) NULL AFTER price_l'],
        ['products', 'is_new', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order'],
        ['products', 'is_hot', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_new'],
        ['toppings', 'name_vi', 'VARCHAR(120) NULL AFTER name'],
        ['toppings', 'name_en', 'VARCHAR(120) NULL AFTER name_vi'],
        ['toppings', 'name_zh', 'VARCHAR(120) NULL AFTER name_en'],
        ['toppings', 'description_vi', 'TEXT NULL AFTER name_zh'],
        ['toppings', 'description_en', 'TEXT NULL AFTER description_vi'],
        ['toppings', 'description_zh', 'TEXT NULL AFTER description_en'],
        ['toppings', 'sell_separately', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER price'],
        ['toppings', 'retail_price', 'DECIMAL(10,2) NULL AFTER sell_separately'],
        ['toppings', 'image', 'VARCHAR(255) NULL AFTER retail_price'],
        ['toppings', 'is_new', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order'],
        ['toppings', 'is_hot', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_new'],
        ['option_profiles', 'display_name_vi', 'VARCHAR(120) NULL AFTER display_name'],
        ['option_profiles', 'display_name_en', 'VARCHAR(120) NULL AFTER display_name_vi'],
        ['option_profiles', 'display_name_zh', 'VARCHAR(120) NULL AFTER display_name_en'],
        ['option_profiles', 'options_json_vi', 'TEXT NULL AFTER options_json'],
        ['option_profiles', 'options_json_en', 'TEXT NULL AFTER options_json_vi'],
        ['option_profiles', 'options_json_zh', 'TEXT NULL AFTER options_json_en'],
        ['order_items', 'cup_size', 'VARCHAR(10) NULL AFTER product_name'],
        ['orders', 'invoice_company_name', 'VARCHAR(200) NULL AFTER invoice_required'],
        ['orders', 'invoice_tax_id', 'VARCHAR(50) NULL AFTER invoice_company_name'],
        ['orders', 'invoice_company_address', 'VARCHAR(255) NULL AFTER invoice_tax_id'],
        ['orders', 'invoice_company_representative', 'VARCHAR(120) NULL AFTER invoice_company_address'],
        ['orders', 'invoice_company_phone', 'VARCHAR(50) NULL AFTER invoice_company_representative'],
        ['orders', 'invoice_email', 'VARCHAR(190) NULL AFTER invoice_company_phone'],
        ['orders', 'order_note', 'TEXT NULL AFTER invoice_email'],
        ['orders', 'coupon_code', 'VARCHAR(80) NULL AFTER order_note'],
        ['orders', 'subtotal_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER coupon_code'],
        ['orders', 'discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal_amount'],
        ['order_items', 'discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price'],
    ];
    foreach ($changes as [$table, $col, $def]) {
        if (column_exists($table, $col)) continue;
        try {
            db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        } catch (Throwable $e) { /* Continue so one denied ALTER does not block the others. */
        }
    }
    try {
        $profiles = [
            'I1' => ['100% Bình thường', ['100% Bình thường'], '100% Normal', ['100% Normal'], '100%正常', ['100%正常']],
            'I2' => ['Bình thường / Ít đá / Không đá / Đá riêng', ['100% Bình thường', '50% Ít đá', '0% Không đá', 'Đá riêng'], 'Normal / Less ice / No ice / Ice on the side', ['100% Normal', '50% Less ice', '0% No ice', 'Ice on the side'], '正常 / 少冰 / 去冰 / 冰塊另附', ['100%正常', '50%少冰', '0%去冰', '冰塊另附']],
            'I3' => ['Ít đá / Không đá / Đá riêng', ['50% Ít đá', '0% Không đá', 'Đá riêng'], 'Less ice / No ice / Ice on the side', ['50% Less ice', '0% No ice', 'Ice on the side'], '少冰 / 去冰 / 冰塊另附', ['50%少冰', '0%去冰', '冰塊另附']],
            'I4' => ['Không đá / Đá riêng', ['0% Không đá', 'Đá riêng'], 'No ice / Ice on the side', ['0% No ice', 'Ice on the side'], '去冰 / 冰塊另附', ['0%去冰', '冰塊另附']],
            'S1' => ['100% Đường', ['100% Đường'], '100% Sugar', ['100% Sugar'], '100%全糖', ['100%全糖']],
            'S2' => ['Đường chuẩn / 70% / 50% / 30% / Không đường', ['100% Đường', '70% Ít đường', '50% Nửa đường', '30% Ít ngọt', '0% Không đường'], 'Full / 70% / 50% / 30% / No sugar', ['100% Sugar', '70% Less sugar', '50% Half sugar', '30% Light sugar', '0% No sugar'], '全糖 / 少糖 / 半糖 / 微糖 / 無糖', ['100%全糖', '70%少糖', '50%半糖', '30%微糖', '0%無糖']],
            'S3' => ['Đường chuẩn / 50% / Không đường', ['100% Đường', '50% Nửa đường', '0% Không đường'], 'Full / 50% / No sugar', ['100% Sugar', '50% Half sugar', '0% No sugar'], '全糖 / 半糖 / 無糖', ['100%全糖', '50%半糖', '0%無糖']],
            'S4' => ['Đường chuẩn / 50%', ['100% Đường', '50% Nửa đường'], 'Full / 50%', ['100% Sugar', '50% Half sugar'], '全糖 / 半糖', ['100%全糖', '50%半糖']],
            'S5' => ['0% Không đường', ['0% Không đường'], '0% No sugar', ['0% No sugar'], '0%無糖', ['0%無糖']],
        ];
        $st = db()->prepare('UPDATE option_profiles SET display_name_vi=?,options_json_vi=?,display_name_en=?,options_json_en=?,display_name_zh=?,options_json_zh=? WHERE code=? AND (display_name_vi IS NULL OR display_name_en IS NULL OR display_name_zh IS NULL)');
        foreach ($profiles as $code => $p) $st->execute([$p[0], json_encode($p[1], JSON_UNESCAPED_UNICODE), $p[2], json_encode($p[3], JSON_UNESCAPED_UNICODE), $p[4], json_encode($p[5], JSON_UNESCAPED_UNICODE), $code]);
    } catch (Throwable $e) { /* New installs already include these values. */
    }
    try {
        $defaults = ['home_banner_active' => '0', 'home_banner_image' => '', 'home_banner_link_type' => 'product', 'home_banner_target_id' => '', 'buy4get1_enabled' => '1', 'buy4get1_code' => 'MUA4TANG1', 'theme_scheme' => 'default'];
        $st = db()->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)');
        foreach ($defaults as $k => $v) $st->execute([$k, $v]);
    } catch (Throwable $e) { /* Settings table may not exist during an incomplete install. */
    }
}
function option_profile(string $type, string $code): array
{
    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    $col = 'options_json_' . $lang;
    try {
        $sql = column_exists('option_profiles', $col) ? "SELECT COALESCE(NULLIF($col,''),options_json) FROM option_profiles WHERE profile_type=? AND code=? AND active=1" : 'SELECT options_json FROM option_profiles WHERE profile_type=? AND code=? AND active=1';
        $st = db()->prepare($sql);
        $st->execute([$type, $code]);
        $raw = $st->fetchColumn();
        $arr = $raw ? json_decode($raw, true) : [];
        return is_array($arr) ? $arr : [];
    } catch (Throwable $e) {
        return [];
    }
}
function profile_display_name(array $row): string
{
    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    $key = 'display_name_' . $lang;
    return trim((string)($row[$key] ?? '')) !== '' ? (string)$row[$key] : (trim((string)($row['display_name_vi'] ?? '')) !== '' ? (string)$row['display_name_vi'] : (string)($row['display_name'] ?? ''));
}

function product_has_sizes(array $product): bool
{
    return !empty($product['has_sizes']);
}
function product_size_options(array $product): array
{
    if (!product_has_sizes($product)) return [];
    return ['L' => (float)($product['price_l'] ?? 0), 'XL' => (float)($product['price_xl'] ?? 0)];
}
function product_price_text(array $product): string
{
    if (product_has_sizes($product)) {
        return 'L ' . currency() . money($product['price_l'] ?? 0) . ' · XL ' . currency() . money($product['price_xl'] ?? 0);
    }
    return currency() . money($product['price'] ?? 0);
}
function coupon_enabled(): bool
{
    return setting('buy4get1_enabled', '1') === '1';
}
function coupon_code(): string
{
    return strtoupper(trim(setting('buy4get1_code', 'MUA4TANG1')));
}
function active_coupon_code(): string
{
    $code = $_SESSION['coupon_code'] ?? '';
    return is_string($code) ? strtoupper(trim($code)) : '';
}
function coupon_record(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    if (coupon_enabled() && coupon_code() !== '' && $code === coupon_code()) return ['code' => $code, 'type' => 'buy4get1', 'amount' => 0.0, 'source' => 'public'];
    if (function_exists('customer_game_wallet_token')) {
        $token = customer_game_wallet_token(false);
        if ($token !== '') {
            try {
                if (!hym_reward_wallet_accessible($token)) return null;
                $st = db()->prepare('SELECT id,code,coupon_type,amount FROM customer_coupons WHERE code=? AND wallet_token=? AND used_order_id IS NULL LIMIT 1');
                $st->execute([$code, $token]);
                $r = $st->fetch();
                if ($r) return function_exists('hgr_coupon_record') ? hgr_coupon_record($r) : ['code' => $r['code'], 'type' => $r['coupon_type'], 'amount' => (float)$r['amount'], 'source' => 'game'];
            } catch (Throwable $e) {
            }
        }
    }
    return null;
}
function coupon_is_valid(string $code): bool
{
    return coupon_record($code) !== null;
}
function clear_coupon(): void
{
    unset($_SESSION['coupon_code']);
    $_SESSION['promotion_mode'] = 'none';
}
function cart_subtotal(?array $cart = null): float
{
    $total = 0.0;
    foreach (($cart ?? ($_SESSION['cart'] ?? [])) as $row) $total += (float)($row['unit_price'] ?? 0) * (int)($row['qty'] ?? 0);
    return $total;
}
/** Single-coupon calculation. Public Buy 4 Get 1 and one-time game coupons cannot stack. */
function cart_coupon_summary(?array $cart = null): array
{
    $cart = $cart ?? ($_SESSION['cart'] ?? []);
    $code = active_coupon_code();
    if (function_exists('promotion_cart_mode') && !in_array(promotion_cart_mode(), ['buy4get1', 'reward'], true)) $code = '';
    return calculate_cart_coupon_summary($cart, $code !== '' ? coupon_record($code) : null);
}
/** Pure calculation reused by private and group carts; never reads another member's session. */
function calculate_cart_coupon_summary(array $cart, ?array $record, ?array $giftLineKeys = null): array
{
    $result = ['code' => '', 'valid' => false, 'type' => '', 'source' => '', 'eligible_qty' => 0, 'free_qty' => 0, 'discount' => 0.0, 'line_discounts' => []];
    if (!$record) return $result;
    $result['code'] = $record['code'];
    $result['valid'] = true;
    $result['type'] = $record['type'];
    $result['source'] = $record['source'];
    if ($record['type'] === 'game_reward') return function_exists('hgr_calculate') ? hgr_calculate($cart, $record, $result, $giftLineKeys) : array_merge($result, ['valid' => false]);
    if ($record['type'] === 'buy4get1') {
        $units = [];
        foreach ($cart as $key => $row) {
            if (empty($row['product_id']) || !empty($row['standalone_topping_id']) || !empty($row['combo_drink'])) continue;
            $qty = max(0, (int)($row['qty'] ?? 0));
            $base = max(0, (float)($row['base_price'] ?? 0));
            $result['eligible_qty'] += $qty;
            for ($i = 0; $i < $qty; $i++) $units[] = ['key' => (string)$key, 'base' => $base];
        }
        $free = (int)floor(count($units) / 5);
        $result['free_qty'] = $free;
        if ($free <= 0) return $result;
        usort($units, fn($a, $b) => $a['base'] <=> $b['base']);
        for ($i = 0; $i < $free; $i++) {
            $u = $units[$i];
            $result['discount'] += $u['base'];
            $result['line_discounts'][$u['key']] = ($result['line_discounts'][$u['key']] ?? 0) + $u['base'];
        }
        return $result;
    }
    if ($record['type'] === 'fixed_drink') {
        $remaining = max(0, (float)$record['amount']);
        foreach ($cart as $key => $row) {
            if (empty($row['product_id']) || !empty($row['standalone_topping_id']) || !empty($row['combo_drink'])) continue;
            $qty = max(0, (int)($row['qty'] ?? 0));
            $base = max(0, (float)($row['base_price'] ?? 0));
            $result['eligible_qty'] += $qty;
            $lineBase = $base * $qty;
            if ($remaining <= 0 || $lineBase <= 0) continue;
            $take = min($remaining, $lineBase);
            $result['line_discounts'][(string)$key] = $take;
            $result['discount'] += $take;
            $remaining -= $take;
        }
        return $result;
    }
    return $result;
}
function cart_discount(): float
{
    return (float)cart_coupon_summary()['discount'];
}
function cart_total(): float
{
    return max(0, cart_subtotal() - cart_discount());
}
function notification_sender_email(): string
{
    return 'no-reply@huiyitea.vn';
}
function system_mail_send(string $to, string $subject, string $body, ?string &$detail = null, ?string $htmlBody = null): bool
{
    $to = trim($to);
    $from = notification_sender_email();
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $detail = 'invalid_recipient';
        return false;
    }
    if (!function_exists('mail')) {
        $detail = 'mail_function_unavailable';
        return false;
    }
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $domain = parse_url(defined('APP_BASE_URL') ? APP_BASE_URL : '', PHP_URL_HOST) ?: 'huiyitea.vn';
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'From: Online Order <' . $from . '>',
        'Sender: ' . $from,
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    $mailBody = $body;
    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'X-HuiYiTea-Mail-Template: order-html-v19.2';
        $mailBody = $htmlBody;
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-HuiYiTea-Mail-Template: plain-v19.2';
    }
    // v18.19: keep MIME Base64 + 76-character wrapping for BOTH HTML and text.
    // This preserves the HTML design while avoiding Exim/cPanel SMTP failures such as
    // "message has lines too long for transport (limit 2048)".
    $headers[] = 'Content-Transfer-Encoding: base64';
    $mailBody = rtrim(chunk_split(base64_encode($mailBody), 76, "\r\n"));
    $headerText = implode("\r\n", $headers);
    // On cPanel, setting the envelope sender to an existing local mailbox greatly
    // improves SPF/Return-Path alignment. Some hosts disallow the fifth mail()
    // parameter, so fall back to the standard four-argument call if needed.
    $ok = false;
    try {
        $ok = @mail($to, $encodedSubject, $mailBody, $headerText, '-f' . $from);
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        try {
            $ok = @mail($to, $encodedSubject, $mailBody, $headerText);
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    $detail = $ok ? 'mail_accepted' : 'mail_rejected';
    return $ok;
}
function order_notification_recipients(): array
{
    $raw = trim((string)setting('order_email_recipients', ''));
    if ($raw === '') $raw = trim((string)setting('order_email', ''));
    if ($raw === '') return [];
    $parts = preg_split('/[\r\n,;]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $email = trim((string)$part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($email), array_map('strtolower', $out), true)) $out[] = $email;
        if (count($out) >= 10) break;
    }
    return $out;
}

function send_test_notification_emails(array $recipients, array &$details = []): bool
{
    $details = [];
    $allOk = true;
    foreach ($recipients as $to) {
        $detail = '';
        $ok = send_test_notification_email($to, $detail);
        $details[$to] = $detail ?: ($ok ? 'mail_accepted' : 'mail_rejected');
        if (!$ok) $allOk = false;
    }
    return !empty($recipients) && $allOk;
}

function email_runtime_diagnostics(): array
{
    return [
        'sender' => notification_sender_email(),
        'recipient' => implode(', ', order_notification_recipients()),
        'mail_available' => function_exists('mail'),
        'sendmail_path' => (string)ini_get('sendmail_path'),
        'smtp_host' => (string)ini_get('SMTP'),
        'smtp_port' => (string)ini_get('smtp_port'),
    ];
}
function send_test_notification_email(string $to, ?string &$detail = null): bool
{
    $site = defined('APP_BASE_URL') ? APP_BASE_URL : '';
    $body = "EMAIL TEST / KIỂM TRA EMAIL / EMAIL 測試\r\n" .
        "========================================\r\n" .
        "This is a test message from the ordering system.\r\n" .
        "Đây là thư kiểm tra từ hệ thống đặt hàng.\r\n" .
        "這是點餐系統寄出的測試通知信。\r\n\r\n" .
        "Sender: " . notification_sender_email() . "\r\n" .
        "Recipient: " . $to . "\r\n" .
        "Website: " . $site . "\r\n" .
        "Time: " . date('Y-m-d H:i:s') . "\r\n";
    return system_mail_send($to, '[TEST] Online Order email notification', $body, $detail);
}
function send_order_notification(array $order, array $cart): bool
{
    $recipients = order_notification_recipients();
    if (!$recipients) return false;

    $lines = [];
    $lines[] = 'NEW ORDER / ĐƠN HÀNG MỚI / 新訂單';
    $lines[] = '========================================';
    $lines[] = 'Order No.: ' . ($order['order_no'] ?? '');
    $lines[] = 'Customer / Khách hàng / 客戶: ' . ($order['customer_name'] ?? '');
    $lines[] = 'Phone / Điện thoại / 電話: ' . ($order['phone'] ?? '');
    $lines[] = 'Address / Địa chỉ / 地址: ' . ($order['address'] ?? '');
    $lines[] = 'Payment / Thanh toán / 付款: ' . (($order['payment_method'] ?? '') === 'transfer' ? 'Bank transfer / Chuyển khoản / 轉帳' : 'Cash / Tiền mặt / 現金');
    $lines[] = 'Invoice / Hóa đơn / 發票: ' . (!empty($order['invoice_required']) ? 'Yes / Có / 是' : 'No / Không / 否');
    if (!empty($order['invoice_required'])) {
        $lines[] = '';
        $lines[] = 'INVOICE INFORMATION / THÔNG TIN HÓA ĐƠN / 發票資料';
        $lines[] = 'Company / Công ty / 公司: ' . ($order['invoice_company_name'] ?? '');
        $lines[] = 'Tax ID / Mã số thuế / 統一稅號: ' . ($order['invoice_tax_id'] ?? '');
        $lines[] = 'Company address / Địa chỉ công ty / 公司地址: ' . ($order['invoice_company_address'] ?? '');
        $lines[] = 'Representative / Người đại diện / 公司代表人: ' . (($order['invoice_company_representative'] ?? '') ?: '—');
        $lines[] = 'Company phone / Điện thoại công ty / 公司電話: ' . ($order['invoice_company_phone'] ?? '');
        $lines[] = 'Invoice Email / Email hóa đơn / 發票 Email: ' . ($order['invoice_email'] ?? '');
    }
    $lines[] = '';
    $lines[] = 'ORDER DETAILS / CHI TIẾT / 訂購明細';
    foreach ($cart as $row) {
        $name = (string)($row['name'] ?? '');
        $size = !empty($row['cup_size']) ? ' ' . (string)$row['cup_size'] : '';
        $qty = (int)($row['qty'] ?? 1);
        $opts = [];
        if (trim((string)($row['ice'] ?? '')) !== '') $opts[] = (string)$row['ice'];
        if (trim((string)($row['sugar'] ?? '')) !== '') $opts[] = (string)$row['sugar'];
        $lines[] = '- ' . $name . $size . ' x ' . $qty . ($opts ? ' | ' . implode(' / ', $opts) : '') . ' | ' . currency() . money(((float)($row['unit_price'] ?? 0)) * $qty);
        if (!empty($row['toppings'])) {
            $tops = [];
            foreach ($row['toppings'] as $top) $tops[] = (string)($top['display_name'] ?? $top['name'] ?? '');
            if ($tops) $lines[] = '  Toppings: ' . implode(', ', $tops);
        }
    }
    $lines[] = '----------------------------------------';
    if (!empty($order['coupon_code'])) $lines[] = 'COUPON / MÃ ƯU ĐÃI / 優惠券: ' . ($order['coupon_code'] ?? '');
    if (isset($order['subtotal_amount'])) $lines[] = 'SUBTOTAL / TẠM TÍNH / 小計: ' . currency() . money($order['subtotal_amount'] ?? 0);
    if ((float)($order['discount_amount'] ?? 0) > 0) $lines[] = 'DISCOUNT / GIẢM GIÁ / 優惠折抵: -' . currency() . money($order['discount_amount']);
    $lines[] = 'TOTAL / TỔNG / 總金額: ' . currency() . money($order['total_amount'] ?? 0);
    if (trim((string)($order['order_note'] ?? '')) !== '') {
        $lines[] = '';
        $lines[] = 'NOTE / GHI CHÚ / 備註:';
        $lines[] = (string)$order['order_note'];
    }
    $lines[] = '';
    $lines[] = 'Generated by ' . store_name();

    $subject = '[Order ' . ($order['order_no'] ?? '') . '] ' . ($order['customer_name'] ?? '');
    $allOk = true;
    foreach ($recipients as $to) {
        $detail = '';
        if (!system_mail_send($to, $subject, implode("\r\n", $lines), $detail)) $allOk = false;
    }
    return $allOk;
}
function cart_count(): int
{
    $n = 0;
    // Dòng combo chỉ là "vỏ" chứa các ly nước: không tính là 1 ly, tránh đếm dư (5 ly + 3 ly = 8, không phải 9).
    foreach (($_SESSION['cart'] ?? []) as $row) {
        if (!empty($row['is_combo'])) continue;
        $n += (int)$row['qty'];
    }
    return $n;
}
function order_no(): string
{
    return date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}
function refresh_cart_language(): void
{
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) return;
    foreach ($_SESSION['cart'] as $key => &$row) {
        try {
            if (!empty($row['standalone_topping_id'])) {
                $st = db()->prepare('SELECT * FROM toppings WHERE id=?');
                $st->execute([(int)$row['standalone_topping_id']]);
                $tr = $st->fetch();
                if ($tr) $row['name'] = localized_name($tr);
            }
            if (!empty($row['product_id'])) {
                $st = db()->prepare('SELECT * FROM products WHERE id=?');
                $st->execute([(int)$row['product_id']]);
                $p = $st->fetch();
                if ($p) {
                    $row['name'] = localized_name($p);
                    if (isset($row['ice_index'])) {
                        $opts = option_profile('ice', $row['ice_profile'] ?? $p['ice_profile']);
                        if (isset($opts[(int)$row['ice_index']])) $row['ice'] = $opts[(int)$row['ice_index']];
                    }
                    if (isset($row['sugar_index'])) {
                        $opts = option_profile('sugar', $row['sugar_profile'] ?? $p['sugar_profile']);
                        if (isset($opts[(int)$row['sugar_index']])) $row['sugar'] = $opts[(int)$row['sugar_index']];
                    }
                }
            }
            if (!empty($row['toppings']) && is_array($row['toppings'])) {
                foreach ($row['toppings'] as &$top) {
                    if (empty($top['id'])) continue;
                    $st = db()->prepare('SELECT * FROM toppings WHERE id=?');
                    $st->execute([(int)$top['id']]);
                    $tr = $st->fetch();
                    if ($tr) $top['display_name'] = localized_name($tr);
                }
                unset($top);
            }
            $row['lang'] = current_lang();
        } catch (Throwable $e) { /* Keep existing cart snapshot if a product was removed. */
        }
    }
    unset($row);
}
