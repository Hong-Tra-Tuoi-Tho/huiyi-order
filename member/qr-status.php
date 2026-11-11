<?php

/**
 * Máy tính hỏi định kỳ: mã QR đăng nhập của session này đã được điện thoại xác nhận chưa.
 * - pending: chưa quét
 * - expired: mã hết hạn → trả kèm link QR mới để vẽ lại
 * - ok: đã đăng nhập session máy tính, trang tự tải lại
 */
require_once __DIR__ . '/../lib/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

try {
    hym_schema();

    if (hym_current_member()) {
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if (!hym_zalo_only() && !hym_zalo_test_mode()) {
        http_response_code(404);
        echo json_encode(['status' => 'disabled']);
        exit;
    }

    $status = hym_qr_login_poll();
    $response = ['status' => $status];

    if ($status === 'expired') {
        $response['qr_url'] = hym_zalo_miniapp_qr_url(hym_qr_login_code(true));
    }

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('member_qr_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
