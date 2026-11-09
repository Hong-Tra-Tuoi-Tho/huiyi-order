<?php
require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/../lib/layout.php';
require_admin();
$msg='';$error='';$mailTestDetail='';
function alert_sound_upload(string $current=''): string {
    if(!isset($_FILES['order_alert_sound']) || $_FILES['order_alert_sound']['error']!==UPLOAD_ERR_OK) return $current;
    $f=$_FILES['order_alert_sound'];
    if($f['size']>5*1024*1024) exit('Audio file too large.');
    $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));
    $allowed=['mp3','wav','ogg'];
    if(!in_array($ext,$allowed,true)) exit('Unsupported audio format.');
    $dir=__DIR__.'/../uploads'; if(!is_dir($dir)) @mkdir($dir,0755,true);
    $fn='order-alert-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$fn)) exit('Audio upload failed.');
    if($current && is_file($dir.'/'.$current)) @unlink($dir.'/'.$current);
    return $fn;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'save');
    $vi=trim($_POST['store_name_vi']??''); $en=trim($_POST['store_name_en']??''); $zh=trim($_POST['store_name_zh']??'');
    $recipientInput=$_POST['order_email_recipients']??[];
    if(!is_array($recipientInput)) $recipientInput=[$recipientInput];
    $emailRecipients=[]; $invalidRecipients=[];
    foreach($recipientInput as $candidate){
        $email=trim((string)$candidate);
        if($email==='') continue;
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$invalidRecipients[]=$email;continue;}
        $lower=strtolower($email); $exists=false;
        foreach($emailRecipients as $existing){if(strtolower($existing)===$lower){$exists=true;break;}}
        if(!$exists)$emailRecipients[]=$email;
        if(count($emailRecipients)>=10) break;
    }
    if($invalidRecipients) $error=t('invalid_email');
    else{
        set_setting('store_name',$vi?:($en?:($zh?:t('default_store_name')))); set_setting('store_name_vi',$vi); set_setting('store_name_en',$en); set_setting('store_name_zh',$zh);
        set_setting('currency',trim($_POST['currency']??'₫'));
        set_setting('order_email_recipients',implode("\n",$emailRecipients));
        set_setting('order_email',$emailRecipients[0]??'');
        $themeScheme=(string)($_POST['theme_scheme']??'default');
        if(!in_array($themeScheme,['default','deep_red'],true)) $themeScheme='default';
        set_setting('theme_scheme',$themeScheme);
        set_setting('show_product_sales_count',isset($_POST['show_product_sales_count'])?'1':'0');

        // Home banners moved to admin/banners.php (slider, lib/home_banners.php).

        set_setting('buy4get1_enabled',isset($_POST['buy4get1_enabled'])?'1':'0');
        $code=strtoupper(trim($_POST['buy4get1_code']??'MUA4TANG1')); set_setting('buy4get1_code',$code?:'MUA4TANG1');

        $currentSound=setting('order_alert_sound','');
        $sound=alert_sound_upload($currentSound);
        $uploadedNewSound=($sound!=='' && $sound!==$currentSound);
        if(isset($_POST['remove_order_alert_sound']) && $sound){
            if(is_file(__DIR__.'/../uploads/'.$sound)) @unlink(__DIR__.'/../uploads/'.$sound);
            $sound='';
        }
        $soundSource=(string)($_POST['order_alert_sound_source']??($sound!==''?'custom':'default'));
        if(!in_array($soundSource,['default','custom'],true)) $soundSource='default';
        if($uploadedNewSound) $soundSource='custom';
        if($sound==='') $soundSource='default';
        set_setting('order_alert_enabled',isset($_POST['order_alert_enabled'])?'1':'0');
        set_setting('order_alert_sound',$sound);
        set_setting('order_alert_sound_source',$soundSource);
        $alertMode=(string)($_POST['order_alert_mode']??'seconds');
        if(!in_array($alertMode,['seconds','minutes','until_accepted'],true)) $alertMode='seconds';
        $alertDuration=(int)($_POST['order_alert_duration']??20);
        if($alertMode==='minutes') $alertDuration=max(1,min(120,$alertDuration));
        else $alertDuration=max(3,min(3600,$alertDuration));
        set_setting('order_alert_mode',$alertMode);
        set_setting('order_alert_duration',(string)$alertDuration);

        require_once __DIR__.'/../lib/promo_popup.php'; promo_popup_save($_POST);
        set_setting('minigame_enabled',isset($_POST['minigame_enabled'])?'1':'0');
        set_setting('game_cups_per_chance','10');
        // Random game prizes are configured in admin/game.php; preserve legacy coupon settings.
        $msg=t('settings_saved');
        if($action==='test_email'){
            if(!$emailRecipients) $error=t('email_test_recipient_required');
            else {
                $details=[];
                $ok=send_test_notification_emails($emailRecipients,$details);
                $mailTestDetail=implode(' | ',array_map(fn($email,$d)=>$email.'='.$d,array_keys($details),array_values($details)));
                if($ok) $msg=t('email_test_accepted_multi',['count'=>(string)count($emailRecipients)]);
                else $error=t('email_test_failed',['detail'=>$mailTestDetail?:'unknown']);
            }
        }
    }
}
$products=db()->query('SELECT * FROM products WHERE active=1 ORDER BY sort_order,id')->fetchAll();
$toppings=db()->query('SELECT * FROM toppings WHERE active=1 AND sell_separately=1 ORDER BY sort_order,id')->fetchAll();
admin_header(t('system_settings'));
?>
<?php if($msg):?><div class="alert success"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?>
<section class="panel narrow"><form method="post" enctype="multipart/form-data" class="form-grid ui-form-grid ui-settings-grid"><?=csrf_field()?>
<div class="multilang-box ui-language-grid"><strong><?=e(t('system_store_name'))?></strong><label><?=e(t('store_name_vi'))?><input name="store_name_vi" value="<?=e(setting('store_name_vi','Hồng Trà Tuổi Thơ Đặt đồ uống trực tuyến'))?>" required></label><label><?=e(t('store_name_en'))?><input name="store_name_en" value="<?=e(setting('store_name_en','Hồng Trà Tuổi Thơ Online Ordering'))?>"></label><label><?=e(t('store_name_zh'))?><input name="store_name_zh" value="<?=e(setting('store_name_zh','Hồng Trà Tuổi Thơ 線上點餐'))?>"></label><div class="field-hint"><?=e(t('language_fields_help'))?></div></div>
<label><?=e(t('currency'))?><input name="currency" value="<?=e(setting('currency','₫'))?>"></label>
<div class="settings-block theme-settings-block"><h2><?=e(t('theme_settings'))?></h2><p class="field-hint"><?=e(t('theme_settings_help'))?></p><div class="theme-picker">
<label class="theme-option"><input type="radio" name="theme_scheme" value="default" <?=theme_scheme()==='default'?'checked':''?>><span class="theme-swatch theme-swatch-default" aria-hidden="true"></span><span><strong><?=e(t('theme_default'))?></strong><small><?=e(t('theme_default_help'))?></small></span></label>
<label class="theme-option"><input type="radio" name="theme_scheme" value="deep_red" <?=theme_scheme()==='deep_red'?'checked':''?>><span class="theme-swatch theme-swatch-red" aria-hidden="true"></span><span><strong><?=e(t('theme_deep_red'))?></strong><small><?=e(t('theme_deep_red_help'))?></small></span></label>
</div></div>
<div class="settings-block compact-settings-link"><h2><?=e(t('transfer_accounts'))?></h2><p class="field-hint"><?=e(t('transfer_payment_help'))?></p><a class="btn" href="<?=e(base_url('admin/payment_accounts.php'))?>"><?=e(t('transfer_accounts'))?></a></div>
<div class="settings-block email-settings-block ui-full"><div class="email-settings-title-row"><h2><?=e(t('email_notification_settings'))?></h2><span class="version-chip">v18.18</span></div>
<label><?=e(t('notification_sender_email'))?><input type="email" value="<?=e(notification_sender_email())?>" readonly><small class="field-hint"><?=e(t('notification_sender_email_help'))?></small></label>
<?php $savedRecipients=order_notification_recipients(); if(!$savedRecipients)$savedRecipients=['']; ?>
<div class="email-recipient-manager email-recipient-manager-v1818" data-email-recipient-manager>
  <div class="email-recipient-head"><div><strong><?=e(t('order_notification_emails'))?></strong><small class="field-hint"><?=e(t('order_notification_emails_help'))?></small></div><button class="btn small primary add-email-recipient-btn" type="button" data-add-email-recipient>＋ <?=e(t('add_email_recipient'))?></button></div>
  <div class="email-recipient-list" data-email-recipient-list>
    <?php foreach($savedRecipients as $email): ?><div class="email-recipient-row"><input name="order_email_recipients[]" type="email" maxlength="190" value="<?=e($email)?>" placeholder="orders@example.com"><button class="btn small danger" type="button" data-remove-email-recipient><?=e(t('remove'))?></button></div><?php endforeach; ?>
  </div>
  <noscript><div class="alert danger">JavaScript is required to add more recipient rows.</div></noscript>
