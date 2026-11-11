<?php

/**
 * Old sign-in link from earlier Mini App builds.
 * Sign-in now always happens inside Zalo's webview, so this only forwards to zalo-login.php.
 */
require __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$token = $_GET['t'] ?? '';
$token = is_string($token) ? $token : '';
// zalo-login.php reports an invalid token to the member.
redirect(base_url('member/zalo-login.php?t=' . rawurlencode($token)));
