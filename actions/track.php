<?php
/**
 * Nhận một lô event tracking từ web (JSON).
 * Vào: {"device":"<uuid>","events":[{"key","type","page","action","at"}]}
 * Ra:  {"ok":true,"accepted":["<event_key>", ...]} — web xóa các key này khỏi hàng đợi.
 */
require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('{"ok":false}');
}

// Không giữ khóa session trong lúc ghi log để không làm chậm các request khác của khách.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        http_response_code(413);
        exit('{"ok":false}');
    }
    $data = json_decode($raw, true, 8);
    if (!is_array($data) || !is_array($data['events'] ?? null)) {
        http_response_code(400);
        exit('{"ok":false}');
    }
    $device = htk_normalize_uuid($data['device'] ?? '');
    $accepted = htk_ingest($device, $data['events']);
    echo json_encode(['ok' => true, 'accepted' => $accepted]);
} catch (Throwable $error) {
    // Lỗi ghi log không được hiện ra ngoài; web sẽ thử gửi lại ở lần sau.
    http_response_code(500);
    echo '{"ok":false}';
}
