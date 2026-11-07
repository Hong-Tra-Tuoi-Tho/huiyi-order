<?php
/** Trang chủ: "Đặt ngay" ở combo => thêm combo vào giỏ thường rồi chuyển vào giỏ hàng. */
require __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}
verify_csrf();
hcgs_guard_cart_add();

try {
    hcc_add_combo((int)($_POST['combo_id'] ?? 0));
} catch (Throwable $e) {
    $_SESSION['coupon_error'] = hcc_error_text($e);
    redirect(base_url('index.php'));
}

redirect(base_url('cart.php'));
