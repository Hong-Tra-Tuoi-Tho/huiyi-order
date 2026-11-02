<?php
// Trỏ ra ngoài 1 cấp thư mục để vào thư mục lib/
require_once __DIR__ . '/../lib/minigame.php';
require_once __DIR__ . '/../lib/promotion_views.php';

$state = hymg_request('state');

$hasReward = isset($state['reward']) && is_array($state['reward']);

$canPlay = minigame_enabled()
    && isset($state['balance']) && (int)$state['balance'] > 0
    && (
        !empty($state['board'])
        ? (isset($state['prizes']) && is_array($state['prizes']) && count($state['prizes']) > 0)
        : hgr_ready()
    );

$appearance = $state['appearance'] ?? ['theme' => '', 'back_image' => ''];
$prizes = $state['prizes'] ?? [];

$prizeDays = ['zh' => '有效 %d 天', 'vi' => 'Có hiệu lực %d ngày', 'en' => 'Valid for %d days'][current_lang()] ?? 'Valid for %d days';
$prizeHeading = ['zh' => '獎項與使用條件', 'vi' => 'Quà và điều kiện sử dụng', 'en' => 'Prizes and conditions'][current_lang()] ?? 'Prizes and conditions';
?>

<!-- File CSS của Minigame -->
<link rel="stylesheet" href="<?= e(base_url('minigame/assets/style.css?v=19.9.14')) ?>">
<link rel="stylesheet" href="<?= e(base_url('minigame/assets/style.css?v=19.9.14')) ?>">

<style>
    /* Style tùy chỉnh cơ bản cho Dialog */
    .kyuc-dialog {
        border: none;
        border-radius: 16px;
        padding: 24px;
        max-width: 850px;
        width: 92vw;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        position: relative;
    }

    .kyuc-dialog::backdrop {
        background: rgba(0, 0, 0, 0.65);
        backdrop-filter: blur(3px);
    }

    .kyuc-dialog-close {
        position: absolute;
        top: 12px;
        right: 16px;
        background: transparent;
        border: none;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        color: #666;
        z-index: 10;
        font-size: xx-large;
        margin: -18px -12px;
    }

    .kyuc-dialog-close:hover {
        color: #000;
    }

    .kyuc-reward-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: center;
    }

    .kyuc-session-rewards {
        margin-top: 16px;
        padding: 12px 16px;
        border: 1px dashed #c9a27a;
        border-radius: 12px;
    }

    .kyuc-session-rewards h4 {
        margin: 0 0 8px;
    }

    .kyuc-session-rewards ol {
        margin: 0;
        padding-left: 20px;
    }

    .kyuc-session-rewards li {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 4px 12px;
        padding: 4px 0;
    }

    .kyuc-session-prize {
        color: #c62828;
        font-weight: bold;
    }

    .kyuc-session-rewards code {
        font-weight: bold;
        user-select: all;
    }
</style>

