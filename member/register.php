<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
// Trang này chỉ dành cho khách vừa đăng nhập Zalo lần đầu (chưa có tài khoản).
$loadFailed = false;
$zaloPending = null;
try {
  hym_schema();
  if (hym_current_member()) redirect(base_url('member/index.php'));
  $zaloPending = hym_zalo_pending();
} catch (Throwable $error) {
  $loadFailed = true;
}
if (!$zaloPending && !$loadFailed) redirect(base_url('member/index.php'));
$flash = $_SESSION['member_flash'] ?? null;
unset($_SESSION['member_flash']);
$GLOBALS['hym_member_page'] = true;
front_header(mt('member_register_title'));
?>
<div class="hym-page hym-page-auth">
  <a class="back" href="<?= e(base_url('member/index.php')) ?>">← <?= e(mt('member_back_login')) ?></a>
  <header class="hym-page-head">
    <div><span class="hym-eyebrow"><?= e(mt('member_entry')) ?></span>
      <h1><?= e(mt('member_register_title')) ?></h1>
    </div>
  </header>
  <?php if (is_array($flash) && !empty($flash['key'])): $flashType = in_array($flash['type'] ?? '', ['success', 'info', 'danger'], true) ? $flash['type'] : 'danger';
    $flashText = mt((string)$flash['key']);
    if ($flashText === (string)$flash['key']) $flashText = mt('member_save_failed'); ?>
    <div class="alert <?= e($flashType) ?>" role="status"><?= e($flashText) ?></div>
  <?php endif; ?>
  <?php if ($loadFailed): ?><div class="alert danger" role="alert"><?= e(mt('member_load_failed')) ?></div><?php endif; ?>
  <div class="hym-auth-single">
    <section class="hym-card" id="member-register">
      <form class="hym-form" method="post" action="<?= e(base_url('member/action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="zalo_register">
        <label for="member-register-name">
          <?= e(mt('member_display_name')) ?>
          <input id="member-register-name" name="display_name" maxlength="100" autocomplete="name" value="<?= e((string)($zaloPending['name'] ?? '')) ?>">
        </label>
        <label for="member-register-phone">
          <span><?= e(mt('member_phone')) ?> <span class="hym-required" aria-hidden="true">*</span></span>
          <input id="member-register-phone" type="tel" inputmode="tel" name="phone" required maxlength="32" autocomplete="tel">
        </label>
        <button class="btn primary block" type="submit"><?= e(mt('member_register')) ?></button>
      </form>
    </section>
  </div>
</div>
<?php front_footer(); ?>
