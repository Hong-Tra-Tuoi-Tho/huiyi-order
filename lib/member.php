<?php
/** Local customer accounts. Staff authentication remains a separate session identity. */
class HymMemberError extends RuntimeException {}
function hym_fail(string $key): never { throw new HymMemberError($key); }
function hym_schema(): void {
    static $done=false;
    if($done)return;
    $pdo=db();
    if($pdo->inTransaction())throw new RuntimeException('member_schema_inside_transaction');
    $st=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $st->execute(['member_schema_version']);
    if((string)$st->fetchColumn()!=='19.4.0'){
        $lock='hym-schema:'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $get=$pdo->prepare('SELECT GET_LOCK(?,10)');$get->execute([$lock]);
        if((int)$get->fetchColumn()!==1)throw new RuntimeException('member_schema_busy');
        try{
            $st->execute(['member_schema_version']);
            if((string)$st->fetchColumn()!=='19.4.0'){
                $sql=file_get_contents(__DIR__.'/member_schema.sql');
                if($sql===false)throw new RuntimeException('member_schema_missing');
                foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
                $mark=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                $mark->execute(['member_schema_version','19.4.0']);
            }
        }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}
    }
    $done=true;
}
function hym_normalize_phone(string $phone): string {
    if(strlen($phone)>80||!preg_match('/^[+0-9\s().-]+$/D',$phone))hym_fail('member_invalid_phone');
    $value=preg_replace('/[\s().-]+/','',$phone);
    if(str_starts_with($value,'+'))$value=substr($value,1);
    elseif(str_starts_with($value,'00'))$value=substr($value,2);
    if(str_starts_with($value,'0'))$value='84'.substr($value,1);
    if(!preg_match('/^(?:84[35789][0-9]{8}|842[0-9]{9})$/D',$value))hym_fail('member_invalid_phone');
    return $value;
}
function hym_text(string $value,int $max): string {
    $value=trim($value);
    if(!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))hym_fail('member_invalid_profile');
    $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
    if($length>$max)hym_fail('member_invalid_profile');
    return $value;
}
function hym_member_row(int $id): ?array {
    if($id<1)return null;
    $st=db()->prepare('SELECT id,phone,display_name,delivery_address,phone_verified_at,created_at FROM members WHERE id=?');
    $st->execute([$id]);$row=$st->fetch();
    if(!$row)return null;
    $row['id']=(int)$row['id'];return $row;
}
function hym_current_member(): ?array { return hym_member_row((int)($_SESSION['member_id']??0)); }
/** Maps a ?return= value to the page a guest is sent back to after signing in; null if not allowed. */
function hym_return_path(string $target): ?string {
    if($target==='group')return 'group/';
    // A group invite link: the guest comes back to the same invite to join.
    if(preg_match('/^invite-([a-f0-9]{64})$/D',$target,$m))return 'group/?invite='.$m[1];
    return null;
}
/** Links a members-only entry point: guests go to sign-in first and come back afterwards. */
function hym_login_gate_url(string $target): string {
    $path=hym_return_path($target);
    if($path===null)return base_url('member/index.php');
    return (int)($_SESSION['member_id']??0)>0?base_url($path):base_url('member/index.php?return='.rawurlencode($target));
}
/** Remembers where to send the customer once sign-in (password, Zalo or registration) completes. */
function hym_remember_return(string $target): void {
    if(hym_return_path($target)!==null)$_SESSION['member_return']=['target'=>$target,'created_at'=>time()];
}
/** Returns and clears the pending post-login destination, if still fresh. */
function hym_take_return(): ?string {
    $pending=$_SESSION['member_return']??null;unset($_SESSION['member_return']);
    if(!is_array($pending)||($pending['created_at']??0)<time()-1800)return null;
    return hym_return_path((string)($pending['target']??''));
}
function hym_require_member(): array {
    $member=hym_current_member();
    if(!$member)redirect(base_url('member/index.php'));
    return $member;
}
function hym_staff(int $id=0): bool {
    $actor=function_exists('haa_current')?haa_current():null;
    if(!$actor||!in_array($actor['role'],['owner','admin'],true))return false;
    $sessionId=(int)($_SESSION['admin_id']??0);
    if($sessionId<1||($id>0&&$sessionId!==$id))return false;
    $st=db()->prepare('SELECT id FROM admins WHERE id=?');$st->execute([$sessionId]);
    return (bool)$st->fetchColumn();
}
function hym_can_access(int $id): bool {
    return $id>0&&((int)($_SESSION['member_id']??0)===$id||hym_staff()||(defined('HYM_SYNC_CLI')&&HYM_SYNC_CLI&&PHP_SAPI==='cli'));
}
function hym_session_login(int $id): void {
    $wasMember=(int)($_SESSION['member_id']??0);
    $guestCandidate=$wasMember>0?'':(function_exists('hym_reward_guest_session_token')?hym_reward_guest_session_token():'');
    if(session_status()===PHP_SESSION_ACTIVE&&!session_regenerate_id(true))hym_fail('member_save_failed');
    if(session_status()===PHP_SESSION_ACTIVE&&!headers_sent()){
        $params=session_get_cookie_params();
        setcookie(session_name(),session_id(),['expires'=>0,'path'=>$params['path']?:'/',
            'secure'=>parse_url(base_url(),PHP_URL_SCHEME)==='https','httponly'=>true,'samesite'=>'Lax']);
    }
    $_SESSION['member_id']=$id;
    // A customer identity change cannot inherit another session's guest payment access.
    unset($_SESSION['customer_payment_orders'],$_SESSION['last_order_no'],$_SESSION['checkout_drafts'],$_SESSION['checkout_requests'],$_SESSION['checkout_errors'],$_SESSION['transfer_payment_flash']);
    unset($_SESSION['member_flash']);
    if(function_exists('hym_reward_claim_guest')){
        // Switching accounts cannot transfer the previous account's or cookie-only rewards.
        if($wasMember>0||$guestCandidate==='')hym_reward_clear_session();
        else{
            try{hym_reward_claim_guest($guestCandidate);hym_reward_clear_session();}
            catch(Throwable $error){
                // Keep only this authenticated account's pending source for a later retry.
                unset($_SESSION['coupon_code'],$_SESSION['last_game_coupon_code'],$_SESSION['last_game_coupon_amount'],$_SESSION['last_order_promotion'],$_SESSION['last_order_expected_game_chances'],$_SESSION['last_order_eligible_drinks']);$_SESSION['promotion_mode']='none';
                $_SESSION['game_wallet_claim_member']=$id;error_log('member_rewards: claim retry pending');
            }
        }
    }
}
function hym_rate_limit(string $scope,string $subject,int $limit,int $seconds): void {
    $pdo=db();$bucket=hash('sha256',$scope.'|'.$subject);$now=time();
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare('INSERT IGNORE INTO member_rate_limits(bucket,attempts,expires_at) VALUES(?,0,?)');$st->execute([$bucket,$now+$seconds]);
        $st=$pdo->prepare('SELECT attempts,expires_at FROM member_rate_limits WHERE bucket=? FOR UPDATE');$st->execute([$bucket]);$row=$st->fetch();
        $count=(int)$row['expires_at']<=$now?0:(int)$row['attempts'];
        $expiry=(int)$row['expires_at']<=$now?$now+$seconds:(int)$row['expires_at'];
        if($count>=$limit){if($own)$pdo->commit();hym_fail('member_rate_limited');}
        $st=$pdo->prepare('UPDATE member_rate_limits SET attempts=?,expires_at=? WHERE bucket=?');$st->execute([$count+1,$expiry,$bucket]);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function hym_client_ip(): string { return substr(client_ip(),0,64); }
function hym_register(string $phone,string $password,string $confirm,string $displayName='',string $address=''): array {
    hym_rate_limit('register-ip',hym_client_ip(),6,3600);
    $phone=hym_normalize_phone($phone);
    if(strlen($password)<8)hym_fail('member_password_short');
    if(strlen($password)>72)hym_fail('member_password_long');
    if(!hash_equals($password,$confirm))hym_fail('member_password_mismatch');
    $name=hym_text($displayName,100);$address=hym_text($address,255);
    $hash=password_hash($password,PASSWORD_DEFAULT);
    if($hash===false)hym_fail('member_save_failed');
    try{
        $st=db()->prepare('INSERT INTO members(phone,password_hash,display_name,delivery_address,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $st->execute([$phone,$hash,$name,$address]);$id=(int)db()->lastInsertId();
    }catch(PDOException $e){if($e->getCode()==='23000')hym_fail('member_phone_registered');hym_fail('member_save_failed');}
    hym_session_login($id);return hym_member_row($id);
}
function hym_login(string $phone,string $password): array {
    hym_rate_limit('login-ip',hym_client_ip(),30,900);
    try{$normalized=hym_normalize_phone($phone);}catch(HymMemberError $e){$normalized='';}
    hym_rate_limit('login-phone',$normalized!==''?$normalized:substr($phone,0,80),10,900);
    $st=db()->prepare('SELECT id,password_hash FROM members WHERE phone=?');$st->execute([$normalized]);$row=$st->fetch();
    // The dummy hash gives an unknown or Zalo-only (empty hash) account the same password verification work.
    $hash=$row?(string)$row['password_hash']:'';
    $passwordless=$hash==='';
    if($passwordless)$hash='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid=strlen($password)<=72&&password_verify($password,$hash)&&!$passwordless;
    if(!$row||!$valid)hym_fail('member_login_failed');
    if(password_needs_rehash($hash,PASSWORD_DEFAULT)){
        $new=password_hash($password,PASSWORD_DEFAULT);$update=db()->prepare('UPDATE members SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?');$update->execute([$new,$row['id']]);
    }
    hym_session_login((int)$row['id']);return hym_member_row((int)$row['id']);
}
function hym_logout(): void {
    unset($_SESSION['customer_payment_orders'],$_SESSION['last_order_no'],$_SESSION['checkout_drafts'],$_SESSION['checkout_requests'],$_SESSION['checkout_errors'],$_SESSION['transfer_payment_flash']);
    if(function_exists('hym_reward_clear_session'))hym_reward_clear_session();
    unset($_SESSION['member_id'],$_SESSION['member_flash']);
    if(session_status()===PHP_SESSION_ACTIVE&&!session_regenerate_id(true))hym_fail('member_save_failed');
}
function hym_save_profile(int $id,string $name,string $address): void {
    if(!hym_can_access($id))hym_fail('member_login_failed');
    $name=hym_text($name,100);$address=hym_text($address,255);
    $st=db()->prepare('UPDATE members SET display_name=?,delivery_address=?,updated_at=UTC_TIMESTAMP() WHERE id=?');$st->execute([$name,$address,$id]);
}
function hym_verify_member_phone(int $id,int $adminId): void {
    if(!hym_staff($adminId))hym_fail('member_login_failed');
    if(!hym_member_row($id))hym_fail('member_invalid_profile');
    $st=db()->prepare('UPDATE members SET phone_verified_at=COALESCE(phone_verified_at,UTC_TIMESTAMP()),phone_verified_by=COALESCE(phone_verified_by,?),updated_at=UTC_TIMESTAMP() WHERE id=?');
    $st->execute([$adminId,$id]);
}
function hym_csrf(): void {
    $posted=$_POST['csrf']??null;
    if(!is_string($posted)||empty($_SESSION['_csrf'])||!hash_equals((string)$_SESSION['_csrf'],$posted))hym_fail('member_form_expired');
}