</div>
<?php $mailDiag=email_runtime_diagnostics(); ?>
<div class="email-diagnostics"><div><span><?=e(t('email_php_mail'))?></span><strong><?=$mailDiag['mail_available']?e(t('email_available')):e(t('email_unavailable'))?></strong></div><div><span><?=e(t('email_server_transport'))?></span><strong><?=e($mailDiag['sendmail_path']!==''?$mailDiag['sendmail_path']:(($mailDiag['smtp_host']?:'—').($mailDiag['smtp_port']?':'.$mailDiag['smtp_port']:'')))?></strong></div></div>
<button class="btn" type="submit" name="action" value="test_email"><?=e(t('send_test_email'))?></button><small class="field-hint"><?=e(t('email_test_help'))?></small>
</div>
<label class="toggle-row"><input type="checkbox" name="show_product_sales_count" value="1" <?=show_product_sales_count()?'checked':''?>><span><strong><?=e(t('show_sales_count'))?></strong><small><?=e(t('show_sales_count_help'))?></small></span></label>

<div class="settings-block"><h2><?=e(t('homepage_banner'))?></h2>
<p class="field-hint"><?=e(hbn_t('intro'))?></p>
<a class="btn" href="<?=e(base_url('admin/banners.php'))?>"><?=e(hbn_t('title'))?> →</a>
</div>

