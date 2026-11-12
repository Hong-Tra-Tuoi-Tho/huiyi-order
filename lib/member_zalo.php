<?php
/** Zalo OAuth v4 (PKCE) sign-in. A Zalo identity is linked to one local member account. */
function hym_zalo_enabled(): bool {
    return defined('ZALO_APP_ID')&&defined('ZALO_APP_SECRET')&&ZALO_APP_ID!==''&&ZALO_APP_SECRET!=='';
}
function hym_zalo_redirect_uri(): string {
    return defined('ZALO_REDIRECT_URI')&&ZALO_REDIRECT_URI!==''?ZALO_REDIRECT_URI:base_url('member/zalo.php');
}
function hym_zalo_schema(): void {
    static $done=false;
    if($done)return;
    db()->exec('CREATE TABLE IF NOT EXISTS member_zalo_links (
 member_id BIGINT UNSIGNED PRIMARY KEY,
 zalo_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 zalo_name VARCHAR(100) NOT NULL DEFAULT \'\',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_member_zalo_id(zalo_id),
 CONSTRAINT fk_member_zalo_link FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS member_login_tokens (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 member_id BIGINT UNSIGNED NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL,
 KEY idx_member_login_token_expiry(expires_at),
 CONSTRAINT fk_member_login_token FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done=true;
}
function hym_zalo_b64url(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }
function hym_zalo_authorize_url(string $returnOrder=''): string {
    if(!hym_zalo_enabled())hym_fail('member_zalo_unavailable');
    $verifier=hym_zalo_b64url(random_bytes(32));$state=bin2hex(random_bytes(16));
    $_SESSION['zalo_oauth']=['state'=>$state,'verifier'=>$verifier,'return_order'=>$returnOrder,'created_at'=>time()];
    return 'https://oauth.zaloapp.com/v4/permission?'.http_build_query([
        'app_id'=>ZALO_APP_ID,'redirect_uri'=>hym_zalo_redirect_uri(),
        'code_challenge'=>hym_zalo_b64url(hash('sha256',$verifier,true)),'state'=>$state],'','&',PHP_QUERY_RFC3986);
}
function hym_zalo_http(string $url,array $headers,?array $post=null): array {
    if(!function_exists('curl_init'))hym_fail('member_zalo_failed');
    // The handle is released when $curl goes out of scope (curl_close is a no-op since PHP 8).
    $curl=curl_init($url);if($curl===false)hym_fail('member_zalo_failed');
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers)];
    if($post!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($post,'','&');}
    curl_setopt_array($curl,$options);
    $body=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if(!is_string($body)||$status!==200){
        // Only the path is logged; the query and headers carry tokens.
        error_log('zalo_http '.parse_url($url,PHP_URL_PATH).' status='.$status.' curl='.curl_error($curl).' body='.substr((string)$body,0,500));
        hym_fail('member_zalo_failed');
    }
    try{$result=json_decode($body,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $e){hym_fail('member_zalo_failed');}
    if(!is_array($result))hym_fail('member_zalo_failed');return $result;
}
/** Validates the callback and returns ['id'=>..., 'name'=>..., 'return_order'=>...]. */
function hym_zalo_callback(array $query): array {
    $pending=$_SESSION['zalo_oauth']??null;unset($_SESSION['zalo_oauth']);
    $state=$query['state']??'';$code=$query['code']??'';
    if(!is_array($pending)||!is_string($state)||!hash_equals((string)$pending['state'],$state)||(int)$pending['created_at']<time()-600)hym_fail('member_form_expired');
    if(!is_string($code)||$code===''||strlen($code)>2048)hym_fail('member_zalo_cancelled');
    hym_rate_limit('zalo-ip',hym_client_ip(),30,900);
    $token=hym_zalo_http('https://oauth.zaloapp.com/v4/access_token',['secret_key: '.ZALO_APP_SECRET,'Content-Type: application/x-www-form-urlencoded'],
        ['app_id'=>ZALO_APP_ID,'code'=>$code,'grant_type'=>'authorization_code','code_verifier'=>(string)$pending['verifier']]);
    $access=$token['access_token']??'';
    if(!is_string($access)||$access==='')hym_fail('member_zalo_failed');
    $me=hym_zalo_http('https://graph.zalo.me/v2.0/me?fields=id,name',['access_token: '.$access]);
    $id=(string)($me['id']??'');
    if(!preg_match('/^[0-9]{1,64}$/D',$id))hym_fail('member_zalo_failed');
    $name=is_string($me['name']??null)?$me['name']:'';
    try{$name=hym_text($name,100);}catch(HymMemberError $e){$name='';}
    return ['id'=>$id,'name'=>$name,'return_order'=>(string)$pending['return_order']];
}
function hym_zalo_member_id(string $zaloId): int {
    hym_zalo_schema();
    $st=db()->prepare('SELECT member_id FROM member_zalo_links WHERE zalo_id=?');$st->execute([$zaloId]);
    return (int)$st->fetchColumn();
}
function hym_zalo_link(int $memberId,string $zaloId,string $name): void {
    hym_zalo_schema();
    try{
        $st=db()->prepare('INSERT INTO member_zalo_links(member_id,zalo_id,zalo_name,created_at) VALUES(?,?,?,UTC_TIMESTAMP())');
        $st->execute([$memberId,$zaloId,$name]);
    }catch(PDOException $e){if($e->getCode()==='23000')hym_fail('member_zalo_already_linked');throw $e;}
}
/** Links a Zalo identity stored before the customer finished signing in or registering. */
function hym_zalo_link_pending(int $memberId): bool {
    $pending=$_SESSION['zalo_pending']??null;unset($_SESSION['zalo_pending']);
    if(!is_array($pending)||(int)($pending['created_at']??0)<time()-1800)return false;
    try{hym_zalo_link($memberId,(string)$pending['id'],(string)$pending['name']);return true;}
    catch(Throwable $e){return false;}
}
/**
 * Đăng ký lần đầu sau khi đăng nhập Zalo OAuth: khách chỉ nhập số điện thoại và họ tên.
 * Tài khoản không có mật khẩu (password_hash rỗng), đăng nhập về sau luôn qua Zalo.
 * Số điện thoại đã thuộc tài khoản khác thì từ chối, vì số này chưa được xác thực.
 */
function hym_zalo_register(string $phone,string $displayName): int {
    $pending=hym_zalo_pending();
    if(!$pending)hym_fail('member_form_expired');
    hym_rate_limit('zalo-register-ip',hym_client_ip(),10,3600);
    $phone=hym_normalize_phone($phone);
    $name=hym_text($displayName,100);
    hym_zalo_schema();
    // Zalo này đã có tài khoản thì không tạo thêm.
    if(hym_zalo_member_id((string)$pending['id'])>0)hym_fail('member_zalo_already_linked');
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare('INSERT INTO members(phone,password_hash,display_name,delivery_address,created_at,updated_at) VALUES(?,\'\',?,\'\',UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $st->execute([$phone,$name]);
        $memberId=(int)$pdo->lastInsertId();
        $st=$pdo->prepare('INSERT INTO member_zalo_links(member_id,zalo_id,zalo_name,created_at) VALUES(?,?,?,UTC_TIMESTAMP())');
        $st->execute([$memberId,(string)$pending['id'],(string)$pending['name']]);
        $pdo->commit();
    }catch(PDOException $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e->getCode()==='23000')hym_fail('member_phone_registered');
        throw $e;
    }
    unset($_SESSION['zalo_pending']);
    hym_session_login($memberId);
    return $memberId;
}
function hym_zalo_pending(): ?array {
    $pending=$_SESSION['zalo_pending']??null;
    return is_array($pending)&&(int)($pending['created_at']??0)>=time()-1800?$pending:null;
}
/* Zalo Mini App: the Mini App sends its user access token and getPhoneNumber() token; Zalo returns the verified number. */
function hym_zalo_miniapp_secret(): string {
    $secret=defined('ZALO_MINIAPP_SECRET')&&ZALO_MINIAPP_SECRET!==''?ZALO_MINIAPP_SECRET:(defined('ZALO_APP_SECRET')?ZALO_APP_SECRET:'');
    if($secret==='')hym_fail('member_zalo_unavailable');
    return $secret;
}
/** Mini App ID from mini.zalo.me; empty = the website does not link to the Mini App. */
function hym_zalo_miniapp_id(): string {
    return defined('ZALO_MINIAPP_ID') ? (string)ZALO_MINIAPP_ID : '';
}
/** Opens the live Mini App, or the Testing version set in ZALO_MINIAPP_TEST_VERSION (admin/tester only). */
function hym_zalo_miniapp_url(): string {
    $url = 'https://zalo.me/s/' . rawurlencode(hym_zalo_miniapp_id()) . '/';
    $testVersion = defined('ZALO_MINIAPP_TEST_VERSION') ? (string)ZALO_MINIAPP_TEST_VERSION : '';
    if ($testVersion !== '') $url .= '?env=TESTING&version=' . rawurlencode($testVersion);
    return $url;
}
/**
 * Chế độ test đăng nhập Zalo Mini App trên web live, không ảnh hưởng khách thường.
 * Vào member/index.php?zalotest=<ZALO_TEST_KEY> để bật cho session này, ?zalotest=off để tắt.
 * Chưa khai báo ZALO_TEST_KEY thì chế độ này không bao giờ bật.
 */
