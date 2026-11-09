<?php
/**
 * v1.7.0 schema and feature helpers.
 * Kept separate from the legacy migration block so existing installations can
 * be upgraded safely without rebuilding their database.
 */
function ensure_v170_schema(): void {
    static $done=false;
    if($done) return;
    $done=true;

    $changes=[
        ['order_items','product_id','INT UNSIGNED NULL AFTER order_id'],
        ['order_items','standalone_topping_id','INT UNSIGNED NULL AFTER product_id'],
        ['orders','game_wallet_token','VARCHAR(64) NULL AFTER status'],
        ['orders','game_award_processed','TINYINT(1) NOT NULL DEFAULT 0 AFTER game_wallet_token'],
        ['orders','game_chances_awarded','INT NOT NULL DEFAULT 0 AFTER game_award_processed'],
    ];
    foreach($changes as [$table,$col,$def]){
        if(column_exists($table,$col)) continue;
        try { db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def"); } catch(Throwable $e) {}
    }

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS product_recommended_toppings (
            product_id INT UNSIGNED NOT NULL,
            topping_id INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(product_id,topping_id),
            KEY idx_prt_topping(topping_id),
            CONSTRAINT fk_prt_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_prt_topping FOREIGN KEY(topping_id) REFERENCES toppings(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) {}

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS game_wallets (
            wallet_token VARCHAR(64) PRIMARY KEY,
            available_chances INT NOT NULL DEFAULT 0,
            total_earned INT NOT NULL DEFAULT 0,
            total_played INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS game_plays (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wallet_token VARCHAR(64) NOT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_game_wallet(wallet_token),
            CONSTRAINT fk_game_wallet FOREIGN KEY(wallet_token) REFERENCES game_wallets(wallet_token) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS customer_coupons (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wallet_token VARCHAR(64) NOT NULL,
            code VARCHAR(80) NOT NULL UNIQUE,
            coupon_type VARCHAR(30) NOT NULL DEFAULT 'fixed_drink',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            used_order_id BIGINT UNSIGNED NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coupon_wallet(wallet_token),
            KEY idx_coupon_used(used_order_id),
            CONSTRAINT fk_coupon_wallet FOREIGN KEY(wallet_token) REFERENCES game_wallets(wallet_token) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) {}

    try {
        $defaults=[
            'show_product_sales_count'=>'1',
            'order_alert_enabled'=>'1',
            'order_alert_sound'=>'',
            'order_alert_duration'=>'20',
            'minigame_enabled'=>'1',
            'game_cups_per_chance'=>'10',
            'game_reward_amount'=>'0',
        ];
        $st=db()->prepare('INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)');
        foreach($defaults as $k=>$v) $st->execute([$k,$v]);
    } catch(Throwable $e) {}

    // Best-effort backfill for orders created before product_id snapshots existed.
    // It only matches exact historical names, so ambiguous names are left untouched.
    try {
        db()->exec("UPDATE order_items oi
            JOIN products p ON oi.product_id IS NULL
              AND (oi.product_name=p.name OR oi.product_name=p.name_vi OR oi.product_name=p.name_en OR oi.product_name=p.name_zh)
            SET oi.product_id=p.id
            WHERE oi.standalone_topping_id IS NULL");
    } catch(Throwable $e) {}
}

function show_product_sales_count(): bool { return setting('show_product_sales_count','1')==='1'; }
function minigame_enabled(): bool { return setting('minigame_enabled','1')==='1'; }
function game_cups_per_chance(): int { return 10; }
function game_chances_for_drink_qty(int $qty): int { return intdiv(max(0,$qty),game_cups_per_chance()); }
/** Honor the saved quote; unsnapshotted legacy orders keep their original one-chance limit. */
function game_saved_chance_count(int $qty,?int $expected): int {
    $maximum=game_chances_for_drink_qty($qty);
    if($expected===null)return min(1,$maximum);
    return $expected>0&&$expected<=$maximum?$expected:0;
}
function game_reward_amount(): float { return max(0,(float)setting('game_reward_amount','0')); }

function product_sold_qty(int $productId): int {
    if($productId<=0) return 0;
    try {
        $st=db()->prepare("SELECT COALESCE(SUM(oi.qty),0)
            FROM order_items oi
            WHERE oi.product_id=?");
        $st->execute([$productId]);
        return (int)$st->fetchColumn();
    } catch(Throwable $e){ return 0; }
}

function customer_game_wallet_token(bool $create=false): string {
    $memberId=hym_reward_current_member_id();
    if($memberId>0){
        $candidate=hym_reward_guest_session_token();$bound=(int)($_SESSION['game_wallet_claim_member']??$memberId);
        if($candidate!==''&&$bound===$memberId&&!db()->inTransaction()){
            try{hym_reward_claim_guest($candidate);unset($_SESSION['game_wallet_token'],$_SESSION['game_wallet_scope'],$_SESSION['game_wallet_claim_member']);}
            catch(Throwable $error){$_SESSION['game_wallet_claim_member']=$memberId;error_log('member_rewards: claim retry pending');}
        }elseif($bound!==$memberId){unset($_SESSION['game_wallet_token'],$_SESSION['game_wallet_scope'],$_SESSION['game_wallet_claim_member']);}
        // The token identifies a database wallet; it is never stored as a device cookie.
        return hym_reward_member_wallet($memberId,true);
    }
    $token=(string)($_SESSION['game_wallet_token']??'');
    if(!preg_match('/^[a-f0-9]{32}$/',$token)) $token='';
    if($token===''){
        $cookie=(string)($_COOKIE['drink_game_wallet']??'');
        if(preg_match('/^[a-f0-9]{32}$/',$cookie)) $token=$cookie;
    }
    if($token!==''){
        $row=hym_reward_one('SELECT member_id,claimed_member_id,claimed_wallet_token FROM game_wallets WHERE wallet_token=?',[$token]);
        if($row&&($row['member_id']!==null||$row['claimed_member_id']!==null||$row['claimed_wallet_token']!==null)){$token='';$create=true;}
    }
    if($token==='' && $create){
        $token=bin2hex(random_bytes(16));
        hym_reward_exec('INSERT INTO game_wallets(wallet_token) VALUES(?)',[$token]);
        $_SESSION['game_wallet_token']=$token;
        if(!headers_sent())setcookie('drink_game_wallet',$token,[
            'expires'=>time()+31536000*2,
            'path'=>'/',
            'samesite'=>'Lax',
            'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
            'httponly'=>true,
        ]);
        $_COOKIE['drink_game_wallet']=$token;
    } elseif($token!=='') {
        $_SESSION['game_wallet_token']=$token;
    }
    if($token!=='')$_SESSION['game_wallet_scope']='guest';
    return $token;
}

function game_wallet_balance(?string $token=null): int {
    $token=$token??customer_game_wallet_token(false);
    if($token===''||!hym_reward_wallet_accessible($token)) return 0;
    try {
        $st=db()->prepare('SELECT available_chances FROM game_wallets WHERE wallet_token=?');
        $st->execute([$token]);
        return max(0,(int)($st->fetchColumn()?:0));
    } catch(Throwable $e){ return 0; }
}

function game_wallet_summary(?string $token=null): array {
    $token=$token??customer_game_wallet_token(false);
    $empty=['wallet_token'=>hym_reward_current_member_id()>0?'':$token,'available_chances'=>0,'total_earned'=>0,'total_played'=>0];
    if($token===''||!hym_reward_wallet_accessible($token)){$empty['wallet_token']='';return $empty;}
    try {
        $st=db()->prepare('SELECT available_chances,total_earned,total_played FROM game_wallets WHERE wallet_token=?');
        $st->execute([$token]);
        return array_merge($empty,$st->fetch()?:[]);
    } catch(Throwable $e){ return $empty; }
}

function order_eligible_drink_qty(int $orderId): int {
    try {
        $st=db()->prepare('SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id=? AND product_id IS NOT NULL AND standalone_topping_id IS NULL AND game_reward_code IS NULL');
        $st->execute([$orderId]);
        return max(0,(int)$st->fetchColumn());
    } catch(Throwable $e){ return 0; }
}

/** Award only once, only after the order reaches done. */
function award_game_chances_for_order(int $orderId): int {
    if($orderId<=0) return 0;
    $pdo=db();
    $started=false;$savepoint='';
    try {
        if(!$pdo->inTransaction()){ $pdo->beginTransaction(); $started=true; }
        else {$savepoint='reward_award_'.bin2hex(random_bytes(6));$pdo->exec('SAVEPOINT '.$savepoint);}
        $st=$pdo->prepare('SELECT id,status,member_id,coupon_code,discount_amount,promotion_mode,game_chances_expected,game_wallet_token,game_award_processed,game_chances_awarded FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$orderId]);
        $order=$st->fetch();
        if(!$order || $order['status']!=='done' || !empty($order['game_award_processed'])){
            if($savepoint!=='')$pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            if($started) $pdo->commit();
            return (int)($order['game_chances_awarded']??0);
        }
        $qty=order_eligible_drink_qty($orderId);
        // Each full ten drinks earns a chance in new quotes. Honor saved counts
        // on existing orders; remainder cups never carry over between orders.
        $mode=(string)$order['promotion_mode'];
        $eligible=$mode==='draw'||($mode==='legacy'&&minigame_enabled());
        // A coupon order never also earns a draw, including pending legacy orders.
        $eligible=$eligible&&trim((string)($order['coupon_code']??''))===''&&(float)$order['discount_amount']<=0;
        $chances=$eligible?game_saved_chance_count($qty,$order['game_chances_expected']===null?null:(int)$order['game_chances_expected']):0;
        $token=$chances>0?hym_reward_award_target($order):'';
        if($chances>0 && preg_match('/^[a-f0-9]{32}$/',$token)){
            $w=$pdo->prepare('INSERT INTO game_wallets(wallet_token,available_chances,total_earned,total_played) VALUES(?,?,?,0) ON DUPLICATE KEY UPDATE available_chances=available_chances+VALUES(available_chances),total_earned=total_earned+VALUES(total_earned)');
            $w->execute([$token,$chances,$chances]);
        } else {
            $chances=0;
        }
        $u=$pdo->prepare('UPDATE orders SET game_award_processed=1,game_chances_awarded=? WHERE id=?');
        $u->execute([$chances,$orderId]);
        if($savepoint!=='')$pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        if($started) $pdo->commit();
        return $chances;
    } catch(Throwable $e){
        if($started && $pdo->inTransaction()) $pdo->rollBack();
        elseif($savepoint!==''&&$pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        return 0;
    }
}

function available_game_coupons(?string $token=null): array {
    $token=$token??customer_game_wallet_token(false);
    if($token===''||!hym_reward_wallet_accessible($token)) return [];
    try{
        $sql=function_exists('hgr_coupon_record')?"SELECT c.id,c.code,c.coupon_type,c.amount,c.used_order_id,c.used_at,c.created_at,d.reward_json,d.expires_at FROM customer_coupons c LEFT JOIN game_coupon_details d ON d.coupon_id=c.id WHERE c.wallet_token=? AND c.used_order_id IS NULL AND (c.coupon_type<>'game_reward' OR d.expires_at>UTC_TIMESTAMP()) ORDER BY c.id DESC":"SELECT id,code,coupon_type,amount,used_order_id,used_at,created_at FROM customer_coupons WHERE wallet_token=? AND used_order_id IS NULL ORDER BY id DESC";
        $st=db()->prepare($sql);
        $st->execute([$token]);
        $rows=$st->fetchAll();foreach($rows as &$row)if($row['coupon_type']==='game_reward')$row['reward']=json_decode($row['reward_json']??'',true);unset($row);return $rows;
    }catch(Throwable $e){ return []; }
}

/** Complete one play and optionally issue a one-time fixed drink discount coupon. */
function complete_game_play(string $token): array {
    $result=['ok'=>false,'coupon_code'=>'','coupon_amount'=>0.0];
    if(!minigame_enabled() || !hym_reward_token_valid($token)||!hym_reward_wallet_accessible($token)) return $result;
    $pdo=db();
    if($pdo->inTransaction())return $result;
    try{
        $pdo->beginTransaction();
        if(!hym_reward_wallet_accessible($token,true)){$pdo->rollBack();return $result;}
        $st=$pdo->prepare('SELECT available_chances FROM game_wallets WHERE wallet_token=? FOR UPDATE');
        $st->execute([$token]);
        $balance=(int)($st->fetchColumn()?:0);
        if($balance<1){ $pdo->rollBack(); return $result; }
        $pdo->prepare('UPDATE game_wallets SET available_chances=available_chances-1,total_played=total_played+1 WHERE wallet_token=?')->execute([$token]);
        $pdo->prepare('INSERT INTO game_plays(wallet_token) VALUES(?)')->execute([$token]);
        $amount=game_reward_amount();
        $code='';
        if($amount>0){
            do { $code='GAME-'.strtoupper(substr(bin2hex(random_bytes(6)),0,12)); $chk=$pdo->prepare('SELECT COUNT(*) FROM customer_coupons WHERE code=?');$chk->execute([$code]); } while((int)$chk->fetchColumn()>0);
            $pdo->prepare("INSERT INTO customer_coupons(wallet_token,code,coupon_type,amount) VALUES(?,?,'fixed_drink',?)")->execute([$token,$code,$amount]);
        }
        $pdo->commit();
        return ['ok'=>true,'coupon_code'=>$code,'coupon_amount'=>$amount];
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); return $result; }
}

function mark_customer_coupon_used(string $code,int $orderId): bool {
    $token=customer_game_wallet_token(false);
    if($token==='' || $code===''||$orderId<1) return false;
    $pdo=db();$own=!$pdo->inTransaction();
    try{
        if($own)$pdo->beginTransaction();
        if(!hym_reward_wallet_accessible($token,true)){if($own)$pdo->rollBack();return false;}
        $sql=function_exists('hgr_coupon_record')?"UPDATE customer_coupons c LEFT JOIN game_coupon_details d ON d.coupon_id=c.id SET c.used_order_id=?,c.used_at=NOW() WHERE c.code=? AND c.wallet_token=? AND c.used_order_id IS NULL AND (c.coupon_type<>'game_reward' OR d.expires_at>UTC_TIMESTAMP())":'UPDATE customer_coupons SET used_order_id=?,used_at=NOW() WHERE code=? AND wallet_token=? AND used_order_id IS NULL';
        $st=$pdo->prepare($sql);
        $st->execute([$orderId,strtoupper(trim($code)),$token]);
        $used=$st->rowCount()===1;if($own)$pdo->commit();return $used;
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();return false;}
}

function latest_order_id(): int {
    try { return (int)(db()->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn()?:0); }
    catch(Throwable $e){ return 0; }
}