<div class="settings-block"><h2><?=e(t('coupon_system'))?></h2><p class="field-hint"><?=e(t('buy4get1_help'))?></p><label class="toggle-row"><input type="checkbox" name="buy4get1_enabled" value="1" <?=setting('buy4get1_enabled','1')==='1'?'checked':''?>><span><strong><?=e(t('enable_buy4get1'))?></strong></span></label><label><?=e(t('coupon_code'))?><input name="buy4get1_code" maxlength="80" value="<?=e(setting('buy4get1_code','MUA4TANG1'))?>"></label><p class="field-hint"><?=e(t('single_coupon_only'))?></p></div>

<?php $pushDiag=push_runtime_diagnostics(); ?>
<div class="settings-block mobile-push-settings"><h2><?=e(t('mobile_push_settings'))?> <small>v19.1</small></h2><p class="field-hint"><?=e(t('mobile_push_help'))?></p><div class="email-diagnostics"><div><span><?=e(t('push_server_status'))?></span><strong><?=($pushDiag['openssl']&&$pushDiag['curl']&&$pushDiag['vapid']&&empty($pushDiag['schema_error']))?e(t('push_ready')):e(t('push_unavailable'))?></strong></div><div><span><?=e(t('push_devices'))?></span><strong><?=e((string)$pushDiag['active'])?></strong></div></div><button class="btn" type="button" id="admin-push-enable-settings"><?=e(current_lang()==='zh'?'通知設定／伺服器推播測試':(current_lang()==='en'?'Notification settings / server test':'Cài đặt thông báo / thử từ máy chủ'))?></button><p class="field-hint"><?=e(t('install_android_help'))?><br><?=e(t('install_ios_help'))?></p></div>

