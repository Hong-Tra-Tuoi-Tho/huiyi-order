<?php
require_once __DIR__ . '/game_config.php';
/** Ký Ức 10 rewards use the order platform's owned, single-use coupons. */
function hgr_schema(): void
{
    static $done = false;
    if ($done) return;
    if (db()->inTransaction()) throw new RuntimeException('game_reward_schema_inside_transaction');
    db()->exec("CREATE TABLE IF NOT EXISTS game_coupon_details (
        coupon_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        reward_json LONGTEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        KEY idx_game_reward_expiry(expires_at),
        CONSTRAINT fk_game_reward_coupon FOREIGN KEY(coupon_id) REFERENCES customer_coupons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (setting('game_rewards_schema_version', '') !== '19.8.0') set_setting('game_rewards_schema_version', '19.8.0');
    $done = true;
}
function hgr_text(string $key): string
{
    $copy = [
        'title' => ['zh' => '10 杯童年回憶', 'vi' => 'Ký ức tuổi thơ', 'en' => 'Ten Cups of Memories'],
        'intro' => ['zh' => '翻開童年回憶，配對 6 組圖案，領取下一次的驚喜。', 'vi' => 'Lật thẻ ký ức, ghép đủ 6 cặp và nhận một món quà cho lần gặp tới.', 'en' => 'Match six pairs of childhood memories and earn a gift for your next order.'],
        'rules' => ['zh' => '選擇買10抽獎，單筆訂單每滿10杯飲品獲1次機會：10杯1次、20杯2次、30杯3次，完成後發放。杯數不跨單累計，不與其他優惠共用。', 'vi' => 'Chọn Mua 10 nhận lượt rút thưởng: mỗi 10 ly trong cùng một đơn nhận 1 lượt khi hoàn tất; 10 ly được 1 lượt, 20 ly được 2, 30 ly được 3. Không cộng ly giữa các đơn hoặc dùng cùng ưu đãi khác.', 'en' => 'Choose Buy 10 for a prize draw: every 10 drinks in one completed order earns 1 play; 10 drinks earn 1, 20 earn 2, 30 earn 3. Cups do not carry over between orders; offers cannot be combined.'],
        'odds_money' => ['zh' => '訂單直接折抵最高：', 'vi' => 'Giảm giá tiền trực tiếp trên đơn lên tới:', 'en' => 'Money off your order, up to:'],
        'pairs' => ['zh' => '配對', 'vi' => 'Cặp hình', 'en' => 'Pairs'],
        'moves' => ['zh' => '翻牌次數', 'vi' => 'Lượt lật', 'en' => 'Moves'],
        'time' => ['zh' => '遊戲時間', 'vi' => 'Thời gian', 'en' => 'Time'],
        'retry' => ['zh' => '重新載入進度', 'vi' => 'Tải lại tiến độ', 'en' => 'Reload progress'],
        'next' => ['zh' => '使用下次機會', 'vi' => 'Chơi lượt tiếp theo', 'en' => 'Use next play'],
        'reward' => ['zh' => '你的回憶好禮', 'vi' => 'Món quà của bạn', 'en' => 'Your memory gift'],
        'no_limit' => ['zh' => '不限時間，配對錯誤不影響獎項。', 'vi' => 'Không giới hạn thời gian; ghép sai không ảnh hưởng quà.', 'en' => 'No time limit; mistakes do not affect your prize.'],
        'prepare' => ['zh' => '活動準備中，請稍後再來。你的遊戲機會會保留。', 'vi' => 'Chương trình đang chuẩn bị. Vui lòng quay lại sau; lượt chơi của bạn vẫn được giữ.', 'en' => 'The campaign is being prepared. Come back later; your plays will be kept.'],
        'settings' => ['zh' => '隨機獎項設定', 'vi' => 'Cài đặt quà ngẫu nhiên', 'en' => 'Random reward settings'],
        'settings_hint' => ['zh' => '設定獎項、機率、使用條件與有效天數；啟用獎項的機率合計須為100%。', 'vi' => 'Cài đặt quà, tỷ lệ, điều kiện và hạn sử dụng; tổng tỷ lệ quà đang bật phải là 100%.', 'en' => 'Configure prizes, odds, conditions and validity; enabled prize odds must total 100%.'],
        'topping' => ['zh' => '指定加料', 'vi' => 'Topping tặng', 'en' => 'Gift topping'],
        'tea' => ['zh' => '指定紅茶', 'vi' => 'Hồng trà tặng', 'en' => 'Gift tea'],
        'size' => ['zh' => '贈送杯型', 'vi' => 'Cỡ ly tặng', 'en' => 'Gift cup size'],
        'select' => ['zh' => '請選擇', 'vi' => 'Vui lòng chọn', 'en' => 'Select an item'],
        'saved' => ['zh' => '獎項設定已儲存。', 'vi' => 'Đã lưu cài đặt quà.', 'en' => 'Reward settings saved.'],
        'invalid' => ['zh' => '請檢查獎項商品、機率、條件、有效天數與圖片設定。', 'vi' => 'Vui lòng kiểm tra món quà, tỷ lệ, điều kiện, hạn sử dụng và hình ảnh.', 'en' => 'Check prize items, odds, conditions, validity and images.'],
        'expires' => ['zh' => '有效至', 'vi' => 'Hạn dùng', 'en' => 'Valid until'],
        'odds' => ['zh' => '獎項機率', 'vi' => 'Tỷ lệ quà', 'en' => 'Prize odds'],
    ];
    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    return $copy[$key][$lang] ?? $copy[$key]['en'] ?? $key;
}
function hgr_names(array $item): array
{
    $result = [];
    foreach (['vi', 'zh', 'en'] as $lang) $result[$lang] = trim((string)($item['name_' . $lang] ?? '')) ?: ((string)($item['name'] ?? ''));
    return $result;
}
function hgr_items(): ?array
{
    $top = hym_reward_one('SELECT * FROM toppings WHERE id=? AND active=1', [(int)setting('hgr_topping_id', '0')]);
    $tea = hym_reward_one('SELECT p.* FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1 AND c.active=1', [(int)setting('hgr_drink_id', '0')]);
    if (!$top || !$tea || (float)$top['price'] <= 0) return null;
    $size = product_has_sizes($tea) ? setting('hgr_drink_size', 'L') : '';
    $prices = product_has_sizes($tea) ? product_size_options($tea) : ['' => (float)$tea['price']];
    if (!isset($prices[$size]) || $prices[$size] <= 0) return null;
    return ['topping' => $top, 'tea' => $tea, 'size' => $size];
}
function hgr_money_text(int $amount, string $lang): string
{
    return number_format($amount, 0, '.', $lang === 'vi' ? '.' : ',') . 'đ';
}
/** Resolve the current catalog once into terms that can be saved with a board. */
function hgr_reward_template(array $prize): array
{
    // Every selected item is gifted, each named with its own cup size, e.g. "Hồng trà (L) + Trà sữa (XL)".
    $names = [];
    $all = [];
    $ids = $prize['item_ids'] ?? [$prize['item_id']];
    foreach (hgr_config_catalog_items($prize) as $i => $item) {
        $label = hgr_names($item);
        $cup = hgr_item_size($prize, (int)$ids[$i]);
        if ($cup !== '') foreach ($label as $lang => $text) $label[$lang] = $text . ' (' . $cup . ')';
        $all[] = $label;
    }
    foreach (['vi', 'zh', 'en'] as $lang) $names[$lang] = implode(' + ', array_column($all, $lang));
    $size = '';
    $each = count($all) > 1;
    $cups = $prize['min_paid_cups'];
    $spend = $prize['min_spend'];
    $gift = max(1, (int)($prize['gift_qty'] ?? 1));
    $name = [];
    $terms = [];
    foreach (['vi', 'zh', 'en'] as $lang) {
        if ($prize['kind'] === 'discount') {
            $amount = hgr_money_text($prize['amount'], $lang);
            $name[$lang] = ['vi' => 'Giảm ' . $amount, 'zh' => '折抵 ' . $amount, 'en' => $amount . ' off'][$lang];
            $terms[$lang] = [
                'vi' => 'Đơn tiếp theo từ ' . hgr_money_text($spend, 'vi') . ' và ít nhất ' . $cups . ' ly trả phí.',
                'zh' => '下一筆訂單滿' . hgr_money_text($spend, 'zh') . '，至少' . $cups . '杯付費飲品。',
                'en' => 'Next order: at least ' . hgr_money_text($spend, 'en') . ' and ' . $cups . ' paid drink(s).'
            ][$lang];
        } elseif ($prize['kind'] === 'topping') {
            $name[$lang] = ['vi' => 'Tặng ' . $gift . ' topping' . ($each ? ' mỗi món' : '') . ': ' . $names['vi'], 'zh' => ($each ? '每項' : '') . '免費加料' . $gift . '份：' . $names['zh'], 'en' => $gift . ' free topping(s)' . ($each ? ' each' : '') . ': ' . $names['en']][$lang];
            $terms[$lang] = [
                'vi' => 'Đơn tiếp theo mua ít nhất ' . $cups . ' ly trả phí và thêm ' . $names['vi'] . ' vào ly. Giảm ' . $gift . ' phần mỗi topping.',
                'zh' => '下一筆訂單至少買' . $cups . '杯付費飲品，並加選' . $names['zh'] . '。每項加料折抵' . $gift . '份。',
                'en' => 'Next order: buy at least ' . $cups . ' paid drink(s) with ' . $names['en'] . '. ' . $gift . ' of each topping is free.'
            ][$lang];
        } else {
            $name[$lang] = ['vi' => 'Tặng ' . $gift . ' ly' . ($each ? ' mỗi món' : '') . ': ' . $names['vi'], 'zh' => ($each ? '每項' : '') . '免費飲品' . $gift . '杯：' . $names['zh'], 'en' => $gift . ' free drink(s)' . ($each ? ' each' : '') . ': ' . $names['en']][$lang];
            $terms[$lang] = [
                'vi' => 'Đơn tiếp theo mua ' . $cups . ' ly trả phí và thêm ' . $gift . ' ly ' . ($each ? 'mỗi món ' : '') . $names['vi'] . '. Chỉ miễn tiền đồ uống, không gồm topping.',
                'zh' => '下一筆訂單買' . $cups . '杯付費飲品，另加' . ($each ? '每項' : '') . $gift . '杯' . $names['zh'] . '。只折抵飲品本體，不含加料。',
                'en' => 'Next order: buy ' . $cups . ' paid drink(s) and add ' . $gift . ' ' . ($each ? 'of each: ' : '') . $names['en'] . '. Drink base only; toppings cost extra.'
            ][$lang];
        }
        if ($prize['kind'] !== 'discount' && $spend > 0) $terms[$lang] .= ['vi' => ' Đơn từ ' . hgr_money_text($spend, 'vi') . '.', 'zh' => ' 訂單須滿' . hgr_money_text($spend, 'zh') . '。', 'en' => ' Order subtotal must reach ' . hgr_money_text($spend, 'en') . '.'][$lang];
        $terms[$lang] .= [
            'vi' => ' Không cộng ưu đãi khác. Đặt nhóm: chỉ áp dụng cho món do trưởng nhóm tự trả hoặc trả hộ; phải giảm đủ giá trị phiếu.',
            'zh' => ' 不與其他優惠共用。團購僅適用團長自身或墊付的商品，須足額折抵。',
            'en' => ' Cannot be combined with other offers. Group orders: applies to host-paid items or advances only; the full coupon value must be applied.'
        ][$lang];
        if ($prize['name'][$lang] !== '') $name[$lang] = $prize['name'][$lang];
    }
    return [
        'id' => $prize['id'],
        'kind' => $prize['kind'],
        'weight' => $prize['weight'],
        'valid_days' => $prize['valid_days'],
        'item_id' => $prize['item_id'],
        'item_ids' => $prize['item_ids'] ?? [$prize['item_id']],
        'gift_qty' => $prize['kind'] === 'discount' ? 0 : $gift,
        'item_sizes' => $prize['item_sizes'] ?? [],
        'cup_size' => $prize['cup_size'],
        'amount' => $prize['amount'],
        'min_spend' => $spend,
        'min_paid_cups' => $cups,
        'name' => $name,
        'terms' => $terms
    ];
}
function hgr_reward_pool(?array $config = null): ?array
{
    try {
        $config = hgr_validate_config($config ?? hgr_config());
        $pool = [];
        foreach ($config['prizes'] as $prize) if ($prize['enabled'] && $prize['weight'] > 0) $pool[] = hgr_reward_template($prize);
        return $pool ?: null;
    } catch (InvalidArgumentException $error) {
        return null;
    }
}
function hgr_public_pool(array $pool): array
{
    $result = [];
    foreach ($pool as $prize) $result[] = [
        'id' => $prize['id'],
        'kind' => $prize['kind'],
        'name' => $prize['name'],
        'terms' => $prize['terms'],
        'odds_percent' => $prize['weight'] / 100,
        'valid_days' => $prize['valid_days'],
        'min_spend' => $prize['min_spend'],
        'min_paid_cups' => $prize['min_paid_cups'],
        'amount' => $prize['amount']
    ];
    return $result;
}
function hgr_odds_text(?array $pool = null): string
{
    $pool = $pool ?? hgr_reward_pool();
    if (!$pool) return hgr_text('prepare');
    $parts = [];
    $lang = function_exists('current_lang') ? current_lang() : 'vi';
    foreach ($pool as $prize) {
        $percent = rtrim(rtrim(number_format($prize['weight'] / 100, 2, '.', ''), '0'), '.');
        $parts[] = ($prize['name'][$lang] ?? $prize['name']['vi']) . ' ' . $percent . '%';
    }
    return implode(' · ', $parts);
}
function hgr_ready(): bool
{
    return hgr_reward_pool() !== null;
}
/** A 0đ prize: a money prize set to 0, so the customer wins nothing. */
function hgr_reward_is_zero(array $reward): bool
{
    return ($reward['kind'] ?? '') === 'discount' && (float)($reward['amount'] ?? 0) <= 0;
}
/** The pool without 0đ prizes, rescaled to 100%, so a share replay always wins. */
function hgr_winning_pool(array $pool): ?array
{
    $pool = array_values(array_filter($pool, fn($prize) => is_array($prize) && !hgr_reward_is_zero($prize)));
    $total = array_sum(array_map(fn($prize) => (int)($prize['weight'] ?? 0), $pool));
    if (!$pool || $total < 1) return null;
    $left = 10000;
    $top = 0;
    foreach ($pool as $i => $prize) {
        $pool[$i]['weight'] = intdiv((int)$prize['weight'] * 10000, $total);
        $left -= $pool[$i]['weight'];
        if ($pool[$i]['weight'] > $pool[$top]['weight']) $top = $i;
    }
    $pool[$top]['weight'] += $left;
    return $pool;
}
/** Supplied board pools stay immutable even after campaign or catalog settings change. */
function hgr_issue_reward_locked(string $token, ?array $pool = null): array
{
    if (!db()->inTransaction()) throw new RuntimeException('game_reward_requires_transaction');
    $pool = $pool ?? hgr_reward_pool();
    if (!$pool || !array_is_list($pool) || count($pool) > 12) throw new RuntimeException('rewards_unavailable');
    $total = 0;
    foreach ($pool as $prize) {
        if (
            !is_array($prize) || !is_int($prize['weight'] ?? null) || $prize['weight'] < 1 || $prize['weight'] > 10000
            || !is_int($prize['valid_days'] ?? null) || $prize['valid_days'] < 1 || $prize['valid_days'] > 365
            || !in_array($prize['kind'] ?? '', ['topping', 'tea', 'discount'], true) || !is_array($prize['name'] ?? null) || !is_array($prize['terms'] ?? null)
        ) throw new RuntimeException('rewards_unavailable');
        $total += $prize['weight'];
    }
    if ($total !== 10000) throw new RuntimeException('rewards_unavailable');
    $draw = random_int(1, 10000);
    $reward = null;
    foreach ($pool as $prize) {
        $draw -= $prize['weight'];
        if ($draw <= 0) {
            $reward = $prize;
            break;
        }
    }
    if (!$reward) throw new RuntimeException('rewards_unavailable');
    // A 0đ prize is not saved as a coupon; the board keeps it only to offer the share replay.
    if (hgr_reward_is_zero($reward)) {
        $reward['coupon_code'] = '';
        $reward['coupon_amount'] = 0;
        $reward['expires_at'] = '';
        return $reward;
    }
    do {
        $code = 'GAME-' . strtoupper(bin2hex(random_bytes(6)));
        $st = db()->prepare('SELECT COUNT(*) FROM customer_coupons WHERE code=?');
        $st->execute([$code]);
    } while ((int)$st->fetchColumn() > 0);
    db()->prepare("INSERT INTO customer_coupons(wallet_token,code,coupon_type,amount) VALUES(?,?,'game_reward',?)")->execute([$token, $code, $reward['amount']]);
    $couponId = (int)db()->lastInsertId();
    $expires = gmdate('Y-m-d H:i:s', time() + $reward['valid_days'] * 86400);
    $reward['coupon_code'] = $code;
    $reward['coupon_amount'] = $reward['amount'];
    $reward['expires_at'] = str_replace(' ', 'T', $expires) . 'Z';
    db()->prepare('INSERT INTO game_coupon_details(coupon_id,reward_json,expires_at) VALUES(?,?,?)')->execute([$couponId, json_encode($reward, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $expires]);
    return $reward;
}
function hgr_coupon_record(array $row): ?array
{
    if (($row['coupon_type'] ?? '') !== 'game_reward') return ['code' => $row['code'], 'type' => $row['coupon_type'], 'amount' => (float)$row['amount'], 'source' => 'game'];
    $details = hym_reward_one('SELECT reward_json,expires_at FROM game_coupon_details WHERE coupon_id=?', [(int)$row['id']]);
    if (!$details || strtotime($details['expires_at'] . ' UTC') <= time()) return null;
    $reward = json_decode($details['reward_json'], true);
    if (!is_array($reward)) return null;
    return ['code' => $row['code'], 'type' => 'game_reward', 'amount' => (float)$row['amount'], 'source' => 'game', 'reward' => $reward];
}
function hgr_label(array $reward, string $field = 'name'): string
{
    $data = $reward['reward'] ?? $reward;
    $lang = current_lang();
    return (string)($data[$field][$lang] ?? $data[$field]['vi'] ?? '');
}
/** Escaped prize label with money amounts (e.g. 46.000đ) wrapped in <b class="kyuc-money">. */
function hgr_label_html(array $reward): string
{
    return preg_replace('/\d[\d.,]*\s?(?:đ|₫)/u', '<b class="kyuc-money">$0</b>', e(hgr_label($reward))) ?? e(hgr_label($reward));
}
/** Prize list for the game page: money prizes on one line (largest highlighted, 0đ hidden), each gift on its own line. */
function hgr_odds_html(array $prizes): string
{
    $money = [];
    $gifts = [];
    $top = 0.0;
    foreach ($prizes as $prize) {
        if (!is_array($prize) || hgr_reward_is_zero($prize)) continue;
        if (($prize['kind'] ?? '') === 'discount') {
            $money[] = $prize;
            $top = max($top, (float)($prize['amount'] ?? 0));
        } else $gifts[] = $prize;
    }
    $html = '';
    if ($money) {
        // One heading, then the amounts only (no "Giảm" before each one).
        $parts = [];
        $lang = function_exists('current_lang') ? current_lang() : 'vi';
        foreach ($money as $prize) {
            $amount = '<b class="kyuc-money">' . e(hgr_money_text((int)($prize['amount'] ?? 0), $lang)) . '</b>';
            $parts[] = (float)($prize['amount'] ?? 0) === $top ? '<span class="kyuc-odds-top">' . $amount . '</span>' : $amount;
        }
        $html .= '<span class="kyuc-odds-line kyuc-odds-money">' . e(hgr_text('odds_money')) . ' ' . implode(' · ', $parts) . '</span>';
    }
    foreach ($gifts as $prize) $html .= '<span class="kyuc-odds-line kyuc-odds-gift">' . hgr_label_html($prize) . '</span>';
    return $html;
}
function hgr_expiry(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i') . ' (UTC+7)';
}
/** Pure cart calculation. Every gift item is free (gift_qty each) on top of the minimum paid cups. */
function hgr_calculate(array $cart, array $record, array $result, ?array $giftLineKeys = null): array
{
    $reward = $record['reward'] ?? null;
    if (!is_array($reward)) {
        $result['valid'] = false;
        return $result;
    }
    $qty = 0;
    $candidates = [];
    $giftIds = array_map('intval', $reward['item_ids'] ?? [$reward['item_id']]);
    $gift = max(1, (int)($reward['gift_qty'] ?? 1));
    // The cheapest matching line of each gift item carries its free units.
    $offer = static function (int $id, string $key, float $price, int $count) use (&$candidates) {
        if ($price > 0 && (!isset($candidates[$id]) || $price < $candidates[$id]['price'])) $candidates[$id] = ['key' => $key, 'price' => $price, 'qty' => $count];
    };
    foreach ($cart as $key => $row) {
        if (empty($row['product_id']) || !empty($row['standalone_topping_id']) || !empty($row['combo_drink'])) continue;
        $count = max(0, (int)($row['qty'] ?? 0));
        if ($count < 1) continue;
        $base = max(0, (float)($row['base_price'] ?? 0));
        if ($base <= 0) continue;
        $qty += $count;
        if ($giftLineKeys !== null && !in_array((string)$key, $giftLineKeys, true)) continue;
        $id = (int)$row['product_id'];
        if ($reward['kind'] === 'tea' && in_array($id, $giftIds, true) && (string)($row['cup_size'] ?? '') === hgr_item_size($reward, $id)) $offer($id, (string)$key, $base, $count);
        if ($reward['kind'] === 'topping') foreach ($row['toppings'] ?? [] as $top) {
            $tid = (int)($top['id'] ?? 0);
            if (in_array($tid, $giftIds, true)) $offer($tid, (string)$key, max(0, (float)($top['price'] ?? 0)), $count);
        }
    }
    $result['eligible_qty'] = $qty;
    $free = 0;
    foreach ($candidates as $c) $free += min($gift, $c['qty']);
    $paid = $qty - ($reward['kind'] === 'tea' ? $free : 0);
    if ($paid < max(1, (int)$reward['min_paid_cups']) || cart_subtotal($cart) < (float)$reward['min_spend']) return $result;
    if ($reward['kind'] === 'discount') {
        $remaining = max(0, (float)$record['amount']);
        foreach ($cart as $key => $row) {
            $take = min($remaining, max(0, (float)($row['unit_price'] ?? 0) * (int)($row['qty'] ?? 0)));
            if ($take > 0) {
                $result['line_discounts'][(string)$key] = $take;
                $result['discount'] += $take;
                $remaining -= $take;
            }
        }
        return $result;
    }
    foreach ($candidates as $c) {
        $n = min($gift, $c['qty']);
        $take = $c['price'] * $n;
        $result['line_discounts'][$c['key']] = ($result['line_discounts'][$c['key']] ?? 0) + $take;
        $result['discount'] += $take;
        $result['free_qty'] += $n;
    }
    return $result;
}

/** Quà chia sẻ Facebook đã cấu hình ở admin (settings.hgr_share_prizes): [['amount' => int, 'weight' => basis 10000], ...]; chưa cấu hình thì không mở lượt chia sẻ. */
function hymg_share_saved(): array {
    $list = [];
    $decoded = json_decode((string)setting('hgr_share_prizes', ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (is_array($row) && (int)($row['amount'] ?? 0) > 0 && (int)($row['weight'] ?? 0) > 0) $list[] = ['amount' => (int)$row['amount'], 'weight' => (int)$row['weight']];
        }
    }
    return $list;
}
