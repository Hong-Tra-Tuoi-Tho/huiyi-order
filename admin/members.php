<?php
/**
 * Quản lý thành viên (admin) — cùng bố cục trang đơn hàng: bộ lọc → thẻ thống kê → bảng → phân trang, kèm xuất Excel.
 *
 * Cấu trúc 1 thành viên mà trang này đọc:
 *  - members:               id, display_name, phone (dạng 84…), delivery_address,
 *                           created_at (UTC), phone_verified_at / phone_verified_by (cửa hàng gọi xác nhận SĐT)
 *  - Giới thiệu đơn nhóm:   members.parent_id (người giới thiệu = cấp cha), points_balance (điểm được cộng
 *                           khi cấp dưới làm trưởng nhóm chốt đơn — xem lib/member_referral.php)
 *  - Đơn website:           orders.member_id → số đơn, chi tiêu (không tính đơn đã hủy)
 *  - iPOS (chỉ đọc):        member_ipos_profiles (tier_name, points), member_sync_states (last_success_at)
 * Thao tác trên từng dòng giữ như cũ: chưa xác nhận → "Đã xác nhận điện thoại"; đã xác nhận → "Cập nhật dữ liệu iPOS".
 */
require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/../lib/layout.php';
require_admin();
$staffQuery=db()->prepare('SELECT id FROM admins WHERE id=?');
$staffQuery->execute([(int)$_SESSION['admin_id']]);
if(!$staffQuery->fetch()) { unset($_SESSION['admin_id']);redirect(base_url('admin/login.php')); }
header('Cache-Control: no-store, private');

// -------------------------------------------------------------
// 1. THAO TÁC TRÊN DÒNG (POST): xác nhận SĐT / cập nhật dữ liệu iPOS — giữ nguyên logic cũ
// -------------------------------------------------------------
$message='';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!is_string($_POST['csrf']??null)||empty($_SESSION['_csrf'])) { http_response_code(419);exit(e(t('csrf_expired'))); }
    verify_csrf();
    $id=max(0,(int)($_POST['member_id']??0));
    $action=(string)($_POST['action']??'');
    try {
        if ($action==='verify') {
            hym_verify_member_phone($id,(int)$_SESSION['admin_id']);
            $message=ma('verified_saved');
        } elseif ($action==='sync') {
            $query=db()->prepare('SELECT id FROM members WHERE id=?');$query->execute([$id]);
            if (!$query->fetch()) throw new RuntimeException('member_missing');
            $result=hym_sync_member($id,true);
            $feedback=mt((string)($result['message_key']??'member_sync_failed'));
            if (($result['status']??'')==='synced') $message=$feedback;
            else $error=$feedback;
        } else {
            http_response_code(400);$error=ma('save_failed');
        }
    } catch(Throwable $exception) {
        $error=ma('save_failed');
    }
}

