<?php
/**
 * Trung tâm đơn hàng (admin) — bố cục giống trang giao dịch: bộ lọc → thẻ thống kê → bảng → phân trang,
 * kèm xuất Excel (.xlsx). Nút Xem mở order.php trong popup (?embed=1). Không dùng component dùng chung; mọi thứ nằm trong file này.
 *
 * Cấu trúc 1 đơn hàng (bảng orders) mà trang này đọc:
 *  - Định danh:   id, order_no, created_at (TIMESTAMP giờ phiên DB, hiển thị nguyên giá trị)
 *  - Khách hàng:  customer_name, phone, address, order_note, member_id (nếu khách đăng nhập)
 *  - Loại đơn:    group_room_id + group_title  → đơn nhóm; NULL → đơn cá nhân
 *  - Thanh toán:  payment_method = transfer | cash | split (split chỉ có ở đơn nhóm chia tiền)
 *  - Tiền:        subtotal_amount − discount_amount (coupon_code) = total_amount
 *  - Trạng thái:  status = new → accepted → done, hoặc cancelled (xem htow_targets)
 *  - Cửa hàng:    dispatch_store_id / dispatch_store_name; NULL = trụ sở, chưa phân công
 *  - Email:       notification_email_status = pending | sent | failed | partial
 * Thực thu KHÔNG lấy từ status mà từ htp_list_sql(): transfer_order_receipts (đơn cá nhân)
 * + group_members.payment_* (đơn nhóm) → received_amount, refunded_amount, financial_state.
 */
require __DIR__.'/../lib/bootstrap.php'; require __DIR__.'/../lib/layout.php'; require_once __DIR__.'/../lib/dispatch_views.php'; require_once __DIR__.'/../lib/order_workflow.php'; require_admin(); hds_view_require_staff(base_url('admin/login.php'));
header('Cache-Control: private, no-store');

// -------------------------------------------------------------
// 1. BỘ LỌC — đọc từ GET, chỉ nhận chuỗi; htp_list_sql() tự kiểm tra từng giá trị hợp lệ
// -------------------------------------------------------------
$filters=[];
foreach(['q','status','payment_state','method','type','store','date_from','date_to'] as $key)$filters[$key]=is_string($_GET[$key]??null)?trim($_GET[$key]):'';
$sorts=['newest'=>'o.id DESC','oldest'=>'o.id ASC','amount_desc'=>'o.total_amount DESC, o.id DESC','amount_asc'=>'o.total_amount ASC, o.id DESC'];
$sort=is_string($_GET['sort']??null)&&isset($sorts[$_GET['sort']])?$_GET['sort']:'newest';
$perPage=in_array((int)($_GET['per_page']??0),[20,50,100],true)?(int)$_GET['per_page']:20;
$page=max(1,(int)($_GET['page']??1));
// Đơn có thể đã gán cho cửa hàng nay đã tắt, nên danh sách lọc lấy cả cửa hàng đang tắt.
try{$stores=hyg_all('SELECT id,name,enabled FROM dispatch_stores ORDER BY enabled DESC,name');}catch(Throwable $error){$stores=[];}
// Giữ nguyên bộ lọc hiện tại, chỉ đổi vài tham số (trang, trạng thái…).
$listUrl=function(array $overrides=[]):string{
    $params=array_merge(array_diff_key($_GET,['page'=>1,'export'=>1]),$overrides);
    return base_url('admin/index.php').'?'.http_build_query(array_filter($params,fn($v)=>$v!==''&&$v!==null));
};
[$listSql,$listArgs]=htp_list_sql($filters);

