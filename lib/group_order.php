<?php
/** Shared carts and frozen group payments. Room locks serialize edits and checkout; client prices are never trusted. */
require_once __DIR__.'/group_i18n.php';
require_once __DIR__.'/group_payments.php';
require_once __DIR__.'/group_payment_audit.php';
require_once __DIR__.'/group_combo.php';
class GroupOrderError extends RuntimeException {}
function hyg_fail(string $key): never { throw new GroupOrderError($key); }
function hyg_schema(): void {
    static $done=false;
    if($done)return;
    $pdo=db();
    if($pdo->inTransaction())throw new RuntimeException('group_schema_inside_transaction');
    if(setting('group_schema_version','')!=='19.5'){
        // The advisory lock is for migrations only, never for normal requests.
        $lock=$pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(),':group-schema'),10)")->fetchColumn();
        if((int)$lock!==1)throw new RuntimeException('group_schema_busy');
        try{
            // A waiting request may have cached the previous marker before another request migrated.
            $version=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$version->execute(['group_schema_version']);
            if((string)$version->fetchColumn()!=='19.5'){
                $sql=file_get_contents(__DIR__.'/group_schema.sql');
                foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
                $changes=[['orders','group_room_id','BIGINT UNSIGNED NULL'],['orders','group_title','VARCHAR(160) NULL'],['order_items','group_member_name','VARCHAR(80) NULL'],['order_items','group_member_id','BIGINT UNSIGNED NULL'],
                    ['group_rooms','checkout_mode',"VARCHAR(16) NOT NULL DEFAULT 'together'"],['group_rooms','settlement_json','LONGTEXT NULL'],['group_rooms','payment_protocol_version','TINYINT UNSIGNED NOT NULL DEFAULT 1'],
                    ['group_members','payment_method',"VARCHAR(20) NOT NULL DEFAULT 'bank_transfer'"],['group_members','payment_status',"VARCHAR(16) NOT NULL DEFAULT 'pending'"],['group_members','payment_completed_at','DATETIME NULL'],
                    ['group_members','payment_requested_at','DATETIME NULL'],['group_members','payment_requested_amount','DECIMAL(12,2) NULL'],['group_members','payment_confirmed_at','DATETIME NULL'],
                    ['group_members','payment_confirmed_by_member_id','BIGINT UNSIGNED NULL'],['group_members','payment_confirmed_amount','DECIMAL(12,2) NULL'],['group_members','payment_invalidated_at','DATETIME NULL'],['group_members','payment_invalidation_reason',"VARCHAR(32) NOT NULL DEFAULT ''"],
                    ['orders','group_checkout_mode',"VARCHAR(16) NOT NULL DEFAULT 'together'"],['orders','group_payment_summary','LONGTEXT NULL']];
                foreach($changes as [$table,$col,$def])if(!column_exists($table,$col))$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                $paymentColumn=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='payment_method'")->fetchColumn();
                if($paymentColumn==="enum('transfer','cash')")$pdo->exec("ALTER TABLE orders MODIFY COLUMN payment_method ENUM('transfer','cash','split') NOT NULL");
                elseif(is_string($paymentColumn)&&str_starts_with($paymentColumn,'enum(')&&!str_contains($paymentColumn,"'split'"))throw new RuntimeException('group_payment_method_schema_incompatible');
                $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='uq_order_group_room'");$st->execute();
                if(!$st->fetchColumn())$pdo->exec('ALTER TABLE orders ADD UNIQUE KEY uq_order_group_room (group_room_id)');
                // Existing rows initially receive protocol 1; only unfinished rooms are upgraded.
                $pdo->exec('ALTER TABLE group_rooms ALTER COLUMN payment_protocol_version SET DEFAULT 2');
                $pdo->beginTransaction();
                try{
                    foreach(hyg_all('SELECT * FROM group_rooms WHERE payment_protocol_version<2 AND status<>\'submitted\' AND order_id IS NULL FOR UPDATE') as $room){
                        $settlement=json_decode((string)($room['settlement_json']??''),true);
                        foreach(hyg_all("SELECT * FROM group_members WHERE room_id=? AND payment_status='paid'",[$room['id']]) as $member){
                            $due=$settlement['payment']['members'][(int)$member['id']]['due']??null;
                            hyg_exec("UPDATE group_members SET payment_status='requested',payment_requested_at=COALESCE(payment_requested_at,payment_completed_at,UTC_TIMESTAMP()),payment_requested_amount=?,payment_confirmed_at=NULL,payment_confirmed_by_member_id=NULL,payment_confirmed_amount=NULL WHERE id=?",[$due,$member['id']]);
                        }
                        hyg_exec('UPDATE group_rooms SET payment_protocol_version=2 WHERE id=?',[$room['id']]);
                    }
                    set_setting('group_schema_version','19.5');$pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();unset($GLOBALS['__drink_order_setting_cache']['group_schema_version']);throw $e;}
            }else $GLOBALS['__drink_order_setting_cache']['group_schema_version']='19.5';
        }finally{$pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':group-schema'))");}
    }
    hyg_apply_four_hour_lifetime();
    hyg_payment_audit_schema();
    // Nhóm combo: cột group_rooms.order_type (lib/group_combo.php).
    hgc_schema();
    $done=true;
}

/**
 * v19.2 created every room with an absolute UTC expiry 24 hours after creation.
 * Rebase only unfinished rooms to creation + 4 hours by subtracting 20 hours.
 * Do not use created_at here: legacy created_at used the database session zone,
 * whereas expires_at was explicitly UTC. Submitted orders are never altered.
 * Marker and deadline changes commit together; repeated deployments are a no-op.
 */
function hyg_apply_four_hour_lifetime(): void {
    $key='group_lifetime_version';
    $version='19.2.1';
    if(setting($key,'')===$version)return;
    $pdo=db();
    if($pdo->inTransaction())throw new RuntimeException('group_lifetime_inside_transaction');
    $lock=$pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(),':group-lifetime'),10)")->fetchColumn();
    if((int)$lock!==1)throw new RuntimeException('group_lifetime_busy');
    try {
        // Re-read directly after acquiring the lock; setting() may hold an old value.
        $st=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $st->execute([$key]);
        if((string)$st->fetchColumn()===$version){
            $GLOBALS['__drink_order_setting_cache'][$key]=$version;
            return;
        }
        $pdo->beginTransaction();
        $pdo->exec("UPDATE group_rooms SET expires_at=DATE_SUB(expires_at,INTERVAL 20 HOUR),revision=revision+1 WHERE status IN ('open','locked') AND order_id IS NULL");
        set_setting($key,$version);
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        unset($GLOBALS['__drink_order_setting_cache'][$key]);
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(),':group-lifetime'))");
    }
}

