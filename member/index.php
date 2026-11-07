<?php
// The Zalo app's registered callback is this page; hand the OAuth response to the Zalo handler.
if (isset($_GET['state']) && (isset($_GET['code']) || isset($_GET['error']))) {
  header('Location: zalo.php?' . ($_SERVER['QUERY_STRING'] ?? ''));
  exit;
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/promotion_views.php';
require_once __DIR__ . '/../lib/order_detail_view.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$returnOrder = $_GET['return_order'] ?? '';
$returnOrder = is_string($returnOrder) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $returnOrder) ? $returnOrder : '';
// Zalo-only sign-in: phones open the Mini App directly, computers also get its QR code.
$zaloOnly = hym_zalo_only();
$isPhone = (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$member = null;
$platformOrders = [];
$loadFailed = false;
try {
  hym_schema();
  $member = hym_current_member();
  if ($member) {
    $memberId = (int)$member['id'];
    $platformOrders = hym_platform_orders($memberId);
  }
} catch (Throwable $error) {
  $loadFailed = true;
}
// Máy tính chưa đăng nhập: QR mang mã riêng của session này để điện thoại xác nhận đăng nhập hộ.
$qrLoginUrl = '';
if ($zaloOnly && !$isPhone && !$member && !$loadFailed) {
  try {
    $qrLoginUrl = hym_zalo_miniapp_qr_url(hym_qr_login_code());
  } catch (Throwable $error) {
    error_log('member_qr_login: ' . $error->getMessage());
    $qrLoginUrl = hym_zalo_miniapp_url();
  }
}
// A guest sent here from a members-only entry point (e.g. "share cart") returns there once signed in.
$returnTarget = $_GET['return'] ?? '';
if (is_string($returnTarget) && $returnTarget !== '') hym_remember_return($returnTarget);
if ($member && ($returnPath = hym_take_return()) !== null) {
  unset($_SESSION['member_flash']);
  redirect(base_url($returnPath));
}
// Group referral points (lib/member_referral.php): balance and how many members sit directly under this one.
$groupPoints = 0;
$groupChildren = 0;
if ($member) try {
  $st = db()->prepare('SELECT points_balance,(SELECT COUNT(*) FROM members c WHERE c.parent_id=m.id) children FROM members m WHERE m.id=?');
  $st->execute([(int)$member['id']]);
  $row = $st->fetch() ?: [];
  $groupPoints = (int)($row['points_balance'] ?? 0);
  $groupChildren = (int)($row['children'] ?? 0);
} catch (Throwable $error) {
}
$rewardCoupons = [];
$rewardBalance = 0;
$rewardsFailed = false;
if ($member) try {
  $rewardCoupons = available_game_coupons();
  $rewardBalance = game_wallet_balance();
} catch (Throwable $error) {
  $rewardsFailed = true;
}
$flash = $_SESSION['member_flash'] ?? null;
unset($_SESSION['member_flash']);
// Legacy sync submissions must not reveal upstream status in the customer interface.
if (is_array($flash) && str_starts_with((string)($flash['key'] ?? ''), 'member_sync_')) $flash = null;
$formatDate = static function ($value, bool $dateOnly = false, bool $isUtc = true): string {
  $value = trim((string)$value);
  if ($value === '' || str_starts_with($value, '0000-00-00')) return mt('member_not_provided');
  try {
    $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    if (!$dateOnly && $isUtc) $date = $date->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    return $date->format($dateOnly ? 'd/m/Y' : 'd/m/Y H:i');
  } catch (Throwable $error) {
    return mt('member_not_provided');
  }
};
$GLOBALS['hym_member_page'] = true;
// Chưa đăng nhập thì trang là lời mời kết nối, đã đăng nhập mới là "Tài khoản của tôi".
$pageTitle = $member ? mt('member_title') : mt('member_connect_title');
front_header($pageTitle);
?>
<div class="hym-page<?= $member ? '' : ' hym-page-auth' ?>">
  <a class="back" href="<?= e(base_url('index.php')) ?>">← <?= e(mt('member_back_menu')) ?></a>
  <header class="hym-page-head">
    <div><span class="hym-eyebrow"><?= e(mt('member_entry')) ?></span>
      <h1><?= e($pageTitle) ?></h1>
      <?php if ($member): ?>
        <div class="hym-points" aria-label="<?= e(mt('member_group_points_title')) ?>">
          <span class="hym-points-icon" aria-hidden="true">★</span>
          <div class="hym-points-text">
            <strong><?= e(mt('member_group_points_title')) ?></strong>
            <small><?= e(mt('member_group_points_hint', ['points' => hym_referral_points()])) ?></small>
            <span class="hym-points-children"><?= e(mt('member_group_children', ['count' => $groupChildren])) ?></span>
          </div>
          <div class="hym-points-value"><b><?= number_format($groupPoints, 0, ',', '.') ?></b><span><?= e(mt('member_group_points_unit')) ?></span></div>
        </div>
      <?php else: ?><p><?= e(mt('member_intro')) ?></p><?php endif; ?>
    </div>
  </header>
  <?php if (is_array($flash) && !empty($flash['key'])): $flashType = in_array($flash['type'] ?? '', ['success', 'info', 'danger'], true) ? $flash['type'] : 'danger';
    $flashText = mt((string)$flash['key']);
    if ($flashText === (string)$flash['key']) $flashText = mt('member_save_failed'); ?>
    <div class="alert <?= e($flashType) ?>" role="status"><?= e($flashText) ?></div>
  <?php endif; ?>
  <?php if ($loadFailed): ?><div class="alert danger" role="alert"><?= e(mt('member_load_failed')) ?></div><?php endif; ?>
  <?php if (!$member): ?>
    <div class="hym-auth-single">
      <section class="hym-card" id="member-login">
        <!-- <h2><?= e(mt('member_login_title')) ?></h2> -->
        <?php if ($zaloOnly): ?>
          <!-- <p class="hym-note"><?= e(mt('member_zalo_only_intro')) ?></p> -->
        <?php else: ?>
          <form class="hym-form" method="post" action="<?= e(base_url('member/action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="login"><?php if ($returnOrder !== ''): ?><input type="hidden" name="return_order" value="<?= e($returnOrder) ?>"><?php endif; ?>
            <label for="member-login-phone"><?= e(mt('member_phone')) ?><input id="member-login-phone" type="tel" inputmode="tel" name="phone" required maxlength="32" autocomplete="username"></label>
            <label for="member-login-password"><?= e(mt('member_password')) ?>
              <span class="hym-pass">
                <input id="member-login-password" type="password" name="password" required maxlength="72" autocomplete="current-password">
                <button class="hym-pass-toggle" type="button" data-pass-toggle aria-controls="member-login-password" aria-pressed="false" aria-label="<?= e(mt('member_password_show')) ?>" data-show-label="<?= e(mt('member_password_show')) ?>" data-hide-label="<?= e(mt('member_password_hide')) ?>">
                  <svg class="hym-pass-eye" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                  <svg class="hym-pass-eye-off" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                  </svg>
                </button>
              </span>
            </label>
            <button class="btn primary block" type="submit"><?= e(mt('member_login')) ?></button>
          </form>
        <?php endif; ?>

        <!-- Đăng nhập xác thực qua zalo -->
        <?php if ($zaloOnly || hym_zalo_enabled()): ?>
          <?php if (!$zaloOnly): ?><div class="hym-auth-divider"><span><?= e(mt('member_or')) ?></span></div><?php endif; ?>
          <a class="btn block btn-zalo-login" href="<?= e($zaloOnly ? hym_zalo_miniapp_url() : base_url('member/zalo.php' . ($returnOrder !== '' ? '?return_order=' . rawurlencode($returnOrder) : ''))) ?>">
            <svg viewBox="0 0 48 48" aria-hidden="true">
              <rect width="48" height="48" rx="12" fill="#fff" /><text x="24" y="30" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" font-weight="700" fill="#0068FF">Zalo</text>
            </svg>
            <span><?= e(mt('member_login_zalo')) ?></span>
          </a>
        <?php endif; ?>

        <?php if ($qrLoginUrl !== ''): ?>
          <div class="hym-zalo-qr">
            <canvas data-zalo-qr="<?= e($qrLoginUrl) ?>" data-qr-status="<?= e(base_url('member/qr-status.php')) ?>" role="img" aria-label="<?= e(mt('member_zalo_qr_hint')) ?>"></canvas>
            <p class="hym-note"><?= e(mt('member_zalo_qr_hint')) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!$zaloOnly): ?>
          <a class="btn block hym-register-link" href="<?= e(base_url('member/register.php')) ?>"><?= e(mt('member_register')) ?></a>
        <?php endif; ?>
      </section>
    </div>
  <?php else: ?>
    <section class="hym-card" id="member-rewards">
      <h2><?= e(mt('member_rewards_title')) ?></h2>
      <p class="hym-source"><?= e(mt('member_rewards_source')) ?></p>
      <p class="hym-note"><?= e(mt('member_rewards_hint')) ?></p>
      <div class="hym-reward-toolbar"><strong><?= e(mt('member_draw_balance', ['chances' => (int)$rewardBalance])) ?></strong><?php if (minigame_enabled() && $rewardBalance > 0): ?><a class="btn" href="<?= e(base_url('minigame/')) ?>"><?= e(mt('member_go_draw')) ?></a><?php endif; ?></div>
      <?php if ($rewardsFailed): ?><p class="hym-note" role="status"><?= e(mt('member_rewards_load_failed')) ?></p><?php elseif (!$rewardCoupons): ?><p class="hym-empty"><?= e(mt('member_rewards_empty')) ?></p><?php else: ?>

        <!-- close-test-Hai | Danh sách thẻ khuyến mãi -->
        <!-- <div class="hym-reward-list">
          <?php foreach ($rewardCoupons as $reward): ?><article class="hym-reward">
              <div><span class="hym-label"><?= e(pt('reward_code')) ?></span><code><?= e($reward['code']) ?></code><strong><?= e($reward['coupon_type'] === 'game_reward' ? hgr_label($reward) : mt('member_reward_amount', ['amount' => currency() . money($reward['amount'])])) ?></strong>
                <p class="hym-note"><?= e($reward['coupon_type'] === 'game_reward' ? hgr_label($reward, 'terms') . ' · ' . hgr_text('expires') . ' ' . hgr_expiry((string)($reward['expires_at'] ?? '')) : mt('member_reward_rule')) ?></p>
              </div>
              <form method="post" action="<?= e(base_url('actions/apply_coupon.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="promotion_mode" value="reward"><input type="hidden" name="coupon_code" value="<?= e($reward['code']) ?>"><button class="btn primary" type="submit"><?= e(mt('member_reward_use')) ?></button></form>
            </article><?php endforeach; ?>
        </div> -->
      <?php endif; ?>
    </section>
    <section class="hym-card" id="member-local-profile">
      <h2><?= e(mt('member_local_profile')) ?></h2>
      <p class="hym-source"><?= e(mt('member_local_source')) ?></p>
      <p class="hym-note"><?= e(mt('member_local_profile_hint')) ?></p>
      <dl class="hym-facts">
        <div>
          <dt><?= e(mt('member_account_phone')) ?></dt>
          <dd><?= e($member['phone']) ?></dd>
        </div>
      </dl>
      <form class="hym-form" method="post" action="<?= e(base_url('member/action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
        <label for="member-profile-name"><?= e(mt('member_display_name')) ?><input id="member-profile-name" name="display_name" maxlength="100" autocomplete="name" value="<?= e($member['display_name'] ?? '') ?>"></label>
        <label for="member-profile-address"><?= e(mt('member_delivery_address')) ?> <span class="hym-optional"><?= e(mt('member_optional')) ?></span><textarea id="member-profile-address" name="delivery_address" maxlength="255" autocomplete="street-address" rows="3"><?= e($member['delivery_address'] ?? '') ?></textarea></label>
        <button class="btn primary" type="submit"><?= e(mt('member_save_profile')) ?></button>
      </form>
    </section>
    <section class="hym-card" id="member-platform-orders">
      <h2><?= e(mt('member_platform_orders')) ?></h2>
      <p class="hym-source"><?= e(mt('member_platform_source')) ?></p>
      <?php if (!$platformOrders): ?><p class="hym-empty"><?= e(mt('member_platform_empty')) ?></p><?php else: ?><div class="hym-order-list">
          <?php foreach ($platformOrders as $order): ?><article class="hym-order">
              <div class="hym-record-head">
                <div><span class="hym-label"><?= e(mt('member_order_number')) ?></span>
                  <h3><?= e($order['order_no']) ?> <span class="hym-order-type">- <?= e(mt(empty($order['group_room_id']) ? 'member_order_single' : 'member_order_group')) ?></span></h3>
                </div><span class="hym-order-status"><?= e(status_label((string)$order['status'])) ?></span>
              </div>
              <dl class="hym-facts">
                <div>
                  <dt><?= e(mt('member_order_date')) ?></dt>
                  <dd><?= e($formatDate($order['created_at'], false, false)) ?></dd>
                </div>
                <div class="hym-record-amount">
                  <dt><?= e(mt('member_order_amount')) ?></dt>
                  <dd><?= e(currency()) ?><?= e(money($order['total_amount'])) ?></dd>
                </div>
              </dl>
              <?php if ($detail = order_detail_render($order)): ?><details class="hym-order-detail">
                  <summary><?= e(mt('member_order_detail')) ?></summary><?= $detail ?>
                </details><?php endif; ?><?php if (htp_receipt((int)$order['id'])): ?><a class="btn" href="<?= e(base_url('payment.php?order=' . rawurlencode($order['order_no']))) ?>"><?= e(htp('payment_link')) ?></a><?php endif; ?>
            </article><?php endforeach; ?>
        </div><?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<script>
  // Giới hạn danh sách đơn website hiển thị 3 đơn, phần còn lại cuộn trong khung
  (function() {
    var list = document.querySelector('#member-platform-orders .hym-order-list');
    if (!list || list.children.length <= 3) return;

    function cap() {
      list.classList.remove('is-capped');
      list.style.maxHeight = '';
      list.style.overflowY = '';
      var listTop = list.getBoundingClientRect().top;
      var third = list.children[2].getBoundingClientRect();
      list.style.maxHeight = Math.ceil(third.bottom - listTop + 2) + 'px';
      list.style.overflowY = 'auto';
      list.classList.add('is-capped');
    }
    cap();
    window.addEventListener('resize', cap);
  })();
