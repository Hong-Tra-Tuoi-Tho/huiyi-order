<?php
if(!function_exists('haa_current')){http_response_code(404);exit;}
/** Small original inline SVG symbols; no remote font/icon dependencies. */
function has_icon(string $name): string {
 $p=[
 'orders'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 11h6M9 15h4"/>',
 'mobile'=>'<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 5h4M11 18h2"/>',
 'products'=>'<path d="M5 7h14l-2 14H7L5 7ZM5 7l2-4h10l2 4M10 10v7M14 10v7"/>',
 'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
 'topping'=>'<path d="M5 12a7 7 0 0 0 14 0H5ZM3 12h18M8 7v2M12 5v4M16 7v2"/>',
 'combo'=>'<path d="M4 8h7l-1 13H5L4 8ZM13 8h7l-1 13h-5l-1-13ZM6 8l1-4h3M15 8l1-4h3"/>',
 'profiles'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
 'game'=>'<rect x="3" y="7" width="18" height="12" rx="4"/><path d="M7 11v4M5 13h4M15 12h.1M18 15h.1M9 7V4h6"/>',
 'image'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-8 8"/>',
 'bank'=>'<path d="m3 9 9-6 9 6H3ZM4 21h16M6 12v6M12 12v6M18 12v6"/>',
 'store'=>'<path d="M4 10v11h16V10M3 10l2-7h14l2 7M9 21v-7h6v7M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
 'users'=>'<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M17 4a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>',
 'sync'=>'<path d="M4 11a8 8 0 0 1 14-5l3 3M21 3v6h-6M20 13A8 8 0 0 1 6 18l-3-3M3 21v-6h6"/>',
 'settings'=>'<path d="M9.245 5.348 L10.186 2.879 L13.814 2.879 L14.755 5.348 L17.167 4.267 L19.733 6.833 L18.652 9.245 L21.121 10.186 L21.121 13.814 L18.652 14.755 L19.733 17.167 L17.167 19.733 L14.755 18.652 L13.814 21.121 L10.186 21.121 L9.245 18.652 L6.833 19.733 L4.267 17.167 L5.348 14.755 L2.879 13.814 L2.879 10.186 L5.348 9.245 L4.267 6.833 L6.833 4.267 Z"/><circle cx="12" cy="12" r="3"/>',
 'book'=>'<path d="M12 5v16M12 5C8 2 4 3 2 4v16c4-2 7-2 10 1 3-3 6-3 10-1V4c-2-1-6-2-10 1Z"/>',
 'shield'=>'<path d="m12 2 8 4v6c0 5-8 10-8 10S4 17 4 12V6l8-4Z"/><path d="m8 12 3 3 5-6"/>',
 'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>','logout'=>'<path d="M9 3H3v18h6M9 12h12m-4-4 4 4-4 4"/>','bell'=>'<path d="M5 17h14l-2-4V8a5 5 0 0 0-10 0v5l-2 4ZM10 21h4"/>'];
 return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($p[$name]??$p['grid']).'</svg>';
}
function has_label(string $zh,string $vi,string $en): string {return current_lang()==='zh'?$zh:(current_lang()==='en'?$en:$vi);}
function has_navigation(): array {
 $groups=[['接單作業','Đơn hàng','Order desk',[
  ['index.php','訂單管理','Đơn hàng','Orders','orders',['index.php','order.php']],
  ['../mobile-admin/index.php','手機接單','Nhận đơn','Mobile desk','mobile',[]]]],
 ['商品與活動','Danh mục & hoạt động','Catalog & promotions',[
  ['products.php','商品管理','Đồ uống','Products','products',['products.php','product_edit.php']],
  ['categories.php','分類管理','Danh mục','Categories','grid',['categories.php']],
  ['toppings.php','配料管理','Topping','Toppings','topping',['toppings.php','topping_edit.php']],
  ['combos.php','套餐管理','Combo','Combos','combo',['combos.php','combo_edit.php']],
  ['profiles.php','甜度冰量','Đá & đường','Ice & sugar','profiles',['profiles.php']],
  ['game.php','小遊戲','Mini Game','Mini Game','game',['game.php']],
  ['banners.php','首頁橫幅','Banner','Banners','image',['banners.php']]]],
 ['門市與會員','Chi nhánh & thành viên','Stores & members',[
  ['stores.php','門市管理','Chi nhánh','Stores','store',['stores.php']],
  ['payment_accounts.php','收款帳戶','Tài khoản thu','Payments','bank',['payment_accounts.php']],
  ['members.php','會員管理','Thành viên','Members','users',['members.php']],
  ['member-settings.php','iPOS 匯入','Nhập iPOS','iPOS import','sync',['member-settings.php']]]],
 ['系統與權限','Hệ thống & quyền','System & access',[
  ['settings.php','系統設定','Thiết lập','Settings','settings',['settings.php']],
  ['developer.php','開發文件','Nhà phát triển','Developer','book',['developer.php']],
  ['accounts.php','帳號管理','Tài khoản','Accounts','shield',['accounts.php']]]]];
 if(admin_is_operator())return [$groups[0]];
 if(!haa_owner())array_pop($groups[3][3]);return $groups;
}
function has_sidebar(string $title): void {
 $me=haa_current();$display=trim($me['display_name']??'')?:($me['username']??'');preg_match('/^./u',$display,$initial);
 ?><a class="admin-skip" href="#admin-main"><?=e(has_label('跳至主要內容','Đến nội dung chính','Skip to content'))?></a>
 <aside class="admin-sidebar" id="admin-sidebar"><a class="admin-sidebar-brand" href="<?=e(base_url('admin/index.php'))?>"><span class="admin-brand-word">HUIYI</span><span><?=e(store_name())?></span><small><?=e(has_label('訂單管理','Quản lý đơn hàng','Order management'))?></small></a>
 <nav class="admin-shell-nav" aria-label="<?=e(t('hq_admin'))?>"><?php foreach(has_navigation() as [$zh,$vi,$en,$items]):$group=has_label($zh,$vi,$en);?><section class="admin-nav-group"><h2><?=e($group)?></h2><div class="admin-nav-grid"><?php foreach($items as [$url,$zh,$vi,$en,$icon,$active]):$label=has_label($zh,$vi,$en);$isActive=in_array(basename($_SERVER['SCRIPT_NAME']??''),$active,true);?><a class="admin-tile<?=$isActive?' active':''?>" href="<?=e($url[0]==='.'?base_url('mobile-admin/index.php'):base_url('admin/'.$url))?>"<?=$isActive?' aria-current="page"':''?>><span class="admin-tile-icon"><?=has_icon($icon)?></span><strong><?=e($label)?></strong></a><?php endforeach;?></div></section><?php endforeach;?></nav>
 <div class="admin-sidebar-user"><span class="admin-avatar"><?=e($initial[0]??'H')?></span><div><strong><?=e($display)?></strong><small><?=e(haa_t($me['role']??'admin'))?></small></div><a href="<?=e(base_url('admin/logout.php'))?>" aria-label="<?=e(t('logout'))?>"><?=has_icon('logout')?></a></div></aside>
 <button class="admin-sidebar-overlay" type="button" aria-label="<?=e(has_label('關閉導覽','Đóng trình đơn','Close navigation'))?>" hidden></button>
 <header class="admin-shell-top"><div class="admin-shell-context"><button class="admin-menu-button" type="button" aria-controls="admin-sidebar" aria-expanded="true" aria-label="<?=e(has_label('切換功能選單','Mở hoặc đóng trình đơn','Toggle navigation'))?>" hidden><?=has_icon('menu')?></button><div><small><?=e(t('headquarters'))?></small><strong><?=e($title)?></strong></div><span class="admin-role-pill"><?=e(haa_t($me['role']??'admin'))?></span></div><div class="admin-shell-actions"><button class="admin-push-button" id="admin-push-enable" type="button"><?=has_icon('mobile')?><span data-push-label><?=e(t('enable_mobile_push'))?></span></button><button class="admin-sound-button" id="admin-sound-enable" type="button"><?=has_icon('bell')?><span data-sound-label><?=e(t('enable_sound'))?></span></button><?=language_switcher('language-switcher admin-langs')?></div></header>
 <?php
}
