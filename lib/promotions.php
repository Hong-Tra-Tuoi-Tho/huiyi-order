<?php

/** One promotion per order. The selected rule is frozen before any award is issued. */
function promotion_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    if ($pdo->inTransaction()) throw new RuntimeException('promotion_schema_inside_transaction');
    $key = 'promotion_schema_version';
    $version = '19.6.0';
    if (setting($key, '') !== $version) {
        if ((int)$pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(),':promotion-schema'),10)")->fetchColumn() !== 1) throw new RuntimeException('promotion_schema_busy');
        try {
            $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
            $st->execute([$key]);
            if ((string)$st->fetchColumn() !== $version) {
                foreach ([['orders', 'promotion_mode', "VARCHAR(16) NOT NULL DEFAULT 'legacy'"], ['orders', 'game_chances_expected', 'INT NULL'], ['group_rooms', 'promotion_mode', "VARCHAR(16) NOT NULL DEFAULT 'legacy'"]] as [$table, $col, $def])
                    if (!column_exists($table, $col)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                // Existing quote signatures and historical orders remain intact.
                $pdo->beginTransaction();
                try {
                    $rows = $pdo->query("SELECT id,status,coupon_code,coupon_type,settlement_json FROM group_rooms WHERE promotion_mode='legacy' AND status<>'submitted' AND order_id IS NULL FOR UPDATE")->fetchAll();
                    $st = $pdo->prepare('UPDATE group_rooms SET promotion_mode=? WHERE id=?');
                    foreach ($rows as $room) {
                        $frozen = $room['status'] === 'locked' ? json_decode((string)($room['settlement_json'] ?? ''), true) : null;
                        $coupon = is_array($frozen) ? ($frozen['coupon_record'] ?? $frozen['coupon'] ?? null) : null;
                        $st->execute([promotion_infer_mode((string)($coupon['code'] ?? $room['coupon_code']), (string)($coupon['type'] ?? $room['coupon_type'])), $room['id']]);
                    }
                    set_setting($key, $version);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    unset($GLOBALS['__drink_order_setting_cache'][$key]);
                    throw $e;
                }
            } else $GLOBALS['__drink_order_setting_cache'][$key] = $version;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':promotion-schema'))");
        }
    }
    $done = true;
}
function promotion_drink_qty(array $cart): int
{
    $qty = 0;
    // Ly nước nằm trong combo không tính vào ưu đãi.
    foreach ($cart as $row) if (!empty($row['product_id']) && empty($row['standalone_topping_id']) && empty($row['combo_drink'])) $qty += max(0, (int)($row['qty'] ?? 0));
    return $qty;
}
/** "Buy 4 get 1" only applies from this many drinks: the discount is 1 drink per full 5 (cart_coupon_summary). */
function promotion_buy4_min_qty(): int
{
    return 5;
}
function promotion_infer_mode(string $code, string $type = ''): string
{
    if ($code === '') return minigame_enabled() ? 'draw' : 'none';
    return $type === 'buy4get1' || $code === coupon_code() ? 'buy4get1' : 'reward';
}
function promotion_cart_mode(): string
{
    $mode = $_SESSION['promotion_mode'] ?? null;
    return is_string($mode) && in_array($mode, ['none', 'buy4get1', 'draw', 'reward'], true) ? $mode : promotion_infer_mode(active_coupon_code());
}
function promotion_validate_choice(string $mode, string $code): ?array
{
    if (!in_array($mode, ['none', 'buy4get1', 'draw', 'reward'], true)) throw new RuntimeException('promotion_invalid');
    if (in_array($mode, ['none', 'draw'], true)) {
        if ($code !== '' || ($mode === 'draw' && !minigame_enabled())) throw new RuntimeException('promotion_invalid');
        return null;
    }
    $record = coupon_record($code);
    if (!$record || ($mode === 'buy4get1' && ($record['type'] !== 'buy4get1' || $record['source'] !== 'public')) || ($mode === 'reward' && $record['source'] !== 'game')) throw new RuntimeException('coupon_changed');
    return $record;
}
function promotion_selection_from_post(array $data): array
{
    $code = $data['coupon_code'] ?? '';
    if (!is_string($code)) throw new RuntimeException('promotion_invalid');
    $code = strtoupper(trim($code));
    if (strlen($code) > 80) throw new RuntimeException('promotion_invalid');
    $mode = $data['promotion_mode'] ?? null;
    if ($mode === null) $mode = $code !== '' ? promotion_infer_mode($code) : 'none';
    if (!is_string($mode)) throw new RuntimeException('promotion_invalid');
    if ($mode === 'buy4get1') {
        $public = coupon_code();
        if ($code !== '' && $code !== $public) throw new RuntimeException('promotion_invalid');
        $code = $public;
    }
    $record = promotion_validate_choice($mode, $code);
    return ['mode' => $mode, 'code' => $code, 'record' => $record];
}
function promotion_cart_choice(?array $cart = null): array
{
    $cart = $cart ?? ($_SESSION['cart'] ?? []);
    $mode = promotion_cart_mode();
    $code = active_coupon_code();
    $valid = true;
    try {
        promotion_validate_choice($mode, $code);
    } catch (Throwable $e) {
        $valid = false;
    }
    $qty = promotion_drink_qty($cart);
    return ['mode' => $mode, 'code' => $code, 'eligible_qty' => $qty, 'expected_chances' => $valid && $mode === 'draw' ? game_chances_for_drink_qty($qty) : 0, 'valid' => $valid];
}
/** Includes the promotion even when switching it does not change the price. */
function promotion_quote_signature(?array $cart = null): string
{
    $cart = $cart ?? ($_SESSION['cart'] ?? []);
    $choice = promotion_cart_choice($cart);
    $member = function_exists('hym_current_member') ? hym_current_member() : null;
    return hash_hmac('sha256', json_encode([$cart, $choice, cart_coupon_summary($cart), $member ? (int)$member['id'] : null], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), csrf_token());
}
function promotion_room_mode(array $room): string
{
    $mode = $room['promotion_mode'] ?? 'legacy';
    return in_array($mode, ['none', 'buy4get1', 'draw', 'reward'], true) ? $mode : promotion_infer_mode((string)($room['coupon_code'] ?? ''), (string)($room['coupon_type'] ?? ''));
}
function promotion_room_choice(array $room, array $cart): array
{
    $settlement = hyg_settlement($room);
    if ($settlement && in_array($room['status'], ['locked', 'submitted'], true) && isset($settlement['promotion'])) return $settlement['promotion'];
    $mode = promotion_room_mode($room);
    $qty = promotion_drink_qty($cart);
    $chances = $mode === 'draw' && minigame_enabled() ? game_chances_for_drink_qty($qty) : 0;
    // Pre-snapshot locked rooms were quoted under the original one-chance rule.
    if ($settlement && in_array($room['status'], ['locked', 'submitted'], true)) $chances = min(1, $chances);
    return ['mode' => $mode, 'code' => (string)($room['coupon_code'] ?? ''), 'eligible_qty' => $qty, 'expected_chances' => $chances, 'valid' => true];
}
function promotion_group_record(array $room): ?array
{
    $mode = promotion_room_mode($room);
    $code = (string)($room['coupon_code'] ?? '');
    try {
        return promotion_validate_choice($mode, $code);
    } catch (Throwable $e) {
        hyg_fail('coupon_changed');
    }
}
