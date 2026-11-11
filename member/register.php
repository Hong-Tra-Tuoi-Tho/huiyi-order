<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
// Accounts are created by the Zalo Mini App.
if (hym_zalo_only()) redirect(base_url('member/index.php'));
$loadFailed = false;
try {
  hym_schema();
  if (hym_current_member()) redirect(base_url('member/index.php'));
} catch (Throwable $error) {
  $loadFailed = true;
}
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
      <form class="hym-form" method="post" action="<?= e(base_url('member/action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="register">
        <label for="member-register-name">
          <?= e(mt('member_display_name')) ?>
          <!-- <span class="hym-optional"><?= e(mt('member_optional')) ?></span> -->
          <input id="member-register-name" name="display_name" maxlength="100" autocomplete="name" value="<?= e((string)(hym_zalo_pending()['name'] ?? '')) ?>">
        </label>
        <label for="member-register-phone">
          <span><?= e(mt('member_phone')) ?> <span class="hym-required" aria-hidden="true">*</span></span>
          <input id="member-register-phone" type="tel" inputmode="tel" name="phone" required maxlength="32" autocomplete="tel" aria-describedby="member-phone-hint">
        </label>
        <!-- <small id="member-phone-hint"><?= e(mt('member_phone_hint')) ?></small> -->
        <label for="member-register-password">
          <span>
            <?= e(mt('member_password')) ?> <span class="hym-required" aria-hidden="true">*</span>
          </span>
          <span class="hym-pass">
            <input id="member-register-password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="member-password-hint" placeholder="<?= e(mt('member_password_hint')) ?>">
            <button class="hym-pass-toggle" type="button" data-pass-toggle aria-controls="member-register-password" aria-pressed="false" aria-label="<?= e(mt('member_password_show')) ?>" data-show-label="<?= e(mt('member_password_show')) ?>" data-hide-label="<?= e(mt('member_password_hide')) ?>">
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
        <!-- <small id="member-password-hint"><?= e(mt('member_password_hint')) ?></small> -->
        <label for="member-register-confirm">
          <span><?= e(mt('member_password_confirm')) ?> <span class="hym-required" aria-hidden="true">*</span></span>
          <span class="hym-pass">
            <input id="member-register-confirm" type="password" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password">
            <button class="hym-pass-toggle" type="button" data-pass-toggle aria-controls="member-register-confirm" aria-pressed="false" aria-label="<?= e(mt('member_password_show')) ?>" data-show-label="<?= e(mt('member_password_show')) ?>" data-hide-label="<?= e(mt('member_password_hide')) ?>">
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
        <button class="btn primary block" type="submit"><?= e(mt('member_register')) ?></button>
      </form>
    </section>
  </div>
</div>
<?php front_footer(); ?>