function hym_zalo_test_mode(): bool {
    $key = defined('ZALO_TEST_KEY') ? (string)ZALO_TEST_KEY : '';
    if ($key === '' || hym_zalo_miniapp_id() === '') return false;
    $param = $_GET['zalotest'] ?? null;
    if (is_string($param) && $param !== '') {
        if (hash_equals($key, $param)) {
            $_SESSION['zalo_test'] = true;
        } elseif ($param === 'off') {
            unset($_SESSION['zalo_test']);
        }
    }
    return ($_SESSION['zalo_test'] ?? false) === true;
}
/** Customers sign in only through the Mini App: password sign-in and registration are closed. */
function hym_zalo_only(): bool {
    return defined('MEMBER_ZALO_ONLY') && MEMBER_ZALO_ONLY === true && hym_zalo_miniapp_id() !== '';
}
function hym_zalo_graph(string $url,array $headers): array {
    $result=hym_zalo_http($url,$headers);
    if((int)($result['error']??0)!==0){
        error_log('zalo_graph '.parse_url($url,PHP_URL_PATH).' error='.(int)$result['error'].' message='.(string)($result['message']??''));
        hym_fail('member_zalo_failed');
    }
    return $result;
}
/** Returns the member id for a Mini App user, creating a phone-verified account on first use. */
function hym_zalo_miniapp_member(string $accessToken,string $phoneToken): int {
    if($accessToken===''||$phoneToken===''||strlen($accessToken)>4096||strlen($phoneToken)>4096)hym_fail('member_zalo_failed');
    hym_rate_limit('zalo-miniapp-ip',hym_client_ip(),30,900);
    $secret=hym_zalo_miniapp_secret();
    $info=hym_zalo_graph('https://graph.zalo.me/v2.0/me/info',['access_token: '.$accessToken,'code: '.$phoneToken,'secret_key: '.$secret]);
    $phone=hym_normalize_phone((string)($info['data']['number']??''));
    $me=hym_zalo_graph('https://graph.zalo.me/v2.0/me?fields=id,name',['access_token: '.$accessToken]);
    $zaloId=(string)($me['id']??'');
    if(!preg_match('/^[0-9]{1,64}$/D',$zaloId))hym_fail('member_zalo_failed');
    try{$name=hym_text(is_string($me['name']??null)?$me['name']:'',100);}catch(HymMemberError $e){$name='';}
    hym_zalo_schema();
    $memberId=hym_zalo_member_id($zaloId);
    if($memberId<1){
        $st=db()->prepare('SELECT id FROM members WHERE phone=?');$st->execute([$phone]);$memberId=(int)$st->fetchColumn();
    }
    if($memberId<1){
        // An empty hash marks a Zalo-only account: password_verify() never accepts it.
        try{
            $st=db()->prepare('INSERT INTO members(phone,password_hash,display_name,delivery_address,created_at,updated_at) VALUES(?,\'\',?,\'\',UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $st->execute([$phone,$name]);$memberId=(int)db()->lastInsertId();
        }catch(PDOException $e){
            if($e->getCode()!=='23000')throw $e;
            $st=db()->prepare('SELECT id FROM members WHERE phone=?');$st->execute([$phone]);$memberId=(int)$st->fetchColumn();
        }
    }
    if($memberId<1)hym_fail('member_zalo_failed');
    // Zalo confirmed this number belongs to the signed-in Zalo user.
    db()->prepare('UPDATE members SET phone_verified_at=COALESCE(phone_verified_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=? AND phone=?')->execute([$memberId,$phone]);
    try{hym_zalo_link($memberId,$zaloId,$name);}catch(Throwable $e){}
    return $memberId;
}
function hym_login_token_issue(int $memberId): string {
    hym_zalo_schema();
    db()->prepare('DELETE FROM member_login_tokens WHERE expires_at<?')->execute([time()]);
    $token=hym_zalo_b64url(random_bytes(32));
    db()->prepare('INSERT INTO member_login_tokens(token_hash,member_id,expires_at) VALUES(?,?,?)')->execute([hash('sha256',$token),$memberId,time()+120]);
    return $token;
}
function hym_login_token_consume(string $token): int {
    if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))hym_fail('member_form_expired');
    hym_zalo_schema();
    $hash=hash('sha256',$token);
    $st=db()->prepare('SELECT member_id FROM member_login_tokens WHERE token_hash=? AND expires_at>=?');$st->execute([$hash,time()]);
    $memberId=(int)$st->fetchColumn();
    $delete=db()->prepare('DELETE FROM member_login_tokens WHERE token_hash=?');$delete->execute([$hash]);
    // Only the request that removed the row may use it.
    if($memberId<1||$delete->rowCount()!==1)hym_fail('member_form_expired');
    return $memberId;
}

