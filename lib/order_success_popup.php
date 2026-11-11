<?php /* Popup chúc mừng đặt đơn thành công, dùng chung cho đơn lẻ (success.php) và đơn nhóm (group/index.php). */ ?>
<dialog id="orderSuccessPopup" class="order-success-popup" aria-labelledby="orderSuccessPopupTitle">
  <canvas class="order-success-fireworks" data-order-popup-fireworks aria-hidden="true"></canvas>
  <div class="order-success-card">
    <div class="order-success-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24">
        <path d="M5 12.5l4.6 4.6L19 7.7" />
      </svg>
    </div>
    <h2 id="orderSuccessPopupTitle"><?=e(t('order_popup_title'))?></h2>
    <p><?=e(t('order_popup_desc'))?></p>
    <button type="button" class="btn primary" data-order-popup-close><?=e(t('order_popup_ok'))?></button>
  </div>
</dialog>
<script src="<?=e(base_url('assets/js/order-success.js?v='.filemtime(__DIR__.'/../assets/js/order-success.js')))?>"></script>
