<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/group_payment_views.php';
require_once __DIR__ . '/lib/promotion_views.php';
require_once __DIR__ . '/lib/customer_checkout.php';
require_once __DIR__ . '/lib/group_views.php'; // hyg_render_steps(): group order guide
header('Cache-Control: private, no-store, max-age=0');
$groupCheckout = null;
$cart = $_SESSION['cart'] ?? [];
if (!empty($_GET['group'])) {
  try {
    $groupCheckout = hyg_checkout(hyg_code($_GET['group']));
    if ($groupCheckout['already']) redirect(hyg_room_url($_GET['group']));
    $cart = $groupCheckout['cart'];
  } catch (Throwable $e) {
    hyg_error_page($e);
  }
}
// Recover an uncertain ordinary submission before an already-used coupon can invalidate its cart.
if (!$groupCheckout && ($uncertainToken = hcq_unknown_token(hcq_context()))) {
  $uncertainRequest = hcq_lookup($uncertainToken, hcq_context());
  try {
    $recovered = hcq_existing_order($uncertainToken, hcq_context());
    if (!$recovered) $recovered = hyg_one('SELECT * FROM orders WHERE order_no=?', [$uncertainRequest['order_no']]);
    if ($recovered && htp_customer_allowed($recovered)) {
      try {
        if ($cart && hash_equals($uncertainRequest['signature'], promotion_quote_signature($cart))) {
          $_SESSION['cart'] = [];
          clear_coupon();
          unset($_SESSION['promotion_mode']);
        }
      } catch (Throwable $ignored) {
      }
      hcq_saved($uncertainToken, $recovered['order_no']);
      header('Location: ' . base_url('payment.php?order=' . rawurlencode($recovered['order_no'])), true, 303);
      exit;
    }
  } catch (Throwable $ignored) {
  }
  front_header(t('order_info'));
  echo '<section class="panel"><div class="alert danger" role="alert"><p>' . e(htp_ux('unknown')) . '</p><a class="btn" href="' . e(base_url('payment.php?order=' . rawurlencode($uncertainRequest['order_no']))) . '">' . e(htp_ux('check_order')) . '</a></div><a class="btn" href="' . e(base_url('index.php')) . '">' . e(t('return_menu')) . '</a></section>';
  front_footer();
  exit;
}
if (!$cart) redirect(base_url('index.php'));
// Giỏ thường có combo chưa chọn đủ nước: quay lại giỏ để chọn tiếp.
if (!$groupCheckout && !hcc_all_complete($cart)) redirect(base_url('cart.php'));
$promotion = $groupCheckout ? promotion_room_choice($groupCheckout['room'], $cart) : promotion_cart_choice($cart);
if (!$groupCheckout && !$promotion['valid']) {
  $_SESSION['coupon_error'] = pt('selection_changed');
  hcgs_release_expired();
  redirect(base_url('cart.php'));
}
$coupon = $groupCheckout ? $groupCheckout['coupon'] : cart_coupon_summary($cart);
if (!$groupCheckout && $promotion['mode'] === 'reward' && (float)$coupon['discount'] <= 0) {
  $_SESSION['coupon_error'] = pt('reward_no_discount');
  hcgs_release_expired();
  redirect(base_url('cart.php'));
}
$checkoutSubtotal = cart_subtotal($cart);
$checkoutTotal = max(0, $checkoutSubtotal - $coupon['discount']);
$groupPayment = $groupCheckout ? hyg_payment_summary($groupCheckout['room'], $cart, $coupon) : null;
$groupPayCode = $groupPayment ? htp_group_order_method($groupPayment) : '';
$hostCollect = ($groupPayment['collection_mode'] ?? 'store') === 'host';
$bankAccounts = active_bank_accounts();
if (!$groupCheckout || $hostCollect) $bankAccounts = []; // Resolve the recipient from the saved order, never a recommendation.
$checkoutMember = hym_current_member();
$memberDefaults = hym_checkout_defaults($checkoutMember);
$checkoutContext = hcq_context($groupCheckout['room']['public_id'] ?? '');
$draft = hcq_draft($checkoutContext);
$values = $draft['values'];
$fieldErrors = $draft['errors'];
$values += ['customer_name' => $memberDefaults['name'], 'phone' => $memberDefaults['phone'], 'address' => $memberDefaults['address'], 'payment_method' => 'transfer', 'invoice_required' => '0'];
foreach (hcq_fields() as $field => $max) $values[$field] ??= '';
$checkoutError = $_SESSION['checkout_errors'][$checkoutContext] ?? '';
unset($_SESSION['checkout_errors'][$checkoutContext]);
$checkoutSignature = $groupCheckout ? (string)$groupCheckout['room']['lock_signature'] : promotion_quote_signature($cart);
$checkoutToken = hcq_request($checkoutContext, $checkoutSignature);
$checkoutRequest = hcq_lookup($checkoutToken, $checkoutContext);
$checkoutUnknown = ($checkoutRequest['state'] ?? '') === 'unknown';
if ($checkoutUnknown) {
  try {
    $recovered = hcq_existing_order($checkoutToken, $checkoutContext);
    if (!$recovered) $recovered = hyg_one('SELECT * FROM orders WHERE order_no=?', [$checkoutRequest['order_no']]);
    if ($recovered && htp_customer_allowed($recovered)) {
      if (!$groupCheckout && $cart && hash_equals($checkoutRequest['signature'], promotion_quote_signature($cart))) {
        $_SESSION['cart'] = [];
        clear_coupon();
        unset($_SESSION['promotion_mode']);
      }
      hcq_saved($checkoutToken, $recovered['order_no']);
      header('Location: ' . base_url('payment.php?order=' . rawurlencode($recovered['order_no'])), true, 303);
      exit;
    }
  } catch (Throwable $e) {
  }
}
// Every full ten drinks grants one chance now; the session locks the cart (or the group room) until it is submitted or expires.
$gameSession = null;
if (!$checkoutUnknown) {
  $gameChances = $promotion['mode'] === 'draw' && (float)$coupon['discount'] <= 0 ? (int)$promotion['expected_chances'] : 0;
  // Drinks added after a play: the running session grows to the new drink count (a Facebook-share bonus chance is kept).
  if (!$groupCheckout && $gameChances > 0) hcgs_grow_group_session('', (int)$promotion['eligible_qty'], $gameChances);
  $gameSession = hcgs_open((int)$promotion['eligible_qty'], $gameChances, (string)($groupCheckout['room']['public_id'] ?? ''));
  // A prize from an earlier, already paid order must not show up in this session's game.
  try {
    hcgs_forget_old_board($gameSession);
  } catch (Throwable $ignored) {
  }
}
// The Mini Game time ran out while this page was open: the cart was deleted, so there is nothing left to check out.
if (!$groupCheckout && empty($_SESSION['cart'])) redirect(base_url('cart.php'));
// Same for a group checkout: the room was cancelled and its drinks deleted.
if ($groupCheckout && !empty($GLOBALS['hcgs_group_dropped'])) redirect(base_url('cart.php'));
// Money prizes won in this session are summed and taken off the bill straight away.
// An expired session keeps applying the prizes it already won.
$gamePrizeSession = $gameSession ?: hcgs_prize_row((string)($groupCheckout['room']['public_id'] ?? ''));
$gameCash = hcgs_cash_discount($cart, $coupon, $gamePrizeSession);
$checkoutTotal = max(0, $checkoutTotal - $gameCash['discount']);
// Free drink/topping prizes are added as 0đ gift lines with the quantity set in the prize settings.
$gameGifts = hcgs_gift_rewards($gamePrizeSession);
$gameFlash = $_SESSION['hcgs_flash'] ?? '';
unset($_SESSION['hcgs_flash']);
if ($groupCheckout) $GLOBALS['hyg_header_room'] = $groupCheckout['room'];
front_header(t('order_info'));
?>

