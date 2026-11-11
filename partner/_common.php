<?php
require_once __DIR__.'/../lib/bootstrap.php';
/** Store partner order desk. Reuses the mobile order desk styles; every query is limited to the partner's store. */
function hp_status_label(string $status): string {return hpa_t(in_array($status,['new','accepted','done','cancelled'],true)?$status:'all');}
function hp_lang_switcher(): string {
    $q=$_GET;unset($q['lang']);$path=basename($_SERVER['PHP_SELF']??'index.php');
    $out='<div class="ma-langs">';
    foreach(['vi'=>'VI','en'=>'EN','zh'=>'中'] as $lang=>$label){$q['lang']=$lang;$out.='<a class="'.(current_lang()===$lang?'active':'').'" href="'.e($path.'?'.http_build_query($q)).'">'.e($label).'</a>';}
    return $out.'</div>';
}
function hp_flash(string $key,string $value): void {$_SESSION['partner_flash'][$key]=$value;}
function hp_take_flash(): array {$flash=$_SESSION['partner_flash']??[];unset($_SESSION['partner_flash']);return is_array($flash)?$flash:[];}
/** Same ring settings admins configure for headquarters alerts (admin/settings.php). */
function hp_sound_settings(): array {
    $mode=setting('order_alert_mode','seconds');if(!in_array($mode,['seconds','minutes','until_accepted'],true))$mode='seconds';
    $duration=(int)setting('order_alert_duration','20');$duration=$mode==='minutes'?max(1,min(120,$duration)):max(3,min(3600,$duration));
    $sound=trim(setting('order_alert_sound',''));$source=setting('order_alert_sound_source',$sound!==''?'custom':'default');
    return ['enabled'=>setting('order_alert_enabled','1')==='1','mode'=>$mode,'duration'=>$duration,'sound_url'=>$source==='custom'&&$sound!==''?base_url('uploads/'.$sound):''];
}
function hp_header(array $partner,string $title='',string $bodyClass=''): void {
    $s=hp_sound_settings();
    ?><!doctype html><html lang="<?=e(html_lang())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="<?=e(theme_primary_color())?>"><meta name="robots" content="noindex,nofollow"><link rel="icon" type="image/png" href="<?=e(base_url('assets/icons/favicon.png?v=19'))?>"><link rel="apple-touch-icon" href="<?=e(base_url('assets/icons/apple-touch-icon.png?v=19'))?>"><link rel="stylesheet" href="<?=e(base_url('mobile-admin/style.css?v=19.7.0'))?>"><link rel="stylesheet" href="<?=e(base_url('assets/css/dispatch.css?v=19.7.0'))?>">
    <style>.hp-store{display:block;font-size:11px;font-weight:700;opacity:.8}.hp-actions{display:grid;gap:9px}.hp-return{margin-top:4px}.hp-return summary{cursor:pointer;font-size:12px;font-weight:800;color:#b42318}.hp-return form{display:grid;gap:8px;margin-top:10px}.hp-return textarea{width:100%;border:1px solid var(--line);border-radius:11px;padding:11px;font:inherit}.hp-return p{margin:0;color:var(--muted);font-size:11px}.ma-btn.danger{border-color:#fecdca;color:#b42318}
    body.hp-wide .ma-main{max-width:1180px}.hp-table-wrap{overflow-x:auto;background:#fff;border:1px solid var(--line);border-radius:17px;-webkit-overflow-scrolling:touch}.hp-table{width:100%;border-collapse:collapse;min-width:820px;font-size:13px}.hp-table th{position:sticky;top:0;background:#f9fafb;color:var(--muted);font-size:10px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);white-space:nowrap}.hp-table td{padding:11px 12px;border-bottom:1px solid #f0f1f3;vertical-align:middle;white-space:nowrap}.hp-table tbody tr:last-child td{border-bottom:0}.hp-table tbody tr:hover{background:#fcfcfd}.hp-table tr.is-new td:first-child{box-shadow:inset 3px 0 0 var(--primary)}.hp-table .num{text-align:right}.hp-table .muted{color:var(--muted);font-size:12px}.hp-order-no{font-weight:900}.hp-row-actions{display:flex;gap:6px;justify-content:flex-end}.hp-row-actions form{margin:0}.hp-row-actions .ma-btn{padding:7px 10px;font-size:12px}
    .hp-top-btn{display:inline-flex;align-items:center;gap:5px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.08);color:#fff;border-radius:9px;padding:6px 9px;font-size:11px;font-weight:800;white-space:nowrap;cursor:pointer}.hp-top-btn.active{background:#ecfdf3;border-color:#abefc6;color:#067647}.hp-top-btn[hidden]{display:none}.hp-offline{font-size:11px;font-weight:800;color:#fecdca;white-space:nowrap}.hp-offline[hidden]{display:none}
    @media(max-width:640px){.hp-top-btn{padding:6px 7px}.hp-top-label{display:none}.ma-brand .hp-store{display:none}}</style>
    <title><?=e($title?:hpa_t('app_name'))?></title></head>
    <body class="admin-mobile <?=e(theme_body_class())?> <?=e($bodyClass)?>" data-poll-url="<?=e(hpa_url('api_new_orders.php'))?>" data-poll-ms="10000" data-login-url="<?=e(hpa_url('login.php'))?>" data-alert-enabled="<?=$s['enabled']?'1':'0'?>" data-alert-mode="<?=e($s['mode'])?>" data-alert-duration="<?=(int)$s['duration']?>" data-sound-url="<?=e($s['sound_url'])?>" data-icon="<?=e(base_url('assets/icons/order-icon-192.png?v=19'))?>" data-t-new-order="<?=e(hpa_t('new_orders'))?>" data-t-sound="<?=e(hpa_t('sound'))?>" data-t-sound-on="<?=e(hpa_t('sound_on'))?>" data-t-notify="<?=e(hpa_t('notify'))?>" data-t-notify-on="<?=e(hpa_t('notify_on'))?>" data-t-notify-denied="<?=e(hpa_t('notify_denied'))?>">
    <header class="ma-top"><a class="ma-brand" href="<?=e(hpa_url('index.php'))?>"><img src="<?=e(base_url('assets/icons/order-icon-192.png?v=19'))?>" alt=""><span><?=e(hpa_t('app_name'))?><small class="hp-store"><?=e($partner['store_name'])?> · <?=e($partner['display_name']?:$partner['username'])?></small></span></a><div class="ma-top-actions"><span class="hp-offline" id="hp-offline" hidden>⚠ <?=e(hpa_t('offline'))?></span><button type="button" id="hp-sound" class="hp-top-btn"></button><button type="button" id="hp-notify" class="hp-top-btn"></button><?=hp_lang_switcher()?><form method="post" action="<?=e(hpa_url('logout.php'))?>"><?=csrf_field()?><button class="ma-logout" type="submit" style="background:none;border:0;cursor:pointer"><?=e(hpa_t('logout'))?></button></form></div></header>
    <div class="ma-alert" id="hp-alert" role="alert" hidden><div><small><?=e(hpa_t('new_orders'))?></small><strong data-alert-title></strong><span data-alert-meta></span></div><div class="ma-alert-actions"><a data-alert-view class="ma-btn primary" href="#"><?=e(hpa_t('view_order'))?></a><button data-alert-stop class="ma-btn" type="button"><?=e(hpa_t('stop'))?></button></div></div>
    <main class="ma-main"><?php
}
function hp_footer(): void {?></main><script src="<?=e(base_url('assets/js/partner-orders.js?v=2'))?>"></script></body></html><?php }