<?php require_once __DIR__.'/../lib/promo_popup.php'; promo_popup_admin_block(); ?>
<div class="settings-block"><h2><?=e(t('game_settings'))?></h2>
<label class="toggle-row"><input type="checkbox" name="minigame_enabled" value="1" <?=minigame_enabled()?'checked':''?>><span><strong><?=e(t('enable_minigame'))?></strong><small><?=e(t('game_pending_note',['cups'=>game_cups_per_chance()]))?></small></span></label>
<label><?=e(t('game_cups_per_chance'))?><input type="number" value="10" readonly></label>
<p class="field-hint"><a href="<?=e(base_url('admin/game.php'))?>"><?=e(hgr_text('settings'))?></a> · <?=e(hgr_text('settings_hint'))?></p>
<p class="field-hint"><?=e(t('anonymous_game_note'))?></p>
</div>
<div class="settings-block order-sound-settings ui-full"><h2><?=e(t('order_alert_settings'))?></h2>
<label class="toggle-row"><input type="checkbox" name="order_alert_enabled" value="1" <?=setting('order_alert_enabled','1')==='1'?'checked':''?>><span><strong><?=e(t('enable_order_alert'))?></strong></span></label>
<?php $savedSound=setting('order_alert_sound',''); $savedSource=setting('order_alert_sound_source',$savedSound!==''?'custom':'default'); if($savedSource==='custom' && $savedSound==='') $savedSource='default'; ?>
<div class="sound-source-picker" role="radiogroup" aria-label="<?=e(t('order_alert_sound'))?>">
<label class="sound-source-card"><input type="radio" name="order_alert_sound_source" value="default" <?=$savedSource==='default'?'checked':''?>><span><strong><?=e(t('sound_source_default'))?></strong><small><?=e(t('sound_source_default_help'))?></small></span></label>
<label class="sound-source-card <?=$savedSound===''?'is-disabled':''?>"><input type="radio" name="order_alert_sound_source" value="custom" <?=$savedSource==='custom'?'checked':''?> <?=$savedSound===''?'disabled':''?>><span><strong><?=e(t('sound_source_custom'))?></strong><small><?=e($savedSound!==''?t('sound_source_custom_ready'):t('sound_source_custom_missing'))?></small></span></label>
</div>
<label><?=e(t('upload_custom_sound'))?><input id="order-alert-sound-file" type="file" name="order_alert_sound" accept="audio/mpeg,audio/wav,audio/x-wav,audio/ogg,.mp3,.wav,.ogg"><small class="field-hint"><?=e(t('order_alert_sound_help'))?></small></label>
<?php if($savedSound!==''):?><div class="sound-preview" data-saved-custom-sound="<?=e(base_url('uploads/'.$savedSound))?>"><div class="sound-current-row"><div><strong><?=e(t('current_custom_sound'))?></strong><small><?=e($savedSound)?></small></div><span class="status done"><?=e($savedSource==='custom'?t('currently_in_use'):t('saved_not_in_use'))?></span></div><audio controls preload="metadata" src="<?=e(base_url('uploads/'.$savedSound))?>"></audio><label class="check-row"><input type="checkbox" name="remove_order_alert_sound"> <span><?=e(t('remove_sound'))?></span></label></div><?php else:?><div class="sound-empty-note"><?=e(t('no_custom_sound'))?></div><?php endif;?>
<div class="sound-actions"><button class="btn" type="button" data-test-order-sound><?=e(t('test_sound'))?></button><small class="field-hint"><?=e(t('test_sound_current_choice_help'))?></small></div>
<label><?=e(t('order_alert_duration'))?><div class="alert-duration-setting"><select name="order_alert_mode" id="order-alert-mode"><option value="seconds" <?=setting('order_alert_mode','seconds')==='seconds'?'selected':''?>><?=e(t('seconds'))?></option><option value="minutes" <?=setting('order_alert_mode','seconds')==='minutes'?'selected':''?>><?=e(t('minutes'))?></option><option value="until_accepted" <?=setting('order_alert_mode','seconds')==='until_accepted'?'selected':''?>><?=e(t('until_accepted'))?></option></select><div class="input-with-unit" id="order-alert-duration-wrap"><input type="number" name="order_alert_duration" id="order-alert-duration" min="3" max="3600" value="<?=e(setting('order_alert_duration','20'))?>"><span id="order-alert-duration-unit"><?=setting('order_alert_mode','seconds')==='minutes'?e(t('minutes')):e(t('seconds'))?></span></div></div><small class="field-hint"><?=e(t('order_alert_duration_help'))?></small></label>
</div>

