<?php
/**
 * Store partners: rows of the `partners` table (shared with the new backend) bound to one dispatch store.
 * They sign in at partner/login.php with their own session keys and never get admin_id, so haa_current()
 * and every headquarters page keep treating them as signed out. A partner only sees orders dispatched to
 * its store, accepts/completes them, or hands a new order back; history rows carry partner_id with admin_id NULL.
 * This file only reads/writes partner rows; the partners schema itself is owned by the new backend's migrations.
 */
if(!function_exists('db')){http_response_code(404);exit;}
class HpaError extends RuntimeException {}
function hpa_fail(string $key): never {throw new HpaError($key);}
function hpa_url(string $path=''): string {return base_url('partner/'.ltrim($path,'/'));}
function hpa_table_ready(): bool {
 static $ready=null;
 return $ready??=(bool)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='partners'")->fetchColumn();
}
function hpa_clear_session(): void {unset($_SESSION['partner_id'],$_SESSION['partner_auth_version']);}
function hpa_current(): ?array {
 static $cache=null;
 $id=(int)($_SESSION['partner_id']??0);if($id<1||!hpa_table_ready())return null;if($cache!==null&&$cache['id']===$id)return $cache;
 // A partner that was locked, unassigned from its store or edited elsewhere (auth_version) loses the session.
 $row=hyg_one("SELECT p.id,p.username,p.display_name,p.auth_version,p.dispatch_store_id,s.name AS store_name FROM partners p JOIN dispatch_stores s ON s.id=p.dispatch_store_id WHERE p.id=? AND p.status='active'",[$id]);
 if(!$row||(int)$row['auth_version']!==(int)($_SESSION['partner_auth_version']??0)){hpa_clear_session();return null;}
 return $cache=['id'=>(int)$row['id'],'username'=>(string)$row['username'],'display_name'=>(string)$row['display_name'],'store_id'=>(int)$row['dispatch_store_id'],'store_name'=>(string)$row['store_name']];
}
function hpa_sign_in(string $username,string $password): bool {
 if($username===''||!hpa_table_ready())return false;
 $row=hyg_one("SELECT p.id,p.password,p.auth_version FROM partners p JOIN dispatch_stores s ON s.id=p.dispatch_store_id WHERE p.username=? AND p.status='active'",[$username]);
 // The new backend stores bcrypt hashes, which password_verify() accepts as-is.
 if(!$row||!password_verify($password,(string)$row['password']))return false;
 session_regenerate_id(true);$_SESSION['partner_id']=(int)$row['id'];$_SESSION['partner_auth_version']=(int)$row['auth_version'];return true;
}
function require_partner(): array {
 $partner=hpa_current();if(!$partner)redirect(hpa_url('login.php'));
 header('Cache-Control: private, no-store');return $partner;
}
/** Locks the signed-in partner inside the caller's transaction; the store comes from this locked row, never the session. */
function hpa_locked(int $partnerId): int {
 if(!db()->inTransaction())hpa_fail('storage');
 $row=hyg_one('SELECT id,status,auth_version,dispatch_store_id FROM partners WHERE id=? FOR UPDATE',[$partnerId]);
 if(!$row||$row['status']!=='active'||$row['dispatch_store_id']===null||(int)$row['auth_version']!==(int)($_SESSION['partner_auth_version']??0)||(int)($_SESSION['partner_id']??0)!==$partnerId)hpa_fail('forbidden');
 return (int)$row['dispatch_store_id'];
}
function hpa_order(int $orderId,int $storeId): ?array {return hyg_one('SELECT * FROM orders WHERE id=? AND dispatch_store_id=?',[$orderId,$storeId]);}
/** Partners move an order forward only: new → accepted → done. Cancelling stays with headquarters (refunds, referral points). */
function hpa_change_status(int $orderId,mixed $target,mixed $version,int $partnerId): void {
 if(!in_array($target,['accepted','done'],true))hpa_fail('status_transition_error');
 htow_transition($orderId,$target,$version,'',null,$partnerId,fn():?int=>hpa_locked($partnerId));
}
/** Hands a not-yet-accepted order back to headquarters for re-dispatch; the reason is kept in dispatch_history. */
function hpa_return_order(int $orderId,mixed $revision,mixed $reason,int $partnerId): void {
 if((!is_string($revision)&&!is_int($revision))||!preg_match('/^\d{1,10}$/D',(string)$revision))hpa_fail('changed');
 if(!is_string($reason)||!preg_match('//u',$reason)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$reason))hpa_fail('reason_required');
 $reason=trim($reason);if($reason===''||mb_strlen($reason,'UTF-8')>1000)hpa_fail('reason_required');
 $pdo=db();if($pdo->inTransaction())hpa_fail('storage');$pdo->beginTransaction();
 try{
  $storeId=hpa_locked($partnerId);
  $order=hyg_one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$orderId]);
  if(!$order||(int)($order['dispatch_store_id']??0)!==$storeId)hpa_fail('not_found');
  if($order['status']!=='new')hpa_fail('return_new_only');
  if((int)($order['dispatch_revision']??0)!==(int)$revision)hpa_fail('changed');
  $next=(int)$revision+1;
  $pdo->prepare('UPDATE orders SET dispatch_store_id=NULL,dispatch_store_name=NULL,dispatch_store_address=NULL,dispatch_store_map_url=NULL,dispatch_assigned_at=UTC_TIMESTAMP(),dispatch_assigned_by_admin_id=NULL,dispatch_revision=? WHERE id=?')->execute([$next,$orderId]);
  $pdo->prepare('INSERT INTO dispatch_history(order_id,revision,store_id,name,address,map_url,admin_id,partner_id,reason,created_at) VALUES(?,?,NULL,NULL,NULL,NULL,NULL,?,?,UTC_TIMESTAMP())')->execute([$orderId,$next,$partnerId,$reason]);
  $pdo->commit();
 }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
