<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'huiyitea_shop');
define('DB_USER', 'root');
define('DB_PASS', 'rootpassword');
define('APP_BASE_URL', 'http://localhost:8000');
define('APP_TIMEZONE', 'Asia/Ho_Chi_Minh');
// Zalo login (developers.zalo.me). Leave empty to hide the button.
define('ZALO_APP_ID', '');
define('ZALO_APP_SECRET', '');
// Optional: defaults to APP_BASE_URL/member/zalo.php; must match a Callback URL in the Zalo app.
define('ZALO_REDIRECT_URI', '');
// Zalo Mini App login: Secret Key of the Mini App (empty = use ZALO_APP_SECRET).
define('ZALO_MINIAPP_SECRET', '');
// Zalo Mini App ID (mini.zalo.me); empty = the website does not link to the Mini App.
define('ZALO_MINIAPP_ID', '');
// Testing version number printed by `zmp deploy`; empty = open the live Mini App.
define('ZALO_MINIAPP_TEST_VERSION', '');
// true = customers sign in only through the Zalo Mini App (password sign-in and registration hidden).
define('MEMBER_ZALO_ONLY', false);
// Mã bí mật để test Zalo Mini App trên web live: vào member/index.php?zalotest=<mã> (trống = tắt).
define('ZALO_TEST_KEY', '');