<?php if ($groupCheckout): ?>
  <!-- Group order guide: the group checkout is step 4 (choose an offer & confirm the order). -->
  <?= hyg_render_steps(4) ?>
<?php endif; ?>
<div class="checkout-wrap">
  <section>
    <span class="eyebrow"><?= e(t('checkout')) ?></span>
    <h1><?= e(t('order_info')) ?></h1>
    <?php if ($checkoutUnknown): ?><div class="alert danger checkout-error-summary" role="alert">
        <p><?= e(htp_ux('unknown')) ?></p><a class="btn" href="<?= e(base_url('payment.php?order=' . rawurlencode($checkoutRequest['order_no']))) ?>"><?= e(htp_ux('check_order')) ?></a>
      </div><?php elseif ($checkoutError || $fieldErrors): ?><div class="alert danger checkout-error-summary" role="alert" tabindex="-1" data-checkout-errors><?= e($checkoutError ?: htp_ux('draft_error')) ?></div><?php endif; ?>
    <?php if ($gameFlash): ?><div class="alert danger" role="alert"><?= e($gameFlash) ?></div><?php endif; ?>

    <!-- Nếu hết lượt chơi tắt mini-game -->
    <?php if ($gameSession && hcgs_remaining($gameSession) > 0): ?>
      <section class="alert success checkout-game-session<?= (int)$gameSession['granted_chances'] > 0 ? '' : ' is-timer-only' ?>" data-game-session data-remaining="<?= max(0, (int)$gameSession['remaining']) ?>">
        <?php $gameHasChances = (int)$gameSession['granted_chances'] > 0; ?>
        <?php if ($gameHasChances): ?>
          <p>
            <strong>
              <?= e(hcgs_text('opened', ['qty' => (int)$gameSession['drink_qty'], 'chances' => (int)$gameSession['granted_chances']])) ?></strong>
          </p>
          <p class="checkout-game-remaining" data-game-remaining-text="<?= e(hcgs_text('plays_left')) ?>">
            <strong>
              <?= e(hcgs_text('plays_left', ['n' => hcgs_remaining($gameSession)])) ?>
            </strong>
          </p>
          <?php
          // Gợi ý mua thêm cho đủ bội số 10 ly kế tiếp (VD: 35 => 40, 32 => 40).
          $gameDrinkQty = (int)$gameSession['drink_qty'];
          $gameMoreQty = (10 - $gameDrinkQty % 10) % 10;

          ?>

          <!-- Hiển thị thông báo đề nghị mua thêm nước ở khung mini-game -->
          <?php if ($gameMoreQty > 0 && $gameMoreQty <= 5): ?>
            <p class="checkout-game-upsell">
              <?= e(hcgs_text('upsell', ['more' => $gameMoreQty])) ?>
            </p>
          <?php endif; ?>

        <?php endif; ?>
        <p class="checkout-game-timer">
          <?= str_replace('{time}', '<b data-game-session-clock>--:--</b>', e(hcgs_text('hold'))) ?>
        </p>
        <?php if ($gameHasChances): ?>
          <div class="checkout-game-actions">

            <button type="button" class="btn primary checkout-game-btn" onclick="openMinigameModal()"><svg class="checkout-game-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path class="pad" d="M7 7h10a5 5 0 0 1 4.9 6l-.7 3.5a2.6 2.6 0 0 1-4.6 1.1L14.8 15H9.2l-1.8 2.6a2.6 2.6 0 0 1-4.6-1.1L2.1 13A5 5 0 0 1 7 7z" />
                <path class="dpad" d="M7.5 9.5v4M5.5 11.5h4" />
                <circle cx="16" cy="10.4" r="1.15" />
                <circle cx="18.2" cy="12.6" r="1.15" />
              </svg>
              Chơi Mini Game nhận quà
            </button>

            <?php if (!$groupCheckout): ?>
              <form class="checkout-game-add-form" method="post" action="<?= e(base_url('actions/game_add_drinks.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn checkout-game-add-btn">
                  <svg class="checkout-game-add-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 4h2l1.8 9.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L20 8H7" />
                    <circle cx="9" cy="19" r="1.5" />
                    <circle cx="17" cy="19" r="1.5" />
                  </svg>
                  <?= e(hcgs_text('add_drinks')) ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($groupCheckout): ?>
              <a class="btn checkout-game-add-btn" href="<?= e(base_url('index.php?group=' . rawurlencode($groupCheckout['room']['public_id']))) ?>">
                <svg class="checkout-game-add-icon" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 4h2l1.8 9.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L20 8H7" />
                  <circle cx="9" cy="19" r="1.5" />
                  <circle cx="17" cy="19" r="1.5" />
                </svg>
                Quay lại trang đặt nước
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>


      <script>
        (function() {
          const box = document.querySelector('[data-game-session]');
          const clock = box && box.querySelector('[data-game-session-clock]');
          if (!clock) return;
          let end = Date.now() + Number(box.dataset.remaining) * 1000;
          // The Mini Game restarts the clock after every finished play.
          window.hcgsSetClock = function(seconds) {
            end = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
          };
          const tick = function() {
            const left = Math.max(0, Math.round((end - Date.now()) / 1000));
            clock.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
            // Expiry is decided on the server; reload slightly after it so the session is already ended.
            if (left === 0) {
              clearInterval(timer);
              setTimeout(function() {
                location.reload();
              }, 1500);
            }
          };
          const timer = setInterval(tick, 1000);
          tick();
          // The countdown badge stays at the top right, flush against the header's bottom edge, whatever the scroll position.
          const badge = box.querySelector('.checkout-game-timer');
          const header = document.querySelector('.topbar');
          const place = function() {
            if (!badge) return;
            const bottom = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
            badge.style.top = bottom + 'px';
          };
          place();
          window.addEventListener('scroll', place, {
            passive: true
          });
          window.addEventListener('resize', place);
        })();
      </script>
    <?php endif; ?>
    <!-- <div class="member-checkout-note">
      <p>
        <?= e(mt($checkoutMember ? 'checkout_member_notice' : 'checkout_member_guest')) ?>
      </p>
      <a href="<?= e(base_url('member/index.php')) ?>"><?= e(mt('member_entry')) ?></a>
    </div> -->
    <form method="post" action="<?= e(base_url('actions/submit_order.php')) ?>" class="checkout-form" id="checkoutForm" data-checkout-form data-game-remaining="<?= hcgs_remaining($gameSession) ?>" data-submitting-label="<?= e(htp_ux('submitting')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="checkout_token" value="<?= e($checkoutToken) ?>">
      <?php if (!$groupCheckout): ?><input type="hidden" name="promotion_signature" value="<?= e(promotion_quote_signature($cart)) ?>"><?php endif; ?>
      <?php if ($groupCheckout): ?><input type="hidden" name="group_id" value="<?= e($groupCheckout['room']['public_id']) ?>"><input type="hidden" name="group_revision" value="<?= (int)$groupCheckout['room']['revision'] ?>"><input type="hidden" name="group_signature" value="<?= e($groupCheckout['room']['lock_signature']) ?>"><?php endif; ?>
      <label><?= e(t('customer_name')) ?><input name="customer_name" value="<?= e($values['customer_name']) ?>" required maxlength="100" autocomplete="name" <?= hcq_field_attrs($fieldErrors, 'customer_name') ?>><?= hcq_field_error($fieldErrors, 'customer_name') ?></label>
      <label><?= e(t('phone')) ?><input name="phone" value="<?= e($values['phone']) ?>" required maxlength="50" inputmode="tel" autocomplete="tel" <?= hcq_field_attrs($fieldErrors, 'phone') ?>><?= hcq_field_error($fieldErrors, 'phone') ?></label>
      <label><?= e(t('delivery_address')) ?><textarea name="address" required maxlength="255" rows="3" autocomplete="street-address" <?= hcq_field_attrs($fieldErrors, 'address') ?>><?= e($values['address']) ?></textarea><?= hcq_field_error($fieldErrors, 'address') ?></label>

      <!-- Phương thức thanh toán nằm ngay dưới địa chỉ giao hàng: đơn nhóm (trừ chia tiền riêng) cũng chọn Chuyển khoản / Tiền mặt tại đây. -->
      <?php $groupChoosesPay = $groupPayment && $groupPayCode !== 'split'; ?>
      <?php $payChoice = $groupChoosesPay ? (in_array($values['payment_method'], ['transfer', 'cash'], true) && !empty($draft['values']['payment_method']) ? $values['payment_method'] : $groupPayCode) : $values['payment_method']; ?>
      <fieldset<?= $groupPayment && !$groupChoosesPay ? ' style="display: none;"' : '' ?>>
        <legend><?= e(t('payment_method')) ?></legend>
        <?php if ($groupPayment && !$groupChoosesPay): ?>
          <input type="hidden" name="payment_method" value="<?= e($groupPayCode) ?>">
          <p><?= e($hostCollect ? htp('merchant_host') : gt($groupPayment['mode'] === 'split' ? 'pay_split' : 'pay_together')) ?><?php if ($hostCollect || $groupPayment['mode'] === 'together'): ?> · <?= e(payment_label($groupPayCode)) ?><?php endif; ?></p>
        <?php elseif ($groupChoosesPay): ?>
          <label class="inline-radio"><input type="radio" name="payment_method" value="transfer" data-payment-toggle <?= $payChoice !== 'cash' ? 'checked' : '' ?>> <?= e(t('transfer')) ?></label>
          <label class="inline-radio"><input type="radio" name="payment_method" value="cash" data-payment-toggle <?= $payChoice === 'cash' ? 'checked' : '' ?>> <?= e(t('cash')) ?></label>
        <?php else: ?>
          <label class="inline-radio"><input type="radio" name="payment_method" value="transfer" data-payment-toggle <?= $values['payment_method'] !== 'cash' ? 'checked' : '' ?>> <?= e(t('transfer')) ?></label>
          <label class="inline-radio"><input type="radio" name="payment_method" value="cash" data-payment-toggle <?= $values['payment_method'] === 'cash' ? 'checked' : '' ?>> <?= e(t('cash')) ?></label>
        <?php endif; ?>
        </fieldset>

        <?php if (!$groupPayment || $groupPayCode === 'transfer'): ?>

          <!-- close-test-Hai | Chuyển thanh toán thẻ trụ sở chính sang chi nhanh tự nhận chuyển khoản -->

          <!-- <section class="transfer-payment-panel" data-transfer-info>
          <div class="transfer-payment-head">
            <strong><?= e(t('transfer_payment_info')) ?></strong>
            <small<?= !$bankAccounts ? ' role="status"' : '' ?>><?= e(!$groupCheckout ? htp('order_first') : ($hostCollect ? htp('collection_before_order') : t($bankAccounts ? 'transfer_payment_help' : 'transfer_payment_later'))) ?></small>
          </div>
          <div class="transfer-account-list">
            <?php foreach ($bankAccounts as $bank): ?>
              <article class="transfer-account-card">
                <?php if (!empty($bank['qr_image'])): ?>
                  <div class="transfer-qr"><img src="<?= e(base_url('uploads/' . $bank['qr_image'])) ?>" alt="<?= e(t('transfer_qr_alt')) ?>"></div>
                <?php endif; ?>
                <div class="transfer-account-details">
                  <strong><?= e($bank['bank_name']) ?></strong>
                  <div><span><?= e(t('account_number')) ?></span><b><?= e($bank['account_number']) ?></b></div>
                  <div><span><?= e(t('account_name')) ?></span><b><?= e($bank['account_name']) ?></b></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section> -->
        <?php endif; ?>

        <fieldset>
          <legend><?= e(t('invoice_question')) ?></legend>
          <label class="inline-radio"><input type="radio" name="invoice_required" value="1" data-invoice-toggle <?= $values['invoice_required'] === '1' ? 'checked' : '' ?>> <?= e(t('yes')) ?></label>
          <label class="inline-radio"><input type="radio" name="invoice_required" value="0" data-invoice-toggle <?= $values['invoice_required'] !== '1' ? 'checked' : '' ?>> <?= e(t('no')) ?></label>
        </fieldset>

        <div class="invoice-fields" data-invoice-fields>
          <div class="invoice-fields-head">
            <strong><?= e(t('invoice_details')) ?></strong>
            <small><?= e(t('invoice_fields_required')) ?></small>
          </div>
          <div class="invoice-grid">
            <label><?= e(t('company_name')) ?><input name="invoice_company_name" value="<?= e($values['invoice_company_name']) ?>" maxlength="200" autocomplete="organization" data-invoice-required<?= hcq_field_attrs($fieldErrors, 'invoice_company_name') ?>><?= hcq_field_error($fieldErrors, 'invoice_company_name') ?></label>
            <label><?= e(t('tax_id')) ?><input name="invoice_tax_id" value="<?= e($values['invoice_tax_id']) ?>" maxlength="50" inputmode="numeric" data-invoice-required<?= hcq_field_attrs($fieldErrors, 'invoice_tax_id') ?>><?= hcq_field_error($fieldErrors, 'invoice_tax_id') ?></label>
            <label class="span-2"><?= e(t('company_address')) ?><textarea name="invoice_company_address" maxlength="255" rows="2" autocomplete="street-address" data-invoice-required<?= hcq_field_attrs($fieldErrors, 'invoice_company_address') ?>><?= e($values['invoice_company_address']) ?></textarea><?= hcq_field_error($fieldErrors, 'invoice_company_address') ?></label>
            <label><?= e(t('company_representative')) ?> <small class="field-optional">(<?= e(t('optional')) ?>)</small><input name="invoice_company_representative" value="<?= e($values['invoice_company_representative']) ?>" maxlength="120"></label>
            <label><?= e(t('company_phone')) ?><input name="invoice_company_phone" value="<?= e($values['invoice_company_phone']) ?>" maxlength="50" inputmode="tel" autocomplete="tel" data-invoice-required<?= hcq_field_attrs($fieldErrors, 'invoice_company_phone') ?>><?= hcq_field_error($fieldErrors, 'invoice_company_phone') ?></label>
            <label class="span-2"><?= e(t('invoice_email')) ?><input name="invoice_email" value="<?= e($values['invoice_email']) ?>" type="email" maxlength="190" autocomplete="email" data-invoice-required<?= hcq_field_attrs($fieldErrors, 'invoice_email') ?>><?= hcq_field_error($fieldErrors, 'invoice_email') ?></label>
          </div>
        </div>

        <label><?= e(t('order_note')) ?><textarea name="order_note" maxlength="1000" rows="4" placeholder="<?= e(t('order_note_placeholder')) ?>"><?= e($values['order_note']) ?></textarea></label>

        <!-- Nút Gửi đơn hàng đặt trên khối Lưu ý trước khi đặt hàng. -->
        <button
          style="position: sticky; bottom: 1vh;"
          class="btn primary block checkout-submit" type="submit"
          <?= $checkoutUnknown ? 'disabled' : '' ?>>
          <?= e(t('submit_order')) ?>
        </button>

        <div class="preorder-notice" role="note" aria-label="<?= e(t('preorder_notice')) ?>">
          <strong><?= e(t('preorder_notice')) ?></strong>
          <p><?= e(t('checkout_confirmation_notice')) ?></p>
          <p><?= e(t('checkout_data_usage_notice')) ?></p>
        </div>
    </form>

    <?php
    // Nút hủy nhóm: chỉ chủ nhóm, mọi loại đơn nhóm (combo / thường), và chỉ khi nhóm không có lượt chơi mini-game.
    // Đang có lượt chơi thì nhóm bị khoá (hcgs_guard_group) nên hủy không được, ẩn nút cho khỏi bấm nhầm.
    $groupGameLocked = false;
    if ($groupCheckout) {
      $lockedGame = hcgs_locked();
      $groupGameLocked = $lockedGame && hash_equals(hcgs_group_of($lockedGame), (string)$groupCheckout['room']['public_id']);
    }
    $canCancelGroup = $groupCheckout && hyg_host($groupCheckout['room']) && !$groupGameLocked;
    ?>
    <?php if ($canCancelGroup): ?>
      <!-- Checkout nhóm: chủ nhóm hủy nhóm ngay tại đây (cùng action "cancel" như ở trang giỏ nhóm) -->
      <form action="<?= e(base_url('group/action.php')) ?>" method="post" class="hyg-payment-form checkout-cancel-group" data-confirm="<?= e(gt('cancel_confirm')) ?>">
        <?= hyg_payment_action_fields($groupCheckout['room'], 'cancel') ?>
        <label><?= e(gt('payment_reason')) ?>
          <input name="reason" required minlength="3" maxlength="240">
        </label>
        <button class="btn danger block" type="submit"><?= e(gt('cancel')) ?></button>
      </form>
    <?php endif; ?>
  </section>

  <aside class="order-review">
    <?= promotion_summary($promotion) ?>
    <?php /* TODO: Chi tiết thanh toán nhóm bị dư UI ở checkout — tạm ẩn.
    <?php if ($groupPayment): ?><?= hyg_render_payment_overview($groupPayment) ?><?php endif; ?>
    */ ?>
    <?php
    // Tổng số ly/phần để hiện trên nút dropdown Chi tiết đơn hàng.
    $reviewQty = 0;
    foreach ($cart as $r) {
      // Dòng combo (qty luôn 1) chỉ là gói; số ly tính theo các ly nước của combo.
      if (!empty($r['is_combo'])) continue;
      $reviewQty += (int)$r['qty'];
    }
    foreach ($gameGifts as $gift) {
      $reviewQty += (int)$gift['qty'];
    }
    ?>
    <!-- Chi tiết đơn hàng: dropdown, mặc định thu gọn; tổng tiền bên dưới luôn hiện. -->
    <details class="order-review-details">
      <summary>
        <h2><?= e(t('order_details')) ?></h2>
        <span class="order-review-count"><?= $reviewQty ?></span>
        <svg class="order-review-chevron" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M6 9l6 6 6-6" />
        </svg>
      </summary>
      <?php
      // Làm rõ ly nước nào thuộc combo nào: tên combo theo khoá dòng (giỏ thường) hoặc id dòng (giỏ nhóm).
      $comboNames = [];
      foreach ($cart as $ck => $cr) {
        if (empty($cr['is_combo'])) continue;
        $comboNames['k' . $ck] = $cr['name'];
        if (isset($cr['_item_id'])) $comboNames['i' . $cr['_item_id']] = $cr['name'];
      }
      ?>
      <?php foreach ($cart as $r):
        $isComboRow = !empty($r['is_combo']);
        $isComboDrink = !empty($r['combo_drink']);
        $parentComboName = '';
        if ($isComboDrink) {
          $parentComboName = $comboNames['i' . ($r['combo_item'] ?? '')] ?? $comboNames['k' . ($r['combo_parent'] ?? '')] ?? '';
        }
      ?>
        <div class="review-row<?= $isComboRow ? ' is-combo' : '' ?><?= $isComboDrink ? ' is-combo-drink' : '' ?>">
          <div>
            <?php if ($isComboRow): ?><span class="review-combo-tag">COMBO · <?= (int)($r['total_cups'] ?? 0) ?> ly</span><?php endif; ?>
            <?php if ($isComboDrink): ?><span class="review-combo-tag is-drink">Ly nước thuộc combo<?= $parentComboName !== '' ? ' ' . e($parentComboName) : '' ?></span><?php endif; ?>
            <?php if (!empty($r['group_member_name'])): ?><span class="hyg-member-tag"><?= e(gt('ordered_by')) ?>：<?= e($r['group_member_name']) ?></span><?php endif; ?><strong><?= e($r['name']) ?><?php if (!empty($r['cup_size'])): ?> · <?= e($r['cup_size']) ?><?php endif; ?> × <?= $r['qty'] ?></strong>
            <?php if (!$isComboRow): ?><small><?= e($r['ice']) ?> / <?= e($r['sugar']) ?></small><?php endif; ?><?php if (!empty($r['toppings'])): ?><small><?= e(t('toppings')) ?>：<?= e(implode('、', array_map(fn($top) => $top['display_name'] ?? $top['name'], $r['toppings']))) ?></small><?php endif; ?>
          </div>
          <b><?= e(currency()) ?><?= money($r['unit_price'] * $r['qty']) ?></b>
        </div>
      <?php endforeach; ?>
      <?php foreach ($gameGifts as $gift): $pick = $gift['item']; ?>
        <div class="review-row">
          <div>
            <strong>🎁 Quà Mini Game · <?= e($pick['name'] . ($pick['cup_size'] !== '' ? ' · ' . $pick['cup_size'] : '')) ?> × <?= (int)$gift['qty'] ?></strong>
            <small><?= e($gift['kind'] === 'tea' ? 'Tặng ' . $gift['qty'] . ' ly' : 'Tặng ' . $gift['qty'] . ' phần topping') ?> · <?= e($gift['code']) ?></small>
          </div>
          <b><?= e(currency()) ?>0</b>
        </div>
      <?php endforeach; ?>
    </details>
    <div class="review-totals">
      <div><span><?= e(t('subtotal')) ?></span><strong><?= e(currency()) ?><?= money($checkoutSubtotal) ?></strong></div><?php if ($coupon['discount'] > 0): ?><div class="discount-line"><span><?= e(t('coupon_discount')) ?> · <?= e($coupon['code']) ?></span><strong>-<?= e(currency()) ?><?= money($coupon['discount']) ?></strong></div><?php endif; ?><?php if ($gameCash['discount'] > 0): ?><div class="discount-line"><span>Quà Mini Game (<?= count($gameCash['codes']) ?> mã) · <?= e(implode(', ', $gameCash['codes'])) ?></span><strong>-<?= e(currency()) ?><?= money($gameCash['discount']) ?></strong></div><?php endif; ?><div class="review-total"><span><?= e(t('total')) ?></span><strong><?= e(currency()) ?><?= money($checkoutTotal) ?></strong></div>
    </div>
  </aside>