function hyg_headers(): void {
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}
function hyg_device(bool $create=false): string {
    $token=(string)($_COOKIE['huiyi_group_device']??($_SESSION['huiyi_group_device']??''));
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))$token='';
    if($token===''&&$create){
        $token=bin2hex(random_bytes(32));
        $path=rtrim((string)parse_url(base_url(),PHP_URL_PATH),'/').'/';
        setcookie('huiyi_group_device',$token,['expires'=>time()+86400*90,'path'=>$path,'secure'=>parse_url(base_url(),PHP_URL_SCHEME)==='https','httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['huiyi_group_device']=$token;
    }
    if($token!=='')$_SESSION['huiyi_group_device']=$token;
    return $token===''?'':hash('sha256',$token);
}
function hyg_csrf(): void {
    $given=$_POST['csrf']??'';
    if(!is_string($given)||empty($_SESSION['_csrf'])||!hash_equals($_SESSION['_csrf'],$given))hyg_fail('expired_form');
}
function hyg_text($value,int $max): string {
    if(!is_string($value))hyg_fail('invalid_input');
    $value=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$value)??'');
    if(!preg_match('//u',$value)||preg_match_all('/./us',$value)>$max)hyg_fail('invalid_input');
    return $value;
}
function hyg_code($v): string { if(!is_string($v)||!preg_match('/^[a-f0-9]{32}$/D',$v))hyg_fail('not_found');return $v; }
function hyg_positive_int(mixed $value): int {
    if(!is_int($value)&&!is_string($value))hyg_fail('invalid_input');
    $number=filter_var($value,FILTER_VALIDATE_INT);if($number===false||$number<1)hyg_fail('invalid_input');return $number;
}
function hyg_one(string $sql,array $params=[]): ?array {$s=db()->prepare($sql);$s->execute($params);return $s->fetch()?:null;}
function hyg_all(string $sql,array $params=[]): array {$s=db()->prepare($sql);$s->execute($params);return $s->fetchAll();}
function hyg_exec(string $sql,array $params=[]): void {$s=db()->prepare($sql);$s->execute($params);}
function hyg_room(string $public,bool $lock=false): array {
    $r=hyg_one('SELECT * FROM group_rooms WHERE public_id=?'.($lock?' FOR UPDATE':''),[hyg_code($public)]);
    if(!$r)hyg_fail('not_found');return function_exists('htp_room_enrich')?htp_room_enrich($r):$r;
}
function hyg_member(array $r): array {
    $hash=hyg_device(); if($hash==='')hyg_fail('not_member');
    $m=hyg_one('SELECT * FROM group_members WHERE room_id=? AND device_hash=?',[$r['id'],$hash]);
    if(!$m){if(hyg_one('SELECT room_id FROM group_removed_members WHERE room_id=? AND device_hash=?',[$r['id'],$hash]))hyg_fail('member_removed');hyg_fail('not_member');}return $m;
}
function hyg_host(array $r): bool { $hash=hyg_device();return $hash!==''&&hash_equals($r['owner_hash'],$hash); }
function hyg_require_host(array $r): void {hyg_member($r);if(!hyg_host($r))hyg_fail('host_only');}
function hyg_open(array $r): void {
    if($r['status']!=='open')hyg_fail('not_open');
    if(strtotime($r['expires_at'].' UTC')<=time())hyg_fail('room_expired');
}
function hyg_touch(int $id): void {hyg_exec('UPDATE group_rooms SET revision=revision+1,updated_at=NOW() WHERE id=?',[$id]);}
function hyg_tx(callable $fn) {
    hyg_schema();$pdo=db();$pdo->beginTransaction();
    try{$v=$fn();$pdo->commit();return $v;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Request-local catalogue memoization: no user/cart data is cached across requests. */
function hyg_catalog_one(string $sql,array $params=[]): ?array {
    static $rows=[];$key=hash('sha256',$sql.json_encode($params));
    if(!array_key_exists($key,$rows))$rows[$key]=hyg_one($sql,$params);
    return $rows[$key];
}
function hyg_profile_options(string $kind,string $code): array {
    $r=hyg_catalog_one('SELECT * FROM option_profiles WHERE profile_type=? AND code=? AND active=1',[$kind,$code]);
    if(!$r)hyg_fail('item_unavailable');
    $raw=trim((string)($r['options_json_'.current_lang()]??''));
    $options=json_decode($raw!==''?$raw:(string)$r['options_json'],true);
    if(!is_array($options))hyg_fail('options_changed');return $options;
}
function hyg_profile_signature(string $kind,string $code): string {
    $r=hyg_catalog_one('SELECT * FROM option_profiles WHERE profile_type=? AND code=? AND active=1',[$kind,$code]);
    if(!$r)hyg_fail('item_unavailable');
    // Indices are meaningful only within the original template. Reject changed templates rather than reinterpret drinks.
    $parts=[];foreach(['options_json','options_json_vi','options_json_en','options_json_zh'] as $k)$parts[]=(string)($r[$k]??'');
    return hash('sha256',json_encode($parts));
}
function hyg_selection(array $row): array {
    $qty=(int)($row['qty']??0);if($qty<1||$qty>order_qty_max())hyg_fail('invalid_quantity');
    if(!empty($row['standalone_topping_id']))return ['kind'=>'topping','id'=>(int)$row['standalone_topping_id'],'qty'=>$qty];
    $p=hyg_catalog_one('SELECT * FROM products WHERE id=? AND active=1',[(int)($row['product_id']??0)]);
    if(!$p)hyg_fail('item_unavailable');
    return ['kind'=>'drink','id'=>(int)$p['id'],'qty'=>$qty,'cup_size'=>(string)($row['cup_size']??''),
        'ice_profile'=>(string)($row['ice_profile']??$p['ice_profile']),'ice_index'=>(int)($row['ice_index']??-1),
        'sugar_profile'=>(string)($row['sugar_profile']??$p['sugar_profile']),'sugar_index'=>(int)($row['sugar_index']??-1),
        'ice_sig'=>hyg_profile_signature('ice',(string)($row['ice_profile']??$p['ice_profile'])),
        'sugar_sig'=>hyg_profile_signature('sugar',(string)($row['sugar_profile']??$p['sugar_profile'])),
        'topping_ids'=>array_values(array_unique(array_map(fn($t)=>(int)$t['id'],$row['toppings']??[])))];
}
function hyg_resolve(array $s): array {
    $qty=(int)($s['qty']??0);if($qty<1||$qty>order_qty_max())hyg_fail('invalid_quantity');
    // Nhóm combo: dòng combo và ly nước chọn theo combo (lib/group_combo.php).
    if(($s['kind']??'')==='combo')return hgc_resolve_combo($s);
    if(($s['kind']??'')==='combo_drink')return hgc_resolve_drink($s);
    if(($s['kind']??'')==='topping'){
        $p=hyg_catalog_one('SELECT * FROM toppings WHERE id=? AND active=1 AND sell_separately=1',[(int)$s['id']]);
        if(!$p)hyg_fail('item_unavailable');$price=(float)$p['retail_price'];
        return ['standalone_topping_id'=>(int)$p['id'],'name'=>localized_name($p),'image'=>$p['image']??'','cup_size'=>null,'base_price'=>$price,'ice'=>'','sugar'=>'','toppings'=>[],'unit_price'=>$price,'qty'=>$qty,'lang'=>current_lang()];
    }
    $p=hyg_catalog_one('SELECT p.* FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1 AND c.active=1',[(int)($s['id']??0)]);
    if(!$p)hyg_fail('item_unavailable');
    foreach(['ice','sugar'] as $kind){
        if(($s[$kind.'_profile']??'')!==$p[$kind.'_profile']||!hash_equals(hyg_profile_signature($kind,$p[$kind.'_profile']),(string)($s[$kind.'_sig']??'')))hyg_fail('options_changed');
        $opts=hyg_profile_options($kind,$p[$kind.'_profile']);if(!isset($opts[(int)$s[$kind.'_index']]))hyg_fail('options_changed');$choices[$kind]=$opts[(int)$s[$kind.'_index']];
    }
    $size=(string)($s['cup_size']??'');$base=(float)$p['price'];
    if(product_has_sizes($p)){$sizes=product_size_options($p);if(!array_key_exists($size,$sizes))hyg_fail('options_changed');$base=$sizes[$size];}
    elseif($size!=='')hyg_fail('options_changed');
    $ids=array_values(array_unique(array_map('intval',$s['topping_ids']??[])));if(count($ids)>50)hyg_fail('invalid_input');sort($ids);
    $tops=[];$extra=0.0;
    foreach($ids as $id){$t=hyg_catalog_one('SELECT * FROM toppings WHERE id=? AND active=1',[$id]);if(!$t)hyg_fail('item_unavailable');$tops[]=['id'=>(int)$t['id'],'name'=>$t['name'],'display_name'=>localized_name($t),'price'=>(float)$t['price']];$extra+=(float)$t['price'];}
    return ['product_id'=>(int)$p['id'],'name'=>localized_name($p),'image'=>$p['image']??'','cup_size'=>$size?:null,'base_price'=>$base,'ice'=>$choices['ice'],'sugar'=>$choices['sugar'],
        'ice_profile'=>$p['ice_profile'],'ice_index'=>(int)$s['ice_index'],'sugar_profile'=>$p['sugar_profile'],'sugar_index'=>(int)$s['sugar_index'],
        'toppings'=>$tops,'unit_price'=>$base+$extra,'qty'=>$qty,'lang'=>current_lang()];
}
function hyg_cart(array $r,bool $strict=true): array {
    $items=hyg_all('SELECT i.*,m.nickname FROM group_items i JOIN group_members m ON m.id=i.member_id WHERE i.room_id=? ORDER BY i.member_id,i.id',[$r['id']]);
    $cart=[];
    foreach($items as $i){
        $s=json_decode($i['selection_json'],true,32,JSON_THROW_ON_ERROR);
        try{$snapshot=$r['status']==='submitted'||($r['status']==='locked'&&!$strict&&!empty($r['settlement_json']));$row=$snapshot?(json_decode($i['snapshot_json'],true)?:hyg_resolve($s)):hyg_resolve($s);}catch(GroupOrderError $e){if($strict)throw $e;$row=json_decode($i['snapshot_json'],true)?:[];$row['_invalid']=$e->getMessage();}
        $row['group_member_name']=$i['nickname'];$row['group_member_id']=(int)$i['member_id'];$row['_item_id']=(int)$i['id'];$row['_item_version']=(int)$i['version'];$row['_selection']=$s;
        $cart['g'.$i['id']]=$row;
    }
    return $cart;
}
function hyg_fingerprint(array $cart,array $coupon,?array $payment=null,?array $promotion=null): string {
    $parts=[];foreach($cart as $key=>$r)$parts[$key]=[$r['_selection'],(float)$r['base_price'],(float)$r['unit_price'],array_map(fn($t)=>[(int)$t['id'],(float)$t['price']],$r['toppings']),(int)$r['qty'],(int)$r['group_member_id']];
    $signed=[$parts,$coupon['code'],(bool)$coupon['valid'],$coupon['type'],$coupon['source'],(int)$coupon['eligible_qty'],(int)$coupon['free_qty'],(float)$coupon['discount'],$coupon['line_discounts']];if($payment!==null)$signed[]=hyg_settlement_signature_parts($payment);
    if($promotion!==null)$signed[]=$promotion;
    return hash('sha256',json_encode($signed,JSON_PRESERVE_ZERO_FRACTION));
}
function hyg_settlement(array $r): ?array {
    $raw=$r['settlement_json']??null;if(!is_string($raw)||$raw==='')return null;
    $settlement=json_decode($raw,true);if(!is_array($settlement)||!in_array($settlement['version']??0,[1,2],true)||!isset($settlement['payment']['members'],$settlement['coupon']))throw new RuntimeException('group_settlement_invalid');
    return $settlement;
}
/** Frozen liabilities with current host approvals; historical submitted orders retain their stored snapshot. */
function hyg_payment_summary(array $r,?array $cart=null,?array $coupon=null): array {
    if($r['status']==='submitted'&&!empty($r['order_id'])){
        $order=hyg_one('SELECT group_payment_summary FROM orders WHERE id=? AND group_room_id=?',[$r['order_id'],$r['id']]);
        if($order&&($historical=hyg_order_payment_summary($order))){
            $historical['protocol_version']=(int)($historical['protocol_version']??1);$historical['approval_legacy']=$historical['protocol_version']<2;
            $historical['can_checkout']=false;
            foreach($historical['members'] as &$person){foreach(['can_request','can_confirm','can_remove','can_reject','can_refund','can_correct','can_correct_received','can_correct_refunded'] as $key)$person[$key]=false;}unset($person);
            return $historical;
        }
    }
    $members=hyg_all('SELECT * FROM group_members WHERE room_id=? ORDER BY id',[$r['id']]);$settlement=hyg_settlement($r);
    if($settlement&&in_array($r['status'],['locked','submitted','cancelled'],true)){
        $payment=$settlement['payment'];$payment['has_items']=$payment['has_items']??(bool)hyg_cart($r,false);$payment['frozen']=true;$payment['legacy']=false;return hyg_overlay_payment_reports($payment,$members,$r);
    }
    $cart=$cart??hyg_cart($r,false);$coupon=$coupon??hyg_coupon($r,$cart,false);$payment=hyg_build_payment_summary($r,$members,$cart,$coupon);
    $payment['legacy']=in_array($r['status'],['locked','submitted'],true);
    return hyg_overlay_payment_reports($payment,$members,$r);
}
function hyg_coupon(array $r,array $cart,bool $validate=true): array {
    $settlement=hyg_settlement($r);
    if($settlement&&(!$validate||$r['status']==='submitted')&&in_array($r['status'],['locked','submitted'],true))return $settlement['coupon'];
    $code=(string)$r['coupon_code'];
    if($r['status']!=='submitted'&&!in_array(promotion_room_mode($r),['buy4get1','reward'],true))$code='';
    if($validate&&$r['status']!=='submitted'){$record=$code!==''?coupon_record($code):null;if($code!==''&&!$record)hyg_fail('coupon_changed');}
    else {$record=$code!==''?['code'=>$code,'type'=>$r['coupon_type'],'source'=>$r['coupon_source'],'amount'=>(float)$r['coupon_amount']]:null;
        // Shared room previews read prize terms; lock and checkout still verify the host's ownership.
        if(($record['type']??'')==='game_reward'){$row=hym_reward_one('SELECT id,code,coupon_type,amount FROM customer_coupons WHERE code=? AND used_order_id IS NULL',[$code]);$record=$row?hgr_coupon_record($row):null;}
    }
    // Historical submitted orders retain their original pricing and coupon allocation.
    if($r['status']==='submitted'&&!$settlement){
        $coupon=calculate_cart_coupon_summary($cart,$record);$order=hyg_one('SELECT coupon_code,discount_amount FROM orders WHERE id=?',[$r['order_id']]);
        if($order){$coupon['discount']=(float)$order['discount_amount'];$coupon['code']=(string)($order['coupon_code']??'');$coupon['valid']=$coupon['code']!=='';}
        return $coupon;
    }
    return hyg_calculate_group_coupon($cart,$record,$r,hyg_all('SELECT * FROM group_members WHERE room_id=? ORDER BY id',[$r['id']]));
}
function hyg_create(string $title,string $nickname,bool $copy,string $request,?array $hostAccount=null,string $orderType='drink'): array {
    hyg_schema();$owner=hyg_device(true);$title=hyg_text($title,80);$nickname=hyg_text($nickname,32);
    if($title===''||$nickname==='')hyg_fail('required_fields');
    if(!preg_match('/^[a-f0-9]{32}$/D',$request))hyg_fail('expired_form');
    // Đơn nhóm gộp: combo và nước lẻ cùng một nhóm, nên không còn kiểu nhóm riêng cho combo ($orderType giữ để tương thích).
    $isCombo=false;
    return hyg_tx(function()use($owner,$title,$nickname,$copy,$request,$hostAccount,$isCombo){
        $previous=hyg_one('SELECT * FROM group_rooms WHERE owner_hash=? AND create_key=?',[$owner,$request]);if($previous)return $previous;
        $n=hyg_one('SELECT COUNT(*) n FROM group_rooms WHERE owner_hash=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)',[$owner]);if((int)$n['n']>=20)hyg_fail('too_many');
        $public=bin2hex(random_bytes(16));$invite=bin2hex(random_bytes(32));
        $account=(int)($_SESSION['member_id']??0)?:null; // The signed-in member who creates the group is its owner.
        hyg_exec('INSERT INTO group_rooms(public_id,invite_token,owner_hash,create_key,title,promotion_mode,owner_member_id,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 HOUR))',[$public,$invite,$owner,$request,$title,!$isCombo&&minigame_enabled()?'draw':'none',$account]);$id=(int)db()->lastInsertId();
        if($isCombo)hyg_exec("UPDATE group_rooms SET order_type='combo' WHERE id=?",[$id]);
        if($hostAccount!==null){htp_group_save($id,$hostAccount);hyg_exec("UPDATE group_rooms SET checkout_mode='split' WHERE id=?",[$id]);}
        hyg_exec('INSERT INTO group_members(room_id,device_hash,nickname,member_id) VALUES(?,?,?,?)',[$id,$owner,$nickname,$account]);$mid=(int)db()->lastInsertId();
        if($copy){$cart=$_SESSION['cart']??[];if(count($cart)>200)hyg_fail('room_full');foreach($cart as $row){if(!empty($row['is_combo'])||!empty($row['combo_drink']))continue;$selection=hyg_selection($row);$resolved=hyg_resolve($selection);hyg_insert_item($id,$mid,$selection,$resolved,bin2hex(random_bytes(16)));}}
        return hyg_room($public);
    });
}
function hyg_join(string $invite,string $nickname): array {
    $nickname=hyg_text($nickname,32);if($nickname==='')hyg_fail('required_fields');
    if(!preg_match('/^[a-f0-9]{64}$/D',$invite))hyg_fail('not_found');$hash=hyg_device(true);
    return hyg_tx(function()use($invite,$nickname,$hash){
        $r=hyg_one('SELECT * FROM group_rooms WHERE invite_token=? FOR UPDATE',[$invite]);if(!$r)hyg_fail('not_found');
        if(hyg_one('SELECT room_id FROM group_removed_members WHERE room_id=? AND device_hash=?',[$r['id'],$hash]))hyg_fail('member_removed');
        $old=hyg_one('SELECT id FROM group_members WHERE room_id=? AND device_hash=?',[$r['id'],$hash]);if($old)return $r;
        hyg_open($r);$count=hyg_one('SELECT COUNT(*) n FROM group_members WHERE room_id=?',[$r['id']]);if((int)$count['n']>=50)hyg_fail('room_full');
        $same=hyg_one('SELECT id FROM group_members WHERE room_id=? AND nickname=?',[$r['id'],$nickname]);if($same)hyg_fail('name_taken');
        hyg_exec('INSERT INTO group_members(room_id,device_hash,nickname,member_id) VALUES(?,?,?,?)',[$r['id'],$hash,$nickname,(int)($_SESSION['member_id']??0)?:null]);hyg_touch((int)$r['id']);return $r;
    });
}
function hyg_insert_item(int $rid,int $mid,array $selection,array $row,string $request): void {
    hyg_exec('INSERT INTO group_items(room_id,member_id,request_key,selection_json,snapshot_json) VALUES(?,?,?,?,?)',[$rid,$mid,$request,json_encode($selection,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
/** The host may keep adding new drinks to a locked room (checkout Mini Game); editing or removing stays blocked. */
function hyg_host_may_append(array $r): bool {
    if($r['status']!=='locked'||!empty($r['order_id'])||!hyg_host($r))return false;
    $members=hyg_all('SELECT * FROM group_members WHERE room_id=?',[$r['id']]);
    return hyg_room_payment_active($r,$members);
}
/** A drink was added to a locked room: reprice the frozen quote and make payers whose amount changed confirm again. */
function hyg_reprice_after_append(array $r,array $host,array $quote): void {
    $cart=hyg_cart($r,false);
    $members=$quote['members'];
    $record=hyg_frozen_coupon_record($r,$quote['settlement']);
    $coupon=hyg_calculate_group_coupon($cart,$record,$r,$members);
    $payment=hyg_build_payment_summary($r,$members,$cart,$coupon);
    $payment['frozen']=true;
    $summary=$quote['payment'];
    foreach($members as $member){
        $id=(int)$member['id'];
        $before=$summary['members'][$id];
        $after=$payment['members'][$id];
        if(!hyg_payment_amount_changed($before,$after))continue;
        if(in_array($member['payment_status'],['requested','paid'],true))hyg_exec("UPDATE group_members SET payment_status='requested',payment_requested_at=COALESCE(payment_requested_at,payment_completed_at),payment_requested_amount=COALESCE(payment_requested_amount,?),payment_invalidated_at=UTC_TIMESTAMP(),payment_invalidation_reason='amount_changed' WHERE id=?",[$before['due'],$id]);
        hyg_payment_event($r,$member,(int)$host['id'],'amount_changed',(float)$after['due']-(float)$before['due'],'item_added',['due'=>$before['due'],'receipt'=>hyg_member_ledger_amounts($member)],['due'=>$after['due'],'receipt'=>hyg_member_ledger_amounts($member)]);
    }
    $settlement=['version'=>2,'payment'=>$payment,'coupon'=>$coupon,'coupon_record'=>$record,'coupon_rule'=>'group_min_base_per_5_v1'];
    $promotion=$quote['settlement']['promotion']??null;
    if($promotion!==null){
        $promotion['eligible_qty']=promotion_game_qty($cart);
        if($promotion['mode']==='draw'&&minigame_enabled())$promotion['expected_chances']=max((int)$promotion['expected_chances'],game_chances_for_drink_qty($promotion['eligible_qty']));
        $settlement['promotion']=$promotion;
    }
    hyg_exec('UPDATE group_rooms SET lock_signature=?,settlement_json=? WHERE id=?',[hyg_fingerprint($cart,$coupon,$payment,$promotion),json_encode($settlement,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$r['id']]);
}
/** After the host adds drinks, the host's own share needs no review: confirm it again, like the Chốt món button does. */
function hyg_reconfirm_host_share(string $public): void {
    $r=hyg_room($public);
    $quote=hyg_locked_quote($r);
    $hostId=(int)($quote['payment']['host_member_id']??0);
    $hostPay=hyg_member_payment($quote['payment'],$hostId);
    if(!$hostPay||empty($hostPay['can_confirm'])||(float)$hostPay['outstanding']<=0)return;
    hyg_mutate($public,'payment_confirm',[
        'revision'=>(int)$r['revision'],
        'quote_signature'=>$r['lock_signature']??'',
        'member_id'=>$hostId,
        'payment_amount'=>(string)hyg_money((float)$hostPay['outstanding']),
    ]);
}
function hyg_save_item(string $public,array $row,int $edit,int $version,string $request): void {
    if(!preg_match('/^[a-f0-9]{32}$/D',$request))hyg_fail('expired_form');
    $appended=false;
    hyg_tx(function()use($public,$row,$edit,$version,$request,&$appended){
        $r=hyg_room($public,true);$m=hyg_member($r);
        $append=!$edit&&hyg_host_may_append($r);
        if(!$append)hyg_open($r);
        if(!$edit&&hyg_one('SELECT id FROM group_items WHERE room_id=? AND member_id=? AND request_key=?',[$r['id'],$m['id'],$request]))return;
        $quote=$append?hyg_locked_quote($r):null;
        $selection=hyg_selection($row);$resolved=hyg_resolve($selection);
        if($edit){
            $i=hyg_one('SELECT * FROM group_items WHERE id=? AND room_id=?',[$edit,$r['id']]);
            if(!$i||(int)$i['member_id']!==(int)$m['id'])hyg_fail('own_items_only');
            if((int)$i['version']!==$version)hyg_fail('changed_reload');
            hyg_exec('UPDATE group_items SET selection_json=?,snapshot_json=?,version=version+1 WHERE id=?',[json_encode($selection,JSON_UNESCAPED_UNICODE),json_encode($resolved,JSON_UNESCAPED_UNICODE),$edit]);
        }else{
            $n=hyg_one('SELECT COUNT(*) n FROM group_items WHERE room_id=?',[$r['id']]);if((int)$n['n']>=200)hyg_fail('room_full');
            hyg_insert_item((int)$r['id'],(int)$m['id'],$selection,$resolved,$request);
            if($quote){
                hyg_reprice_after_append($r,$m,$quote);
                $appended=true;
            }
        }
        hyg_touch((int)$r['id']);
    });
    if(!$appended)return;
    hyg_reconfirm_host_share($public);
    $promotion=hyg_settlement(hyg_room($public))['promotion']??null;
    if($promotion&&$promotion['mode']==='draw')hcgs_grow_group_session($public,(int)$promotion['eligible_qty'],(int)$promotion['expected_chances']);
}
/** Validate the immutable quote without requiring payment yet; callers serialize on the room row. */
function hyg_locked_quote(array $r,bool $allowExpiredResolution=false): array {
    if($r['status']!=='locked')hyg_fail('payment_not_ready');
    $members=hyg_all('SELECT * FROM group_members WHERE room_id=? ORDER BY id',[$r['id']]);
    if(!$allowExpiredResolution&&!hyg_room_payment_active($r,$members))hyg_fail('room_expired');
    $settlement=hyg_settlement($r);if(!$settlement||(int)($r['payment_protocol_version']??1)<2)hyg_fail('settlement_unavailable');
    $cart=hyg_cart($r,false);$coupon=$settlement['coupon'];
    $fresh=hyg_build_payment_summary($r,$members,$cart,$coupon);$fresh['frozen']=true;
    $promotion=$settlement['promotion']??null;
    if($promotion!==null&&($promotion['mode']!==promotion_room_mode($r)||$promotion['code']!==(string)$r['coupon_code']))hyg_fail('prices_changed');
    if(!hash_equals((string)$r['lock_signature'],hyg_fingerprint($cart,$coupon,$fresh,$promotion))||!hash_equals((string)$r['lock_signature'],hyg_fingerprint($cart,$coupon,$settlement['payment'],$promotion)))hyg_fail('prices_changed');
    return ['cart'=>$cart,'coupon'=>$coupon,'members'=>$members,'settlement'=>$settlement,'payment'=>hyg_overlay_payment_reports($fresh,$members,$r)];
}
/** Removing a member reprices only the frozen cart and coupon, and invalidates changed payer approvals. */
function hyg_remove_member(array $r,array $host,array $data): void {
    if(!in_array($r['status'],['open','locked'],true))hyg_fail('not_open');
    if($r['status']==='open'&&strtotime($r['expires_at'].' UTC')<=time())hyg_fail('room_expired');
    if(hyg_positive_int($data['revision']??null)!==(int)$r['revision'])hyg_fail('changed_reload');
    $target=hyg_one('SELECT * FROM group_members WHERE id=? AND room_id=?',[hyg_positive_int($data['member_id']??null),$r['id']]);
    if(!$target||(int)$target['id']===(int)$host['id'])hyg_fail('member_remove_forbidden');
    $quote=$r['status']==='locked'?hyg_locked_quote($r):null;$summary=$quote['payment']??hyg_payment_summary($r);
    $person=hyg_member_payment($summary,(int)$target['id']);if(!$person||!$person['can_remove'])hyg_fail('member_paid_remove_forbidden');
    hyg_payment_event($r,$target,(int)$host['id'],'member_removed',0,'',$target,null);
    hyg_exec('INSERT INTO group_removed_members(room_id,device_hash,original_member_id,removed_by_member_id,removed_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',[$r['id'],$target['device_hash'],$target['id'],$host['id']]);
    hyg_exec('DELETE FROM group_members WHERE id=? AND room_id=?',[$target['id'],$r['id']]);
    if(!$quote)return;
    $cart=array_filter($quote['cart'],fn($row)=>(int)$row['group_member_id']!==(int)$target['id']);
    $members=array_values(array_filter($quote['members'],fn($member)=>(int)$member['id']!==(int)$target['id']));
    $record=hyg_frozen_coupon_record($r,$quote['settlement']);$coupon=hyg_calculate_group_coupon($cart,$record,$r,$members);
    $payment=hyg_build_payment_summary($r,$members,$cart,$coupon);$payment['frozen']=true;
    foreach($members as $member){
        $id=(int)$member['id'];$before=$summary['members'][$id];$after=$payment['members'][$id];
        if(hyg_payment_amount_changed($before,$after)){
            // Keep the previous report amount for the next payer report, never approve it as the new amount.
            if(in_array($member['payment_status'],['requested','paid'],true))hyg_exec("UPDATE group_members SET payment_status='requested',payment_requested_at=COALESCE(payment_requested_at,payment_completed_at),payment_requested_amount=COALESCE(payment_requested_amount,?),payment_invalidated_at=UTC_TIMESTAMP(),payment_invalidation_reason='amount_changed' WHERE id=?",[$before['due'],$id]);
            hyg_payment_event($r,$member,(int)$host['id'],'amount_changed',(float)$after['due']-(float)$before['due'],'member_removed',['due'=>$before['due'],'receipt'=>hyg_member_ledger_amounts($member)],['due'=>$after['due'],'receipt'=>hyg_member_ledger_amounts($member)]);
        }
    }
    $settlement=['version'=>2,'payment'=>$payment,'coupon'=>$coupon,'coupon_record'=>$record,'coupon_rule'=>'group_min_base_per_5_v1'];
    $promotion=$quote['settlement']['promotion']??null;
    if($promotion!==null){$promotion['eligible_qty']=promotion_game_qty($cart);$promotion['expected_chances']=$promotion['mode']==='draw'?min(max(0,(int)$promotion['expected_chances']),game_chances_for_drink_qty($promotion['eligible_qty'])):0;$settlement['promotion']=$promotion;}
    hyg_exec('UPDATE group_rooms SET lock_signature=?,settlement_json=? WHERE id=?',[hyg_fingerprint($cart,$coupon,$payment,$promotion),json_encode($settlement,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$r['id']]);
}
/** A group order needs at least this many drinks in total before the host can press "Chốt món". */
function hyg_min_lock_cups(): int {return 2;}
function hyg_mutate(string $public,string $action,array $data): void {
    hyg_tx(function()use($public,$action,$data){
        $r=hyg_room($public,true);$m=hyg_member($r);$rid=(int)$r['id'];
        if(in_array($action,['lock','reopen','cancel','coupon','rotate','checkout_mode','payment_confirm','remove_member','payment_reject','payment_refund','payment_correct','payment_extend'],true))hyg_require_host($r);
        if($action==='cancel'){
            if(!in_array($r['status'],['open','locked'],true))hyg_fail('not_open');
            $members=hyg_all('SELECT * FROM group_members WHERE room_id=? ORDER BY id',[$rid]);
            // Phần của chính chủ nhóm được tự xác nhận "đã trả" lúc chốt món: không phải tiền thu của ai,
            // nên không chặn hủy nhóm. Chỉ chặn khi thành viên khác đã báo trả / đã được ghi nhận tiền.
            $hostMemberId = hyg_host_member_id($r, $members);
            $otherMembers = array_filter(
                $members,
                fn($member) => (int)$member['id'] !== $hostMemberId
            );
            if (hyg_room_has_payment_work($otherMembers)) {
                hyg_fail('payment_cancel_unresolved');
            }
            if(hyg_positive_int($data['revision']??null)!==(int)$r['revision'])hyg_fail('changed_reload');
            $reason=hyg_payment_reason($data);if($r['status']==='locked')hyg_payment_control_quote($r,$data);
            hyg_payment_event($r,null,(int)$m['id'],'cancelled',0,$reason);
            hyg_exec("UPDATE group_rooms SET status='cancelled',invite_token=NULL,lock_signature=NULL WHERE id=?",[$rid]);
        }elseif($action==='reopen'){
            if($r['status']!=='locked'||strtotime($r['expires_at'].' UTC')<=time())hyg_fail('room_expired');
            if(hyg_room_has_payment_work(hyg_all('SELECT * FROM group_members WHERE room_id=?',[$rid])))hyg_fail('payment_locked');
            hyg_exec("UPDATE group_rooms SET status='open',lock_signature=NULL,settlement_json=NULL WHERE id=?",[$rid]);
        }elseif($action==='lock'){
            hyg_open($r);if((int)($data['revision']??-1)!==(int)$r['revision'])hyg_fail('changed_reload');
            $cart=hyg_cart($r);if(!$cart)hyg_fail('empty_room');
            // Giỏ có combo: mọi combo phải chọn đủ nước theo luật combo (thay luật tối thiểu 2 ly nước lẻ).
            if(hgc_has_combo($cart))hgc_validate_lock($cart);
            elseif(promotion_drink_qty($cart)<hyg_min_lock_cups())hyg_fail('min_cups');
            $record=promotion_group_record($r);
            $members=hyg_all('SELECT * FROM group_members WHERE room_id=? ORDER BY id',[$rid]);$coupon=hyg_calculate_group_coupon($cart,$record,$r,$members);$payment=hyg_build_payment_summary($r,$members,$cart,$coupon);$payment['frozen']=true;
            $promotion=promotion_room_choice($r,$cart);
            $settlement=['version'=>2,'payment'=>$payment,'coupon'=>$coupon,'coupon_record'=>$record,'coupon_rule'=>'group_min_base_per_5_v1','promotion'=>$promotion];
            hyg_exec("UPDATE group_rooms SET status='locked',payment_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR),lock_signature=?,settlement_json=? WHERE id=?",[hyg_fingerprint($cart,$coupon,$payment,$promotion),json_encode($settlement,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$rid]);
            foreach($cart as $row){$snapshot=$row;unset($snapshot['_selection']);hyg_exec('UPDATE group_items SET snapshot_json=? WHERE id=?',[json_encode($snapshot,JSON_UNESCAPED_UNICODE),$row['_item_id']]);}
        }elseif($action==='checkout_mode'){
            hyg_open($r);$mode=$data['checkout_mode']??'';if(!is_string($mode)||!in_array($mode,['together','split'],true))hyg_fail('checkout_mode_invalid');
            hyg_exec('UPDATE group_rooms SET checkout_mode=? WHERE id=?',[$mode,$rid]);
        }elseif($action==='payment_method'){
            hyg_open($r);$method=$data['payment_method']??'';$host=hyg_host($r);
            if(!is_string($method)||!in_array($method,['bank_transfer','cash','host_advance'],true)||($host&&$method==='host_advance'))hyg_fail('payment_method_invalid');
            if(hyg_checkout_mode($r)==='together'&&!$host)hyg_fail('payment_not_ready');
            hyg_exec('UPDATE group_members SET payment_method=? WHERE id=?',[$method,$m['id']]);
        }elseif($action==='payment_complete'||$action==='payment_confirm'){
            $quote=hyg_locked_quote($r);hyg_payment_control_quote($r,$data);
            $targetId=$action==='payment_complete'?(int)$m['id']:hyg_positive_int($data['member_id']??null);
            $person=hyg_member_payment($quote['payment'],$targetId);if(!$person)hyg_fail('not_member');
            $before=hyg_one('SELECT * FROM group_members WHERE id=? AND room_id=?',[$targetId,$rid]);
            if($person['due']<=0)hyg_fail('payment_no_amount');
            $increment=hyg_money((float)$person['outstanding']);
            if(hyg_payment_input_amount($data['payment_amount']??null)!==$increment)hyg_fail('payment_report_amount_changed');
            if($action==='payment_complete'){
                if($person['is_host'])hyg_fail('payment_not_ready');
                if($person['status']==='paid'||($person['status']==='requested'&&!$person['amount_changed']))return;
                if(!$person['can_request'])hyg_fail('payment_not_ready');
                hyg_exec("UPDATE group_members SET payment_status='requested',payment_requested_at=UTC_TIMESTAMP(),payment_requested_amount=?,payment_completed_at=UTC_TIMESTAMP(),payment_invalidated_at=NULL,payment_invalidation_reason='' WHERE id=? AND room_id=?",[$increment,$targetId,$rid]);
                hyg_payment_event($r,$before,(int)$m['id'],'requested',$increment,'',$before,hyg_one('SELECT * FROM group_members WHERE id=?',[$targetId]));
            }else{
                if($person['status']==='paid'&&$person['confirmed'])return;
                if(!$person['is_host']&&$person['status']!=='requested')hyg_fail('payment_request_required');
                if(!$person['is_host']&&$person['amount_changed'])hyg_fail('payment_amount_changed');
                if(!$person['can_confirm'])hyg_fail('payment_not_ready');
                hyg_exec("UPDATE group_members SET payment_status='paid',payment_requested_at=COALESCE(payment_requested_at,UTC_TIMESTAMP()),payment_requested_amount=?,payment_completed_at=COALESCE(payment_completed_at,UTC_TIMESTAMP()),payment_confirmed_at=UTC_TIMESTAMP(),payment_confirmed_by_member_id=?,payment_confirmed_amount=?,payment_invalidated_at=NULL,payment_invalidation_reason='' WHERE id=? AND room_id=?",[$increment,$m['id'],$person['due'],$targetId,$rid]);
                hyg_exec('UPDATE group_members SET payment_received_amount=payment_received_amount+? WHERE id=?',[$increment,$targetId]);
                hyg_payment_event($r,$before,(int)$m['id'],'confirmed',$increment,'',$before,hyg_one('SELECT * FROM group_members WHERE id=?',[$targetId]));
            }
        }elseif(in_array($action,['payment_reject','payment_refund','payment_correct'],true)){
            hyg_payment_control($r,$m,$action,$data);
        }elseif($action==='payment_extend'){
            hyg_payment_control_quote($r,$data);$reason=hyg_payment_reason($data);hyg_locked_quote($r,true);
            $before=['payment_expires_at'=>$r['payment_expires_at']??null];
            hyg_exec('UPDATE group_rooms SET payment_expires_at=DATE_ADD(GREATEST(COALESCE(payment_expires_at,UTC_TIMESTAMP()),UTC_TIMESTAMP()),INTERVAL 24 HOUR) WHERE id=?',[$rid]);
            hyg_payment_event($r,null,(int)$m['id'],'extended',0,$reason,$before,['payment_expires_at'=>hyg_room($public)['payment_expires_at']]);
        }elseif($action==='remove_member'){
            hyg_remove_member($r,$m,$data);
        }elseif($action==='coupon'){
            hyg_open($r);if(isset($data['revision'])&&hyg_positive_int($data['revision'])!==(int)$r['revision'])hyg_fail('changed_reload');
            try{$choice=promotion_selection_from_post($data);}catch(Throwable $e){hyg_fail('coupon_changed');}$record=$choice['record'];
            hyg_exec('UPDATE group_rooms SET promotion_mode=?,coupon_code=?,coupon_type=?,coupon_source=?,coupon_amount=? WHERE id=?',[$choice['mode'],$choice['code'],$record['type']??'',$record['source']??'',(float)($record['amount']??0),$rid]);
        }elseif($action==='rotate'){
            hyg_open($r);hyg_exec('UPDATE group_rooms SET invite_token=? WHERE id=?',[bin2hex(random_bytes(32)),$rid]);
        }elseif($action==='remove'||$action==='quantity'){
            hyg_open($r);$i=hyg_one('SELECT * FROM group_items WHERE id=? AND room_id=?',[(int)($data['item_id']??0),$rid]);
            if(!$i||((int)$i['member_id']!==(int)$m['id']&&!hyg_host($r)))hyg_fail('own_items_only');
            if((int)$i['version']!==(int)($data['item_version']??-1))hyg_fail('changed_reload');
            // Nhóm combo: số lượng đi theo luật combo, không sửa trực tiếp; xoá combo thì xoá luôn nước đã chọn cho nó.
            if($action==='quantity'&&in_array(json_decode((string)$i['selection_json'],true)['kind']??'',['combo','combo_drink'],true))hyg_fail('combo_room_only');
            if($action==='remove'&&(json_decode((string)$i['selection_json'],true)['kind']??'')==='combo')hgc_delete_children($rid,(int)$i['id']);
            if($action==='remove')hyg_exec('DELETE FROM group_items WHERE id=?',[$i['id']]);
            else{$qty=filter_var($data['qty']??'',FILTER_VALIDATE_INT);if($qty===false||$qty<1||$qty>order_qty_max())hyg_fail('invalid_quantity');$s=json_decode($i['selection_json'],true);$s['qty']=$qty;$row=hyg_resolve($s);hyg_exec('UPDATE group_items SET selection_json=?,snapshot_json=?,version=version+1 WHERE id=?',[json_encode($s,JSON_UNESCAPED_UNICODE),json_encode($row,JSON_UNESCAPED_UNICODE),$i['id']]);}
        }else hyg_fail('invalid_input');
        hyg_touch($rid);
    });
}
/** Caller must hold an active transaction for writes. New quotes honor the server snapshot fixed at lock. */
function hyg_checkout(string $public,bool $lock=false): array {
    hyg_schema();$r=hyg_room($public,$lock);hyg_require_host($r);
    if($r['status']==='submitted')return ['room'=>$r,'already'=>true,'cart'=>[],'coupon'=>[]];
    if($r['status']!=='locked')hyg_fail('lock_first');
    $quote=hyg_locked_quote($r);if(!$quote['cart'])hyg_fail('empty_room');
    // Frozen discounts still require ownership of an unused prize at checkout.
    if(($quote['coupon']['source']??'')==='game'&&($quote['coupon']['valid']??false)){
        $record=coupon_record((string)$quote['coupon']['code']);
        $frozenAmount=$quote['settlement']['coupon_record']['amount']??$quote['coupon']['original_amount']??null;
        if(!$record||$record['source']!=='game'||(float)$quote['coupon']['discount']<=0||($frozenAmount!==null&&(float)$record['amount']!==(float)$frozenAmount))hyg_fail('coupon_changed');
    }
    if(!hyg_payments_confirmed($quote['payment']))hyg_fail('payments_unconfirmed');
    return ['room'=>$r,'already'=>false,'cart'=>$quote['cart'],'coupon'=>$quote['coupon'],'payment'=>$quote['payment']];
}
function hyg_store_order_payment_summary(array $r,int $orderId,?string $payChoice=null): void {
    if(!db()->inTransaction())throw new RuntimeException('group_payment_snapshot_requires_transaction');
    $payment=hyg_payment_summary($r);
    // The host picked transfer or cash on the checkout page: the saved snapshot shows that choice, not the one stored when the room was quoted.
    $hostId=(int)($payment['host_member_id']??0);
    if(in_array($payChoice,['transfer','cash'],true)&&isset($payment['members'][$hostId]))$payment['members'][$hostId]['method']=$payChoice==='cash'?'cash':'bank_transfer';
    hyg_exec('UPDATE orders SET group_checkout_mode=?,group_payment_summary=? WHERE id=? AND group_room_id=?',[$payment['mode'],json_encode($payment,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$orderId,$r['id']]);
}
function hyg_finish(array $ctx,int $orderId,?string $payChoice=null): void {
    if(!db()->inTransaction())throw new RuntimeException('group_finish_requires_transaction');
    $r=hyg_room((string)$ctx['room']['public_id'],true);hyg_require_host($r);
    if(!hyg_payment_quote_signature_valid($r,$ctx['room']['lock_signature']??null))hyg_fail('changed_reload');
    $quote=hyg_locked_quote($r);if(!$quote['cart'])hyg_fail('empty_room');
    if(!hyg_payments_confirmed($quote['payment']))hyg_fail('payments_unconfirmed');
    hyg_store_order_payment_summary($r,$orderId,$payChoice);
    hyg_exec("UPDATE group_rooms SET status='submitted',order_id=?,submitted_at=NOW(),invite_token=NULL,revision=revision+1 WHERE id=? AND status='locked'",[$orderId,$r['id']]);
}
/** A group the host cancelled is removed completely: members, drinks and payment events go with it. */
function hyg_delete_cancelled_room(string $public): void {
    hyg_tx(function()use($public){
        $r=hyg_room($public,true);
        hyg_require_host($r);
        if($r['status']!=='cancelled'||!empty($r['order_id']))hyg_fail('not_open');
        $rid=(int)$r['id'];
        hyg_exec('DELETE FROM group_payment_events WHERE room_id=?',[$rid]);
        try{hyg_exec('DELETE FROM transfer_group_accounts WHERE room_id=?',[$rid]);}catch(Throwable $e){}
        // Members, removed-member marks and items follow through ON DELETE CASCADE.
        hyg_exec('DELETE FROM group_rooms WHERE id=?',[$rid]);
    });
}
function hyg_room_url(string $public): string {return base_url('group/?id='.hyg_code($public));}
function hyg_context(): ?array {
    if(empty($_GET['group']))return null;
    hyg_schema();$r=hyg_room(hyg_code($_GET['group']));$m=hyg_member($r);
    if(!hyg_host_may_append($r))hyg_open($r);
    hyg_headers();
    return $GLOBALS['hyg_context']=['room'=>$r,'member'=>$m];
}
/** True while this device is in a group still taking orders, or the host has an unsent group checkout (header shows "Giỏ nhóm"). */
function hyg_in_active_group(): bool {
    if(!empty($GLOBALS['front_cart_room']))return true;
    try{
        $device=hyg_device();if($device==='')return false;
        return (bool)hyg_one("SELECT 1 FROM group_rooms r JOIN group_members m ON m.room_id=r.id WHERE m.device_hash=? AND r.status='open' AND r.expires_at>UTC_TIMESTAMP() LIMIT 1",[$device]);
    }catch(Throwable $e){return false;}
}
/** True while this device is the host of a group order not yet sent or cancelled (still choosing drinks, or closed and awaiting checkout): starting another one is locked. Members of someone else's group are not locked. */
function hyg_has_live_group(): bool {
    try{
        $device=hyg_device();if($device==='')return false;
        return (bool)hyg_one("SELECT 1 FROM group_rooms r WHERE r.owner_hash=? AND r.status IN ('open','locked') AND r.order_id IS NULL AND r.expires_at>UTC_TIMESTAMP() LIMIT 1",[$device]);
    }catch(Throwable $e){return false;}
}
function hyg_url(string $path): string {
    $ctx=$GLOBALS['hyg_context']??null;
    if(!$ctx)return base_url($path);
    $parts=explode('#',$path,2);$path=$parts[0].(str_contains($parts[0],'?')?'&':'?').'group='.$ctx['room']['public_id'];
    return base_url($path.(isset($parts[1])?'#'.$parts[1]:''));
}
function hyg_form_fields(?array $ctx,?array $item=null): string {
    if(!$ctx)return '';
    return '<input type="hidden" name="group_id" value="'.e($ctx['room']['public_id']).'"><input type="hidden" name="group_item_id" value="'.(int)($item['id']??0).'"><input type="hidden" name="group_item_version" value="'.(int)($item['version']??0).'"><input type="hidden" name="group_request" value="'.bin2hex(random_bytes(16)).'">';
}
function hyg_edit_item(?array $ctx,string $kind,int $id): ?array {
    if(!$ctx||empty($_GET['edit']))return null;
    $i=hyg_one('SELECT * FROM group_items WHERE id=? AND room_id=? AND member_id=?',[(int)$_GET['edit'],$ctx['room']['id'],$ctx['member']['id']]);
    if(!$i)hyg_fail('own_items_only');$s=json_decode($i['selection_json'],true);if($s['kind']!==$kind||(int)$s['id']!==$id)hyg_fail('invalid_input');
    $i['selection']=$s;return $i;
}
function hyg_error_page(Throwable $e): never {
    if(db()->inTransaction())db()->rollBack();
    $key=$e instanceof GroupOrderError?$e->getMessage():'server_error';
    if(!$e instanceof GroupOrderError)error_log('group_order: '.$e->getMessage());
    http_response_code($key==='not_found'?404:($key==='not_member'||$key==='host_only'?403:409));hyg_headers();
    require_once __DIR__.'/layout.php';front_header(gt('title'));
    echo '<section class="hyg-card hyg-error"><h1>'.e(gt('cannot_continue')).'</h1><p>'.e(gt($key)).'</p><a class="btn primary" href="'.e(base_url('group/')).'">'.e(gt('my_groups')).'</a></section>';front_footer();exit;
}
