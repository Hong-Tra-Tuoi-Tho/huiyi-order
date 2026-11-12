<?php
/** Webhook nhận sự kiện từ Zalo Mini App (khách rút đồng ý / yêu cầu xóa dữ liệu). Luôn trả 200. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = (string)file_get_contents('php://input', false, null, 0, 16385);
    // Chỉ ghi log để xử lý thủ công, không tự xóa dữ liệu.
    error_log('zalo_webhook: ' . substr($raw, 0, 2000));
}
http_response_code(200);
echo json_encode(['ok' => true]);
