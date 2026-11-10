<?php

/** Shared customer copy and views. Selection and award ownership are validated by the server. */
function pt(string $key, array $vars = []): string
{
  static $texts = [
    'zh' => [
      'title' => '本次優惠',
      'choose_one' => '每筆訂單僅選一種優惠。使用優惠券不會取得本次抽獎機會。',
      'none' => '不使用優惠',
      'none_hint' => '本次不折抵金額，也不累積抽獎機會。',
      'buy4get1' => '買4送1',
      'buy4get1_hint' => '每滿5杯飲品，折抵1杯飲品本體，不含加料。',
      'draw' => '買10抽獎',
      'draw_hint' => '單筆訂單每滿10杯飲品可獲1次抽獎機會：10杯1次、20杯2次，完成訂單後發放。杯數不跨單累計，不折抵本次金額。',
      'reward' => '獎項序號',
      'reward_hint' => '使用已取得的獎項序號，依券面條件折抵，不累積本次抽獎機會。',
      'reward_no_discount' => '尚未符合券面商品、杯數或金額條件，請調整購物車；獎項券會保留。',
      'reward_code' => '獎項序號',
      'reward_code_hint' => '選擇「獎項序號」時，請填寫或選取可用序號。',
      'reward_code_placeholder' => '輸入獎項序號',
      'apply' => '套用優惠',
      'selected' => '已選擇',
      'selection_saved' => '已更新本次優惠。',
      'selection_changed' => '本次優惠已變更，請返回購物車重新確認。',
      'selection_unavailable' => '此優惠目前無法使用，請重新選擇。',
      'no_draw' => '本次不累積抽獎機會。',
      'draw_ready' => '完成此訂單可獲 {chances} 次抽獎機會。',
      'draw_progress' => '目前 {qty} / 10 杯飲品。',
      'host_discount_note' => '團主需有自己的訂購或墊付款，才能使用買4送1或獎項序號；仍可選買10抽獎。',
      'buy4_unavailable' => '目前未提供買4送1優惠。',
      'buy4_need_qty' => '需滿 {min} 杯飲品才可使用（目前 {qty} 杯）。',
      'draw_unavailable' => '目前未提供買10抽獎優惠。',
      'frozen' => '已截止收單，本次優惠與付款金額已固定。',
      'member_saved' => '抽獎機會與獎項券會保存在你的會員帳號。',
      'guest_saved' => '抽獎機會與獎項券保存在目前的瀏覽器。登入會員後可在會員頁查看與使用。',
      'view_member_rewards' => '查看會員獎項券',
    ],
    'vi' => [
      'title' => 'Ưu đãi cho đơn này',
      'choose_one' => 'Mỗi đơn chỉ chọn một ưu đãi. Dùng mã ưu đãi sẽ không nhận lượt rút thưởng cho đơn này.',
      'none' => 'Không dùng ưu đãi',
      'none_hint' => 'Không giảm giá và không tích lượt rút thưởng cho đơn này.',
      'buy4get1' => 'Mua 4 tặng 1',
      'buy4get1_hint' => 'Cứ đủ 5 ly đồ uống, giảm giá 1 ly đồ uống, không gồm topping.',
      'draw' => 'Mua 10 nhận lượt rút thưởng',
      'draw_hint' => 'Mỗi 10 ly trong cùng một đơn nhận 1 lượt rút thưởng: 10 ly được 1 lượt, 20 ly được 2 lượt. Áp dụng trực tiếp cho đơn hiện tại.',
      'reward' => 'Mã phần thưởng',
      'reward_hint' => 'Dùng mã phần thưởng theo điều kiện trên phiếu; không tích lượt rút thưởng cho đơn này.',
      'reward_no_discount' => 'Đơn chưa đủ món, số ly hoặc tiền theo điều kiện phiếu. Hãy điều chỉnh giỏ hàng; mã phần thưởng vẫn được giữ.',
      'reward_code' => 'Mã phần thưởng',
      'reward_code_hint' => 'Khi chọn “Mã phần thưởng”, hãy nhập hoặc chọn mã còn dùng được.',
      'reward_code_placeholder' => 'Nhập mã phần thưởng',
      'apply' => 'Áp dụng ưu đãi',
      'selected' => 'Đã chọn',
      'selection_saved' => 'Đã cập nhật ưu đãi cho đơn này.',
      'selection_changed' => 'Ưu đãi đã thay đổi. Hãy quay lại giỏ hàng để kiểm tra.',
      'selection_unavailable' => 'Ưu đãi này hiện không dùng được. Hãy chọn lại.',
      'no_draw' => 'Không tích lượt rút thưởng cho đơn này.',
      'draw_ready' => 'Hoàn tất đơn này để nhận {chances} lượt rút thưởng.',
      'draw_progress' => 'Hiện có {qty} / 10 ly đồ uống.',
      'host_discount_note' => 'Trưởng nhóm cần có món hoặc khoản trả hộ để dùng Mua 4 tặng 1 hay mã phần thưởng; vẫn có thể chọn Mua 10 nhận lượt rút thưởng.',
      'buy4_unavailable' => 'Hiện chưa có ưu đãi Mua 4 tặng 1.',
      'buy4_need_qty' => 'Cần ít nhất {min} ly đồ uống để áp dụng (hiện có {qty} ly).',
      'draw_unavailable' => 'Hiện chưa có ưu đãi Mua 10 nhận lượt rút thưởng.',
      'frozen' => 'Đã chốt món. Ưu đãi và số tiền phải trả của đơn này đã được cố định.',
      'member_saved' => 'Lượt rút thưởng và mã phần thưởng được lưu trong tài khoản thành viên của bạn.',
      'guest_saved' => 'Lượt rút thưởng và mã phần thưởng được lưu trong trình duyệt này. Đăng nhập để xem và dùng tại trang thành viên.',
      'view_member_rewards' => 'Xem mã phần thưởng của thành viên',
    ],
    'en' => [
      'title' => 'Offer for this order',
      'choose_one' => 'Choose one offer per order. Using a coupon does not earn a draw chance for this order.',
      'none' => 'No offer',
      'none_hint' => 'No discount or draw chance for this order.',
      'buy4get1' => 'Buy 4 get 1',
      'buy4get1_hint' => 'For every 5 drinks, receive a discount for 1 drink, excluding toppings.',
      'draw' => 'Buy 10 for a prize draw',
      'draw_hint' => 'Earn 1 draw chance for every 10 drinks in a single completed order: 10 drinks earn 1 chance, 20 earn 2. Cups do not carry over between orders; no discount on this order.',
      'reward' => 'Prize code',
      'reward_hint' => 'Use a prize code according to its terms. This order will not earn a draw chance.',
      'reward_no_discount' => 'The required items, drink count or amount have not been met. Adjust your cart; your prize coupon will be kept.',
      'reward_code' => 'Prize code',
      'reward_code_hint' => 'When choosing “Prize code”, enter or select an available code.',
      'reward_code_placeholder' => 'Enter a prize code',
      'apply' => 'Apply offer',
      'selected' => 'Selected',
      'selection_saved' => 'Your offer selection has been updated.',
      'selection_changed' => 'The offer changed. Return to your cart and check it again.',
      'selection_unavailable' => 'This offer is currently unavailable. Please choose another.',
      'no_draw' => 'This order does not earn a draw chance.',
      'draw_ready' => 'Complete this order to earn {chances} draw chances.',
      'draw_progress' => 'Currently {qty} / 10 drinks.',
      'host_discount_note' => 'The host needs their own items or payments for members to use Buy 4 get 1 or a prize code. Buy 10 for a prize draw is still available.',
      'buy4_unavailable' => 'Buy 4 get 1 is currently unavailable.',
      'buy4_need_qty' => 'Needs at least {min} drinks to apply (currently {qty}).',
      'draw_unavailable' => 'Buy 10 for a prize draw is currently unavailable.',
      'frozen' => 'Ordering is closed. This offer and the payment amounts are fixed.',
      'member_saved' => 'Draw chances and prize coupons are saved in your member account.',
      'guest_saved' => 'Draw chances and prize coupons are saved in this browser. Sign in to view and use them on your member page.',
      'view_member_rewards' => 'View member prize coupons',
    ],
  ];
  $lang = function_exists('current_lang') ? current_lang() : 'vi';
  $text = $texts[$lang][$key] ?? $texts['en'][$key] ?? $key;
  foreach ($vars as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
  return $text;
}
/** One form explicitly selects one offer; reward codes are ignored for other modes. */
function promotion_selector(array $choice, array $options = []): string
{
  $group = (string)($options['group_public'] ?? '');
  $hostDiscount = $options['host_discount_available'] ?? true;
  // Whether admin offers "buy 4 get 1" at all; the 5-drink minimum is checked below as $buy4.
  $buy4Offered = coupon_enabled() && coupon_code() !== '';
  $draw = minigame_enabled();
  $coupons = available_game_coupons();
  $mode = (string)($choice['mode'] ?? 'none');
  if (!in_array($mode, ['none', 'buy4get1', 'draw', 'reward'], true)) $mode = 'none';
  if ((!$hostDiscount && in_array($mode, ['buy4get1', 'reward'], true)) || (!$buy4Offered && $mode === 'buy4get1') || (!$draw && $mode === 'draw')) $mode = 'none';
  $prefix = $group !== '' ? 'group-promotion' : 'cart-promotion';
  // close-test-Hai | Tạm ẩn "Mã phần thưởng": ưu đãi đã lưu kiểu này hiển thị như "Không dùng ưu đãi"
  if ($mode === 'reward') $mode = 'none';
  $code = $mode === 'reward' ? strtoupper((string)($choice['code'] ?? '')) : '';
  if ($code !== '' && !coupon_record($code)) $code = '';
  ob_start(); ?>

  <?php
  // Các biến đầu vào
  $totalQuantity =  $choice['eligible_qty'] ?? 0; // Số ly nước khách mua
  $hasVoucher    = !empty($voucherCode); // Kiểm tra xem khách có đang dùng Voucher không

  // 1. Kiểm tra các mốc số lượng ly
  // "Mua 4 tặng 1" giảm 1 ly cho mỗi đủ 5 ly, nên chỉ cho chọn từ 5 ly trở lên (và khi admin đang bật ưu đãi).
  $buy4MinQty = promotion_buy4_min_qty();
  $buy4  = $buy4Offered && $totalQuantity >= $buy4MinQty;
  $buy10 = $totalQuantity >= 10;

  // 2. Logic Không dùng Voucher
  $canUseDrinkDiscount = !$hasVoucher && $hostDiscount;

  // 3. Ép chuyển $mode về 'none' nếu chọn 'draw' hoặc 'buy4get1' khi chưa đủ số lượng ly
  if (($mode === 'draw' && !$buy10) || ($mode === 'buy4get1' && !$buy4)) {
    $mode = 'none';
  }

  // Đơn nhóm có combo: chỉ còn ưu đãi "Mua 10 nhận lượt rút thưởng", tự chọn khi đủ 10 ly; các ưu đãi khác ẩn.
  $comboOnly = !empty($options['combo_only']);
  if ($comboOnly) {
    $mode = ($draw && $buy10) ? 'draw' : 'none';
  }

  // Nếu $mode chưa chọn thì chọn theo mốc
  if (!$comboOnly && (empty($mode) || $mode === 'none')) {
    if ($buy10 && $canUseDrinkDiscount) {
      $mode = 'draw';
    } elseif ($buy4 && $canUseDrinkDiscount) {
      $mode = 'buy4get1';
    } else {
      $mode = 'none';
    }
  }
  ?>

  <section class="promotion-panel">
    <h2><?= e(pt('title')) ?></h2>
    <?php /* TODO: Tạm ẩn câu "Mỗi đơn chỉ chọn một ưu đãi. Dùng mã ưu đãi sẽ không nhận lượt rút thưởng cho đơn này."
    <p class="promotion-help"><?= e(pt('choose_one')) ?></p>
    */ ?>
    <form id="<?= e($prefix) ?>-selector" class="promotion-selector" data-promotion-selector<?= $group !== '' ? ' data-promotion-group' : '' ?> method="post" action="<?= e(base_url($group !== '' ? 'group/action.php' : 'actions/apply_coupon.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="<?= $group !== '' ? 'coupon' : 'apply' ?>">
      <?php if ($group !== ''): ?><input type="hidden" name="room_id" value="<?= e($group) ?>"><input type="hidden" name="revision" value="<?= (int)($options['revision'] ?? 0) ?>"><?php endif; ?>


      <fieldset class="promotion-options">
        <legend class="sr-only"><?= e(pt('title')) ?></legend>

        <?php // close-test-Hai | Tạm ẩn lựa chọn "Mã phần thưởng" (giỏ hàng lẫn đơn nhóm)
        // foreach (['none', 'buy4get1', 'draw', 'reward'] as $value): ?>
        <?php foreach ($comboOnly ? ['draw'] : ['none', 'buy4get1', 'draw'] as $value): ?>
          <?php
          // Bổ sung điều kiện !$buy10 vào nút 'draw'
          $disabled = ($value === 'buy4get1' && (!$buy4 || !$hostDiscount))
            || ($value === 'draw' && (!$draw || !$buy10))
            || ($value === 'reward' && !$hostDiscount);
          ?>

          <label class="promotion-option <?= $disabled ? 'is-disabled' : '' ?>" for="<?= $prefix ?>-<?= $value ?>">
            <input
              id="<?= $prefix ?>-<?= $value ?>"
              type="radio"
              name="promotion_mode"
              value="<?= $value ?>"
              <?= $mode === $value ? 'checked' : '' ?>
              <?= $disabled ? 'disabled' : '' ?> />

            <span>
              <strong><?= e(pt($value)) ?></strong>

              <small>
                <?php /* Đơn nhóm dùng chung câu mô tả ngắn với đơn lẻ (câu dài riêng của nhóm: gt('coupon_hint')). */ ?>
                <?= e(pt($value . '_hint')) ?>
              </small>

              <?php if ($value === 'buy4get1' && !$buy4Offered): ?>
                <small><?= e(pt('buy4_unavailable')) ?></small>
              <?php elseif ($value === 'buy4get1' && !$buy4): ?>
                <small><?= e(pt('buy4_need_qty', ['min' => $buy4MinQty, 'qty' => (int) $totalQuantity])) ?></small>
              <?php elseif ($value === 'draw' && !$draw): ?>
                <small><?= e(pt('draw_unavailable')) ?></small>
              <?php elseif ($value === 'draw' && !$buy10): ?>
                <small><?= e(pt('draw_progress', ['qty' => (int) $totalQuantity])) ?></small>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>

      </fieldset>



      <?php // close-test-Hai | Tạm ẩn ô nhập "Mã phần thưởng" (giỏ hàng lẫn đơn nhóm)
      /*
      <div class="promotion-reward-field" data-promotion-reward <?= $mode !== 'reward' ? 'hidden' : '' ?>><label for="<?= $prefix ?>-code"><?= e(pt('reward_code')) ?></label><input id="<?= $prefix ?>-code" data-promotion-code name="coupon_code" value="<?= e($code) ?>" <?= $mode === 'reward' ? 'required' : 'disabled' ?> maxlength="80" autocomplete="off" spellcheck="false" list="<?= $prefix ?>-codes" placeholder="<?= e(pt('reward_code_placeholder')) ?>" aria-describedby="<?= $prefix ?>-code-hint"><datalist id="<?= $prefix ?>-codes"><?php foreach ($coupons as $coupon): ?><option value="<?= e($coupon['code']) ?>"><?= e($coupon['coupon_type'] === 'game_reward' ? hgr_label($coupon) : currency() . money($coupon['amount'])) ?></option><?php endforeach; ?></datalist><small id="<?= $prefix ?>-code-hint"><?= e(pt('reward_code_hint')) ?></small></div>
      */ ?>
      <?php if (!$hostDiscount): ?><p class="promotion-help"><?= e(pt('host_discount_note')) ?></p><?php endif; ?>
      <?php // close-test-Hai | Bỏ nút "Áp dụng ưu đãi" ở đơn nhóm: chỉ chọn radio, nút "Chốt món" gửi kèm lựa chọn
      /*
      <?php if ($group !== ''): ?><button class="btn primary block" type="submit" data-promotion-apply><?= e(pt('apply')) ?></button><?php endif; ?>
      */ ?>
    </form>
  </section>


<?php return ob_get_clean();
}
/** This view never consults current offer settings, so frozen orders remain faithful. */
function promotion_summary(array $choice): string
{
  $mode = (string)($choice['mode'] ?? 'none');
  if (!in_array($mode, ['none', 'buy4get1', 'draw', 'reward'], true)) $mode = 'none';
  $showRewardCode = $mode === 'reward' && !empty($choice['code']) && coupon_record((string)$choice['code']);
  ob_start(); ?>
<?php /* TẠM TẮT khối "Ưu đãi cho đơn này"
  <section class="promotion-summary" aria-label="<?= e(pt('title')) ?>">
    <h3><?= e(pt('title')) ?></h3><strong><?= e(pt($mode)) ?></strong>
    <?php if ($showRewardCode): ?><code><?= e($choice['code']) ?></code><?php endif; ?>
    <?php if (isset($choice['valid']) && !$choice['valid']): ?><p class="promotion-help"><?= e(pt('selection_unavailable')) ?></p><?php endif; ?>
    <?php if ($mode === 'draw'): ?><p class="promotion-help"><?= e(pt((int)($choice['expected_chances'] ?? 0) > 0 ? 'draw_ready' : 'draw_progress', ['chances' => (int)($choice['expected_chances'] ?? 0), 'qty' => (int)($choice['eligible_qty'] ?? 0)])) ?></p><?php else: ?><p class="promotion-help"><?= e(pt('no_draw')) ?></p><?php endif; ?>
  </section>
*/ ?>
<?php return ob_get_clean();
}