<!-- Thẻ Dialog chứa toàn bộ Game -->
<dialog id="minigameModal" class="kyuc-dialog">
    <!-- Nút đóng Dialog -->
    <button type="button" class="kyuc-dialog-close" onclick="document.getElementById('minigameModal').close()">✕</button>

    <div class="kyuc-game" data-checkout="1" data-theme="<?= e($appearance['theme'] ?? '') ?>" data-api="<?= e(base_url('actions/minigame.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-lang="<?= e(current_lang()) ?>" data-currency="<?= e(currency()) ?>">

        <div class="kyuc-layout">
            <!-- Banner Hero -->
            <section class="kyuc-hero">
                <div class="kyuc-hero-copy">
                    <span class="kyuc-eyebrow">HỒNG TRÀ TUỔI THƠ · LITTLE MEMORIES</span>
                    <h1><?= e(hgr_text('title')) ?></h1>
                    <?php /* TODO: Tạm ẩn câu giới thiệu "Lật thẻ ký ức, ghép đủ 6 cặp..." ở hộp Mini Game checkout.
                    <p class="kyuc-lead"><?= e(hgr_text('intro')) ?></p>
                    */ ?>
                </div>
                <div class="kyuc-mascots" aria-hidden="true">
                    <img class="kyuc-boy" src="<?= e(base_url('minigame/assets/boy.webp')) ?>" alt="">
                    <img class="kyuc-girl" src="<?= e(base_url('minigame/assets/girl.webp')) ?>" alt="">
                </div>
            </section>

            <?php if (!$canPlay && !$hasReward): ?>
                <!-- Màn hình hết lượt chơi / không đủ điều kiện -->
                <section class="kyuc-board kyuc-empty">
                    <h2><?= e(isset($state['balance']) && (int)$state['balance'] > 0 && minigame_enabled() ? hgr_text('prepare') : t('game_no_chances')) ?></h2>
                    <p><?= e(hgr_text('rules')) ?></p>
                    <button type="button" class="btn primary" onclick="document.getElementById('minigameModal').close()"><?= e(t('return_menu')) ?></button>
                </section>
            <?php else: ?>
                <!-- Khung Game chính -->
                <section class="kyuc-board">
                    <div class="kyuc-board-heading">
                        <h2><?= e(t('game_center')) ?></h2>
                    </div>

                    <div class="kyuc-stats">
                        <div class="kyuc-stat"><span><?= e(hgr_text('pairs')) ?></span><strong><b data-game-pairs>0</b> / 6</strong></div>
                        <div class="kyuc-stat"><span><?= e(hgr_text('moves')) ?></span><strong data-game-moves>0</strong></div>
                        <div class="kyuc-stat"><span><?= e(hgr_text('time')) ?></span><strong data-game-time>00:00</strong></div>
                    </div>

                    <progress data-game-progress max="6" value="0" aria-label="<?= e(hgr_text('pairs')) ?>"></progress>

                    <!-- Lưới thẻ bài -->
                    <div class="kyuc-grid-wrap">
                        <div class="kyuc-grid" data-game-grid aria-label="<?= e(t('game_center')) ?>">
                            <?php for ($i = 0; $i < 12; $i++): ?>
                                <button type="button" class="kyuc-card" data-position="<?= $i ?>" disabled>
                                    <span class="kyuc-card-inner">
                                        <span class="kyuc-card-back <?= ($appearance['back_image'] ?? '') !== '' ? 'kyuc-custom-back' : '' ?>" aria-hidden="true">
                                            <span class="kyuc-card-mark">✦</span>
                                            <span class="kyuc-card-label">KÝ ỨC</span>
                                            <small class="kyuc-card-brand">TUỔI THƠ</small>
                                            <?php if (($appearance['back_image'] ?? '') !== ''): ?>
                                                <img data-game-back-image src="<?= e($appearance['back_image']) ?>" alt="">
                                            <?php else: ?>
                                                <img data-game-back-image hidden alt="">
                                            <?php endif; ?>
                                        </span>
                                        <span class="kyuc-card-face"></span>
                                    </span>
                                </button>
                            <?php endfor; ?>
                        </div>

                        <!-- Màn hình hoàn thành game -->
                        <div class="kyuc-cleared" data-game-cleared hidden>
                            <span aria-hidden="true">✓</span>
                            <h3><?= e(t('game_complete')) ?></h3>
                            <p><?= e(hgr_text('reward')) ?></p>
                        </div>
                    </div>

                    <p class="kyuc-notice" data-game-notice role="status" aria-live="polite"></p>

                    <div class="kyuc-actions">
                        <button class="btn" type="button" data-game-retry hidden><?= e(hgr_text('retry')) ?></button>
                    </div>

                    <!-- Nút ngoài popup: chơi tiếp lượt còn lại + (khi 0đ) chia sẻ Facebook; game.js bấm hộ nút tương ứng trong popup. -->
                    <div class="kyuc-outside-actions">
                        <button class="btn primary kyuc-outside-next" type="button" data-game-next-outside hidden></button>
                        <button class="btn kyuc-fb-btn" type="button" data-game-share-outside hidden>
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M14 8h3V4h-3c-2.8 0-4.5 1.8-4.5 4.6V11H7v4h2.5v9h4v-9h3l.5-4h-3.5V9c0-.6.4-1 1-1z" />
                            </svg>
                            <span></span>
                        </button>
                    </div>

                    <!-- Popup nhận thưởng: mỗi lượt chơi = 1 mã quà riêng; quà 0đ hiện "Chúc bạn may mắn lần sau" + chia sẻ Facebook nhận 1 lượt chắc trúng 52.000đ. -->
                    <dialog class="kyuc-reward-popup" data-game-reward-popup aria-labelledby="kyuc-reward-title">
                        <section class="kyuc-reward" data-game-reward hidden>
                            <button class="kyuc-reward-x" type="button" data-game-reward-close aria-label="Đóng">×</button>

                            <!-- Pháo giấy: chỉ hiện khi trúng quà -->
                            <div class="kyuc-confetti" aria-hidden="true">
                                <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
                            </div>

                            <!-- Icon hộp quà (trúng) / cỏ bốn lá (0đ) -->
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

                    <!-- Danh sách tất cả mã quà đã trúng trong đơn này -->
                    <section class="kyuc-session-rewards" data-game-session-rewards hidden>
                        <h4 data-game-session-heading></h4>
                        <ol data-game-session-list></ol>
                        <p data-game-session-cash hidden></p>
                    </section>
                </section>
            <?php endif; ?>
        </div>

        <!-- Quy tắc game & Chi tiết phần thưởng -->
        <?php /* TODO: Tạm ẩn khối "Giảm giá tiền trực tiếp trên đơn lên tới" ở hộp Mini Game checkout (bỏ if (false) để hiện lại). */ ?>
        <?php if (false): ?>
            <section class="kyuc-rules">
                <?php /* TODO: Tạm ẩn chú thích quy tắc Mua 10 nhận lượt (dư UI).
        <?php /* TODO: Tạm ẩn khối "Giảm giá tiền trực tiếp trên đơn lên tới" ở hộp Mini Game checkout (bỏ if (false) để hiện lại). */ ?>
                <?php if (false): ?>
                    <section class="kyuc-rules">
                        <?php /* TODO: Tạm ẩn chú thích quy tắc Mua 10 nhận lượt (dư UI).
            <p><?= e(hgr_text('rules')) ?></p>
            */ ?>
                        <p class="kyuc-odds" data-game-odds data-money-label="<?= e(hgr_text('odds_money')) ?>" <?= $prizes ? '' : 'hidden' ?>>
                            <?= hgr_odds_html($prizes) ?>
                        </p>
                        <?php /* TODO: Tạm ẩn chú thích lượt/mã quà lưu trong tài khoản (dư UI).
                <p class="kyuc-odds" data-game-odds data-money-label="<?= e(hgr_text('odds_money')) ?>" <?= $prizes ? '' : 'hidden' ?>>
                    <?= hgr_odds_html($prizes) ?>
                </p>
                <?php /* TODO: Tạm ẩn chú thích lượt/mã quà lưu trong tài khoản (dư UI).
            <p><?= e(pt(hym_current_member() ? 'member_saved' : 'guest_saved')) ?></p>
            */ ?>
                    </section>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php /* Khối "Chia sẻ để bạn bè cùng nhận quà" ẩn khỏi màn hình nhưng vẫn nằm trong trang:
        nút "Chia sẻ & nhận lượt chơi" ở popup bấm hộ nút Facebook bên trong (game.js, [data-fb-share-open]). */ ?>
        <div hidden>
            <?php require __DIR__ . '/share_facebook.php'; ?>
        </div>

        <?php /* TODO: Tạm ẩn khối "Quà và điều kiện sử dụng" ở hộp Mini Game checkout (bỏ if (false) để hiện lại). */ ?>
        <?php if (false): ?>
            <details class="kyuc-prize-details" data-game-prize-details <?= array_filter($prizes, fn($prize) => !hgr_reward_is_zero($prize)) ? '' : 'hidden' ?>>
                <summary><?= e($prizeHeading) ?></summary>
                <ul data-game-prizes>
                    <?php foreach ($prizes as $prize): if (hgr_reward_is_zero($prize)) continue; ?>
                        <li>
                            <strong><?= e(hgr_label($prize)) ?></strong>
                            <span><?= e(hgr_label($prize, 'terms') . ' ' . sprintf($prizeDays, (int)($prize['valid_days'] ?? 0))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
        <?php /* TODO: Tạm ẩn khối "Quà và điều kiện sử dụng" ở hộp Mini Game checkout (bỏ if (false) để hiện lại). */ ?>
        <?php if (false): ?>
            <details class="kyuc-prize-details" data-game-prize-details <?= array_filter($prizes, fn($prize) => !hgr_reward_is_zero($prize)) ? '' : 'hidden' ?>>
                <summary><?= e($prizeHeading) ?></summary>
                <ul data-game-prizes>
                    <?php foreach ($prizes as $prize): if (hgr_reward_is_zero($prize)) continue; ?>
                        <li>
                            <strong><?= e(hgr_label($prize)) ?></strong>
                            <span><?= e(hgr_label($prize, 'terms') . ' ' . sprintf($prizeDays, (int)($prize['valid_days'] ?? 0))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
</dialog>

<!-- Chỉ nạp game.js một lần, nạp 2 lần sẽ gửi trùng mỗi lần lật thẻ -->
<script defer src="<?= e(base_url('minigame/assets/game.js?v=19.9.28')) ?>"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const minigameModal = document.getElementById('minigameModal');

        if (minigameModal) {
            // Prizes apply to this order straight away; reload once the game is closed so the bill shows them.
            minigameModal.addEventListener('close', function() {
                if (minigameModal.querySelector('.kyuc-game')?.dataset.awarded === '1') location.reload();
            });
            // Click ra ngoài vùng Dialog (click vào phần nền xám) để đóng
            minigameModal.addEventListener('click', function(event) {
                const rect = minigameModal.getBoundingClientRect();
                const isInDialog = (
                    rect.top <= event.clientY &&
                    event.clientY <= rect.top + rect.height &&
                    rect.left <= event.clientX &&
                    event.clientX <= rect.left + rect.width
                );
                if (!isInDialog) {
                    minigameModal.close();
                }
            });
        }
    });

    // Hàm toàn cục để dễ gọi mở/đóng từ nút bấm trên Checkout
    window.openMinigameModal = function() {
        const modal = document.getElementById('minigameModal');
        if (modal) modal.showModal();
    };

    window.closeMinigameModal = function() {
        const modal = document.getElementById('minigameModal');
        if (modal) modal.close();
    };
</script>