// -------------------------------------------------------------
// 2. XUẤT EXCEL (.xlsx) — theo đúng bộ lọc + sắp xếp đang xem; operator không được xuất như trang đối soát
// -------------------------------------------------------------
if(($_GET['export']??'')==='xlsx'){
    if(admin_is_operator()){http_response_code(403);exit(e(pwf('export_forbidden')));}
    require_once __DIR__.'/../lib/xlsx_export.php';
    $rows=(function()use($listSql,$listArgs,$sorts,$sort){
        $st=db()->prepare($listSql.' ORDER BY '.$sorts[$sort]);$st->execute($listArgs);
        while($o=$st->fetch())yield [$o['order_no'],$o['created_at'],$o['group_room_id']?pwf('type_group'):pwf('type_personal'),$o['group_title']??'',$o['customer_name'],$o['phone'],$o['address'],payment_label((string)$o['payment_method']),status_label((string)$o['status']),pwf((string)$o['financial_state']),
            $o['subtotal_amount'],$o['discount_amount'],$o['coupon_code']??'',$o['total_amount'],$o['received_amount'],$o['refunded_amount'],(float)$o['received_amount']-(float)$o['refunded_amount'],
            $o['dispatch_store_name']?:pwf('list_store_hq'),order_notification_status_label((string)($o['notification_email_status']??'pending')),$o['order_note']??''];
    })();
    // Khối tiêu đề đầu file: tên danh sách, giờ xuất + số đơn, và các bộ lọc đang áp dụng.
    $storeName=$filters['store']==='hq'?pwf('list_store_hq'):'';foreach($stores as $s)if((string)$s['id']===$filters['store'])$storeName=$s['name'];
    $applied=array_filter([
        $filters['q']!==''?pwf('list_search').': "'.$filters['q'].'"':'',
        $filters['status']!==''?t('status').': '.status_label($filters['status']):'',
        $filters['payment_state']!==''?pwf('payment_state').': '.pwf($filters['payment_state']):'',
        $filters['method']!==''?pwf('list_method').': '.payment_label($filters['method']):'',
        $filters['type']!==''?pwf('list_type').': '.pwf('type_'.$filters['type']):'',
        $storeName!==''?pwf('list_store').': '.$storeName:'',
        $filters['date_from']!==''?pwf('list_date_from').': '.$filters['date_from']:'',
        $filters['date_to']!==''?pwf('list_date_to').': '.$filters['date_to']:'',
    ]);
    $title=[pwf('xls_title_orders').' — '.store_name(),strtr(pwf('xls_exported'),['{time}'=>date('Y-m-d H:i')]),pwf('xls_filters').': '.($applied?implode(' · ',$applied):pwf('xls_no_filter'))];
    // Cột 10,11,13–16 là tiền: ghi dạng số (có dấu phân cách hàng nghìn) để Excel cộng/lọc được.
    hxl_send('don-hang-'.date('Ymd-His').'.xlsx',[t('order_no'),t('time'),pwf('list_type'),pwf('detail_group'),t('customer'),t('phone'),t('address'),t('payment'),t('status'),pwf('payment_state'),t('subtotal'),pwf('detail_discount'),t('coupon'),t('amount'),pwf('received_amount'),pwf('refunded_amount'),pwf('net_received'),pwf('list_store'),t('email_status'),t('order_note')],$rows,[10,11,13,14,15,16],$title);
}

