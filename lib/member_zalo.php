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

/**
 * Đăng nhập thử cho môi trường phát triển (member/local-login.php).
 * Chỉ bật khi cả 3 điều kiện cùng đúng: truy cập từ chính máy này (127.0.0.1 / ::1),
 * host là localhost hoặc 127.0.0.1, và không đi qua proxy / ngrok (không có X-Forwarded-*).
 * Trên shoptest hay production các điều kiện này không bao giờ thỏa nên nút tự ẩn.
 */
function hym_local_login_allowed(): bool {
    $addr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($addr, ['127.0.0.1', '::1'], true)) return false;

    $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if (!in_array($host, ['localhost', '127.0.0.1'], true)) return false;

    foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED'] as $header) {
        if (!empty($_SERVER[$header])) return false;
    }

    return true;
}
