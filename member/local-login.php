<?php

/**
 * Đăng nhập thử trên máy local: nhập số điện thoại, có tài khoản thì vào, chưa có thì tạo mới.
 * Chỉ chạy khi hym_local_login_allowed() (localhost); nơi khác trả 404.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

// File helper nằm trong .gitignore: có trên máy local, không có trên server => trả 404.
if (is_file(__DIR__ . '/_local_login.php')) {
    require_once __DIR__ . '/_local_login.php';
}
if (!function_exists('hym_local_login_allowed') || !hym_local_login_allowed() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}

try {
    hym_schema();
    hym_csrf();

    $phoneInput = $_POST['phone'] ?? '';
    $phone = hym_normalize_phone(is_string($phoneInput) ? $phoneInput : '');

    $st = db()->prepare('SELECT id FROM members WHERE phone=?');
    $st->execute([$phone]);
    $memberId = (int)$st->fetchColumn();

    if ($memberId < 1) {
        // Giống tài khoản tạo qua Mini App: password_hash rỗng, số điện thoại coi như đã xác thực.
        db()->prepare(
            'INSERT INTO members(phone,password_hash,display_name,delivery_address,phone_verified_at,created_at,updated_at)
             VALUES(?,\'\',?,\'\',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute([$phone, 'Local ' . substr($phone, -4)]);
        $memberId = (int)db()->lastInsertId();
    }

    hym_session_login($memberId);
    $_SESSION['member_flash'] = ['type' => 'success', 'key' => 'member_logged_in'];
} catch (HymMemberError $e) {
    $_SESSION['member_flash'] = ['type' => 'danger', 'key' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('member_local_login: ' . $e->getMessage());
    $_SESSION['member_flash'] = ['type' => 'danger', 'key' => 'member_save_failed'];
}

$returnOrder = $_POST['return_order'] ?? '';
$returnOrder = is_string($returnOrder) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $returnOrder) ? $returnOrder : '';
redirect(base_url('member/index.php' . ($returnOrder !== '' ? '?return_order=' . rawurlencode($returnOrder) : '')));