// -------------------------------------------------------------
// 3. THỐNG KÊ — tính trên cùng bộ lọc với bảng (thẻ số liệu không được lệch với danh sách)
// -------------------------------------------------------------
$unpaidStates="'unpaid','requested','partial','rejected'";
$stats=hyg_one("SELECT COUNT(*) total,COALESCE(SUM(x.status<>'cancelled'),0) active_count,
    COALESCE(SUM(CASE WHEN x.status<>'cancelled' THEN x.total_amount END),0) revenue,
    COALESCE(SUM(x.received_amount-x.refunded_amount),0) net,
    COALESCE(SUM(x.status<>'cancelled' AND x.financial_state IN($unpaidStates)),0) outstanding_count,
    COALESCE(SUM(CASE WHEN x.status<>'cancelled' AND x.financial_state IN($unpaidStates) THEN GREATEST(x.receipt_due-(x.received_amount-x.refunded_amount),0) END),0) outstanding_amount,
    COALESCE(SUM(x.status='cancelled'),0) cancelled_count,COALESCE(SUM(CASE WHEN x.status='cancelled' THEN x.total_amount END),0) cancelled_amount
    FROM ($listSql) x",$listArgs)?:[];
// Số đơn theo từng trạng thái: giữ các bộ lọc khác, bỏ riêng bộ lọc trạng thái để các tab luôn có số.
[$countSql,$countArgs]=htp_list_sql(array_merge($filters,['status'=>'']));
$counts=[];foreach(hyg_all("SELECT x.status,COUNT(*) c FROM ($countSql) x GROUP BY x.status",$countArgs) as $r)$counts[$r['status']]=(int)$r['c'];

// -------------------------------------------------------------
// 4. DANH SÁCH TRANG HIỆN TẠI
// -------------------------------------------------------------
$total=(int)($stats['total']??0);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
$orders=htp_list_query($filters,$perPage,($page-1)*$perPage,$sorts[$sort]);$dispatchList=hds_order_dispatch_list($orders);
$money=fn($v)=>e(currency()).money($v);

admin_header(t('order_center'));?>

<?php /* ---------- Bộ lọc ---------- */ ?>
<section class="panel ma-panel">
  <form method="get" action="<?=e(base_url('admin/index.php'))?>" class="aol-filters">
    <label class="aol-wide"><?=e(pwf('list_search'))?><input type="search" name="q" value="<?=e($filters['q'])?>" placeholder="<?=e(pwf('list_search_hint'))?>"></label>
    <label><?=e(t('status'))?><select name="status"><option value=""><?=e(pwf('list_status_all'))?></option><?php foreach(['new','accepted','done','cancelled'] as $key):?><option value="<?=$key?>" <?=$filters['status']===$key?'selected':''?>><?=e(status_label($key))?></option><?php endforeach;?></select></label>
    <label><?=e(pwf('payment_state'))?><select name="payment_state"><option value=""><?=e(pwf('filter_all'))?></option><?php foreach(['unpaid','requested','partial','confirmed','overpaid','refund_pending','refunded','legacy','not_required','rejected'] as $key):?><option value="<?=$key?>" <?=$filters['payment_state']===$key?'selected':''?>><?=e(pwf($key))?></option><?php endforeach;?></select></label>
    <label><?=e(pwf('list_method'))?><select name="method"><option value=""><?=e(pwf('list_method_all'))?></option><?php foreach(['transfer','cash','split'] as $key):?><option value="<?=$key?>" <?=$filters['method']===$key?'selected':''?>><?=e(payment_label($key))?></option><?php endforeach;?></select></label>
    <label><?=e(pwf('list_type'))?><select name="type"><option value=""><?=e(pwf('list_type_all'))?></option><option value="personal" <?=$filters['type']==='personal'?'selected':''?>><?=e(pwf('type_personal'))?></option><option value="group" <?=$filters['type']==='group'?'selected':''?>><?=e(pwf('type_group'))?></option></select></label>
    <label><?=e(pwf('list_store'))?><select name="store"><option value=""><?=e(pwf('list_store_all'))?></option><option value="hq" <?=$filters['store']==='hq'?'selected':''?>><?=e(pwf('list_store_hq'))?></option><?php foreach($stores as $s):?><option value="<?=(int)$s['id']?>" <?=$filters['store']===(string)$s['id']?'selected':''?>><?=e($s['name'])?><?=$s['enabled']?'':' ('.e(pwf('list_store_off')).')'?></option><?php endforeach;?></select></label>
    <label><?=e(pwf('list_date_from'))?><input type="date" name="date_from" value="<?=e($filters['date_from'])?>"></label>
    <label><?=e(pwf('list_date_to'))?><input type="date" name="date_to" value="<?=e($filters['date_to'])?>"></label>
    <label><?=e(pwf('list_sort'))?><select name="sort"><?php foreach(['newest'=>'sort_newest','oldest'=>'sort_oldest','amount_desc'=>'sort_amount_desc','amount_asc'=>'sort_amount_asc'] as $key=>$label):?><option value="<?=$key?>" <?=$sort===$key?'selected':''?>><?=e(pwf($label))?></option><?php endforeach;?></select></label>
    <label><?=e(pwf('list_per_page'))?><select name="per_page"><?php foreach([20,50,100] as $n):?><option value="<?=$n?>" <?=$perPage===$n?'selected':''?>><?=$n?></option><?php endforeach;?></select></label>
    <div class="aol-actions">
      <button class="btn primary ma-btn" type="submit"><?=e(pwf('filter'))?></button>
      <a class="btn ma-btn" href="<?=e(base_url('admin/index.php'))?>"><?=e(pwf('list_reset'))?></a>
      <?php if(!admin_is_operator()):?>
        <a class="btn ma-btn" href="<?=e($listUrl(['export'=>'xlsx']))?>"><?=e(pwf('list_export_orders'))?></a>
        <a class="btn ma-btn" href="<?=e(base_url('admin/payment_export.php'))?>"><?=e(pwf('export'))?></a>
      <?php endif;?>
    </div>
  </form>
</section>

<?php /* ---------- Tab trạng thái đơn (số đếm theo bộ lọc, bấm để lọc nhanh) ---------- */ ?>
<div class="stats">
  <?php foreach(['new'=>t('new_order'),'accepted'=>t('accepted'),'done'=>t('completed'),'cancelled'=>t('cancelled')] as $key=>$label):?>
    <a href="<?=e($listUrl(['status'=>$filters['status']===$key?'':$key]))?>" class="<?=$filters['status']===$key?'aol-active':''?>"><b><?=$counts[$key]??0?></b><span><?=e($label)?></span></a>
  <?php endforeach;?>
</div>

<?php /* ---------- Thẻ tiền: giá trị đơn, thực thu ròng, chưa thu đủ, đã hủy ---------- */ ?>
<div class="aol-metrics">
  <div class="aol-metric"><span><?=e(pwf('metric_revenue'))?></span><b><?=$money($stats['revenue']??0)?></b><small><?=e(str_replace('{count}',number_format((int)($stats['active_count']??0)),pwf('metric_orders_sub')))?></small></div>
  <div class="aol-metric good"><span><?=e(pwf('metric_net'))?></span><b><?=$money($stats['net']??0)?></b><small><?=e(pwf('metric_orders'))?>: <?=number_format($total)?></small></div>
  <div class="aol-metric warn"><span><?=e(pwf('metric_outstanding'))?></span><b><?=number_format((int)($stats['outstanding_count']??0))?></b><small><?=e(strtr(pwf('metric_outstanding_sub'),['{count}'=>number_format((int)($stats['outstanding_count']??0)),'{amount}'=>currency().money($stats['outstanding_amount']??0)]))?></small></div>
  <div class="aol-metric bad"><span><?=e(pwf('metric_cancelled'))?></span><b><?=number_format((int)($stats['cancelled_count']??0))?></b><small><?=$money($stats['cancelled_amount']??0)?></small></div>
</div>

<?php /* ---------- Bảng đơn hàng: mỗi cột ứng với một nhóm trường của đơn (xem chú thích đầu file) ----------
   Thời gian      ← created_at (+ #id)
   Mã đơn         ← order_no (link trang chi tiết) + loại đơn: group_room_id (Cá nhân / Nhóm) + group_title
   Khách hàng     ← customer_name + phone
   Thanh toán     ← payment_method + financial_state (trạng thái thực thu)
   Cửa hàng       ← dispatch_store_* (+ cửa hàng đề xuất nếu chưa phân công)
   Tổng tiền      ← total_amount (+ discount_amount / coupon_code)
   Thực thu ròng  ← received_amount − refunded_amount
   Trạng thái     ← status
   Email          ← notification_email_status
*/ ?>
<div class="table-wrap aol-table"><table><thead><tr>
  <th><?=e(t('time'))?></th><th><?=e(t('order_no'))?></th><th><?=e(t('customer'))?></th><th><?=e(t('payment'))?></th>
  <th><?=e(pwf('list_store'))?></th><th class="aol-num"><?=e(t('amount'))?></th><th class="aol-num"><?=e(pwf('net_received'))?></th><th><?=e(t('status'))?></th><th><?=e(t('email_status'))?></th><th></th>
</tr></thead><tbody>
<?php if(!$orders):?><tr><td colspan="10" class="muted"><?=e(pwf('list_empty'))?></td></tr><?php endif;?>
<?php foreach($orders as $o):$emailStatus=(string)($o['notification_email_status']??'pending');$net=(float)$o['received_amount']-(float)$o['refunded_amount'];?>
<tr>
  <td class="aol-c-time" data-label="<?=e(t('time'))?>"><strong><?=e($o['created_at'])?></strong><br><small class="muted">#<?=(int)$o['id']?></small></td>
  <td class="aol-c-code" data-label="<?=e(t('order_no'))?>"><a class="aol-code" href="order.php?id=<?=(int)$o['id']?>" data-order-popup="<?=e($o['order_no'])?>"><?=e($o['order_no'])?></a><br><span class="aol-type <?=$o['group_room_id']?'group':'personal'?>"><?=e($o['group_room_id']?pwf('type_group'):pwf('type_personal'))?></span><?php if($o['group_room_id']&&($o['group_title']??'')!==''):?> <small class="muted"><?=e($o['group_title'])?></small><?php endif;?></td>
  <td class="aol-c-customer" data-label="<?=e(t('customer'))?>"><strong><?=e($o['customer_name'])?></strong><br><small class="muted"><?=e($o['phone'])?></small></td>
  <td class="aol-c-pay" data-label="<?=e(t('payment'))?>"><span class="aol-method <?=e((string)$o['payment_method'])?>"><?=e(payment_label((string)$o['payment_method']))?></span><br><small class="muted"><?=e(pwf((string)$o['financial_state']))?></small></td>
  <td class="aol-c-store" data-label="<?=e(pwf('list_store'))?>"><?=hds_render_order_badge($o,$dispatchList[(int)$o['id']]??[])?></td>
  <td class="aol-num aol-c-amount" data-label="<?=e(t('amount'))?>"><strong><?=$money($o['total_amount'])?></strong><?php if((float)$o['discount_amount']>0):?><br><small class="muted">-<?=$money($o['discount_amount'])?><?=$o['coupon_code']?' · '.e($o['coupon_code']):''?></small><?php endif;?></td>
  <td class="aol-num aol-c-net" data-label="<?=e(pwf('net_received'))?>"><strong class="<?=$net>0?'aol-in':''?>"><?=$money($net)?></strong></td>
  <td class="aol-c-status" data-label="<?=e(t('status'))?>"><span class="status <?=e($o['status'])?>"><?=e(status_label($o['status']))?></span></td>
  <td class="aol-c-email" data-label="<?=e(t('email_status'))?>"><span class="status <?=e($emailStatus==='sent'?'done':($emailStatus==='failed'?'cancelled':'new'))?>"><?=e(order_notification_status_label($emailStatus))?></span></td>
  <td class="aol-row-actions aol-c-actions"><a class="btn small" href="order.php?id=<?=(int)$o['id']?>" data-order-popup="<?=e($o['order_no'])?>"><?=e(pwf('list_view_detail'))?></a></td>
</tr>
<?php endforeach;?>
</tbody></table></div>

<?php /* ---------- Phân trang ---------- */ ?>
<?php if($total>0):?>
<nav class="aol-pager">
  <span class="muted"><?=e(strtr(pwf('list_showing'),['{from}'=>number_format(($page-1)*$perPage+1),'{to}'=>number_format(min($total,$page*$perPage)),'{total}'=>number_format($total)]))?></span>
  <span>
    <?php if($page>1):?><a class="btn small" href="<?=e($listUrl(['page'=>$page-1]))?>">← <?=e(pwf('list_prev'))?></a><?php endif;?>
    <strong><?=$page?> / <?=$pages?></strong>
    <?php if($page<$pages):?><a class="btn small" href="<?=e($listUrl(['page'=>$page+1]))?>"><?=e(pwf('list_next'))?> →</a><?php endif;?>
  </span>
</nav>
<?php endif;?>

<?php /* ---------- Popup chi tiết đơn: nhúng chính order.php (?embed=1) nên giữ đủ mọi thao tác của trang Xem ---------- */ ?>
<dialog id="aol-order" class="aol-dialog" aria-labelledby="aol-order-title">
  <div class="aol-dialog-head">
    <strong id="aol-order-title"></strong>
    <span><a class="btn small" data-slot="page" target="_blank" rel="noopener"><?=e(pwf('detail_open'))?> ↗</a> <button type="button" class="btn small" data-close><?=e(pwf('detail_close'))?></button></span>
  </div>
  <iframe data-slot="frame" title="<?=e(t('order_no'))?>"></iframe>
</dialog>

<style>
.aol-filters{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;align-items:end}
.aol-filters label{display:grid;gap:6px;font-size:13px;font-weight:700}
.aol-filters .aol-wide{grid-column:span 2}
.aol-actions{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px}
.stats a.aol-active{border-color:#111827;box-shadow:inset 0 0 0 1px #111827}
.aol-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.aol-metric{display:grid;gap:4px;background:#fff;border:1px solid var(--line);border-left-width:4px;border-radius:15px;padding:16px;min-width:0}
.aol-metric span{color:var(--muted);font-size:13px}.aol-metric b{font-size:22px;overflow-wrap:anywhere}.aol-metric small{color:var(--muted);font-size:12px}
.aol-metric.good{border-left-color:#16a34a}.aol-metric.warn{border-left-color:#d97706}.aol-metric.bad{border-left-color:#dc2626}
.aol-type{display:inline-block;margin-top:4px;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:700}
.aol-type.personal{background:#e0f2fe;color:#0369a1}.aol-type.group{background:#fef3c7;color:#92400e}
.aol-row-actions{white-space:nowrap}.aol-row-actions .btn{white-space:nowrap;width:max-content}
.aol-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.aol-in{color:#16a34a}
.aol-code{color:#0284c7;font-weight:700}
.aol-method{display:inline-block;padding:2px 7px;border-radius:6px;font-size:12px;font-weight:700;border:1px solid}
.aol-method.transfer{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.aol-method.cash{background:#fff7ed;color:#c2410c;border-color:#ffedd5}.aol-method.split{background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe}
.aol-pager{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px}
.aol-pager>span:last-child{display:flex;align-items:center;gap:8px}
@media(max-width:900px){.aol-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
.aol-dialog{width:min(1100px,calc(100vw - 24px));height:calc(100vh - 24px);max-height:none;padding:0;border:1px solid var(--line);border-radius:16px;overflow:hidden}
.aol-dialog[open]{display:flex;flex-direction:column}
.aol-dialog::backdrop{background:rgba(17,24,39,.45)}
.aol-dialog-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);background:#fff}
.aol-dialog-head strong{font-size:17px;overflow-wrap:anywhere}.aol-dialog-head>span{display:flex;gap:8px;flex:none}
.aol-dialog iframe{flex:1;width:100%;border:0;background:#f6f7f9}
@media(max-width:900px){.aol-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){
  .aol-filters{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.aol-filters .aol-wide{grid-column:1/-1}
  .aol-actions .btn{flex:1 1 auto}
  .aol-metric{padding:12px}.aol-metric b{font-size:22px}.aol-metric span{font-size:16px}.aol-metric small{font-size:15px}
  .aol-dialog{width:100vw;height:100vh;border-radius:0}
  /* Thẻ đơn trên mobile: lưới 10 cột (hàng đầu 8:2, các hàng sau 5:5)
     [Mã đơn + loại (80%)       | Trạng thái (20%)]
     [Khách hàng + SĐT               ]
     [Thanh toán         | Cửa hàng  ]
     [Số tiền            | Thực thu  ]
     [Thời gian          | Email     ]
     [Nút Xem                        ] */
  body.admin-shell .admin-container .aol-table:has(td[data-label]) tr{display:grid;grid-template-columns:repeat(10,minmax(0,1fr));grid-template-areas:"code code code code code code code code status status" "customer customer customer customer customer customer customer customer customer customer" "pay pay pay pay pay store store store store store" "amount amount amount amount amount net net net net net" "time time time time time email email email email email" "actions actions actions actions actions actions actions actions actions actions";gap:0;padding:4px 0;margin-bottom:12px}
  body.admin-shell .admin-container .aol-table:has(td[data-label]) td{display:block;padding:8px 14px;border:0;text-align:left;min-width:0;font-size:18px}
  body.admin-shell .admin-container .aol-table td[data-label]:before{font-size:15px;margin-bottom:4px}
  .aol-table .aol-c-code{grid-area:code;padding-top:12px!important}.aol-table .aol-c-status{grid-area:status;text-align:right!important;padding:12px 14px 8px 0!important}.aol-table .aol-c-status .status{white-space:nowrap}
  .aol-table .aol-c-customer{grid-area:customer;border-bottom:1px solid var(--line)!important;padding-bottom:12px!important}
  .aol-table .aol-c-pay{grid-area:pay;padding-top:12px!important}.aol-table .aol-c-store{grid-area:store;padding-top:12px!important}
  .aol-table .aol-c-amount{grid-area:amount}.aol-table .aol-c-net{grid-area:net}
  .aol-table .aol-c-time{grid-area:time}.aol-table .aol-c-email{grid-area:email}
  .aol-table .aol-c-actions{grid-area:actions;padding-bottom:12px!important}.aol-table .aol-c-actions .btn{display:flex;width:100%}
  /* Mã đơn, trạng thái, khách hàng tự rõ nghĩa → bỏ nhãn nhỏ để thẻ gọn hơn */
  .aol-table :is(.aol-c-code,.aol-c-status,.aol-c-customer)[data-label]:before{display:none}
  .aol-table .aol-c-code .aol-code{font-size:19px;overflow-wrap:anywhere}
  .aol-table .aol-c-time strong{font-weight:600;font-size:17px}
  .aol-table .aol-c-store .hds-list-summary{font-size:18px}.aol-table .aol-c-store .hds-list-summary>span{display:none}.aol-table .aol-c-store .hds-list-summary>small{font-size:16px}
  .aol-table td small{font-size:16px}.aol-table :is(.status,.aol-method){font-size:16px}.aol-table .aol-type{font-size:15px}.aol-table .aol-c-actions .btn{font-size:18px;padding:12px 16px}
}
</style>
<script>
// Bấm "Xem" / mã đơn → mở order.php?embed=1 trong popup. Ctrl/Shift/chuột giữa vẫn mở tab như link thường.
// Nếu trong popup có thao tác (form gửi đi → iframe tải lại), đóng popup sẽ tải lại danh sách cho số liệu mới.
(function(){
  const dialog=document.getElementById('aol-order');if(!dialog||!dialog.showModal)return;
  const frame=dialog.querySelector('[data-slot="frame"]'),page=dialog.querySelector('[data-slot="page"]'),title=document.getElementById('aol-order-title');
  const titleText=<?=json_encode(pwf('detail_title'),JSON_UNESCAPED_UNICODE)?>;let loads=0;
  frame.addEventListener('load',function(){if(frame.getAttribute('src'))loads++;});
  document.addEventListener('click',function(event){
    const link=event.target.closest('a[data-order-popup]');
    if(!link||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    event.preventDefault();
    const url=new URL(link.href,location.href);page.href=url.href;url.searchParams.set('embed','1');
    title.textContent=titleText.replace('{no}',link.dataset.orderPopup);loads=0;frame.src=url.href;dialog.showModal();
  });
  const close=function(){dialog.close();};
  dialog.querySelector('[data-close]').addEventListener('click',close);
  dialog.addEventListener('click',function(event){if(event.target===dialog)close();});
  dialog.addEventListener('close',function(){frame.src='about:blank';if(loads>1)location.reload();});
})();
</script>
<?php admin_footer();?>