/*
 * Đăng nhập máy tính bằng QR (giống Zalo Web).
 * 1. Máy tính tạo mã riêng, lưu trong session của máy tính và gắn vào link Mini App trong QR.
 * 2. Điện thoại quét QR, khách bấm xác nhận trong Mini App → server gắn member_id vào mã.
 * 3. Máy tính hỏi server định kỳ (member/qr-status.php); thấy mã đã xác nhận thì đăng nhập session của máy tính.
 * Chỉ lưu hash của mã trong DB; mã gốc chỉ có trong QR và session của máy tính.
 */
const HYM_QR_LOGIN_TTL = 180;

function hym_qr_login_schema(): void {
    static $done = false;
    if ($done) return;

    db()->exec('CREATE TABLE IF NOT EXISTS member_qr_logins (
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 member_id BIGINT UNSIGNED NULL,
 expires_at BIGINT UNSIGNED NOT NULL,
 KEY idx_member_qr_login_expiry(expires_at),
 CONSTRAINT fk_member_qr_login FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $done = true;
}

/** Mã QR của session máy tính hiện tại; tạo mã mới khi chưa có hoặc sắp hết hạn. */
function hym_qr_login_code(bool $renew = false): string {
    $current = $_SESSION['qr_login'] ?? null;
    $stillValid = is_array($current)
        && is_string($current['code'] ?? null)
        && (int)($current['expires_at'] ?? 0) > time() + 20;
    if (!$renew && $stillValid) return $current['code'];

    hym_qr_login_schema();
    db()->prepare('DELETE FROM member_qr_logins WHERE expires_at<?')->execute([time()]);
    if (is_array($current) && is_string($current['code'] ?? null)) {
        db()->prepare('DELETE FROM member_qr_logins WHERE code_hash=?')->execute([hash('sha256', $current['code'])]);
    }

    $code = hym_zalo_b64url(random_bytes(32));
    $expiresAt = time() + HYM_QR_LOGIN_TTL;
    db()->prepare('INSERT INTO member_qr_logins(code_hash,member_id,expires_at) VALUES(?,NULL,?)')
        ->execute([hash('sha256', $code), $expiresAt]);

    $_SESSION['qr_login'] = ['code' => $code, 'expires_at' => $expiresAt];
    return $code;
}

/** Link Mini App kèm mã QR của máy tính. */
function hym_zalo_miniapp_qr_url(string $code): string {
    $url = hym_zalo_miniapp_url();
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'qr=' . rawurlencode($code);
}

/** Điện thoại (Mini App) xác nhận đăng nhập cho máy tính đang giữ mã này. */
function hym_qr_login_approve(string $code, int $memberId): void {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $code)) hym_fail('member_qr_expired');
    if ($memberId < 1) hym_fail('member_zalo_failed');

    hym_qr_login_schema();
    $st = db()->prepare('UPDATE member_qr_logins SET member_id=? WHERE code_hash=? AND member_id IS NULL AND expires_at>=?');
    $st->execute([$memberId, hash('sha256', $code), time()]);

    // Mã đã dùng, hết hạn hoặc không tồn tại.
    if ($st->rowCount() !== 1) hym_fail('member_qr_expired');
}

