<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/group_views.php';
try {
  hyg_headers();
  hyg_schema();
  hyg_device(true);
  $r = null;
  $invited = null;
  $id = (string)($_GET['id'] ?? '');
  $invite = (string)($_GET['invite'] ?? '');
  // Creating a group needs a signed-in member (the group's owner_member_id).
  if ($id === '' && $invite === '' && !hym_current_member()) redirect(hym_login_gate_url('group'));
  if ($id !== '') {
    $r = hyg_room($id);
    hyg_member($r);
    // The host with an unsent Mini Game checkout for this room always goes back to that checkout page.
    $pendingGame = hcgs_locked();
    if ($pendingGame && hash_equals(hcgs_group_of($pendingGame), (string)$r['public_id'])) redirect(hcgs_checkout_url($pendingGame));
  } elseif ($invite !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/D', $invite)) hyg_fail('not_found');
    $invited = hyg_one("SELECT * FROM group_rooms WHERE invite_token=?", [$invite]);
    if (!$invited) hyg_fail('not_found');
    $joined = hyg_one('SELECT id FROM group_members WHERE room_id=? AND device_hash=?', [$invited['id'], hyg_device()]);
    if ($joined) redirect(hyg_room_url($invited['public_id']));
    hyg_open($invited);
  }
  $rooms = hyg_all('SELECT r.public_id,r.title,r.status,r.expires_at FROM group_rooms r JOIN group_members m ON m.room_id=r.id WHERE m.device_hash=? ORDER BY r.id DESC LIMIT 20', [hyg_device()]);
} catch (Throwable $e) {
  hyg_error_page($e);
}
if ($r) {
  $GLOBALS['hyg_context'] = ['room' => $r, 'member' => hyg_member($r)];
  $GLOBALS['hyg_hide_mode'] = true;
}
$GLOBALS['hyg_qr_page'] = true;
// Signed-in customers start with their member name as the group nickname; they can still change it.
try {
  $memberNickname = mb_substr(trim((string)(hym_current_member()['display_name'] ?? '')), 0, 32);
} catch (Throwable $e) {
  $memberNickname = '';
}
// A name typed before being sent to sign in wins over the member name for that same invite.
$pendingNickname = $_SESSION['hyg_pending_nickname'] ?? null;
if (is_array($pendingNickname) && $invited && ($pendingNickname['invite'] ?? '') === $invite) {
  unset($_SESSION['hyg_pending_nickname']);
  if (($pendingNickname['created_at'] ?? 0) >= time() - 1800) $memberNickname = (string)($pendingNickname['nickname'] ?? '');
}
front_header(gt('title'));
$msg = (string)($_SESSION['hyg_message'] ?? '');
unset($_SESSION['hyg_message']);
$orderSuccess = !empty($_SESSION['hyg_order_success']);
unset($_SESSION['hyg_order_success']);
?>
<div class="hyg-page">
  <a class="back" href="<?= e(base_url('index.php')) ?>">← <?= e(t('back_menu')) ?></a>
  <?php if ($msg !== ''): ?><div class="alert info"><?= e($msg) ?></div><?php endif; ?>
  <?php if (!$r && !$invited): ?>
    <!-- Group order guide: create page = step 1. -->
    <?= hyg_render_steps(1) ?>
  <?php elseif ($r): ?>
    <!-- Group order guide: room page = step 2, or 3 once the group has a drink. group-order.js refreshes this slot. -->
    <div id="hyg-steps-slot"><?= $r['status'] === 'submitted' ? '' : hyg_render_steps(hyg_room_step($r)) ?></div>
  <?php endif; ?>
  <?php if ($r): ?>
    <?php
    // The host's share card is titled "Chia sẻ giỏ hàng - <group name>". Without it (members, or a closed/expired
    // room) the group name stays as the page title. The "Đặt hàng nhóm" eyebrow is no longer shown.
    $share = hyg_host($r) ? hyg_render_share($r) : '';
    ?>
    <?php if ($share === ''): ?>
      <header class="hyg-page-head">
        <div>
          <h1><?= e($r['title']) ?></h1>
        </div><?php /* TẠM ẨN nút "Nhóm của tôi" ở đầu phòng nhóm (danh sách nhóm đã có ở trang giỏ hàng cart.php)
        <a class="btn" href="<?= e(base_url('group/')) ?>"><?= e(gt('my_groups')) ?></a>
        */ ?>
      </header>
    <?php endif; ?>
    <?php if (hyg_host($r)): ?><div id="hyg-share-slot"><?= $share ?></div><?php endif; ?>
    <div class="hyg-sync" role="status"><span data-sync-status><?= e(gt('sync')) ?></span><button type="button" class="hyg-text-btn" data-group-refresh><?= e(gt('refresh')) ?></button></div>
    <?= hyg_render_room($r) ?>
    <script id="hyg-config" type="application/json">
      <?= json_encode(['snapshot' => base_url('group/state.php?id=' . $r['public_id']), 'copy' => gt('copied'), 'manual' => gt('manual_copy'), 'offline' => gt('offline'), 'changed' => gt('changed_notice'), 'sync' => gt('sync'), 'serverTime' => time(), 'qrError' => gt('qr_error'), 'copyLabel' => gt('copy'), 'sheetTitle' => gt('share_sheet_title'), 'sheetHint' => gt('share_sheet_hint'), 'zalo' => gt('share_zalo'), 'zaloCopied' => gt('share_zalo_copied'), 'zaloInsideCopied' => gt('share_zalo_inside_copied'), 'messenger' => gt('share_messenger'), 'telegram' => gt('share_telegram'), 'more' => gt('share_more'), 'close' => gt('share_close'),'roomExpired' => gt('room_expired')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>
    </script>
    <script>
      // Browser Back from the group checkout lands here; send the customer to the home page instead.
      (function() {
        const home = <?= json_encode(base_url(''), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
        // Only a checkout left in the last minute counts, so an old mark cannot redirect later visits.
        const leftCheckout = () => {
          let at = 0;
          try { at = Number(sessionStorage.getItem('hygLeftCheckout')) || 0; sessionStorage.removeItem('hygLeftCheckout'); } catch (_) {}
          return at > 0 && Date.now() - at < 60000;
        };
        const nav = performance.getEntriesByType('navigation')[0];
        if (leftCheckout() && nav && nav.type === 'back_forward') location.replace(home);
        // Restored from the back/forward cache: the code above does not run again.
        window.addEventListener('pageshow', event => { if (event.persisted && leftCheckout()) location.replace(home); });
      })();
    </script>
    <?php if ($orderSuccess) require __DIR__ . '/../lib/order_success_popup.php'; ?>
  <?php elseif ($invited): ?>
    <section class="hyg-welcome hyg-card"><span class="hyg-eyebrow"><?= e(gt('join_title')) ?></span>
      <h1><?= e($invited['title']) ?></h1>
      <p class="hyg-deadline-note"><?= e(gt('expires')) ?> <?= e(date('m/d H:i T', strtotime($invited['expires_at'] . ' UTC'))) ?></p>
      <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hyg-form"><?= csrf_field() ?><input type="hidden" name="action" value="join"><input type="hidden" name="invite" value="<?= e($invite) ?>"><label><?= e(gt('nickname')) ?><input name="nickname" required maxlength="32" autocomplete="nickname" value="<?= e($memberNickname) ?>"></label><small><?= e(gt('nickname_hint')) ?></small><button class="btn primary block" type="submit"><?= e(gt('join')) ?></button></form>
    </section>
  <?php else: ?>
    <section class="hyg-welcome hyg-card"><span class="hyg-eyebrow"><?= e(gt('title')) ?></span>
      <h1><?= e(gt('entry_title')) ?></h1>
      <p><?= e(gt('entry_desc')) ?></p>
      <form method="post" enctype="multipart/form-data" action="<?= e(base_url('group/action.php')) ?>" class="hyg-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <label><?= e(gt('room_name')) ?>
          <input name="title" maxlength="80" required placeholder="<?= e(gt('room_placeholder')) ?>">
        </label>
        <label>
          <?= e(gt('nickname')) ?><input name="nickname" maxlength="32" required autocomplete="nickname" value="<?= e($memberNickname) ?>">
        </label>

        <!-- CheckBox (AutoComplete) payment group -->
        <!-- close-test-Hai | (Trưởng nhóm thu tiền hộ) -->
        <!-- <?= htp_render_group_fields() ?> -->

        <?php if (!empty($_SESSION['cart'])): ?>
          <label class="hyg-check"><input type="checkbox" name="copy_cart" value="1" checked>
            <span><?= e(gt('copy_cart')) ?> · <?= cart_count() ?></span>
          </label>
          <small><?= e(gt('copy_hint')) ?></small><?php endif; ?>
        <button type="submit" class="btn primary block"><?= e(gt('create')) ?></button><small><?= e(gt('expiry_note')) ?></small>
      </form>
    </section>
<?php /* TẠM ẨN "Nhóm của tôi" (đã đưa sang trang giỏ hàng cart.php)
    <section class="hyg-card">
      <h2><?= e(gt('my_groups')) ?></h2><?php if (!$rooms): ?><p class="hyg-muted"><?= e(gt('no_groups')) ?></p><?php endif; ?><div class="hyg-room-list"><?php foreach ($rooms as $room): $s = $room['status'];
                                                                                                                                                            if (in_array($s, ['open', 'locked'], true) && strtotime($room['expires_at'] . ' UTC') <= time()) $s = 'expired'; ?><a href="<?= e(hyg_room_url($room['public_id'])) ?>"><strong><?= e($room['title']) ?></strong><span class="hyg-status"><?= e(gt($s)) ?></span></a><?php endforeach; ?></div>
    </section>
*/ ?>
  <?php endif; ?>
  <?php /* TODO: Tạm ẩn hai dòng ghi chú cuối trang nhóm ("Trưởng nhóm chọn thanh toán chung hoặc riêng..." và "Cần đăng nhập thành viên để tham gia nhóm...").
  <div class="hyg-privacy">
    <p><?= e(gt('rules')) ?></p>
    <p><?= e(gt('device_note')) ?></p>
  </div>
  */ ?>
</div>
<?php front_footer(); ?>