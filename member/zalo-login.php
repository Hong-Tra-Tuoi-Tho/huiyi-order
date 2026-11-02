<?php

/** Consumes a one-time sign-in link issued to the Zalo Mini App. */
require_once __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
try {
    hym_schema();
    $token = $_GET['t'] ?? '';
    hym_session_login(hym_login_token_consume(is_string($token) ? $token : ''));
    $_SESSION['member_flash'] = ['type' => 'success', 'key' => 'member_logged_in'];
} catch (HymMemberError $e) {
    $_SESSION['member_flash'] = ['type' => 'error', 'key' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('member_zalo_login: ' . $e->getMessage());
    $_SESSION['member_flash'] = ['type' => 'error', 'key' => 'member_zalo_failed'];
}
redirect(base_url('member/index.php'));
