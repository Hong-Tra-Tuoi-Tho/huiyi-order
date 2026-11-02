<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/promotion_views.php';
require_once __DIR__ . '/../lib/minigame.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
// A customer with an open checkout Mini Game session plays it inside that checkout (hold timer, chances and prizes belong to the order).
if ($pendingGame = hcgs_locked()) redirect(hcgs_checkout_url($pendingGame) . '#play');
// A customer with an open checkout Mini Game session plays it inside that checkout (hold timer, chances and prizes belong to the order).
if ($pendingGame = hcgs_locked()) redirect(hcgs_checkout_url($pendingGame) . '#play');
$state = hymg_request('state');
$hasReward = is_array($state['reward']);
$canPlay = minigame_enabled() && (int)$state['balance'] > 0 && ($state['board'] ? count($state['prizes']) > 0 : hgr_ready());
$appearance = $state['appearance'];
$prizeDays = ['zh' => '有效 %d 天', 'vi' => 'Có hiệu lực %d ngày', 'en' => 'Valid for %d days'][current_lang()] ?? 'Valid for %d days';
$prizeHeading = ['zh' => '獎項與使用條件', 'vi' => 'Quà và điều kiện sử dụng', 'en' => 'Prizes and conditions'][current_lang()] ?? 'Prizes and conditions';
if (!$canPlay && !$hasReward) http_response_code(403);
front_header(hgr_text('title'));
?>
<link rel="stylesheet" href="<?= e(base_url('minigame/assets/style.css?v=19.9.14')) ?>">
<style>
  .kyuc-reward-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
  }