</script>
<?php if ($qrLoginUrl !== ''): ?>
  <script src="<?= e(base_url('assets/vendor/huiyi-qrcode.js?v=19.8.0')) ?>"></script>
  <script>
    // Vẽ mã QR mở Mini App để khách trên máy tính quét bằng Zalo trên điện thoại.
    // Sau đó hỏi server định kỳ: điện thoại xác nhận xong thì máy tính tự đăng nhập.
    (function() {
      var canvas = document.querySelector('[data-zalo-qr]');
      if (!canvas || !window.HuiYiQR) return;

      function draw(url) {
        var qr = window.HuiYiQR.encode(url);
        var border = 4;
        var scale = 6;
        var size = (qr.size + border * 2) * scale;
        canvas.width = size;
        canvas.height = size;

        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = '#000';
        for (var y = 0; y < qr.size; y++) {
          for (var x = 0; x < qr.size; x++) {
            if (qr.isDark(x, y)) ctx.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
          }
        }
      }

      draw(canvas.getAttribute('data-zalo-qr'));

      var statusUrl = canvas.getAttribute('data-qr-status');
      if (!statusUrl || !window.fetch) return;

      var done = false;
      var busy = false;

      function check() {
        // Tab bị ẩn thì tạm dừng, quay lại tab sẽ hỏi tiếp.
        if (done || busy || document.hidden) return;
        busy = true;

        fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store' })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data.status === 'ok') {
              done = true;
              window.location.reload();
              return;
            }
            if (data.status === 'expired' && data.qr_url) {
              draw(data.qr_url);
            }
            if (data.status === 'disabled') {
              done = true;
            }
          })
          .catch(function() {})
          .then(function() { busy = false; });
      }

      setInterval(check, 2500);
      document.addEventListener('visibilitychange', check);
    })();
  </script>
<?php endif; ?>
<?php front_footer(); ?>