/** partners.id => display name for history rows; empty when the partners table does not exist here. */
function hpa_partner_names(array $ids): array {
 $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));if(!$ids||!hpa_table_ready())return [];
 $rows=hyg_all('SELECT id,display_name,username FROM partners WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
 $names=[];foreach($rows as $row)$names[(int)$row['id']]=trim((string)$row['display_name'])?:(string)$row['username'];return $names;
}
/** Who wrote a history row: a store partner (partner_id), the system (admin_id 0) or the given staff label. */
function hpa_actor_label(array $entry,array $partnerNames,string $staffLabel): string {
 $partnerId=(int)($entry['partner_id']??0);
 if($partnerId>0)return hpa_t('actor_partner').': '.($partnerNames[$partnerId]??'#'.$partnerId);
 if(isset($entry['admin_id'])&&(int)$entry['admin_id']===0)return hpa_t('actor_system');
 return $staffLabel;
}
function hpa_error(Throwable $error): string {
 if($error instanceof HpaError)return hpa_t($error->getMessage());
 if($error instanceof OrderWorkflowError){$key=$error->getMessage();return in_array($key,['not_found','status_changed_error','status_transition_error'],true)?hpa_t($key==='status_changed_error'?'changed':$key):htow_error($error);}
 return hpa_t('storage');
}
function hpa_t(string $key): string {
 $map=[
 'vi'=>['app_name'=>'Hồng Trà Tuổi Thơ · Cửa hàng','login'=>'Đăng nhập','username'=>'Tên đăng nhập','password'=>'Mật khẩu','login_error'=>'Sai tên đăng nhập hoặc mật khẩu, hoặc tài khoản chưa được kích hoạt / chưa gán cửa hàng.','orders'=>'Đơn của cửa hàng','all'=>'Tất cả','new'=>'Đơn mới','accepted'=>'Đang làm','done'=>'Hoàn thành','cancelled'=>'Đã hủy','logout'=>'Đăng xuất','refresh'=>'Tải lại','empty'=>'Chưa có đơn nào.','back'=>'Quay lại','customer'=>'Khách hàng','phone'=>'Điện thoại','address'=>'Địa chỉ','payment'=>'Thanh toán','note'=>'Ghi chú','time'=>'Thời gian','accept'=>'Nhận đơn','complete'=>'Hoàn thành','complete_confirm'=>'Xác nhận đơn đã hoàn thành? Đơn hoàn thành sẽ cộng điểm cho khách và không thể đổi lại.','return'=>'Trả đơn về trụ sở','return_help'=>'Chỉ trả được khi chưa nhận đơn. Trụ sở sẽ phân lại cho cửa hàng khác.','return_reason'=>'Lý do trả đơn','return_confirm'=>'Trả đơn này về trụ sở?','saved'=>'Đã cập nhật đơn.','returned'=>'Đã trả đơn về trụ sở.','new_orders'=>'Có đơn mới','not_found'=>'Không tìm thấy đơn, hoặc đơn đã được chuyển cho cửa hàng khác.','changed'=>'Đơn vừa được cập nhật. Hãy tải lại trang rồi thử lại.','status_transition_error'=>'Không thể chuyển đơn sang trạng thái này.','reason_required'=>'Hãy nhập lý do (tối đa 1000 ký tự).','return_new_only'=>'Chỉ trả được đơn chưa nhận.','forbidden'=>'Phiên đăng nhập đã hết hạn. Hãy đăng nhập lại.','storage'=>'Tạm thời chưa lưu được. Hãy thử lại sau.','final'=>'Đơn đã kết thúc, không còn thao tác.','actor_partner'=>'Cửa hàng','actor_system'=>'Hệ thống','order_no'=>'Mã đơn','total'=>'Tổng tiền','status'=>'Trạng thái','view'=>'Xem','view_order'=>'Xem đơn','stop'=>'Tắt chuông','sound'=>'Bật chuông','sound_on'=>'Chuông đang bật','notify'=>'Bật thông báo','notify_on'=>'Thông báo đang bật','notify_denied'=>'Trình duyệt đang chặn thông báo. Hãy cho phép trong cài đặt trang web.','offline'=>'Mất kết nối, đang thử lại…'],
 'en'=>['app_name'=>'Hồng Trà Tuổi Thơ · Store','login'=>'Sign in','username'=>'Username','password'=>'Password','login_error'=>'Wrong username or password, or the account is not active / has no store yet.','orders'=>'Store orders','all'=>'All','new'=>'New','accepted'=>'In progress','done'=>'Completed','cancelled'=>'Cancelled','logout'=>'Sign out','refresh'=>'Reload','empty'=>'No orders yet.','back'=>'Back','customer'=>'Customer','phone'=>'Phone','address'=>'Address','payment'=>'Payment','note'=>'Note','time'=>'Time','accept'=>'Accept order','complete'=>'Complete','complete_confirm'=>'Mark this order completed? Completed orders award customer points and cannot be changed back.','return'=>'Hand back to headquarters','return_help'=>'Only before accepting. Headquarters will dispatch it to another store.','return_reason'=>'Reason','return_confirm'=>'Hand this order back to headquarters?','saved'=>'Order updated.','returned'=>'Order handed back to headquarters.','new_orders'=>'New order received','not_found'=>'Order not found, or it was moved to another store.','changed'=>'This order was just updated. Reload and try again.','status_transition_error'=>'The order cannot move to this status.','reason_required'=>'Enter a reason (up to 1000 characters).','return_new_only'=>'Only orders not yet accepted can be handed back.','forbidden'=>'Your session expired. Sign in again.','storage'=>'Unable to save right now. Try again later.','final'=>'This order is closed.','actor_partner'=>'Store','actor_system'=>'System','order_no'=>'Order','total'=>'Total','status'=>'Status','view'=>'View','view_order'=>'View order','stop'=>'Stop ring','sound'=>'Enable ring','sound_on'=>'Ring on','notify'=>'Enable notifications','notify_on'=>'Notifications on','notify_denied'=>'Notifications are blocked. Allow them in the site settings.','offline'=>'Connection lost, retrying…'],
 'zh'=>['app_name'=>'Hồng Trà Tuổi Thơ · 門市','login'=>'登入','username'=>'帳號','password'=>'密碼','login_error'=>'帳號或密碼錯誤，或帳號尚未啟用／尚未指定門市。','orders'=>'門市訂單','all'=>'全部','new'=>'新訂單','accepted'=>'製作中','done'=>'已完成','cancelled'=>'已取消','logout'=>'登出','refresh'=>'重新整理','empty'=>'尚無訂單。','back'=>'返回','customer'=>'顧客','phone'=>'電話','address'=>'地址','payment'=>'付款','note'=>'備註','time'=>'時間','accept'=>'接單','complete'=>'完成','complete_confirm'=>'確認訂單已完成？完成後將發放顧客點數且無法改回。','return'=>'退回總部','return_help'=>'僅限尚未接單時。總部會改派其他門市。','return_reason'=>'退回原因','return_confirm'=>'將此訂單退回總部？','saved'=>'訂單已更新。','returned'=>'訂單已退回總部。','new_orders'=>'有新訂單','not_found'=>'找不到訂單，或已改派其他門市。','changed'=>'訂單剛被更新，請重新整理後再試。','status_transition_error'=>'訂單無法變更為此狀態。','reason_required'=>'請輸入原因（最多 1000 字）。','return_new_only'=>'僅能退回尚未接單的訂單。','forbidden'=>'登入已逾時，請重新登入。','storage'=>'暫時無法儲存，請稍後再試。','final'=>'此訂單已結束。','actor_partner'=>'門市','actor_system'=>'系統','order_no'=>'訂單編號','total'=>'金額','status'=>'狀態','view'=>'查看','view_order'=>'查看訂單','stop'=>'停止鈴聲','sound'=>'開啟鈴聲','sound_on'=>'鈴聲已開啟','notify'=>'開啟通知','notify_on'=>'通知已開啟','notify_denied'=>'瀏覽器已封鎖通知，請在網站設定中允許。','offline'=>'連線中斷，重試中…'],
 ];
 $lang=function_exists('current_lang')?current_lang():'vi';return $map[$lang][$key]??$map['vi'][$key]??$key;
}