/**
 * Máy tính hỏi trạng thái mã QR của mình.
 * Trả về 'pending' (chưa quét), 'expired' (cần mã mới) hoặc 'ok' (đã đăng nhập session này).
 */
function hym_qr_login_poll(): string {
    $current = $_SESSION['qr_login'] ?? null;
    if (!is_array($current) || !is_string($current['code'] ?? null)) return 'expired';

    hym_qr_login_schema();
    $hash = hash('sha256', $current['code']);
    $st = db()->prepare('SELECT member_id,expires_at FROM member_qr_logins WHERE code_hash=?');
    $st->execute([$hash]);
    $row = $st->fetch();

    if (!$row) return 'expired';

    $memberId = (int)($row['member_id'] ?? 0);
    if ($memberId < 1) {
        return (int)$row['expires_at'] < time() ? 'expired' : 'pending';
    }

    $delete = db()->prepare('DELETE FROM member_qr_logins WHERE code_hash=?');
    $delete->execute([$hash]);
    // Chỉ request xoá được dòng mới được đăng nhập (chặn 2 request song song).
    if ($delete->rowCount() !== 1) return 'expired';

    unset($_SESSION['qr_login']);
    hym_session_login($memberId);
    $_SESSION['member_flash'] = ['type' => 'success', 'key' => 'member_logged_in'];
    return 'ok';
}
