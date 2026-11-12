<?php

/**
 * Popup quảng bá khi khách mới vào website: dạng slide, mỗi slide là một mục quảng bá.
 * Cấu hình ở admin/settings.php, lưu trong bảng settings:
 *   promo_popup_enabled     1/0  bật / tắt cả popup
 *   promo_popup_show_combos 1/0  mỗi combo đang bán là một slide (nút "Đặt ngay" dẫn tới mục combo ở trang chủ)
 *   promo_popup_show_game   1/0  slide giới thiệu Mini Game (chỉ hiện khi Mini Game đang bật)
 *   promo_popup_product_id  id   thêm 1 món được chọn làm slide (0 = không)
 *   promo_popup_title       chữ   tiêu đề phía trên slide (không bắt buộc)
 */

function promo_popup_enabled(): bool
{
    return setting('promo_popup_enabled', '0') === '1';
}

/** Món đang được chọn để quảng bá (còn bán), hoặc null. */
function promo_popup_product(): ?array
{
    $id = (int)setting('promo_popup_product_id', '0');
    if ($id < 1) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM products WHERE id=? AND active=1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Lưu cấu hình từ form admin (gọi trong khối POST của admin/settings.php). */
function promo_popup_save(array $post): void
{
    set_setting('promo_popup_enabled', isset($post['promo_popup_enabled']) ? '1' : '0');
    set_setting('promo_popup_show_combos', isset($post['promo_popup_show_combos']) ? '1' : '0');
    set_setting('promo_popup_show_game', isset($post['promo_popup_show_game']) ? '1' : '0');
    set_setting('promo_popup_product_id', (string)max(0, (int)($post['promo_popup_product_id'] ?? 0)));
    set_setting('promo_popup_title', mb_substr(trim((string)($post['promo_popup_title'] ?? '')), 0, 120));
}

/** Khối cấu hình trong admin/settings.php. */
function promo_popup_admin_block(): void
{
    $products = db()->query('SELECT id,name,name_vi,name_en,name_zh FROM products WHERE active=1 ORDER BY name')->fetchAll();
    $current = (int)setting('promo_popup_product_id', '0');
    ?>
<div class="settings-block"><h2>Popup quảng bá khi vào website</h2>
<p class="field-hint">Khi khách mới vào website, popup hiện dạng slide gồm các mục bạn chọn bên dưới (mỗi lần mở trình duyệt hiện 1 lần).</p>
<label class="toggle-row"><input type="checkbox" name="promo_popup_enabled" value="1" <?= promo_popup_enabled() ? 'checked' : '' ?>><span><strong>Hiện popup quảng bá</strong></span></label>
<label class="toggle-row"><input type="checkbox" name="promo_popup_show_combos" value="1" <?= setting('promo_popup_show_combos', '1') === '1' ? 'checked' : '' ?>><span><strong>Slide combo</strong><small>Mỗi combo đang bán là một slide.</small></span></label>
<label class="toggle-row"><input type="checkbox" name="promo_popup_show_game" value="1" <?= setting('promo_popup_show_game', '1') === '1' ? 'checked' : '' ?>><span><strong>Slide Mini Game</strong><small>Chỉ hiện khi Mini Game đang bật.</small></span></label>
<label>Thêm 1 món vào slide (không bắt buộc)
  <select name="promo_popup_product_id">
    <option value="0">— Không thêm —</option>
    <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $current === (int)$p['id'] ? 'selected' : '' ?>><?= e(localized_name($p)) ?></option><?php endforeach; ?>
  </select>
</label>
<label>Tiêu đề popup (không bắt buộc)<input name="promo_popup_title" maxlength="120" value="<?= e(setting('promo_popup_title', '')) ?>" placeholder="Ưu đãi hôm nay"></label>
</div>
    <?php
}

/** Danh sách slide: mỗi phần tử ['kind','image','name','sub','btn','url']. */
function promo_popup_slides(string $groupPublic = ''): array
{
    $slides = [];

    if (setting('promo_popup_show_combos', '1') === '1' && function_exists('hcf_active')) {
        foreach (hcf_active() as $combo) {
            $slides[] = [
                'kind' => 'combo',
                'combo_id' => (int)$combo['id'],
                'image' => !empty($combo['image']) ? base_url('uploads/' . $combo['image']) : '',
                'name' => localized_name($combo),
                'sub' => function_exists('hcf_price') ? hcf_price($combo) : '',
                'btn' => 'Đặt ngay',
                'url' => '#combos',
            ];
        }
    }

    if (setting('promo_popup_show_game', '1') === '1' && minigame_enabled()) {
        $slides[] = [
            'kind' => 'game',
            'image' => '',
            'name' => 'Mini Game lật thẻ',
            'sub' => 'Mua ' . game_cups_per_chance() . ' ly nhận 1 lượt chơi, trúng quà ngay! Ly trong combo cũng được tính.',
            'btn' => 'Đặt nước ngay',
            'url' => '#',
        ];
    }

    $p = promo_popup_product();
    if ($p) {
        $slides[] = [
            'kind' => 'product',
            'image' => !empty($p['image']) ? base_url('uploads/' . $p['image']) : '',
            'name' => localized_name($p),
            'sub' => '',
            'btn' => 'Xem món',
            'url' => base_url('product.php?id=' . (int)$p['id'] . ($groupPublic !== '' ? '&group=' . rawurlencode($groupPublic) : '')),
        ];
    }

    return $slides;
}

/** Vẽ popup ở trang chủ nếu đang bật và có ít nhất 1 slide. */
function promo_popup_render(string $groupPublic = ''): void
{
    if (!promo_popup_enabled()) {
        return;
    }
    $slides = promo_popup_slides($groupPublic);
    if (!$slides) {
        return;
    }
    $title = trim(setting('promo_popup_title', ''));
    // Đổi danh sách slide thì khoá đổi theo => khách đã đóng popup cũ vẫn thấy popup mới.
    $key = 'promo_popup_' . substr(md5(json_encode($slides)), 0, 10);
    ?>
<div class="promo-popup" id="promo-popup" data-key="<?= e($key) ?>" role="dialog" aria-modal="true" aria-label="<?= e($title !== '' ? $title : 'Ưu đãi') ?>" hidden>
  <div class="promo-popup-card">
    <button type="button" class="promo-popup-close" data-promo-close aria-label="Đóng">&times;</button>
    <div class="promo-popup-track" data-promo-track>
      <?php
      $badges = ['combo' => 'COMBO', 'game' => 'MINI GAME', 'product' => $title !== '' ? $title : 'GỢI Ý HÔM NAY'];
      foreach ($slides as $s): ?>
        <div class="promo-popup-slide is-<?= e($s['kind']) ?>">
          <div class="promo-popup-media">
            <?php if ($s['image'] !== ''): ?>
              <img class="promo-popup-img" src="<?= e($s['image']) ?>" alt="<?= e($s['name']) ?>" loading="lazy">
            <?php elseif ($s['kind'] === 'game'): ?>
              <div class="promo-popup-gift" aria-hidden="true"><span>🎁</span><i></i><i></i></div>
            <?php endif; ?>
            <div class="promo-popup-info">
              <?php if ($s['kind'] !== 'combo'): ?>
              <span class="promo-popup-badge"><?= e($badges[$s['kind']] ?? '') ?></span>
              <?php endif; ?>
              <h3 class="promo-popup-name"><?= e($s['name']) ?></h3>
              <?php if ($s['sub'] !== ''): ?><p class="promo-popup-sub"><?= e($s['sub']) ?></p><?php endif; ?>
            </div>
          </div>
          <?php if (!empty($s['combo_id'])): ?>
            <?php // Đặt ngay / bấm vào hình: luôn vào đơn nhóm (như nút Đặt ngay ở mục combo). ?>
            <?php if ($groupPublic !== ''): ?>
              <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="promo-popup-form" data-promo-order>
                <?= csrf_field() ?>
                <input type="hidden" name="room_id" value="<?= e($groupPublic) ?>">
                <input type="hidden" name="action" value="add_combo">
                <input type="hidden" name="combo_id" value="<?= (int)$s['combo_id'] ?>">
                <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
                <button type="submit" class="promo-popup-cta"><?= e($s['btn']) ?> <span aria-hidden="true">&rarr;</span></button>
              </form>
            <?php else: ?>
              <form method="get" action="<?= e(base_url('group/index.php')) ?>" class="promo-popup-form" data-promo-order>
                <input type="hidden" name="mode" value="combo">
                <input type="hidden" name="combo" value="<?= (int)$s['combo_id'] ?>">
                <button type="submit" class="promo-popup-cta"><?= e($s['btn']) ?> <span aria-hidden="true">&rarr;</span></button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <a class="promo-popup-cta" href="<?= e($s['url']) ?>" data-promo-close><?= e($s['btn']) ?> <span aria-hidden="true">&rarr;</span></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($slides) > 1): ?>
      <button type="button" class="promo-popup-nav is-prev" data-promo-prev aria-label="Trước">&#8249;</button>
      <button type="button" class="promo-popup-nav is-next" data-promo-next aria-label="Sau">&#8250;</button>
    <?php endif; ?>
  </div>
</div>
<style>
.promo-popup[hidden]{display:none}
.promo-popup{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(18,8,8,.6);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);animation:ppFade .25s ease}
.promo-popup{--pp-size:min(calc(100vw - 36px),calc(100vh - 220px),620px)}
.promo-popup-card{position:relative;width:var(--pp-size);max-width:100%;animation:ppNews .75s cubic-bezier(.2,.8,.25,1) both}
.promo-popup-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;scroll-behavior:smooth}
.promo-popup-track::-webkit-scrollbar{display:none}
.promo-popup-slide{position:relative;flex:0 0 100%;min-width:0;scroll-snap-align:center;display:flex;flex-direction:column;align-items:center;color:#fff;text-align:center}
.promo-popup-slide.is-combo .promo-popup-media{cursor:pointer}
.promo-popup-media{position:relative;width:100%;aspect-ratio:1/1;overflow:hidden;border-radius:12px;background:#b30019}
.promo-popup-media::after{content:"";position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.7) 0,rgba(0,0,0,0) 45%);pointer-events:none}
.promo-popup-img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center}
.promo-popup-info{position:absolute;left:0;right:0;bottom:0;z-index:2;padding:0 clamp(20px,calc(var(--pp-size)*.05),44px) clamp(18px,calc(var(--pp-size)*.045),40px);text-align:left}
.promo-popup-badge{display:inline-block;margin-bottom:6px;color:#ffd54a;font-size:clamp(12px,calc(var(--pp-size)*.03),18px);font-weight:800;letter-spacing:.14em;text-transform:uppercase;text-shadow:0 1px 8px rgba(0,0,0,.8)}
.promo-popup-name{margin:0 0 4px;font-size:clamp(26px,calc(var(--pp-size)*.07),56px);line-height:1.2;font-weight:800;text-shadow:0 2px 14px rgba(0,0,0,.9)}
.promo-popup-sub{margin:0;text-shadow:0 1px 10px rgba(0,0,0,.9);font-size:clamp(15px,calc(var(--pp-size)*.04),28px);font-weight:700;color:#ffd54a}
.promo-popup-form{margin:16px 0 0;display:flex;justify-content:center}
.promo-popup-slide>.promo-popup-cta{margin-top:16px}
.promo-popup-cta{border:0;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:clamp(200px,calc(var(--pp-size)*.4),340px);padding:clamp(13px,calc(var(--pp-size)*.025),20px) clamp(28px,calc(var(--pp-size)*.06),48px);border-radius:999px;background:#ffd54a;color:#4a1d00;font-size:clamp(16px,calc(var(--pp-size)*.035),24px);font-weight:800;text-decoration:none;box-shadow:0 10px 30px rgba(255,213,74,.35);transition:transform .15s,box-shadow .15s}
.promo-popup-cta:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(255,213,74,.5)}
.promo-popup-slide.is-game .promo-popup-media{background:none}
.promo-popup-gift{position:absolute;inset:0 0 30% 0;display:flex;align-items:center;justify-content:center}
.promo-popup-gift span{position:relative;z-index:1;font-size:clamp(130px,calc(var(--pp-size)*.32),280px);filter:drop-shadow(0 16px 30px rgba(255,159,26,.55));animation:ppFloat 2.6s ease-in-out infinite}
.promo-popup-gift i{position:absolute;left:50%;top:50%;border-radius:50%;transform:translate(-50%,-50%);background:radial-gradient(circle,rgba(255,159,26,.45),rgba(255,159,26,0) 70%)}
.promo-popup-gift i:nth-child(2){width:calc(var(--pp-size)*.7);height:calc(var(--pp-size)*.7)}
.promo-popup-gift i:nth-child(3){width:calc(var(--pp-size)*.98);height:calc(var(--pp-size)*.98);opacity:.5}
.promo-popup-gift i:nth-child(4){display:none}
.promo-popup-close{position:absolute;top:12px;right:12px;z-index:5;width:40px;height:40px;padding:0;border:0;border-radius:50%;background:#fff;color:#1b0f0f;font-size:26px;font-weight:700;line-height:40px;text-align:center;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.45);transition:transform .15s,background .15s}
.promo-popup-close:hover{background:#ffd54a;transform:scale(1.08)}
.promo-popup-nav{position:absolute;top:calc(var(--pp-size)/2 - 24px);z-index:5;width:48px;height:48px;padding:0 0 4px;border:2px solid #ffa21a;border-radius:50%;background:rgba(20,10,10,.6);color:#ffb52e;font-size:36px;font-weight:800;line-height:42px;text-align:center;cursor:pointer;box-shadow:0 0 0 4px rgba(255,162,26,.25),0 6px 18px rgba(0,0,0,.55);transition:transform .15s,background .15s,color .15s}
.promo-popup-nav:hover{background:#ffa21a;color:#2a1200;transform:scale(1.1)}
.promo-popup-nav.is-prev{left:8px}.promo-popup-nav.is-next{right:8px}
@keyframes ppFade{from{opacity:0}to{opacity:1}}
@keyframes ppNews{0%{opacity:0;transform:rotate(-540deg) scale(.05)}55%{opacity:1}100%{opacity:1;transform:rotate(0) scale(1)}}
@keyframes ppFloat{0%,100%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-12px) rotate(4deg)}}
@media (prefers-reduced-motion:reduce){.promo-popup,.promo-popup-card,.promo-popup-gift span{animation:none}}
</style>
<script>
(function () {
  var box = document.getElementById('promo-popup');
  if (!box) return;
  var key = box.dataset.key;
  try { if (sessionStorage.getItem(key)) return; } catch (e) {}
  var track = box.querySelector('[data-promo-track]');
  var count = track.children.length;
  var timer = null;
  var settle = null;

  function jump(left) {
    // Nhảy tức thì (không hiệu ứng), dùng khi xoay vòng danh sách slide.
    track.style.scrollBehavior = 'auto';
    track.style.scrollSnapType = 'none';
    track.scrollLeft = left;
    track.style.scrollBehavior = '';
    track.style.scrollSnapType = '';
  }
  // Slide đầu luôn là slide đang xem: lướt xong thì chuyển các slide đã qua xuống cuối, nên chạy vòng một chiều, không đảo lại.
  function normalize() {
    var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
    if (i < 1) return;
    for (var k = 0; k < i; k++) track.appendChild(track.firstElementChild);
    jump(0);
  }
  function next() { track.scrollTo({ left: track.clientWidth, behavior: 'smooth' }); }
  function prev() {
    track.insertBefore(track.lastElementChild, track.firstElementChild);
    jump(track.clientWidth);
    track.scrollTo({ left: 0, behavior: 'smooth' });
  }
  function close() {
    box.hidden = true;
    clearInterval(timer);
    try { sessionStorage.setItem(key, '1'); } catch (e) {}
  }

  box.hidden = false;
  track.addEventListener('scroll', function () {
    clearTimeout(settle);
    settle = setTimeout(normalize, 140);
  }, { passive: true });
  var prevBtn = box.querySelector('[data-promo-prev]');
  var nextBtn = box.querySelector('[data-promo-next]');
  if (prevBtn) prevBtn.addEventListener('click', function () { prev(); });
  if (nextBtn) nextBtn.addEventListener('click', function () { next(); });
  if (count > 1) {
    timer = setInterval(next, 4000);
    box.addEventListener('pointerdown', function () { clearInterval(timer); });
  }
  box.addEventListener('click', function (ev) {
    if (ev.target === box || ev.target.closest('[data-promo-close]')) { close(); return; }
    // Bấm vào hình combo = bấm "Đặt ngay" của slide đó.
    var media = ev.target.closest('.promo-popup-media');
    var orderForm = media && media.parentNode.querySelector('[data-promo-order]');
    if (orderForm) orderForm.requestSubmit();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !box.hidden) close(); });
})();
</script>
    <?php
}
