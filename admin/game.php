<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_admin();
require_once __DIR__ . '/../lib/game_reward_admin.php';
require_once __DIR__ . '/../lib/game_revenue.php';
$rewardMessage = hgr_admin_save();
$wallets = [];
$recent = [];
$coupons = [];
try {
    $wallets = db()->query('SELECT * FROM game_wallets ORDER BY updated_at DESC LIMIT 200')->fetchAll();
} catch (Throwable $e) {
}
try {
    $recent = db()->query("SELECT id,order_no,status,game_chances_awarded,created_at FROM orders WHERE game_award_processed=1 OR game_chances_awarded>0 ORDER BY id DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) {
}
try {
    $coupons = db()->query('SELECT c.*,d.reward_json,d.expires_at FROM customer_coupons c LEFT JOIN game_coupon_details d ON d.coupon_id=c.id ORDER BY c.id DESC LIMIT 100')->fetchAll();
    foreach ($coupons as &$coupon) if ($coupon['coupon_type'] === 'game_reward') $coupon['reward'] = json_decode($coupon['reward_json'] ?? '', true);
    unset($coupon);
} catch (Throwable $e) {
}
admin_header(t('game_center'));
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/game-admin.css?v=19.9.4')) ?>">
<?php if ($rewardMessage !== ''): ?><div class="alert" role="status"><?= e($rewardMessage) ?></div><?php endif; ?>
<?php hgr_revenue_panel(); ?>
<?php hgr_admin_form(); ?>
<div class="stats game-stats"><a href="#"><b><?= array_sum(array_map(fn($r) => (int)$r['available_chances'], $wallets)) ?></b><span><?= e(t('available')) ?></span></a><a href="#"><b><?= array_sum(array_map(fn($r) => (int)$r['total_earned'], $wallets)) ?></b><span><?= e(t('earned')) ?></span></a><a href="#"><b><?= array_sum(array_map(fn($r) => (int)$r['total_played'], $wallets)) ?></b><span><?= e(t('played')) ?></span></a><a href="#"><b><?= game_cups_per_chance() ?></b><span><?= e(t('game_cups_per_chance')) ?></span></a></div>
<!-- <section class="panel">
    <h2><?= e(t('game_wallets')) ?></h2>
    <p class="field-hint"><?= e(t('anonymous_game_note')) ?></p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= e(t('wallet')) ?></th>
                    <th><?= e(t('available')) ?></th>
                    <th><?= e(t('earned')) ?></th>
                    <th><?= e(t('played')) ?></th>
                    <th><?= e(t('time')) ?></th>
                </tr>
            </thead>
            <tbody><?php if (!$wallets): ?><tr>
                        <td colspan="5"><?= e(t('no_data')) ?></td>
                    </tr><?php endif; ?><?php foreach ($wallets as $w): ?><tr>
                        <td><code>…<?= e(substr($w['wallet_token'], -8)) ?></code></td>
                        <td><?= number_format((int)$w['available_chances']) ?></td>
                        <td><?= number_format((int)$w['total_earned']) ?></td>
                        <td><?= number_format((int)$w['total_played']) ?></td>
                        <td><?= e($w['updated_at']) ?></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section> -->
<!-- <section class="panel">
    <h2><?= e(t('game_awarded')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= e(t('order_no')) ?></th>
                    <th><?= e(t('status')) ?></th>
                    <th><?= e(t('game_awarded')) ?></th>
                    <th><?= e(t('time')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody><?php if (!$recent): ?><tr>
                        <td colspan="5"><?= e(t('no_data')) ?></td>
                    </tr><?php endif; ?><?php foreach ($recent as $r): ?><tr>
                        <td><?= e($r['order_no']) ?></td>
                        <td><span class="status <?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span></td>
                        <td><?= number_format((int)$r['game_chances_awarded']) ?></td>
                        <td><?= e($r['created_at']) ?></td>
                        <td><a class="btn small" href="order.php?id=<?= $r['id'] ?>"><?= e(t('view')) ?></a></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section> -->

<!-- <section class="panel">
    <h2><?= e(t('game_reward_coupon')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= e(t('coupon_code')) ?></th>
                    <th><?= e(t('amount')) ?></th>
                    <th><?= e(t('status')) ?></th>
                    <th><?= e(t('time')) ?></th>
                </tr>
            </thead>
            <tbody><?php if (!$coupons): ?><tr>
                        <td colspan="4"><?= e(t('no_data')) ?></td>
                    </tr><?php endif; ?><?php foreach ($coupons as $c): ?><tr>
                        <td><code><?= e($c['code']) ?></code></td>
                        <td><?= e($c['coupon_type'] === 'game_reward' ? hgr_label($c) : currency() . money($c['amount'])) ?></td>
                        <td><?= empty($c['used_order_id']) ? e(t('available')) : e(t('applied')) ?></td>
                        <td><?= e($c['created_at']) ?></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section> -->
<script defer src="<?= e(base_url('assets/js/game-admin.js?v=19.9.5')) ?>"></script>
<?php admin_footer(); ?>