</div>

<!-- Dialog Mua 10ly nước được chơi game: chỉ khi khách chọn "Mua 10 nhận lượt rút thưởng" và đủ 10 ly -->
<?php if ($gameSession) require_once __DIR__ . '/minigame/minigame_dialog.php'; ?>
<?php if ($gameSession && (int)$gameSession['granted_chances'] > 0): ?>
  <script>
    // The header "Mini Game" button sends the customer here with #play: open the game popup right away.
    window.addEventListener('DOMContentLoaded', function() {
      if (location.hash !== '#play' || typeof window.openMinigameModal !== 'function') return;
      history.replaceState(null, '', location.pathname + location.search);
      window.openMinigameModal();
    });
  </script>
<?php endif; ?>
<?php if ($gameSession): ?>
  <!-- Submitting with unplayed chances asks first: they are forfeited, not kept for later. -->
  <dialog id="gameForfeitModal" class="kyuc-confirm">
    <h3><?= str_replace('%N%', '<b data-forfeit-count>' . hcgs_remaining($gameSession) . '</b>', e(hcgs_text('forfeit_title', ['n' => '%N%']))) ?></h3>
    <p><?= e(hcgs_text('forfeit_body')) ?></p>
    <div class="kyuc-confirm-actions">
      <button type="button" class="btn primary checkout-game-btn" data-forfeit-play>
        <svg class="checkout-game-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path class="pad" d="M7 7h10a5 5 0 0 1 4.9 6l-.7 3.5a2.6 2.6 0 0 1-4.6 1.1L14.8 15H9.2l-1.8 2.6a2.6 2.6 0 0 1-4.6-1.1L2.1 13A5 5 0 0 1 7 7z" />
          <path class="dpad" d="M7.5 9.5v4M5.5 11.5h4" />
          <circle cx="16" cy="10.4" r="1.15" />
          <circle cx="18.2" cy="12.6" r="1.15" />
        </svg>
        <?= e(hcgs_text('play_now')) ?>
      </button>
      <button type="button" class="btn" data-forfeit-submit><?= e(hcgs_text('forfeit_submit')) ?></button>
    </div>
  </dialog>
  <style>
    .kyuc-confirm {
      border: none;
      border-radius: 16px;
      padding: 24px;
      max-width: 420px;
      width: calc(100vw - 32px);
      box-shadow: 0 10px 30px rgba(0, 0, 0, .3)
    }

    .kyuc-confirm::backdrop {
      background: rgba(0, 0, 0, .55)
    }

    .kyuc-confirm h3 {
      margin: 0 0 8px
    }

    .kyuc-confirm-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: flex-end;
      margin-top: 16px
    }
  </style>
  <script>
    (function() {
      const form = document.querySelector('[data-checkout-form]');
      const ask = document.getElementById('gameForfeitModal');
      if (!form || !ask) return;
      let confirmed = false;
      // Registered before app.js, so the order is not marked as submitting while the customer decides.
      form.addEventListener('submit', function(event) {
        const left = Number(form.dataset.gameRemaining) || 0;
        if (confirmed || left < 1) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        ask.querySelector('[data-forfeit-count]').textContent = String(left);
        ask.showModal();
      });
      ask.querySelector('[data-forfeit-play]').addEventListener('click', function() {
        ask.close();
        openMinigameModal();
      });
      ask.querySelector('[data-forfeit-submit]').addEventListener('click', function() {
        confirmed = true;
        ask.close();
        form.requestSubmit();
      });
    })();
  </script>
<?php endif; ?>

<?php if ($groupCheckout): ?>
  <script>
    // Browser Back must not return to a group checkout; send the customer to the home page instead.
    (function() {
      const home = <?= json_encode(base_url(''), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
      // Arrived here with Back/Forward (fresh load or restored from the back/forward cache).
      const nav = performance.getEntriesByType('navigation')[0];
      window.addEventListener('pageshow', event => {
        if (event.persisted || (nav && nav.type === 'back_forward')) location.replace(home);
      });
      // Lets the group room know it was reached by leaving this page (see group/index.php).
      window.addEventListener('pagehide', () => {
        try {
          sessionStorage.setItem('hygLeftCheckout', String(Date.now()));
        } catch (_) {}
      });
      // When the order is sent, point this history entry at the home page (replaceState adds no entry).
      window.addEventListener('submit', event => {
        if (event.defaultPrevented || !event.target.matches('[data-checkout-form]')) return;
        history.replaceState(null, '', home);
      });
    })();
  </script>
<?php endif; ?>

<?php front_footer(); ?>