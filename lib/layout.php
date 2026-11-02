<?php
require_once __DIR__ . '/push_ui.php';
require_once __DIR__ . '/dispatch_i18n.php';
require_once __DIR__ . '/developer_docs.php';
function front_header(string $title = ''): void
{
    $store = store_name();
    $frontMember = function_exists('hym_current_member') ? hym_current_member() : null;
    $memberName = trim((string)($frontMember['display_name'] ?? ''));
    $memberLabel = $memberName !== '' ? mt('member_entry_named', ['name' => $memberName]) : mt('member_entry');
    $assetVersion = '19.15.22';
    $gameBalance = minigame_enabled() ? game_wallet_balance() : 0;
    $groupContext = $GLOBALS['hyg_context'] ?? null;
    // Group checkout sets only hyg_header_room, so the header cart shows the group cart without full group mode.
    $cartRoom = $groupContext['room'] ?? ($GLOBALS['hyg_header_room'] ?? null);
    // A host with an unsent group checkout keeps seeing that group's cart on every page.
    if (!$cartRoom) {
        try {
            $pendingGame = hcgs_locked();
            if ($pendingGame && hcgs_group_of($pendingGame) !== '') $cartRoom = hyg_room(hcgs_group_of($pendingGame));
        } catch (Throwable $e) {
            $cartRoom = null;
        }
    }
    $GLOBALS['front_cart_room'] = $cartRoom;
    // After "Thêm nước vào đơn này" the cart button goes straight back to checkout (Mini Game chances are recounted there).
    $frontCartUrl = $cartRoom ? hyg_room_url($cartRoom['public_id']) : base_url(!empty($_SESSION['hcgs_adding']) ? 'actions/game_back_checkout.php' : 'cart.php');
    // The logo keeps an open group's menu (and its cart); a locked group goes back to the ordinary menu.
    $brandUrl = $cartRoom && ($cartRoom['status'] ?? '') === 'open' ? base_url('index.php?group=' . rawurlencode($cartRoom['public_id'])) : base_url('index.php');
    $frontCartCount = cart_count();
    if ($cartRoom) {
        $frontCartCount = 0;
        foreach (hyg_cart($cartRoom, false) as $row) $frontCartCount += (int)$row['qty'];
    }

?>
    <!doctype html>
    <html lang="<?= e(html_lang()) ?>">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
        <meta name="theme-color" content="<?= e(theme_primary_color()) ?>">
        <link rel="icon" type="image/png" href="<?= e(base_url('assets/icons/favicon.png?v=19')) ?>">
        <link rel="apple-touch-icon" href="<?= e(base_url('assets/icons/apple-touch-icon.png?v=19')) ?>">
        <link rel="manifest" href="<?= e(base_url('manifest.webmanifest?v=19')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css?v=' . $assetVersion)) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/group-order.css?v=19.8.22')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/member.css?v=19.8.8')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/footer.css?v=1.0.4')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/header.css?v=1.0.8')) ?>">
        <title><?= e($title ? $title . '｜' . $store : $store) ?></title><?php $ogDesc = ['vi' => '🎁 Mua 4 tặng 1 · 🎮 Mua 10 ly nhận 1 lượt Mini Game – cơ hội trúng giải đến 145K · 👥 Đặt đơn nhóm cùng bạn bè, giao tận nơi.', 'zh' => '🎁 買4送1 · 🎮 買10杯送1次小遊戲機會，最高抽中145K好禮 · 👥 揪團點單，外送到府。', 'en' => '🎁 Buy 4 get 1 free · 🎮 Buy 10 drinks for a Mini Game chance to win up to 145K · 👥 Group orders, delivered to your door.'][current_lang()] ?? ''; ?>
        <meta name="description" content="<?= e($ogDesc) ?>">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="<?= e($store) ?>">
        <meta property="og:title" content="<?= e(['vi' => '🧋 Hồng Trà Tuổi Thơ – Mua 4 tặng 1 · Mua 10 ly chơi Mini Game trúng đến 145K', 'zh' => '🧋 回憶紅茶 – 買4送1 · 買10杯玩小遊戲最高抽145K', 'en' => '🧋 Hong Tra Tuoi Tho – Buy 4 get 1 · Buy 10 drinks, win up to 145K'][current_lang()] ?? $store) ?>">
        <meta property="og:description" content="<?= e($ogDesc) ?>"><?php
                                                                    // Share previews use the homepage banner (even while it is hidden on the page), otherwise the app icon.
                                                                    $ogFile = basename(function_exists('hbn_preview_image') ? hbn_preview_image() : ''); // first home slider banner (lib/home_banners.php)
                                                                    $ogPath = $ogFile !== '' ? __DIR__ . '/../uploads/' . $ogFile : '';
                                                                    if ($ogPath === '' || !is_file($ogPath)) {
                                                                        $ogFile = '';
                                                                        $ogPath = __DIR__ . '/../assets/icons/order-icon-512.png';
                                                                    }
                                                                    $ogImage = $ogFile !== '' ? base_url('uploads/' . rawurlencode($ogFile)) : base_url('assets/icons/order-icon-512.png');
                                                                    $ogSize = @getimagesize($ogPath);
                                                                    ?>
        <meta property="og:url" content="<?= e(base_url('')) ?>">
        <meta property="og:image" content="<?= e($ogImage) ?>"><?php if ($ogSize): ?>
            <meta property="og:image:width" content="<?= (int)$ogSize[0] ?>">
            <meta property="og:image:height" content="<?= (int)$ogSize[1] ?>"><?php endif; ?>
        <meta property="og:image:alt" content="<?= e($store) ?>">
        <meta name="twitter:card" content="summary_large_image">
    </head>

    <body class="front-body <?= e(theme_body_class()) ?>">
        <header class="topbar">
            <div class="topbar-inner"><a class="brand" href="<?= e($brandUrl) ?>"><span class="brand-mark brand-mark-image" aria-hidden="true"><img src="<?= e(base_url('assets/icons/order-icon-192.png?v=19')) ?>" alt=""></span><span class="brand-text"><?= e($store) ?></span></a>
                <div class="topbar-actions"><?= language_switcher() ?><?php if (minigame_enabled() && $gameBalance > 0): ?><a class="game-link" href="<?= e(base_url('minigame/')) ?>" aria-label="<?= e(t('game_center')) ?>"><svg class="game-link-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path class="pad" d="M7 7h10a5 5 0 0 1 4.9 6l-.7 3.5a2.6 2.6 0 0 1-4.6 1.1L14.8 15H9.2l-1.8 2.6a2.6 2.6 0 0 1-4.6-1.1L2.1 13A5 5 0 0 1 7 7z" />
                            <path class="dpad" d="M7.5 9.5v4M5.5 11.5h4" />
                            <circle cx="16" cy="10.4" r="1.15" />
                            <circle cx="18.2" cy="12.6" r="1.15" />
                        </svg><span class="game-link-label"><?= e(t('game_center')) ?></span><?php if ($gameBalance > 0): ?><b><?= $gameBalance ?></b><?php endif; ?></a><?php endif; ?><?php if ($frontMember): ?><div class="member-menu"><button type="button" class="member-link member-menu-toggle" aria-haspopup="true" aria-expanded="false" aria-label="<?= e($memberLabel) ?>"><svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="8" r="4" />
                                <path d="M4 21v-2a8 8 0 0 1 16 0v2" />
                            </svg><span><?= e($memberLabel) ?></span><svg class="member-menu-caret" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M6 9l6 6 6-6" />
                            </svg></button>
                        <div class="member-menu-panel" hidden><a class="member-menu-name" href="<?= e(base_url('member/index.php')) ?>"><?= e($memberName !== '' ? $memberName : (string)($frontMember['phone'] ?? '')) ?></a>
                            <form method="post" action="<?= e(base_url('member/action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button type="submit" class="member-menu-item"><svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" />
                                        <path d="M10 17l-5-5 5-5" />
                                        <path d="M5 12h11" />
                                    </svg><?= e(mt('member_logout')) ?></button></form>
                        </div>
                    </div>
                    <script>
                        // Nhấn nút thành viên để mở/đóng menu; nhấn ra ngoài để đóng
                        (function() {
                            var menu = document.currentScript.previousElementSibling;
                            var toggle = menu.querySelector('.member-menu-toggle');
                            var panel = menu.querySelector('.member-menu-panel');

                            function setOpen(open) {
                                panel.hidden = !open;
                                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                                menu.classList.toggle('is-open', open);
                            }
                            toggle.addEventListener('click', function() {
                                setOpen(panel.hidden);
                            });
                            document.addEventListener('click', function(event) {
                                if (!menu.contains(event.target)) setOpen(false);
                            });
                            document.addEventListener('keydown', function(event) {
                                if (event.key === 'Escape') setOpen(false);
                            });
                        })();
                    </script><?php else: ?><a class="member-link" href="<?= e(base_url('member/index.php')) ?>" aria-label="<?= e($memberLabel) ?>"><svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="8" r="4" />
                            <path d="M4 21v-2a8 8 0 0 1 16 0v2" />
                        </svg><span><?= e($memberLabel) ?></span></a><?php endif; ?><a class="cart-link" href="<?= e($frontCartUrl) ?>" aria-label="<?= e($cartRoom ? gt('cart_short') : t('cart')) ?>"><svg class="cart-symbol" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 4h2l1.8 9.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L20 8H7" />
                        <circle cx="9" cy="19" r="1.5" />
                        <circle cx="17" cy="19" r="1.5" />
                    </svg><span class="cart-label"><?= e($cartRoom ? gt('cart_short') : t('cart')) ?></span><span class="cart-count"><?= $frontCartCount ?></span></a>
                </div>
            </div>
        </header>
        <main class="container"><?php
                                if ($groupContext && empty($GLOBALS['hyg_hide_mode'])) { ?>
                <section class="hyg-mode">
                    <div><strong><?= e(gt('mode')) ?> · <?= e($groupContext['room']['title']) ?></strong><small><?= e(gt('nickname')) ?>：<?= e($groupContext['member']['nickname']) ?></small></div>
                    <div class="hyg-mode-actions"><a class="btn" href="<?= e($frontCartUrl) ?>"><?= e(gt('back_room')) ?></a><a href="<?= e(base_url('index.php')) ?>"><?= e(gt('personal')) ?></a></div>
                </section>
            <?php }
                            }
                            function front_footer(): void
                            {
                                $flash = (string)($_SESSION['cart_flash'] ?? '');
                                unset($_SESSION['cart_flash']);
                                $count = cart_count();
                                $groupContext = $GLOBALS['hyg_context'] ?? null;
                                // Same cart as the header: the group being browsed, or the host's unsent group checkout.
                                $floatingRoom = $groupContext['room'] ?? ($GLOBALS['front_cart_room'] ?? null);
                                $floatingUrl = $floatingRoom ? hyg_room_url($floatingRoom['public_id']) : base_url(!empty($_SESSION['hcgs_adding']) ? 'actions/game_back_checkout.php' : 'cart.php');
                                if ($floatingRoom) {
                                    $count = 0;
                                    foreach (hyg_cart($floatingRoom, false) as $row) $count += (int)$row['qty'];
                                }

                                $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
                                $showFloating = empty($GLOBALS['hym_member_page']) && empty($GLOBALS['hyg_hide_mode']) && ($count > 0 || $groupContext) && in_array($page, ['index.php', 'product.php', 'topping.php'], true);
            ?>
        </main>
        <?php if ($flash !== ''): ?><div class="cart-toast" role="status" aria-live="polite"><span class="cart-toast-check" aria-hidden="true">✓</span><span><?= e($flash) ?></span></div><?php endif; ?>
        <?php if ($showFloating): ?><a class="floating-cart" href="<?= e($floatingUrl) ?>" aria-label="<?= e(t('cart')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 4h2l1.8 9.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L20 8H7" />
                    <circle cx="9" cy="19" r="1.5" />
                    <circle cx="17" cy="19" r="1.5" />
                </svg><span class="floating-cart-count"><?= $count ?></span></a><?php endif; ?>

        <!-- Footer website order -->
        <footer class="site-footer">
            <div class="site-footer-main">
                <div class="site-footer-brand">
                    <a class="site-footer-logo" href="<?= e(base_url('index.php')) ?>">
                        <img src="<?= e(base_url('assets/icons/order-icon-192.png?v=19')) ?>" alt="">
                        <span>
                            <strong>
                                <?= e(store_name()) ?>
                            </strong>
                        </span>
                    </a>

                    <div class="site-footer-franchise">
                        <p><strong>CÔNG TY TNHH VẠN NHẤT THIÊN THÀNH</strong></p>
                        <p><?= e(t('footer_tax_code')) ?> 0319146701</p>
                        <p><?= e(t('footer_phone')) ?></p>
                        <ul>
                            <li><b><?= e(t('footer_franchise')) ?> <a href="tel:0909782338">0909782338</a> </b> </li>
                            <li><b><?= e(t('footer_media')) ?> <a href="tel:0906712638">0906712638</a> </b></li>
                            <li><b><?= e(t('footer_support')) ?> <a href="tel:0901655231">0901655231</a> </b></li>
                        </ul>
                    </div>
                </div>

                <div class="site-footer-col">
                    <h3>
                        <span class="site-footer-badge">
                            <svg viewBox="0 0 24 24">
                                <path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z" />
                            </svg>
                        </span><?= e(t('footer_contact')) ?>
                    </h3>
                    <ul class="site-footer-contact">
                        <li>
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" />
                            </svg>
                            <span>
                                <?= t('footer_address') ?>
                            </span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24">
                                <path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z" />
                            </svg>
                            <span>
                                Hotline: <a href="tel:0901655231">0901 655 231</a><br>(8:00 - 22:00)
                            </span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" class="is-stroke">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v5l3 2" />
                            </svg>
                            <span>
                                <?= e(t('footer_opening_hours')) ?><br>8:00 - 22:00 <?= e(t('footer_daily')) ?>
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="site-footer-social">
                    <h3>
                        <?= e(t('footer_follow')) ?>
                    </h3>
                    <div class="social-media-links">
                        <a class="social-media-link" href="https://www.facebook.com/huiyitea.vn" target="_blank" rel="noopener noreferrer"
                            aria-label="Facebook"><span class="social-media-icon social-facebook"
                                aria-hidden="true">f</span><span>Facebook</span></a>
                        <a class="social-media-link" href="https://www.instagram.com/huiyitea/" target="_blank" rel="noopener noreferrer"
                            aria-label="Instagram"><span class="social-media-icon social-instagram" aria-hidden="true"><svg
                                    viewBox="0 0 24 24" focusable="false">
                                    <rect x="3.5" y="3.5" width="17" height="17" rx="5" />
                                    <circle cx="12" cy="12" r="4" />
                                    <circle cx="17.5" cy="6.7" r="1" class="fill-dot" />
                                </svg></span><span>Instagram</span></a>
                        <a class="social-media-link" href="https://www.threads.com/@huiyitea" target="_blank" rel="noopener noreferrer"
                            aria-label="Threads"><span class="social-media-icon social-threads"
                                aria-hidden="true">@</span><span>Threads</span></a>
                        <a class="social-media-link" href="https://www.tiktok.com/@huiyitea" target="_blank" rel="noopener noreferrer"
                            aria-label="TikTok"><span class="social-media-icon social-tiktok" aria-hidden="true"><svg viewBox="0 0 24 24"
                                    focusable="false">
                                    <path
                                        d="M14.2 4.2v9.1a4.45 4.45 0 1 1-3.7-4.38v3.02a1.65 1.65 0 1 0 .9 1.47V4.2h2.8Zm0 0c.55 2.1 1.85 3.42 4.1 3.8v2.77a7.3 7.3 0 0 1-4.1-1.42" />
                                </svg></span><span>TikTok</span></a>
                        <a class="social-media-link" href="https://www.youtube.com/@huiyitea" target="_blank" rel="noopener noreferrer"
                            aria-label="YouTube"><span class="social-media-icon social-youtube" aria-hidden="true"><svg viewBox="0 0 24 24"
                                    focusable="false">
                                    <rect x="2.7" y="5.4" width="18.6" height="13.2" rx="4" />
                                    <path class="social-youtube-play" d="m10.1 9 5.1 3-5.1 3Z" />
                                </svg></span><span>YouTube</span></a>
                    </div>
                </div>

                <div class="site-footer-col">
                    <h3><span class="site-footer-badge"><svg viewBox="0 0 24 24">
                                <path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5zm-1.2 14-3.6-3.6 1.4-1.4 2.2 2.2 4.8-4.8 1.4 1.4z" />
                            </svg></span><?= e(t('footer_policy')) ?></h3>
                    <ul class="site-footer-links">
                        <li><a href="#"><?= e(t('footer_delivery')) ?></a></li>
                        <li><a href="#"><?= e(t('footer_refund')) ?></a></li>
                        <li><a href="#"><?= e(t('footer_privacy')) ?></a></li>
                        <li><a href="#"><?= e(t('footer_terms')) ?></a></li>
                    </ul>
                </div>
            </div>

            <div class="site-footer-bottom">
                <svg class="site-footer-wave" viewBox="0 0 1440 40" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 22C180 6 360 6 540 18s360 22 540 8 270-20 360-10V40H0z" />
                </svg>
                <p>© <?= date('Y') ?> <?= e(store_name()) ?></p>
            </div>
        </footer>


        <script src="<?= e(base_url('assets/js/app.js?v=19.15.3')) ?>"></script><?php if (!empty($GLOBALS['hyg_qr_page'])): ?><script src="<?= e(base_url('assets/vendor/huiyi-qrcode.js?v=19.8.0')) ?>"></script>
            <script src="<?= e(base_url('assets/js/group-qr.js?v=19.8.3')) ?>"></script><?php endif; ?><script src="<?= e(base_url('assets/js/group-order.js?v=19.8.6.3')) ?>"></script>
        <script src="<?= e(base_url('assets/js/promotion.js?v=19.9.2')) ?>"></script>
        <script src="<?= e(base_url('assets/js/game-entry.js?v=19.9.1')) ?>"></script>
    </body>

    </html><?php }
                            function admin_nav_active(string ...$files): string
                            {
                                return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $files, true) ? ' active' : '';
                            }

                            /** ?embed=1: the page is shown inside an admin popup (iframe), so no shell, alerts or push prompt. */
                            function admin_is_embed(): bool
                            {
                                return ($_GET['embed'] ?? '') === '1';
                            }

                            function admin_header(string $title = ''): void
                            {
                                require_once __DIR__ . '/admin_shell.php';
                                $title = $title ?: t('hq_admin');
                                $embed = admin_is_embed();
                                $latest = latest_order_id();
                                $alertEnabled = !$embed && setting('order_alert_enabled', '1') === '1' ? '1' : '0';
                                $alertMode = setting('order_alert_mode', 'seconds');
                                if (!in_array($alertMode, ['seconds', 'minutes', 'until_accepted'], true)) $alertMode = 'seconds';
                                $duration = (int)setting('order_alert_duration', '20');
                                $duration = $alertMode === 'minutes' ? max(1, min(120, $duration)) : max(3, min(3600, $duration));
                                $sound = trim(setting('order_alert_sound', ''));
                                $soundSource = setting('order_alert_sound_source', $sound !== '' ? 'custom' : 'default');
                                if ($soundSource === 'custom' && $sound === '') $soundSource = 'default';
                                $customSoundUrl = $sound !== '' ? base_url('uploads/' . $sound) : '';
                                $soundUrl = $soundSource === 'custom' ? $customSoundUrl : '';
            ?>
    <!doctype html>
    <html lang="<?= e(html_lang()) ?>">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
        <meta name="theme-color" content="<?= e(theme_primary_color()) ?>">
        <link rel="icon" type="image/png" href="<?= e(base_url('assets/icons/favicon.png?v=19')) ?>">
        <link rel="apple-touch-icon" href="<?= e(base_url('assets/icons/apple-touch-icon.png?v=19')) ?>">
        <link rel="manifest" href="<?= e(base_url('admin/manifest.webmanifest?v=19.1')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css?v=19.15.2')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/group-order.css?v=19.8.22')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/dispatch.css?v=19.8.0')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/admin-shell.css?v=19.14.3')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/admin-ui.css?v=19.14.4')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/admin-grid.css?v=19.14.4')) ?>">
        <link rel="stylesheet" href="<?= e(base_url('assets/css/admin-tables.css?v=19.14.5')) ?>">
        <title><?= e($title) ?></title>
    </head>

    <body class="admin-body admin-shell <?= $embed ? 'admin-embed ' : '' ?><?= e(theme_body_class()) ?>" data-order-alert-enabled="<?= e($alertEnabled) ?>" data-order-alert-latest="<?= $latest ?>" data-order-alert-url="<?= e(base_url('admin/api_new_orders.php')) ?>" data-order-alert-sound="<?= e($soundUrl) ?>" data-order-alert-custom-sound="<?= e($customSoundUrl) ?>" data-order-alert-sound-source="<?= e($soundSource) ?>" data-order-alert-mode="<?= e($alertMode) ?>" data-order-alert-duration="<?= $duration ?>" data-order-view-base="<?= e(base_url('admin/order.php?id=')) ?>" data-push-public-key="<?= e(webpush_public_key()) ?>" data-push-subscribe-url="<?= e(base_url('admin/push_subscription.php')) ?>">
        <?php if ($embed): ?>
            <style>
                .admin-shell.admin-embed {
                    --shell-sidebar: 0px;
                    min-height: 0
                }

                .admin-shell.admin-embed .admin-container {
                    margin-left: 0;
                    padding: 16px;
                    min-height: 0
                }
            </style>
            <main class="admin-container" id="admin-main"><?php return;
                                                        endif; ?>
            <?php has_sidebar($title); ?>
            <div class="order-alert-toast" id="order-alert-toast" hidden>
                <div><span class="eyebrow"><?= e(t('new_order_alert')) ?></span><strong data-alert-title></strong><small data-alert-meta></small></div>
                <div class="order-alert-actions"><a class="btn small primary" data-alert-view href="#"><?= e(t('view_order')) ?></a><button class="btn small" type="button" data-alert-stop><?= e(t('stop_sound')) ?></button></div>
            </div>
            <main class="admin-container" id="admin-main">
                <?php /* Ẩn kiểm tra tối ưu màn hình: tạm bỏ khung tiêu đề (Trụ sở / tên trang / Xem trang đặt hàng) trên mọi trang admin.
            <div class="page-title">
                <div><span class="page-kicker"><?= e(t('headquarters')) ?></span>
                    <h1><?= e($title) ?></h1>
                </div><a class="view-store-link" href="<?= e(base_url('index.php')) ?>" target="_blank" rel="noopener"><?= e(t('view_store')) ?></a>
            </div>
            */ ?><?php
                            }
                            function admin_footer(): void
                            { ?>
            </main>
            <script src="<?= e(base_url('assets/js/app.js?v=19.15.1')) ?>"></script>
            <script src="<?= e(base_url('assets/js/admin-shell.js?v=19.14.5')) ?>"></script>
            <script src="<?= e(base_url('assets/js/admin-tables.js?v=19.14.5')) ?>" defer></script><?php if (!admin_is_embed()) hqp_ui_config('admin'); ?>
    </body>

    </html><?php }
