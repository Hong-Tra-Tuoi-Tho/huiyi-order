<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_admin();
$staffQuery = db()->prepare('SELECT id FROM admins WHERE id=?');
$staffQuery->execute([(int)$_SESSION['admin_id']]);
if (!$staffQuery->fetch()) {
    unset($_SESSION['admin_id']);
    redirect(base_url('admin/login.php'));
}
header('Cache-Control: no-store, private');
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || empty($_SESSION['_csrf'])) {
        http_response_code(419);
        exit(e(t('csrf_expired')));
    }
    verify_csrf();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'referral') {
    // Group referral settings (see lib/member_referral.php).
    $points = filter_var($_POST['group_referral_points'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
    if ($points === false) $error = ma('referral_invalid');
    else try {
        set_setting('group_referral_enabled', isset($_POST['group_referral_enabled']) ? '1' : '0');
        set_setting('group_referral_points', (string)$points);
        unset($GLOBALS['__drink_order_setting_cache']['group_referral_enabled'], $GLOBALS['__drink_order_setting_cache']['group_referral_points']);
        $message = ma('referral_saved');
    } catch (Throwable $exception) {
        $error = ma('save_failed');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rules = [];
        $sourceLines = preg_split('/\R/', trim((string)($_POST['allowed_sources'] ?? '')));
        if (count($sourceLines) > 100) throw new InvalidArgumentException('invalid_rules');
        foreach ($sourceLines as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3 || in_array('', $parts, true)) throw new InvalidArgumentException('invalid_rules');
            $rules[] = ['channel' => $parts[0], 'source' => $parts[1], 'sale_type' => $parts[2]];
        }
        hym_save_config([
            'enabled' => isset($_POST['enabled']),
            'base_url' => 'https://api.foodbook.vn',
            'pos_parent' => trim((string)($_POST['pos_parent'] ?? '')),
            'token' => trim((string)($_POST['token'] ?? '')),
            'clear_token' => isset($_POST['clear_token']),
            'transactions_enabled' => isset($_POST['transactions_enabled']),
            'allowed_sources' => $rules,
            'source_timezone' => trim((string)($_POST['source_timezone'] ?? 'Asia/Ho_Chi_Minh')),
        ]);
        $message = ma('saved');
    } catch (InvalidArgumentException $exception) {
        $error = ma('invalid_rules');
    } catch (Throwable $exception) {
        $error = ma('save_failed');
    }
}
$configuration = hym_config();
$sourceText = implode("\n", array_map(static fn($r) => ($r['channel'] ?? '') . '|' . ($r['source'] ?? '') . '|' . ($r['sale_type'] ?? ''), $configuration['allowed_sources'] ?? []));
admin_header(ma('ipos_settings'));
?>
<?php if ($message): ?><div class="alert success" role="status"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel narrow">
    <p><?= e(ma('source')) ?></p>
    <p class="field-hint"><?= e(ma('no_api_write')) ?></p>
    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?>
        <label class="toggle-row"><input type="checkbox" name="enabled" value="1" <?= !empty($configuration['enabled']) ? 'checked' : '' ?>><span><?= e(ma('enabled')) ?></span></label>
        <label><?= e(ma('brand')) ?><input name="pos_parent" maxlength="100" value="<?= e($configuration['pos_parent'] ?? '') ?>"><small class="field-hint"><?= e(ma('brand_help')) ?></small></label>
        <label><?= e(ma('token')) ?><input type="password" name="token" value="" maxlength="2048" autocomplete="new-password"><small class="field-hint"><?= e(ma(!empty($configuration['token_configured']) ? 'token_present' : 'token_missing')) ?></small></label>
        <label class="check-row"><input type="checkbox" name="clear_token" value="1"><span><?= e(ma('clear_token')) ?></span></label>
        <p class="field-hint"><?= e(ma('endpoint')) ?>：https://api.foodbook.vn</p>
        <label><?= e(ma('timezone')) ?><input name="source_timezone" required maxlength="64" value="<?= e($configuration['source_timezone'] ?? 'Asia/Ho_Chi_Minh') ?>"><small class="field-hint"><?= e(ma('timezone_help')) ?></small></label>
        <p><?= e(ma('profile_note')) ?></p>
        <label class="toggle-row"><input type="checkbox" name="transactions_enabled" value="1" <?= !empty($configuration['transactions_enabled']) ? 'checked' : '' ?>><span><?= e(ma('transactions_enabled')) ?></span></label>
        <label><?= e(ma('rules')) ?><textarea name="allowed_sources" rows="5" maxlength="12000" spellcheck="false"><?= e($sourceText) ?></textarea><small class="field-hint"><?= e(ma('rules_help')) ?></small></label>
        <p class="field-hint"><?= e(ma('rules_pending')) ?></p>
        <button class="btn primary" type="submit"><?= e(ma('save')) ?></button>
    </form>
</section>
<section class="panel narrow">
    <h2><?= e(ma('referral_title')) ?></h2>
    <p class="field-hint"><?= e(ma('referral_help')) ?></p>
    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="form" value="referral">
        <label class="toggle-row"><input type="checkbox" name="group_referral_enabled" value="1" <?= hym_referral_enabled() ? 'checked' : '' ?>><span><?= e(ma('referral_enabled')) ?></span></label>
        <label><?= e(ma('referral_points')) ?><input type="number" name="group_referral_points" required min="0" max="1000000" step="1" inputmode="numeric" value="<?= hym_referral_points() ?>"></label>
        <button class="btn primary" type="submit"><?= e(ma('save')) ?></button>
    </form>
</section>
<?php admin_footer(); ?>