<button class="btn primary" type="submit" name="action" value="save"><?=e(t('save_settings'))?></button></form></section>
<script>document.addEventListener('DOMContentLoaded',()=>{const type=document.getElementById('banner-link-type'),target=document.getElementById('banner-target');function sync(){if(!type||!target)return;[...target.options].forEach(o=>{if(!o.dataset.kind)return;o.hidden=o.dataset.kind!==type.value;});const sel=target.selectedOptions[0];if(sel&&sel.dataset.kind&&sel.dataset.kind!==type.value)target.value='0';}type?.addEventListener('change',sync);sync();const mode=document.getElementById('order-alert-mode'),wrap=document.getElementById('order-alert-duration-wrap'),input=document.getElementById('order-alert-duration'),unit=document.getElementById('order-alert-duration-unit');function syncAlert(){if(!mode||!wrap||!input||!unit)return;const until=mode.value==='until_accepted';wrap.hidden=until;if(mode.value==='minutes'){input.min='1';input.max='120';unit.textContent='<?=e(t('minutes'))?>';}else{input.min='3';input.max='3600';unit.textContent='<?=e(t('seconds'))?>';}}mode?.addEventListener('change',syncAlert);syncAlert();const soundFile=document.getElementById('order-alert-sound-file');const customRadio=document.querySelector('input[name="order_alert_sound_source"][value="custom"]');soundFile?.addEventListener('change',()=>{if(soundFile.files&&soundFile.files[0]&&customRadio){customRadio.disabled=false;customRadio.checked=true;customRadio.closest('.sound-source-card')?.classList.remove('is-disabled');}});});</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const manager=document.querySelector('[data-email-recipient-manager]');
  if(!manager)return;
  const list=manager.querySelector('[data-email-recipient-list]');
  const add=manager.querySelector('[data-add-email-recipient]');
  const removeLabel=<?=json_encode(t('remove'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  const bindRemove=(btn)=>btn.addEventListener('click',()=>{const row=btn.closest('.email-recipient-row');const rows=list.querySelectorAll('.email-recipient-row');if(rows.length<=1){const input=row?.querySelector('input');if(input)input.value='';return;}row?.remove();});
  list.querySelectorAll('[data-remove-email-recipient]').forEach(bindRemove);
  add?.addEventListener('click',()=>{
    if(list.querySelectorAll('.email-recipient-row').length>=10)return;
    const row=document.createElement('div');row.className='email-recipient-row';
    const input=document.createElement('input');input.type='email';input.name='order_email_recipients[]';input.maxLength=190;input.placeholder='orders@example.com';
    const btn=document.createElement('button');btn.className='btn small danger';btn.type='button';btn.dataset.removeEmailRecipient='';btn.textContent=removeLabel;
    row.append(input,btn);list.appendChild(row);bindRemove(btn);input.focus();
  });
});
</script>
<?php admin_footer();?>
