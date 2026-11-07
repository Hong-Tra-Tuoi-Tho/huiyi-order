<?php
/** Trang chọn nước của combo trong giỏ thường (combo.php): thêm ly nước hoặc bỏ ly đã chọn. */
require __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}
verify_csrf();
hcgs_guard_cart();

$comboKey = (string)($_POST['combo_key'] ?? '');
$back = base_url('combo.php?key=' . rawurlencode($comboKey));

if (!isset($_SESSION['cart'][$comboKey]) || empty($_SESSION['cart'][$comboKey]['is_combo'])) {
    redirect(base_url('cart.php'));
}

try {
    if (($_POST['action'] ?? '') === 'remove_drink') {
        $drinkKey = (string)($_POST['drink_key'] ?? '');
        $drink = $_SESSION['cart'][$drinkKey] ?? null;
        if ($drink && !empty($drink['combo_drink']) && (string)$drink['combo_parent'] === $comboKey) {
            unset($_SESSION['cart'][$drinkKey]);
            $_SESSION['hcc_message'] = hgc_t('drink_removed');
        }
    } else {
        hcc_add_drink($_POST);
        $_SESSION['hcc_message'] = hgc_t('drink_added');
    }
} catch (Throwable $e) {
    $_SESSION['hcc_error'] = hcc_error_text($e);
}

redirect($back);
