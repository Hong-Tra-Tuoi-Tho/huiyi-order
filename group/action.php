<?php
require __DIR__ . '/../lib/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
try {
    hyg_headers();
    hyg_schema();
    hyg_csrf();
    $action = (string)($_POST['action'] ?? '');
    // -- khi nhấn vào btn tạo nhóm sẽ chạy vô đây
    if ($action === 'create') {
        if (!hym_current_member()) {
            header('Location: ' . hym_login_gate_url('group'), true, 303);
            exit;
        }
        $account = htp_group_input($_POST, $_FILES);
        // order_type=combo: tạo từ nút "Đặt combo nhóm" (group/?mode=combo).
        $orderType = ($_POST['order_type'] ?? '') === 'combo' ? 'combo' : 'drink';
        $r = hyg_create($_POST['title'] ?? '', $_POST['nickname'] ?? '', !empty($_POST['copy_cart']), (string)($_POST['request_key'] ?? ''), $account, $orderType);
        // Tạo nhóm từ nút "Đặt ngay" của slider combo: đưa combo khách chọn vào giỏ nhóm vừa tạo.
        $comboId = (int)($_POST['combo_id'] ?? 0);
        unset($_SESSION['hyg_pending_combo']);
        if ($comboId > 0) {
            try {
                hgc_add_combo($r['public_id'], ['combo_id' => $comboId, 'request_key' => bin2hex(random_bytes(16))]);
                $r = hyg_room($r['public_id']);
            } catch (Throwable $e) {
                // Combo không còn bán: vẫn tạo nhóm, chủ nhóm chọn lại combo trong nhóm.
                error_log('create group with combo: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'join') {
        // dd('join', $r);
        // Members join without signing in: guests can join straight from the invite link.
        $invite = (string)($_POST['invite'] ?? '');
        /* TODO: Tạm bỏ yêu cầu đăng nhập khi tham gia nhóm (bỏ comment để yêu cầu lại).
        if (!hym_current_member()) {
            // Keep the name the guest typed so the invite form shows it again after sign-in.
            $typed = $_POST['nickname'] ?? '';
            if (is_string($typed) && trim($typed) !== '') $_SESSION['hyg_pending_nickname'] = ['invite' => $invite, 'nickname' => mb_substr(trim($typed), 0, 32), 'created_at' => time()];
            header('Location: ' . hym_login_gate_url(preg_match('/^[a-f0-9]{64}$/D', $invite) ? 'invite-' . $invite : 'group'), true, 303);
            exit;
        }
        */
        $r = hyg_join((string)($_POST['invite'] ?? ''), $_POST['nickname'] ?? '');
    } else {
        // -- Khi nhấn btn chốt món thì sẽ chạy vô đây

        $id = hyg_code($_POST['room_id'] ?? '');
        // While the host plays this room's checkout Mini Game, the room stays as it was quoted.
        hcgs_guard_group($id);
        if ($action === 'checkout') {
            // Only the host's checkout button may open a Mini Game session for this group, like the cart's checkout button.
            $r = hyg_checkout($id);
            // dd('checkout', $r);
            if (!$r['already']) $_SESSION['hcgs_allow_open'] = $r['room']['public_id'];
            header('Location: ' . base_url('checkout.php?group=' . rawurlencode($r['room']['public_id'])), true, 303);
            exit;
        }
        // Nhóm combo: chủ nhóm thêm combo (index.php?group=...), thành viên chọn nước (group/combo.php).
        if ($action === 'add_combo') {
            hgc_add_combo($id, $_POST);
            $_SESSION['hyg_message'] = hgc_t('combo_added');
            header('Location: ' . hyg_room_url($id), true, 303);
            exit;
        }
        if ($action === 'combo_drink') {
            hgc_add_drink($id, $_POST);
            // Ly vừa thêm làm combo đủ nước (đủ số ly và mỗi nhóm đạt yêu cầu) thì báo riêng.
            $message = hgc_t('drink_added');
            $comboState = hgc_state(hyg_cart(hyg_room($id), false))[(int)($_POST['combo_item'] ?? 0)] ?? null;
            if ($comboState && !empty($comboState['full']) && !empty($comboState['complete'])) {
                $message = hgc_t('combo_complete');
            }
            $_SESSION['hyg_message'] = $message;
            header('Location: ' . base_url('group/combo.php?id=' . $id), true, 303);
            exit;
        }
        hyg_mutate($id, $action, $_POST);
        if ($action === 'remove' && ($_POST['back'] ?? '') === 'combo') {
            // Xoá combo hay chỉ bỏ 1 ly nước: phân biệt theo dòng vừa xoá (đã bị xoá nên đọc từ form gửi lên).
            $_SESSION['hyg_message'] = hgc_t(($_POST['removed'] ?? '') === 'drink' ? 'drink_removed' : 'combo_removed');
            header('Location: ' . base_url('group/combo.php?id=' . $id), true, 303);
            exit;
        }
        if ($action === 'cancel') {
            // A cancelled group is deleted right away and the host goes back to the menu.
            hyg_delete_cancelled_room($id);
            header('Location: ' . base_url('index.php'), true, 303);
            exit;
        }
        $r = hyg_room($id);
        if ($action === 'coupon' && ($_POST['lock_after'] ?? '') === '1') {
            // "Lock" submits the offer form: save the offer, then lock at the revision it produced.
            hyg_mutate($id, 'lock', ['revision' => (int)$r['revision']] + $_POST);
            $r = hyg_room($id);
            // dd('action', $r);
            // The host's own share needs no review, so confirm it here instead of on the room page.
            $quote = hyg_locked_quote($r);
            $hostId = (int)($quote['payment']['host_member_id'] ?? 0);
            $hostPay = hyg_member_payment($quote['payment'], $hostId);
            if ($hostPay && !empty($hostPay['can_confirm']) && (float)$hostPay['outstanding'] > 0) {
                hyg_mutate($id, 'payment_confirm', [
                    'revision' => (int)$r['revision'],
                    'quote_signature' => $r['lock_signature'] ?? '',
                    'member_id' => $hostId,
                    'payment_amount' => (string)hyg_money((float)$hostPay['outstanding']),
                ]);
                $r = hyg_room($id);
                $quote = hyg_locked_quote($r);
            }
            // Once locked, go straight to checkout when the group can already pay; otherwise stay in the room.
            $hasInvalid = false;
            foreach ($quote['cart'] as $item) {
                if (!empty($item['_invalid'])) {
                    $hasInvalid = true;
                    break;
                }
            }
            if (!empty($quote['payment']['can_checkout']) && !empty($quote['cart']) && !$hasInvalid) {
                $_SESSION['hcgs_allow_open'] = $r['public_id'];
                header('Location: ' . base_url('checkout.php?group=' . rawurlencode($r['public_id'])), true, 303);
                exit;
            }
        }
        if ($action === 'payment_confirm') {
            $quote = hyg_locked_quote($r);
            $hasInvalid = false;
            foreach ($quote['cart'] as $item) {
                if (!empty($item['_invalid'])) {
                    $hasInvalid = true;
                    break;
                }
            }
            if (!empty($quote['payment']['can_checkout']) && !empty($quote['cart']) && !$hasInvalid) {
                $_SESSION['hcgs_allow_open'] = $r['public_id'];
                header('Location: ' . base_url('checkout.php?group=' . rawurlencode($r['public_id'])), true, 303);
                exit;
            }
        }
        if (in_array($action, ['payment_reject', 'payment_refund', 'payment_correct', 'payment_extend'], true)) $_SESSION['hyg_message'] = gt('payment_controls_saved');
    }
    // dd($r);
    header('Location: ' . hyg_room_url($r['public_id']), true, 303);
    exit;
} catch (Throwable $e) {
    if ($e instanceof TransferPaymentError) {
        $_SESSION['hyg_message'] = htp_error($e);
        header('Location: ' . base_url('group/'), true, 303);
        exit;
    }
    if ($e instanceof GroupOrderError) {
        $_SESSION['hyg_message'] = gt($e->getMessage());
        $return = base_url('group/');
        try {
            if (!empty($_POST['room_id'])) {
                $r = hyg_room(hyg_code($_POST['room_id']));
                hyg_member($r);
                $return = hyg_room_url($r['public_id']);
                // Lỗi từ trang chọn nước (thêm nước, xoá combo) quay lại trang đó.
                $fromComboPage = ($_POST['action'] ?? '') === 'combo_drink' || ($_POST['back'] ?? '') === 'combo';
                if ($fromComboPage) {
                    $return = base_url('group/combo.php?id=' . $r['public_id']);
                }
            } elseif (!empty($_POST['invite']) && preg_match('/^[a-f0-9]{64}$/D', (string)$_POST['invite'])) $return = base_url('group/?invite=' . $_POST['invite']);
        } catch (Throwable $ignored) {
        }
        header('Location: ' . $return, true, 303);
        exit;
    }
    hyg_error_page($e);
}
