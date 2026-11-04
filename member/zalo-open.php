<?php

/**
 * Landing page of the Zalo Mini App sign-in link.
 * Tries the phone's own browser first; if it does not open, signs in right here in Zalo's webview.
 * The one-time token stays unused here so whichever side opens zalo-login.php can consume it.
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
if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
  redirect($loginUrl);
}

// Zalo has no API for this: these links are the OS's own way out of an in-app webview.
$scheme = parse_url($loginUrl, PHP_URL_SCHEME) ?: 'https';
$address = preg_replace('#^https?://#i', '', $loginUrl);
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$browserUrl = '';
if (str_contains($userAgent, 'Android')) {
  // Without Chrome, Android loads browser_fallback_url in this webview: that is the in-Zalo sign-in.
  $browserUrl = 'intent://' . $address
    . '#Intent;scheme=' . $scheme
    . ';package=com.android.chrome'
    . ';S.browser_fallback_url=' . rawurlencode($loginUrl)
    . ';end';
} elseif (preg_match('/iPhone|iPad|iPod/', $userAgent)) {
  $browserUrl = 'x-safari-' . $loginUrl;
}

$GLOBALS['hym_member_page'] = true;
front_header(mt('member_zalo_open_title'));
?>
<noscript>
  <meta http-equiv="refresh" content="0;url=<?= e($loginUrl) ?>">
</noscript>
<div class="hym-page hym-page-auth">
  <header class="hym-page-head">
    <div><span class="hym-eyebrow"><?= e(mt('member_entry')) ?></span>
      <h1><?= e(mt('member_zalo_open_title')) ?></h1>
    </div>
  </header>
  <div class="hym-auth-single">
    <section class="hym-card">
      <div class="hym-form">
        <p><?= e(mt('member_zalo_open_redirecting')) ?></p>
      </div>
    </section>
  </div>
</div>
<script>
  (function() {
    var browserUrl = <?= json_encode($browserUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var loginUrl = <?= json_encode($loginUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    // The browser opening sends this page to the background; only then is the in-Zalo sign-in skipped,
    // so the one-time link is never used by both sides.
    var left = false;

    function markLeft() {
      left = true;
    }

    function continueInZalo() {
      if (left || document.hidden) {
        return;
      }
      left = true;
      location.replace(loginUrl);
    }

    document.addEventListener('visibilitychange', function() {
      if (document.hidden) {
        markLeft();
      }
    });
    window.addEventListener('pagehide', markLeft);
    window.addEventListener('blur', markLeft);

    if (!browserUrl) {
      continueInZalo();
      return;
    }
    location.href = browserUrl;
    // Long enough for a cold-starting browser to take focus before Zalo uses the link itself.
    setTimeout(continueInZalo, 2000);
  })();
</script>
<?php front_footer(); ?>