// -------------------------------------------------------------
// 1b. LỊCH SỬ ĐIỂM NHÓM (GET ?points_log=ID → JSON cho popup)
//     member_point_transactions: 1 dòng / lần cộng điểm; order_id = đơn nhóm, source_member_id = trưởng nhóm (cấp dưới).
//     created_at dùng giờ phiên DB giống orders.created_at nên hiển thị nguyên giá trị.
// -------------------------------------------------------------
if (isset($_GET['points_log'])) {
    header('Content-Type: application/json; charset=utf-8');
    $memberId=max(0,(int)$_GET['points_log']);
    $rows=hyg_all("SELECT t.points,t.reason,t.order_id,t.created_at,o.order_no,o.status AS order_status,o.group_title,s.display_name AS source_name,s.phone AS source_phone
        FROM member_point_transactions t LEFT JOIN orders o ON o.id=t.order_id LEFT JOIN members s ON s.id=t.source_member_id
        WHERE t.member_id=? ORDER BY t.id DESC LIMIT 500",[$memberId]);
    echo json_encode(['rows'=>array_map(fn($r)=>[
        'time'=>(string)$r['created_at'],'points'=>(int)$r['points'],
        'reason'=>ma('ml_reason_'.$r['reason'])!=='ml_reason_'.$r['reason']?ma('ml_reason_'.$r['reason']):(string)$r['reason'],
        'order'=>(string)($r['order_no']??($r['order_id']?'#'.$r['order_id']:'')),'order_url'=>$r['order_id']?base_url('admin/order.php?id='.(int)$r['order_id']):'',
        'cancelled'=>($r['order_status']??'')==='cancelled','group'=>(string)($r['group_title']??''),
        'source'=>trim((string)($r['source_name']??'').(($r['source_phone']??'')!==''?' · '.$r['source_phone']:'')),
    ],$rows)],JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// 2. BỘ LỌC — chỉ nhận giá trị trong danh sách cho phép; ngày lọc theo giờ Việt Nam, đổi sang UTC để so với created_at
// -------------------------------------------------------------
$get=fn(string $key):string=>is_string($_GET[$key]??null)?trim($_GET[$key]):'';
$search=mb_substr($get('q'),0,160);
$verify=in_array($get('verify'),['verified','pending'],true)?$get('verify'):'';
$ipos=in_array($get('ipos'),['synced','never'],true)?$get('ipos'):'';
$referral=in_array($get('referral'),['has_parent','no_parent','has_children'],true)?$get('referral'):'';
$sorts=['newest'=>'m.id DESC','oldest'=>'m.id ASC','points'=>'m.points_balance DESC, m.id DESC','orders'=>'order_count DESC, m.id DESC','spent'=>'order_spent DESC, m.id DESC'];
$sort=isset($sorts[$get('sort')])?$get('sort'):'newest';
$perPage=in_array((int)$get('per_page'),[20,50,100],true)?(int)$get('per_page'):20;
$page=max(1,(int)$get('page'));

$where=[];$args=[];
if ($search!=='') {
    // SĐT lưu dạng 84…: khách gõ 09… thì đổi sang 849… trước khi tìm.
    $phoneSearch=preg_replace('/[\s().+\-]/','',$search);
    if (preg_match('/^0\d{9,10}$/',$phoneSearch)) $phoneSearch='84'.substr($phoneSearch,1);
    $where[]='(m.phone LIKE ? OR m.display_name LIKE ?)';
    array_push($args,'%'.addcslashes($phoneSearch,'%_\\').'%','%'.addcslashes($search,'%_\\').'%');
}
if ($verify==='verified') $where[]='m.phone_verified_at IS NOT NULL'; elseif ($verify==='pending') $where[]='m.phone_verified_at IS NULL';
if ($ipos==='synced') $where[]='ss.last_success_at IS NOT NULL'; elseif ($ipos==='never') $where[]='ss.last_success_at IS NULL';
if ($referral==='has_parent') $where[]='m.parent_id IS NOT NULL';
elseif ($referral==='no_parent') $where[]='m.parent_id IS NULL';
elseif ($referral==='has_children') $where[]='COALESCE(ch.children,0)>0';
$dateFrom=$get('date_from');$dateTo=$get('date_to');
foreach (['>='=>[$dateFrom,''],'<'=>[$dateTo,'+1 day']] as $op=>[$day,$shift]) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)) continue;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('Asia/Ho_Chi_Minh'));
    if (!$date||$date->format('Y-m-d')!==$day) continue;
    $where[]='m.created_at'.$op.'?';$args[]=($shift?$date->modify($shift):$date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
// Một dòng / thành viên: người giới thiệu, số cấp dưới, đơn website, hồ sơ iPOS, lần cập nhật iPOS.
$listSql="SELECT m.id,m.phone,m.display_name,m.delivery_address,m.phone_verified_at,m.created_at,m.parent_id,m.points_balance,
    p.display_name AS parent_name,p.phone AS parent_phone,COALESCE(ch.children,0) AS children,
    COALESCE(od.order_count,0) AS order_count,COALESCE(od.order_spent,0) AS order_spent,
    ip.tier_name AS ipos_tier,ip.points AS ipos_points,ss.last_success_at AS ipos_synced_at
  FROM members m
  LEFT JOIN members p ON p.id=m.parent_id
  LEFT JOIN (SELECT parent_id,COUNT(*) children FROM members WHERE parent_id IS NOT NULL GROUP BY parent_id) ch ON ch.parent_id=m.id
  LEFT JOIN (SELECT member_id,COUNT(*) order_count,SUM(CASE WHEN status<>'cancelled' THEN total_amount ELSE 0 END) order_spent FROM orders WHERE member_id IS NOT NULL GROUP BY member_id) od ON od.member_id=m.id
  LEFT JOIN member_ipos_profiles ip ON ip.member_id=m.id
  LEFT JOIN member_sync_states ss ON ss.member_id=m.id".($where?' WHERE '.implode(' AND ',$where):'');
$listUrl=function(array $overrides=[]):string{
    $params=array_merge(array_diff_key($_GET,['page'=>1,'export'=>1]),$overrides);
    return base_url('admin/members.php').'?'.http_build_query(array_filter($params,fn($v)=>$v!==''&&$v!==null));
};
// created_at / phone_verified_at lưu UTC → hiển thị giờ Việt Nam.
$memberDate=static function($value): string {
    if (!$value) return ma('unknown');
    try {
        return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i');
    } catch(Throwable $exception) { return ma('unknown'); }
};
$parentLabel=fn(array $m):string=>$m['parent_id']?trim(($m['parent_name']?:'#'.$m['parent_id']).($m['parent_phone']?' · '.$m['parent_phone']:'')):ma('ml_none');
$iposLabel=fn(array $m):string=>($m['ipos_tier']??'')!==''||$m['ipos_points']!==null?trim(($m['ipos_tier']??'').($m['ipos_points']!==null?' · '.money($m['ipos_points']):''),' ·'):ma('ml_none');

// -------------------------------------------------------------
// 3. XUẤT EXCEL — theo đúng bộ lọc + sắp xếp; vận hành viên (operator) không được xuất dữ liệu khách
// -------------------------------------------------------------
if ($get('export')==='xlsx') {
    if (admin_is_operator()) { http_response_code(403);exit(e(ma('ml_export_forbidden'))); }
    require_once __DIR__.'/../lib/xlsx_export.php';
    $rows=(function()use($listSql,$args,$sorts,$sort,$memberDate,$parentLabel){
        $st=db()->prepare($listSql.' ORDER BY '.$sorts[$sort]);$st->execute($args);
        while($m=$st->fetch())yield [$m['id'],$m['display_name'],$m['phone'],$m['delivery_address'],$memberDate($m['created_at']),
            ma($m['phone_verified_at']?'verified':'pending'),$m['phone_verified_at']?$memberDate($m['phone_verified_at']):'',
            $parentLabel($m),$m['children'],$m['points_balance'],$m['order_count'],$m['order_spent'],
            $m['ipos_tier']??'',$m['ipos_points']??'',$m['ipos_synced_at']?$memberDate($m['ipos_synced_at']):''];
    })();
    // Khối tiêu đề đầu file: tên danh sách, giờ xuất + số thành viên, và các bộ lọc đang áp dụng.
    $applied=array_filter([
        $search!==''?ma('search').': "'.$search.'"':'',
        $verify!==''?ma('verification').': '.ma($verify):'',
        $ipos!==''?ma('ml_ipos').': '.ma('ml_ipos_'.$ipos):'',
        $referral!==''?ma('ml_referral').': '.ma('ml_'.$referral):'',
        preg_match('/^\d{4}-\d{2}-\d{2}$/D',$dateFrom)?ma('ml_date_from').': '.$dateFrom:'',
        preg_match('/^\d{4}-\d{2}-\d{2}$/D',$dateTo)?ma('ml_date_to').': '.$dateTo:'',
    ]);
    $title=[ma('ml_xls_title').' — '.store_name(),ma('ml_xls_exported',['time'=>date('Y-m-d H:i'),'count'=>'{count}']),ma('ml_xls_filters').': '.($applied?implode(' · ',$applied):ma('ml_xls_no_filter'))];
    // Cột 0, 8–11, 13 là số để Excel cộng / lọc được.
    hxl_send('thanh-vien-'.date('Ymd-His').'.xlsx',['ID',ma('name'),ma('phone'),ma('ml_col_address'),ma('joined'),ma('verification'),ma('verification').' — '.t('time'),
        ma('ml_col_parent'),ma('ml_col_children'),ma('ml_col_points'),ma('ml_col_orders'),ma('ml_col_spent'),ma('ml_col_ipos'),ma('ml_ipos_points'),ma('last_sync')],$rows,[0,8,9,10,11,13],$title);
}

// -------------------------------------------------------------
// 4. THỐNG KÊ theo bộ lọc + trang hiện tại
// -------------------------------------------------------------
$stats=db()->prepare("SELECT COUNT(*) total,COALESCE(SUM(x.phone_verified_at IS NOT NULL),0) verified,COALESCE(SUM(x.parent_id IS NOT NULL),0) with_parent,
    COALESCE(SUM(x.points_balance),0) points,COALESCE(SUM(x.points_balance>0),0) with_points FROM ($listSql) x");
$stats->execute($args);$stats=$stats->fetch()?:[];
$total=(int)($stats['total']??0);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
$query=db()->prepare($listSql.' ORDER BY '.$sorts[$sort].' LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
$query->execute($args);$members=$query->fetchAll();
$money=fn($v)=>e(currency()).money($v);
admin_header(ma('members'));
?>
<?php /* Ẩn kiểm tra tối ưu màn hình: tạm bỏ thông báo xanh sau khi xác nhận SĐT / cập nhật iPOS (thông báo lỗi đỏ vẫn giữ).
<?php if($message):?><div class="alert success" role="status"><?=e($message)?></div><?php endif;?>
*/ ?>
<?php if($error):?><div class="alert danger" role="alert"><?=e($error)?></div><?php endif;?>

<?php /* ---------- Ghi chú nguồn dữ liệu + bộ lọc ---------- */ ?>
<section class="panel">
  <p><?=e(ma('source'))?></p>
  <p class="field-hint"><?=e(ma('verify_help'))?></p>
  <form method="get" action="<?=e(base_url('admin/members.php'))?>" class="mbl-filters">
    <label class="mbl-wide"><?=e(ma('search'))?><input type="search" name="q" value="<?=e($search)?>" maxlength="160" autocomplete="off"></label>
    <label><?=e(ma('verification'))?><select name="verify"><option value=""><?=e(ma('ml_verify_all'))?></option><option value="verified" <?=$verify==='verified'?'selected':''?>><?=e(ma('verified'))?></option><option value="pending" <?=$verify==='pending'?'selected':''?>><?=e(ma('pending'))?></option></select></label>
    <label><?=e(ma('ml_ipos'))?><select name="ipos"><option value=""><?=e(ma('ml_ipos_all'))?></option><option value="synced" <?=$ipos==='synced'?'selected':''?>><?=e(ma('ml_ipos_synced'))?></option><option value="never" <?=$ipos==='never'?'selected':''?>><?=e(ma('ml_ipos_never'))?></option></select></label>
    <label><?=e(ma('ml_referral'))?><select name="referral"><option value=""><?=e(ma('ml_referral_all'))?></option><?php foreach(['has_parent','no_parent','has_children'] as $key):?><option value="<?=$key?>" <?=$referral===$key?'selected':''?>><?=e(ma('ml_'.$key))?></option><?php endforeach;?></select></label>
    <label><?=e(ma('ml_date_from'))?><input type="date" name="date_from" value="<?=e($dateFrom)?>"></label>
    <label><?=e(ma('ml_date_to'))?><input type="date" name="date_to" value="<?=e($dateTo)?>"></label>
    <label><?=e(ma('ml_sort'))?><select name="sort"><?php foreach(array_keys($sorts) as $key):?><option value="<?=$key?>" <?=$sort===$key?'selected':''?>><?=e(ma('ml_sort_'.$key))?></option><?php endforeach;?></select></label>
    <label><?=e(ma('ml_per_page'))?><select name="per_page"><?php foreach([20,50,100] as $n):?><option value="<?=$n?>" <?=$perPage===$n?'selected':''?>><?=$n?></option><?php endforeach;?></select></label>
    <div class="mbl-actions">
      <button class="btn primary" type="submit"><?=e(ma('ml_filter'))?></button>
      <a class="btn" href="<?=e(base_url('admin/members.php'))?>"><?=e(ma('ml_reset'))?></a>
      <?php if(!admin_is_operator()):?><a class="btn" href="<?=e($listUrl(['export'=>'xlsx']))?>"><?=e(ma('ml_export'))?></a><?php endif;?>
      <a class="btn" href="<?=e(base_url('admin/member-settings.php'))?>"><?=e(ma('configuration'))?></a>
    </div>
  </form>
</section>

<?php /* ---------- Thẻ thống kê (theo bộ lọc) ---------- */ ?>
<div class="mbl-metrics">
  <div class="mbl-metric"><span><?=e(ma('ml_metric_total'))?></span><b><?=number_format($total)?></b><small><?=e(ma('ml_metric_total_sub',['count'=>number_format((int)($stats['with_parent']??0))]))?></small></div>
  <div class="mbl-metric good"><span><?=e(ma('ml_metric_verified'))?></span><b><?=number_format((int)($stats['verified']??0))?></b></div>
  <div class="mbl-metric warn"><span><?=e(ma('ml_metric_pending'))?></span><b><?=number_format($total-(int)($stats['verified']??0))?></b></div>
  <div class="mbl-metric"><span><?=e(ma('ml_metric_points'))?></span><b><?=number_format((int)($stats['points']??0))?></b><small><?=e(ma('ml_metric_points_sub',['count'=>number_format((int)($stats['with_points']??0))]))?></small></div>
</div>

<?php /* ---------- Bảng thành viên: mỗi cột ứng với một nhóm trường (xem chú thích đầu file) ----------
   Thành viên        ← display_name + #id
   Điện thoại        ← phone
   Ngày đăng ký      ← created_at
   Xác nhận ĐT       ← phone_verified_at
   Người giới thiệu  ← parent_id → tên + SĐT người cha
   Cấp dưới / Điểm   ← số members có parent_id = id / points_balance
   Đơn website       ← orders.member_id (số đơn + chi tiêu, bỏ đơn hủy)
   iPOS              ← member_ipos_profiles + lần cập nhật thành công gần nhất
*/ ?>
<?php if(!$members):?><section class="panel"><p><?=e(ma('empty'))?></p></section><?php else:?>
<div class="table-wrap mbl-table"><table><thead><tr>
  <th><?=e(ma('ml_col_member'))?></th><th><?=e(ma('phone'))?></th><th><?=e(ma('joined'))?></th><th><?=e(ma('verification'))?></th>
  <th><?=e(ma('ml_col_parent'))?></th><th class="mbl-num"><?=e(ma('ml_col_children'))?></th><th class="mbl-num"><?=e(ma('ml_col_points'))?></th>
  <th class="mbl-num"><?=e(ma('ml_col_orders'))?></th><th><?=e(ma('ml_col_ipos'))?></th><th></th>
</tr></thead><tbody>
<?php foreach($members as $member):$sync=hym_sync_state((int)$member['id']);?>
<tr>
  <td class="mbl-c-name" data-label="<?=e(ma('ml_col_member'))?>"><strong><?=e($member['display_name']?:ma('ml_none'))?></strong><br><small class="muted">#<?=(int)$member['id']?></small></td>
  <td class="mbl-c-phone" data-label="<?=e(ma('phone'))?>"><?=e($member['phone'])?></td>
  <td class="mbl-c-joined" data-label="<?=e(ma('joined'))?>"><?=e($memberDate($member['created_at']))?></td>
  <td class="mbl-c-verify" data-label="<?=e(ma('verification'))?>"><span class="status <?=$member['phone_verified_at']?'done':'new'?>"><?=e(ma($member['phone_verified_at']?'verified':'pending'))?></span><?php if($member['phone_verified_at']):?><br><small class="muted"><?=e($memberDate($member['phone_verified_at']))?></small><?php endif;?></td>
  <td class="mbl-c-parent" data-label="<?=e(ma('ml_col_parent'))?>"><?php if($member['parent_id']):?><strong><?=e($member['parent_name']?:'#'.$member['parent_id'])?></strong><?php if($member['parent_phone']):?><br><small class="muted"><?=e($member['parent_phone'])?></small><?php endif;?><?php else:?><span class="muted"><?=e(ma('ml_none'))?></span><?php endif;?></td>
  <td class="mbl-num mbl-c-children" data-label="<?=e(ma('ml_col_children'))?>"><?=e(ma('ml_children_value',['count'=>number_format((int)$member['children'])]))?></td>
  <td class="mbl-num mbl-c-points" data-label="<?=e(ma('ml_col_points'))?>"><button type="button" class="mbl-points" data-points-log="<?=(int)$member['id']?>" data-member-name="<?=e($member['display_name']?:$member['phone'])?>" title="<?=e(ma('ml_log_open'))?>"><?=number_format((int)$member['points_balance'])?></button></td>
  <td class="mbl-num mbl-c-orders" data-label="<?=e(ma('ml_col_orders'))?>"><strong><?=e(ma('ml_orders_value',['count'=>number_format((int)$member['order_count'])]))?></strong><br><small class="muted"><?=$money($member['order_spent'])?></small></td>
  <td class="mbl-c-ipos" data-label="<?=e(ma('ml_col_ipos'))?>"><?=e($iposLabel($member))?><br><small class="muted"><?=e(ma('last_sync'))?>: <?=e($memberDate($sync['last_success_at']??null))?></small></td>
  <td class="mbl-c-actions">
    <?php if(!$member['phone_verified_at']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="verify"><input type="hidden" name="member_id" value="<?=(int)$member['id']?>"><button class="btn small" type="submit"><?=e(ma('verify'))?></button></form>
    <?php else:?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sync"><input type="hidden" name="member_id" value="<?=(int)$member['id']?>"><button class="btn small" type="submit"><?=e(ma('sync'))?></button></form><?php endif;?>
  </td>
</tr>
<?php endforeach;?>
</tbody></table></div>

<?php /* ---------- Phân trang ---------- */ ?>
<nav class="mbl-pager">
  <span class="muted"><?=e(ma('ml_showing',['from'=>number_format(($page-1)*$perPage+1),'to'=>number_format(min($total,$page*$perPage)),'total'=>number_format($total)]))?></span>
  <span>
    <?php if($page>1):?><a class="btn small" href="<?=e($listUrl(['page'=>$page-1]))?>">← <?=e(ma('ml_prev'))?></a><?php endif;?>
    <strong><?=$page?> / <?=$pages?></strong>
    <?php if($page<$pages):?><a class="btn small" href="<?=e($listUrl(['page'=>$page+1]))?>"><?=e(ma('ml_next'))?> →</a><?php endif;?>
  </span>
</nav>
<?php endif;?>

<?php /* ---------- Popup lịch sử điểm nhóm (nội dung tải từ ?points_log=ID) ---------- */ ?>
<dialog id="mbl-points" class="mbl-dialog" aria-labelledby="mbl-points-title">
  <div class="mbl-dialog-head"><strong id="mbl-points-title"></strong><button type="button" class="btn small" data-close><?=e(ma('ml_log_close'))?></button></div>
  <div class="mbl-dialog-body">
    <p class="muted" data-slot="summary"></p>
    <div class="table-wrap"><table><thead><tr><th><?=e(ma('ml_log_time'))?></th><th class="mbl-num"><?=e(ma('ml_log_points'))?></th><th><?=e(ma('ml_log_reason'))?></th><th><?=e(ma('ml_log_order'))?></th><th><?=e(ma('ml_log_source'))?></th></tr></thead><tbody data-slot="rows"></tbody></table></div>
  </div>
</dialog>

<style>
.mbl-filters{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;align-items:end;margin-top:12px}
.mbl-filters label{display:grid;gap:6px;font-size:13px;font-weight:700}
.mbl-filters .mbl-wide{grid-column:span 2}
.mbl-actions{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px}
.mbl-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.mbl-metric{display:grid;gap:4px;align-content:start;background:#fff;border:1px solid var(--line);border-left-width:4px;border-radius:15px;padding:16px;min-width:0}
.mbl-metric span{color:var(--muted);font-size:13px}.mbl-metric b{font-size:22px;overflow-wrap:anywhere}.mbl-metric small{color:var(--muted);font-size:12px}
.mbl-metric.good{border-left-color:#16a34a}.mbl-metric.warn{border-left-color:#d97706}
.mbl-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.mbl-in{color:#16a34a}.mbl-out{color:#dc2626}
.mbl-c-actions form{margin:0}.mbl-c-actions .btn{white-space:nowrap}
/* Compact row buttons on desktop; beats the shared admin-ui .btn sizing (44px, 14px, wrapping). Mobile keeps its full-width rule. */
@media(min-width:641px){.admin-shell .admin-container .mbl-c-actions .btn{min-height:0;padding:5px 10px;font-size:12px;line-height:1.3;border-radius:8px;white-space:nowrap}}.mbl-pager{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px}
.mbl-pager>span:last-child{display:flex;align-items:center;gap:8px}
.mbl-points{appearance:none;border:1px solid #0284c7;background:#0284c7;color:#fff;border-radius:9px;padding:5px 12px;font:inherit;font-weight:800;cursor:pointer;min-width:44px}
.mbl-points:hover{background:#0369a1;border-color:#0369a1}.mbl-points:focus-visible{outline:3px solid #7dd3fc;outline-offset:2px}
.mbl-dialog{width:min(860px,calc(100vw - 24px));max-height:calc(100vh - 24px);padding:0;border:1px solid var(--line);border-radius:16px}
.mbl-dialog::backdrop{background:rgba(17,24,39,.45)}
.mbl-dialog-head{position:sticky;top:0;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);background:#fff}
.mbl-dialog-head strong{font-size:17px;overflow-wrap:anywhere}.mbl-dialog-body{padding:12px 16px 16px}
.mbl-dialog .table-wrap td{white-space:normal}.mbl-dialog .mbl-cancelled{color:#b42318;font-size:12px;display:block}
@media(max-width:900px){.mbl-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){
  .mbl-filters{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.mbl-filters .mbl-wide{grid-column:1/-1}
  .mbl-actions .btn{flex:1 1 auto}
  .mbl-metric{padding:12px}.mbl-metric b{font-size:22px}.mbl-metric span{font-size:16px}.mbl-metric small{font-size:15px}
  /* Thẻ thành viên trên mobile: lưới 2 cột
     [Tên + #id          | Xác nhận ĐT ]
     [Điện thoại         | Ngày đăng ký]
     [Người giới thiệu   | Cấp dưới    ]
     [Điểm               | Đơn website ]
     [iPOS                             ]
     [Nút thao tác                     ] */
  body.admin-shell .admin-container .mbl-table:has(td[data-label]) tr{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);grid-template-areas:"name verify" "phone joined" "parent children" "points orders" "ipos ipos" "actions actions";gap:0;padding:4px 0;margin-bottom:12px}
  body.admin-shell .admin-container .mbl-table:has(td[data-label]) td{display:block;padding:8px 14px;border:0;text-align:left;min-width:0;font-size:18px}
  body.admin-shell .admin-container .mbl-table td[data-label]:before{font-size:15px;margin-bottom:4px}
  .mbl-table .mbl-c-name{grid-area:name;padding-top:12px!important}.mbl-table .mbl-c-verify{grid-area:verify;text-align:right!important;padding-top:12px!important}
  .mbl-table .mbl-c-phone{grid-area:phone}.mbl-table .mbl-c-joined{grid-area:joined}
  .mbl-table .mbl-c-parent{grid-area:parent}.mbl-table .mbl-c-children{grid-area:children}
  .mbl-table .mbl-c-points{grid-area:points}.mbl-table .mbl-c-orders{grid-area:orders}
  .mbl-table .mbl-c-ipos{grid-area:ipos}
  .mbl-table .mbl-c-actions{grid-area:actions;padding-bottom:12px!important}.mbl-table .mbl-c-actions .btn{display:flex;width:100%;font-size:18px;padding:12px 16px;white-space:normal}
  /* Tên + trạng thái xác nhận tự rõ nghĩa → bỏ nhãn nhỏ cho gọn */
  .mbl-table :is(.mbl-c-name,.mbl-c-verify)[data-label]:before{display:none}
  .mbl-table .mbl-c-name strong{font-size:19px;overflow-wrap:anywhere}
  .mbl-table td small{font-size:16px}.mbl-table .status{font-size:16px}
  .mbl-table .mbl-points{font-size:18px;padding:6px 16px}
  .mbl-dialog{width:100vw;max-height:100vh;height:100vh;border-radius:0}
}
</style>
<script>
// Bấm số điểm nhóm → popup lịch sử cộng điểm. Dữ liệu JSON dựng bằng textContent (không chèn HTML).
(function(){
  const dialog=document.getElementById('mbl-points');if(!dialog||!dialog.showModal)return;
  const t=<?=json_encode(['title'=>ma('ml_log_title'),'total'=>ma('ml_log_total'),'empty'=>ma('ml_log_empty'),'loading'=>ma('ml_log_loading'),'error'=>ma('ml_log_error'),'cancelled'=>ma('ml_log_cancelled'),
      'time'=>ma('ml_log_time'),'points'=>ma('ml_log_points'),'reason'=>ma('ml_log_reason'),'order'=>ma('ml_log_order'),'source'=>ma('ml_log_source'),'group'=>ma('ml_log_group')],JSON_UNESCAPED_UNICODE)?>;
  const title=document.getElementById('mbl-points-title'),summary=dialog.querySelector('[data-slot="summary"]'),body=dialog.querySelector('[data-slot="rows"]');
  const cell=(text,label)=>{const td=document.createElement('td');td.textContent=text;if(label)td.dataset.label=label;return td;};
  let request=0;
  document.addEventListener('click',function(event){
    const button=event.target.closest('[data-points-log]');if(!button)return;
    const id=++request;title.textContent=t.title.replace('{name}',button.dataset.memberName||'');summary.textContent=t.loading;body.replaceChildren();dialog.showModal();
    fetch(<?=json_encode(base_url('admin/members.php?points_log='))?>+encodeURIComponent(button.dataset.pointsLog),{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(r=>{if(!r.ok)throw new Error(r.status);return r.json();})
      .then(data=>{
        if(id!==request)return;const rows=data.rows||[];
        summary.textContent=rows.length?t.total.replace('{count}',rows.length).replace('{points}',rows.reduce((s,r)=>s+r.points,0)):t.empty;
        rows.forEach(r=>{
          const tr=document.createElement('tr');const order=cell('',t.order);
          if(r.order_url){const a=document.createElement('a');a.href=r.order_url;a.target='_blank';a.rel='noopener';a.textContent=r.order;order.append(a);}else order.textContent=r.order||'—';
          if(r.group){const g=document.createElement('small');g.className='muted';g.style.display='block';g.textContent=t.group+': '+r.group;order.append(g);}
          if(r.cancelled){const c=document.createElement('span');c.className='mbl-cancelled';c.textContent=t.cancelled;order.append(c);}
          const pts=cell((r.points>0?'+':'')+r.points,t.points);pts.className='mbl-num '+(r.points<0?'mbl-out':'mbl-in');
          tr.append(cell(r.time,t.time),pts,cell(r.reason,t.reason),order,cell(r.source||'—',t.source));body.append(tr);
        });
      })
      .catch(()=>{if(id===request)summary.textContent=t.error;});
  });
  dialog.querySelector('[data-close]').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
})();
</script>
<?php admin_footer(); ?>
