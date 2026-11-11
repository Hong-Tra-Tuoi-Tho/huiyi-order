<?php
require __DIR__.'/_common.php';
header('Cache-Control: private, no-store');
if(hpa_current())redirect(hpa_url('index.php'));
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $u=is_string($_POST['username']??null)?trim($_POST['username']):'';$p=is_string($_POST['password']??null)?$_POST['password']:'';
    if(hpa_sign_in($u,$p))redirect(hpa_url('index.php'));
    $err=hpa_t('login_error');
}
?><!doctype html><html lang="<?=e(html_lang())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="<?=e(theme_primary_color())?>"><meta name="robots" content="noindex,nofollow"><link rel="icon" href="<?=e(base_url('assets/icons/favicon.png?v=19'))?>"><link rel="apple-touch-icon" href="<?=e(base_url('assets/icons/apple-touch-icon.png?v=19'))?>"><link rel="stylesheet" href="<?=e(base_url('mobile-admin/style.css?v=19.7.0'))?>"><title><?=e(hpa_t('app_name'))?></title></head><body class="ma-login-bg <?=e(theme_body_class())?>"><main class="ma-login-card"><img class="ma-login-icon" src="<?=e(base_url('assets/icons/order-icon-192.png?v=19'))?>" alt=""><div class="ma-login-head"><h1><?=e(hpa_t('app_name'))?></h1><?=hp_lang_switcher()?></div><?php if($err):?><div class="ma-error" role="alert"><?=e($err)?></div><?php endif;?><form method="post" class="ma-form"><?=csrf_field()?><label><?=e(hpa_t('username'))?><input name="username" autocomplete="username" required autofocus></label><label><?=e(hpa_t('password'))?><input name="password" type="password" autocomplete="current-password" required></label><button class="ma-btn primary wide" type="submit"><?=e(hpa_t('login'))?></button></form></main></body></html>
