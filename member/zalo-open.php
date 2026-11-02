<?php

/**
 * Landing page of the Zalo Mini App sign-in link: offers to continue in the phone's browser.
 * The one-time token must stay unused here so the browser that opens zalo-login.php can consume it.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$token = $_GET['t'] ?? '';
$token = is_string($token) ? $token : '';
$loginUrl = base_url('member/zalo-login.php?t=' . rawurlencode($token));
// zalo-login.php reports the invalid token to the member.
if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) redirect($loginUrl);

// Zalo has no API for this: these links are the OS's own way out of an in-app webview.
$scheme = parse_url($loginUrl, PHP_URL_SCHEME) ?: 'https';
$address = preg_replace('#^https?://#i', '', $loginUrl);
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$browserUrl = $loginUrl;
if (str_contains($userAgent, 'Android')) {
  $browserUrl = 'intent://' . $address . '#Intent;scheme=' . $scheme . ';package=com.android.chrome;end';
} elseif (preg_match('/iPhone|iPad|iPod/', $userAgent)) {
  $browserUrl = 'x-safari-' . $loginUrl;
}

$GLOBALS['hym_member_page'] = true;
front_header(mt('member_zalo_open_title'));
?>
<div class="hym-page hym-page-auth">
  <header class="hym-page-head">
    <div><span class="hym-eyebrow"><?= e(mt('member_entry')) ?></span>
      <h1><?= e(mt('member_zalo_open_title')) ?></h1>
    </div>
  </header>
  <div class="hym-auth-single">
    <section class="hym-card">
      <div class="hym-form">
        <p><?= e(mt('member_zalo_open_intro')) ?></p>
        <a class="btn primary block" href="<?= e($browserUrl) ?>"><?= e(mt('member_zalo_open_browser')) ?></a>
        <a class="btn block" href="<?= e($loginUrl) ?>"><?= e(mt('member_zalo_open_continue')) ?></a>
        <small><?= e(mt('member_zalo_open_hint')) ?></small>
        <input id="zalo-open-link" type="text" readonly value="<?= e($loginUrl) ?>" aria-label="<?= e(mt('member_zalo_open_copy')) ?>">
        <button class="btn block" id="zalo-open-copy" type="button" data-copied-label="<?= e(mt('member_zalo_open_copied')) ?>"><?= e(mt('member_zalo_open_copy')) ?></button>
      </div>
    </section>
  </div>
</div>
<script>
  (function() {
    var input = document.getElementById('zalo-open-link');
    var button = document.getElementById('zalo-open-copy');

    function showCopied() {
      button.textContent = button.dataset.copiedLabel;
    }

    function copyWithSelection() {
      input.select();
      if (document.execCommand('copy')) showCopied();
    }

    button.addEventListener('click', function() {
      if (!navigator.clipboard) {
        copyWithSelection();
        return;
      }
      navigator.clipboard.writeText(input.value).then(showCopied, copyWithSelection);
    });
  })();
</script>
<?php front_footer(); ?>