</style>
<div class="kyuc-game" data-checkout="1" data-exit-url="<?= e(base_url('index.php')) ?>" data-theme="<?= e($appearance['theme']) ?>" data-api="<?= e(base_url('actions/minigame.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-lang="<?= e(current_lang()) ?>" data-currency="<?= e(currency()) ?>">
  <div class="kyuc-game" data-checkout="1" data-exit-url="<?= e(base_url('index.php')) ?>" data-theme="<?= e($appearance['theme']) ?>" data-api="<?= e(base_url('actions/minigame.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-lang="<?= e(current_lang()) ?>" data-currency="<?= e(currency()) ?>">
    <div class="kyuc-toolbar"><a class="back" href="<?= e(base_url('index.php')) ?>">← <?= e(t('back_menu')) ?></a>
      <div class="kyuc-balance"><span><?= e(t('game_chances')) ?></span><strong data-game-balance><?= (int)$state['balance'] ?></strong></div>
    </div>
    <div class="kyuc-layout">
      <section class="kyuc-hero">
        <div class="kyuc-hero-copy"><span class="kyuc-eyebrow">HỒNG TRÀ TUỔI THƠ · LITTLE MEMORIES</span>
          <h1><?= e(hgr_text('title')) ?></h1>
          <?php /* TODO: Tạm ẩn câu giới thiệu "Lật thẻ ký ức, ghép đủ 6 cặp..." (giống hộp Mini Game checkout).
        <p class="kyuc-lead"><?= e(hgr_text('intro')) ?></p>
        */ ?>
        </div>
        <div class="kyuc-mascots" aria-hidden="true"><img class="kyuc-boy" src="<?= e(base_url('minigame/assets/boy.webp')) ?>" alt=""><img class="kyuc-girl" src="<?= e(base_url('minigame/assets/girl.webp')) ?>" alt=""></div>
      </section>
      <?php if (!$canPlay && !$hasReward): ?>
        <section class="kyuc-board kyuc-empty">
          <h2><?= e((int)$state['balance'] > 0 && minigame_enabled() ? hgr_text('prepare') : t('game_no_chances')) ?></h2>
          <p><?= e(hgr_text('rules')) ?></p><a class="btn primary" href="<?= e(base_url('index.php')) ?>"><?= e(t('return_menu')) ?></a>
        </section>
      <?php else: ?>
        <section class="kyuc-board">
          <div class="kyuc-board-heading">
            <h2><?= e(t('game_center')) ?></h2>
            <?php /* TODO: Tạm ẩn câu "Không giới hạn thời gian; ghép sai không ảnh hưởng quà." (giống hộp Mini Game checkout).
          <?php /* TODO: Tạm ẩn câu "Không giới hạn thời gian; ghép sai không ảnh hưởng quà." (giống hộp Mini Game checkout).
          <p><?= e(hgr_text('no_limit')) ?></p>
          */ ?>
            */ ?>
          </div>
          <div class="kyuc-stats">
            <div class="kyuc-stat"><span><?= e(hgr_text('pairs')) ?></span><strong><b data-game-pairs>0</b> / 6</strong></div>
            <div class="kyuc-stat"><span><?= e(hgr_text('moves')) ?></span><strong data-game-moves>0</strong></div>
            <div class="kyuc-stat"><span><?= e(hgr_text('time')) ?></span><strong data-game-time>00:00</strong></div>
          </div>
          <progress data-game-progress max="6" value="0" aria-label="<?= e(hgr_text('pairs')) ?>"></progress>
          <div class="kyuc-grid-wrap">
            <div class="kyuc-grid" data-game-grid aria-label="<?= e(t('game_center')) ?>">
              <?php for ($i = 0; $i < 12; $i++): ?><button type="button" class="kyuc-card" data-position="<?= $i ?>" disabled><span class="kyuc-card-inner"><span class="kyuc-card-back <?= $appearance['back_image'] !== '' ? 'kyuc-custom-back' : '' ?>" aria-hidden="true"><span class="kyuc-card-mark">✦</span><span class="kyuc-card-label">KÝ ỨC</span><small class="kyuc-card-brand">TUỔI THƠ</small><img data-game-back-image <?= $appearance['back_image'] !== '' ? 'src="' . e($appearance['back_image']) . '"' : 'hidden' ?> alt=""></span><span class="kyuc-card-face"></span></span></button><?php endfor; ?>
            </div>
            <div class="kyuc-cleared" data-game-cleared hidden><span aria-hidden="true">✓</span>
              <h3><?= e(t('game_complete')) ?></h3>
              <p><?= e(hgr_text('reward')) ?></p>
            </div>
          </div>
          <p class="kyuc-notice" data-game-notice role="status" aria-live="polite"></p>
          <div class="kyuc-actions"><button class="btn" type="button" data-game-retry hidden><?= e(hgr_text('retry')) ?></button></div>

          <!-- Nút ngoài popup: chơi tiếp lượt còn lại + chia sẻ Facebook; game.js bấm hộ nút tương ứng trong popup. -->
          <div class="kyuc-outside-actions">
            <button class="btn primary kyuc-outside-next" type="button" data-game-next-outside hidden></button>
            <button class="btn kyuc-fb-btn" type="button" data-game-share-outside hidden>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M14 8h3V4h-3c-2.8 0-4.5 1.8-4.5 4.6V11H7v4h2.5v9h4v-9h3l.5-4h-3.5V9c0-.6.4-1 1-1z" />
              </svg>
              <span></span>
            </button>
          </div>

          <!-- Popup nhận thưởng: giống hộp Mini Game ở checkout. -->
          <dialog class="kyuc-reward-popup" data-game-reward-popup aria-labelledby="kyuc-reward-title">
            <section class="kyuc-reward" data-game-reward hidden>
              <button class="kyuc-reward-x" type="button" data-game-reward-close aria-label="Đóng">×</button>

              <div class="kyuc-confetti" aria-hidden="true">
                <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
              </div>

              <div class="kyuc-reward-icon" aria-hidden="true">
                <svg class="kyuc-icon-gift" viewBox="0 0 48 48">
                  <rect x="7" y="19" width="34" height="23" rx="3" />
                  <rect x="4" y="13" width="40" height="8" rx="2" />
                  <path d="M24 13v29" />
                  <path d="M24 13c-3-7-12-8-12-3s8 3 12 3zM24 13c3-7 12-8 12-3s-8 3-12 3z" />
                </svg>
                <svg class="kyuc-icon-clover" viewBox="0 0 48 48">
                  <path d="M24 24c-9-1-14-6-11-11s9-2 11 4c2-6 8-9 11-4s-2 10-11 11z" />
                  <path d="M24 24c-9 1-14 6-11 11s9 2 11-4c2 6 8 9 11 4s-2-10-11-11z" />
                  <path d="M24 24c4 6 6 12 4 19" />
                </svg>
              </div>

              <span class="kyuc-eyebrow" data-game-reward-head><?= e(hgr_text('reward')) ?></span>
              <h3 id="kyuc-reward-title" data-game-reward-name></h3>
              <p data-game-reward-terms></p>
              <code data-game-reward-code></code>
              <span data-game-reward-amount hidden></span>
              <p data-game-reward-expiry></p>

              <div class="kyuc-share-bonus" data-game-share-bonus hidden>
                <p></p>
                <button class="btn kyuc-fb-btn" type="button">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M14 8h3V4h-3c-2.8 0-4.5 1.8-4.5 4.6V11H7v4h2.5v9h4v-9h3l.5-4h-3.5V9c0-.6.4-1 1-1z" />
                  </svg>
                  <span></span>
                </button>
              </div>

              <div class="kyuc-reward-actions">
                <button class="btn primary" type="button" data-game-next hidden><?= e(hgr_text('next')) ?> (còn <b data-game-balance>0</b> lượt)</button>
                <button class="btn kyuc-reward-done" type="button" data-game-reward-close></button>
              </div>
            </section>
          </dialog>
        </section>
        <script defer src="<?= e(base_url('minigame/assets/game.js?v=19.9.28')) ?>"></script>
      <?php endif; ?>
    </div>
    <?php /* TODO: Tạm ẩn khối quy tắc / tỉ lệ quà (giống hộp Mini Game checkout; bỏ if (false) để hiện lại). */ ?>
    <?php if (false): ?>
      <section class="kyuc-rules">
        <p><?= e(hgr_text('rules')) ?></p>
        <p class="kyuc-odds" data-game-odds data-money-label="<?= e(hgr_text('odds_money')) ?>" <?= $state['prizes'] ? '' : 'hidden' ?>><?= hgr_odds_html($state['prizes']) ?></p>
        <p><?= e(pt(hym_current_member() ? 'member_saved' : 'guest_saved')) ?></p>
      </section>
    <?php endif; ?>
    <?php /* Khối chia sẻ Facebook ẩn khỏi màn hình nhưng vẫn nằm trong trang: nút "Chia sẻ & nhận lượt chơi" ở popup bấm hộ nút bên trong. */ ?>
    <div hidden>
      <?php require __DIR__ . '/share_facebook.php'; ?>
    </div>
    <?php /* TODO: Tạm ẩn khối "Quà và điều kiện sử dụng" (giống hộp Mini Game checkout; bỏ if (false) để hiện lại). */ ?>
    <?php if (false): ?>
      <details class="kyuc-prize-details" data-game-prize-details <?= $state['prizes'] ? '' : 'hidden' ?>>
        <summary><?= e($prizeHeading) ?></summary>
        <ul data-game-prizes><?php foreach ($state['prizes'] as $prize): if (hgr_reward_is_zero($prize)) continue; ?><li><strong><?= e(hgr_label($prize)) ?></strong><span><?= e(hgr_label($prize, 'terms') . ' ' . sprintf($prizeDays, (int)$prize['valid_days'])) ?></span></li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
  </div>
  <?php front_footer(); ?>