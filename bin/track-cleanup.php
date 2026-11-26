<?php
/** Chạy bằng cron (mỗi ngày): xóa log tracking thô quá hạn giữ. Thống kê theo ngày giữ vĩnh viễn. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
try {
    $deleted = htk_cleanup();
    echo "tracking_cleanup_ok deleted={$deleted}\n";
} catch (Throwable $error) {
    fwrite(STDERR, "tracking_cleanup_error\n");
    exit(1);
}
