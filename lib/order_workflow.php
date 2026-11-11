<?php
/** One versioned order-status service for desktop and mobile staff actions. */
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/payment_workflow_i18n.php';
class OrderWorkflowError extends RuntimeException {}
function htow_fail(string $key): never {throw new OrderWorkflowError($key);}
function htow_schema(): void {
    static $done=false;if($done)return;$pdo=db();if($pdo->inTransaction())htow_fail('storage');
    $st=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$st->execute(['order_workflow_schema']);
    if($st->fetchColumn()!=='19.17.0'){
        $lock='htow:'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $guard=$pdo->prepare('SELECT GET_LOCK(?,10)');$guard->execute([$lock]);if((int)$guard->fetchColumn()!==1)htow_fail('storage');
        try{
            $st->execute(['order_workflow_schema']);if($st->fetchColumn()!=='19.17.0'){
                $check=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='status_version'");
                if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE orders ADD COLUMN status_version INT UNSIGNED NOT NULL DEFAULT 1');
                $check=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='checkout_request_hash'");
                if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE orders ADD COLUMN checkout_request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
                $check=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='uq_order_checkout_request'");
                if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE orders ADD UNIQUE KEY uq_order_checkout_request (checkout_request_hash)');
                $pdo->exec("CREATE TABLE IF NOT EXISTS order_status_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,from_status VARCHAR(16) NOT NULL,to_status VARCHAR(16) NOT NULL,from_version INT UNSIGNED NOT NULL,to_version INT UNSIGNED NOT NULL,admin_id BIGINT UNSIGNED NULL,partner_id BIGINT UNSIGNED NULL,reason VARCHAR(1000) NOT NULL,created_at DATETIME NOT NULL,KEY idx_order_status_history(order_id,id),KEY idx_status_events_partner(partner_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                // Store partners act with admin_id NULL and their partners.id; admin_id 0 keeps meaning "system".
                if($pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_status_events' AND COLUMN_NAME='admin_id'")->fetchColumn()==='NO')$pdo->exec('ALTER TABLE order_status_events MODIFY COLUMN admin_id BIGINT UNSIGNED NULL');
                $check=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_status_events' AND COLUMN_NAME='partner_id'");
                if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE order_status_events ADD COLUMN partner_id BIGINT UNSIGNED NULL AFTER admin_id');
                $check=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_status_events' AND INDEX_NAME='idx_status_events_partner'");
                if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE order_status_events ADD KEY idx_status_events_partner(partner_id)');
                $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['order_workflow_schema','19.17.0']);
            }
        }finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    }$done=true;
}
function htow_targets(string $status): array {
    // A done order is final: it has earned its rewards (game chances, referral points) and can no longer be cancelled.
    return ['new'=>['accepted','cancelled'],'accepted'=>['done','cancelled'],'done'=>[],'cancelled'=>[]][$status]??[];
}
function htow_change(int $orderId,mixed $target,mixed $version,mixed $reason,int $adminId): void {
    htp_staff_guard($adminId);
    htow_transition($orderId,$target,$version,$reason,$adminId,null,function()use($adminId):?int{htp_staff_locked($adminId);return null;});
}
/**
 * Exactly one of $adminId (staff) or $partnerId (store partner) is the actor. $lockActor runs first inside the
 * transaction; a store id it returns limits the change to orders dispatched to that store.
 */
function htow_transition(int $orderId,mixed $target,mixed $version,mixed $reason,?int $adminId,?int $partnerId,callable $lockActor): void {
    if(!is_string($target)||!in_array($target,['new','accepted','done','cancelled'],true))htow_fail('status_transition_error');
    // Cancelling touches refunds and referral points, so it stays a staff decision.
    if(($adminId===null)===($partnerId===null)||($partnerId!==null&&$target==='cancelled'))htow_fail('status_transition_error');
    if((!is_string($version)&&!is_int($version))||!preg_match('/^[1-9]\d{0,9}$/D',(string)$version))htow_fail('status_changed_error');
    // Status reason is optional: blank stays '' in order_status_events; a given reason is still validated.
    if($reason===null||(is_string($reason)&&trim($reason)===''))$reason='';
    else try{$reason=htp_reason($reason);}catch(TransferPaymentError $e){htow_fail('reason_required');}
    $pdo=db();if($pdo->inTransaction())htow_fail('storage');$pdo->beginTransaction();
    try{
        $storeId=$lockActor();
        $order=hyg_one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$orderId]);
        if(!$order||($storeId!==null&&(int)($order['dispatch_store_id']??0)!==$storeId))htow_fail('not_found');
        if((int)$order['status_version']!==(int)$version)htow_fail('status_changed_error');
        if($order['status']===$target){$pdo->commit();return;}
        if(!in_array($target,htow_targets($order['status']),true))htow_fail('status_transition_error');
        $pdo->prepare('UPDATE orders SET status=?,status_version=status_version+1 WHERE id=? AND status_version=?')->execute([$target,$orderId,$version]);
        $pdo->prepare('INSERT INTO order_status_events(order_id,from_status,to_status,from_version,to_version,admin_id,partner_id,reason,created_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$orderId,$order['status'],$target,$version,(int)$version+1,$adminId,$partnerId,$reason]);
        if($target==='cancelled'){
            // A cancelled group order must not keep the referral points it earned on submit.
            hym_group_referral_revoke($orderId);
            $r=hyg_one('SELECT * FROM transfer_order_receipts WHERE order_id=? FOR UPDATE',[$orderId]);
            if($r){$after=$r;$after['version']=(int)$r['version']+1;$pdo->prepare('UPDATE transfer_order_receipts SET version=version+1 WHERE order_id=?')->execute([$orderId]);htp_event($orderId,'cancelled',$r,$after,$adminId,$reason);}
        }
        if($target==='done'){
            award_game_chances_for_order($orderId);
            $processed=hyg_one('SELECT game_award_processed FROM orders WHERE id=?',[$orderId]);
            if(empty($processed['game_award_processed']))htow_fail('reward_failed');
            // Group referral points are earned only by completed orders.
            hym_group_referral_credit($orderId);
        }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function htow_history(int $orderId): array {return hyg_all('SELECT * FROM order_status_events WHERE order_id=? ORDER BY id DESC',[$orderId]);}
function htow_error(Throwable $error): string {
    if($error instanceof TransferPaymentError)return htp_error($error);
    if($error instanceof OrderWorkflowError){$label=pwf($error->getMessage());return $label!==$error->getMessage()?$label:htp('storage');}
    return function_exists('hds_view_error')?hds_view_error($error):htp('storage');
}
function htow_render_history(array $order): string {
    $history=htow_history((int)$order['id']);$partners=hpa_partner_names(array_column($history,'partner_id'));ob_start();?><section class="panel ma-panel"><p class="muted"><?=e(pwf('policy_hint'))?></p><details><summary><?=e(pwf('order_history'))?></summary><?php foreach($history as $entry):?><p><?=e(status_label($entry['from_status']))?> → <?=e(status_label($entry['to_status']))?> · <?=e(hds_view_time($entry['created_at']))?> · <?=e(hpa_actor_label($entry,$partners,pwf('staff').' #'.(int)$entry['admin_id']))?><?=$entry['reason']!==''?' · '.e($entry['reason']):''?></p><?php endforeach;?></details></section><?php return ob_get_clean();
}
/** Shared filters use real receipt balances, never fulfillment status as a paid indicator. */
function htp_list_query(array $filters,int $limit=200,int $offset=0,string $order='o.id DESC'): array {
    [$sql,$args]=htp_list_sql($filters);
    return hyg_all($sql.' ORDER BY '.$order.' LIMIT '.max(1,min(500,$limit)).' OFFSET '.max(0,$offset),$args);
}
/** Unordered list SQL + args; callers wrap it for paging, totals or export. */
function htp_list_sql(array $filters): array {
    $where=[];$args=[];$status=$filters['status']??'';$payment=$filters['payment_state']??'';
    if(is_string($status)&&in_array($status,['new','accepted','done','cancelled'],true)){$where[]='o.status=?';$args[]=$status;}
    // Admin list filters: free text, payment method, personal/group, dispatch store and created_at day range.
    $q=trim(is_string($filters['q']??null)?$filters['q']:'');
    if($q!==''){$where[]='(o.order_no LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR o.group_title LIKE ?)';$like='%'.addcslashes(mb_substr($q,0,80),'%_\\').'%';array_push($args,$like,$like,$like,$like);}
    $method=$filters['method']??'';
    if(is_string($method)&&in_array($method,['transfer','cash','split'],true)){$where[]='o.payment_method=?';$args[]=$method;}
    $type=$filters['type']??'';
    if($type==='group')$where[]='o.group_room_id IS NOT NULL';elseif($type==='personal')$where[]='o.group_room_id IS NULL';
    $store=$filters['store']??'';
    if($store==='hq')$where[]='o.dispatch_store_id IS NULL';elseif(is_string($store)&&preg_match('/^[1-9]\d{0,9}$/D',$store)){$where[]='o.dispatch_store_id=?';$args[]=(int)$store;}
    foreach(['date_from'=>['>=',''],'date_to'=>['<','+1 day']] as $key=>[$op,$shift]){
        $day=$filters[$key]??'';if(!is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day))continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);if(!$date||$date->format('Y-m-d')!==$day)continue;
        // orders.created_at is a session-time TIMESTAMP, shown as-is in the list, so days compare in that same clock.
        $where[]='o.created_at'.$op.'?';$args[]=($shift?$date->modify($shift):$date)->format('Y-m-d H:i:s');
    }
    // Group orders are collected per member in the room (group_members); a staff ledger row, if any, adds to it.
    $due='COALESCE(r.amount,o.total_amount)';$recv='(COALESCE(r.received_amount,0)+COALESCE(g.received,0))';$ref='(COALESCE(r.refunded_amount,0)+COALESCE(g.refunded,0))';
    $state="CASE WHEN o.status='cancelled' AND $recv-$ref>0 THEN 'refund_pending' WHEN o.status='cancelled' AND $recv>0 AND $recv=$ref THEN 'refunded' WHEN r.order_id IS NULL AND g.room_id IS NULL THEN 'legacy' WHEN $recv-$ref>$due THEN 'overpaid' WHEN $due=0 AND $recv=$ref THEN 'not_required' WHEN $due>0 AND $recv-$ref>=$due THEN 'confirmed' WHEN $recv-$ref>0 THEN 'partial' WHEN r.report_state='requested' THEN 'requested' WHEN r.report_state='rejected' THEN 'rejected' ELSE 'unpaid' END";
    if(is_string($payment)&&in_array($payment,['unpaid','requested','partial','confirmed','overpaid','refund_pending','refunded','legacy','not_required','rejected'],true)){$where[]='('.$state.')=?';$args[]=$payment;}
    $sql="SELECT o.*,r.status AS receipt_status,r.version AS receipt_version,$due AS receipt_due,$recv AS received_amount,$ref AS refunded_amount,r.report_state,r.ledger_only,$state AS financial_state FROM orders o LEFT JOIN transfer_order_receipts r ON r.order_id=o.id LEFT JOIN (SELECT room_id,SUM(payment_received_amount) AS received,SUM(payment_refunded_amount) AS refunded FROM group_members GROUP BY room_id) g ON g.room_id=o.group_room_id".($where?' WHERE '.implode(' AND ',$where):'');
    return [$sql,$args];
}
function htp_render_list_filters(string $path,array $filters,bool $export=true): string {
    ob_start();?><section class="panel ma-panel"><form method="get" action="<?=e($path)?>" class="form-grid"><input type="hidden" name="status" value="<?=e(is_string($filters['status']??'')?$filters['status']??'':'')?>"><label><?=e(pwf('payment_state'))?><select name="payment_state"><option value=""><?=e(pwf('filter_all'))?></option><?php foreach(['unpaid','requested','partial','confirmed','overpaid','refund_pending','refunded','legacy','not_required','rejected'] as $key):?><option value="<?=e($key)?>" <?=($filters['payment_state']??'')===$key?'selected':''?>><?=e(pwf($key))?></option><?php endforeach;?></select></label><button class="btn ma-btn" type="submit"><?=e(pwf('filter'))?></button></form><?php if($export&&!admin_is_operator()):?><p><a class="btn ma-btn" href="<?=e(base_url('admin/payment_export.php'))?>"><?=e(pwf('export'))?></a></p><?php endif;?></section><?php return ob_get_clean();
}
function htp_export_date(mixed $value): DateTimeImmutable {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new InvalidArgumentException('date_invalid');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Ho_Chi_Minh'));
    if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('date_invalid');return $date;
}
function htp_csv_safe(mixed $value): string {
    $text=(string)($value??'');
    // Spreadsheet formula/control prefixes must remain plain text even after leading spaces.
    if(preg_match('/^[\x00-\x20]*[=+\-@]/',$text)||preg_match('/^[\t\r\n]/',$text))return "'".$text;return $text;
}
