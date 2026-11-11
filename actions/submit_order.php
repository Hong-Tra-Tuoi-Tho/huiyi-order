<?php
require __DIR__.'/../lib/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
require_once __DIR__.'/../lib/promotion_views.php';
require_once __DIR__.'/../lib/customer_checkout.php';
header('Cache-Control: private, no-store, max-age=0');
function hcq_restore_existing(string $token,string $context,string $group): void {
    $request=hcq_lookup($token,$context);if(!$request)return;
    $saved=hcq_existing_order($token,$context);if(!$saved)return;
    if($context==='ordinary'&&$request['state']!=='saved'){
        $cart=$_SESSION['cart']??[];if($cart&&hash_equals($request['signature'],promotion_quote_signature($cart))){$_SESSION['cart']=[];clear_coupon();unset($_SESSION['promotion_mode']);}
    }
    hcq_saved($token,$saved['order_no']);unset($_SESSION['checkout_drafts'][$context],$_SESSION['checkout_errors'][$context]);$_SESSION['last_order_no']=$saved['order_no'];
    header('Location: '.($group!==''?hyg_room_url($group):base_url('payment.php?order='.rawurlencode($saved['order_no']))),true,303);exit;
}
try{$groupId=!empty($_POST['group_id'])?hyg_code($_POST['group_id']):'';}catch(Throwable $e){hyg_error_page($e);}
$checkoutContext=hcq_context($groupId);$csrf=$_POST['csrf']??null;$expectedCsrf=$_SESSION['_csrf']??null;
if(!is_string($csrf)||!is_string($expectedCsrf)||$expectedCsrf===''||!hash_equals($expectedCsrf,$csrf))hcq_fail($checkoutContext,$groupId,t('csrf_expired'));
$postedToken=$_POST['checkout_token']??null;$checkoutRequest=hcq_lookup($postedToken,$checkoutContext);
if($postedToken!==null&&!$checkoutRequest)hcq_fail($checkoutContext,$groupId,htp('changed'));
if($checkoutRequest)try{hcq_restore_existing($postedToken,$checkoutContext,$groupId);}catch(Throwable $e){hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));}
if(hcq_outcome($postedToken,$checkoutContext)){
    try{$saved=htp_customer_order($checkoutRequest['order_no']);hcq_saved($postedToken,$saved['order_no']);header('Location: '.base_url('payment.php?order='.rawurlencode($saved['order_no'])),true,303);exit;}catch(Throwable $e){hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));}
}
$groupCheckout=null;
$cart=$_SESSION['cart']??[]; if(!$groupId&&!$cart) redirect(base_url('index.php'));
if(!$groupId&&!hcc_all_complete($cart))redirect(base_url('cart.php'));
$draft=hcq_capture($checkoutContext,$_POST);$values=$draft['values'];
if($groupId===''&&$values['payment_method']==='split'){$_SESSION['checkout_drafts'][$checkoutContext]['errors']['payment_method']='incomplete_data';$draft['errors']['payment_method']='incomplete_data';}
if($draft['errors'])hcq_fail($checkoutContext,$groupId,htp_ux('draft_error'));
$name=$values['customer_name'];$phone=$values['phone'];$address=$values['address'];$pay=$values['payment_method'];$invoice=(int)$values['invoice_required'];$orderNote=$values['order_note'];
$companyName=$values['invoice_company_name'];$taxId=$values['invoice_tax_id'];$companyAddress=$values['invoice_company_address'];$companyRepresentative=$values['invoice_company_representative'];$companyPhone=$values['invoice_company_phone'];$invoiceEmail=$values['invoice_email'];
if(!$invoice)$companyName=$taxId=$companyAddress=$companyRepresentative=$companyPhone=$invoiceEmail='';

// A locked group may already have earned eligibility before the activity is disabled.
$walletToken=customer_game_wallet_token(true);
$orderMember=hym_current_member();
$orderMemberId=$orderMember?(int)$orderMember['id']:null;
if($groupId===''){
    $given=$_POST['promotion_signature']??null;
    $choice=promotion_cart_choice($cart);
    if(!$choice['valid']||!is_string($given)||!hash_equals(promotion_quote_signature($cart),$given)){
        $_SESSION['coupon_error']=pt('selection_changed');header('Location: '.base_url('cart.php'),true,303);exit;
    }
    if($choice['mode']==='reward'&&(float)cart_coupon_summary($cart)['discount']<=0){$_SESSION['coupon_error']=pt('reward_no_discount');header('Location: '.base_url('cart.php'),true,303);exit;}
}
if($groupId!==''){try{hyg_csrf();hyg_schema();}catch(Throwable $e){hyg_error_page($e);}}
$requestSignature=$groupId!==''?(is_string($_POST['group_signature']??null)?$_POST['group_signature']:''):promotion_quote_signature($cart);
$checkoutToken=hcq_unknown_token($checkoutContext)??($checkoutRequest?$postedToken:hcq_request($checkoutContext,$requestSignature));$commitStarted=false;$no='';
try{hcq_restore_existing($checkoutToken,$checkoutContext,$groupId);}catch(Throwable $e){hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));}
// Old rendered forms may omit the token; their fallback must obey the same uncertain-outcome guard.
if($retry=hcq_outcome($checkoutToken,$checkoutContext)){
    try{$saved=htp_customer_order($retry['order_no']);hcq_saved($checkoutToken,$saved['order_no']);header('Location: '.base_url('payment.php?order='.rawurlencode($saved['order_no'])),true,303);exit;}catch(Throwable $e){hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));}
}
if($checkoutRequest&&!hash_equals($checkoutRequest['signature'],$requestSignature))hcq_fail($checkoutContext,$groupId,htp('changed'));
// Resolve (or expire) the checkout Mini Game session before the order transaction opens.
$gameSessionId=hcgs_prize_row($groupId)?hcgs_session_id():'';
$no=order_no();hcq_saved($checkoutToken,$no,'unknown');
// Persist the reservation before SQL. A process crash must leave an unknown result, never an open retry.
if(session_status()!==PHP_SESSION_ACTIVE||!session_write_close())hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));
if(!@session_start())hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));
$reopenedRequest=hcq_lookup($checkoutToken,$checkoutContext);
if(!$reopenedRequest||($reopenedRequest['state']??'')!=='unknown'||($reopenedRequest['order_no']??'')!==$no||(int)($_SESSION['member_id']??0)!==(int)($orderMemberId??0))hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));
$pdo=db();
// Bảng chi tiết combo của đơn (lib/order_combo.php): tạo ngoài transaction vì DDL tự commit; lỗi thì đơn vẫn lưu như cũ.
occ_schema();
try{
    $pdo->beginTransaction();
    if($groupId!==''){
        $groupCheckout=hyg_checkout($groupId,true);
        if($groupCheckout['already']){$existing=hyg_one('SELECT order_no FROM orders WHERE id=?',[$groupCheckout['room']['order_id']]);$pdo->commit();if($existing)hcq_saved($checkoutToken,$existing['order_no']);header('Location: '.hyg_room_url($groupId),true,303);exit;}
        // Payment reports/approvals change the revision; removing a member changes the signed quote.
        if(!is_string($_POST['group_signature']??null)||!hash_equals((string)$groupCheckout['room']['lock_signature'],$_POST['group_signature']))hyg_fail('checkout_change');
        $cart=$groupCheckout['cart'];$coupon=$groupCheckout['coupon'];
        $choice=promotion_room_choice($groupCheckout['room'],$cart);
        $groupPayment=hyg_payment_summary($groupCheckout['room'],$cart,$coupon);
        $expectedPay=htp_group_order_method($groupPayment);
        // The customer picks transfer or cash on the checkout page; only a split-payment group keeps the method fixed by its quote.
        $payAllowed=$expectedPay==='split'?$pay===$expectedPay:in_array($pay,['transfer','cash'],true);
        if(!$payAllowed)hyg_fail('checkout_change');
    }else{
        $choice=promotion_cart_choice($cart);
        promotion_validate_choice($choice['mode'],$choice['code']);$coupon=cart_coupon_summary($cart);
        if($choice['mode']==='reward'&&(float)$coupon['discount']<=0)throw new RuntimeException('coupon_no_discount');
    }
    $subtotal=cart_subtotal($cart);$discount=(float)$coupon['discount'];$total=max(0,$subtotal-$discount);$couponCode=$coupon['valid']?$coupon['code']:'';
    $eligibleDrinkQty=promotion_game_qty($cart);
    $expectedGameChances=$choice['mode']==='draw'&&$couponCode===''&&$discount<=0?game_saved_chance_count($eligibleDrinkQty,(int)$choice['expected_chances']):0;
    // Money prizes won in this checkout session are summed, taken off this order and spent with it.
    $gameCash=hcgs_cash_discount($cart,$coupon,hcgs_row($gameSessionId));
    if($gameCash['discount']>0){
        $discount+=$gameCash['discount'];$total=max(0,$subtotal-$discount);
        foreach($gameCash['line_discounts'] as $k=>$v)$coupon['line_discounts'][$k]=($coupon['line_discounts'][$k]??0)+$v;
        $couponCode=implode(',',$gameCash['codes']);if(strlen($couponCode)>80)$couponCode=$gameCash['codes'][0].' +'.(count($gameCash['codes'])-1);
    }
    $st=$pdo->prepare('INSERT INTO orders(order_no,customer_name,phone,address,payment_method,invoice_required,invoice_company_name,invoice_tax_id,invoice_company_address,invoice_company_representative,invoice_company_phone,invoice_email,order_note,coupon_code,subtotal_amount,discount_amount,total_amount,game_wallet_token,group_room_id,group_title,member_id,promotion_mode,game_chances_expected,checkout_request_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$no,$name,$phone,$address,$pay,$invoice?1:0,$companyName?:null,$taxId?:null,$companyAddress?:null,$companyRepresentative?:null,$companyPhone?:null,$invoiceEmail?:null,$orderNote?:null,$couponCode?:null,$subtotal,$discount,$total,$choice['mode']==='draw'?($walletToken?:null):null,$groupCheckout['room']['id']??null,$groupCheckout['room']['title']??null,$orderMemberId,$choice['mode'],$expectedGameChances,hash('sha256',$checkoutToken)]);
    $oid=(int)$pdo->lastInsertId();
    $st2=$pdo->prepare('INSERT INTO order_items(order_id,product_id,standalone_topping_id,product_name,cup_size,base_price,ice_choice,sugar_choice,toppings_text,unit_price,discount_amount,qty,line_total,group_member_name,group_member_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $itemIds=[];
    foreach($cart as $key=>$r){
        $tops=implode('、',array_map(fn($top)=>($top['display_name']??$top['name']).'(+'.currency().money($top['price']).')',$r['toppings']??[]));
        $lineDiscount=(float)($coupon['line_discounts'][(string)$key]??0); $lineOriginal=(float)$r['unit_price']*(int)$r['qty'];
        $productId=!empty($r['product_id'])?(int)$r['product_id']:null;
        $standaloneId=!empty($r['standalone_topping_id'])?(int)$r['standalone_topping_id']:null;
        $st2->execute([$oid,$productId,$standaloneId,$r['name'],$r['cup_size']??null,$r['base_price'],$r['ice']??'',$r['sugar']??'',$tops,$r['unit_price'],$lineDiscount,$r['qty'],max(0,$lineOriginal-$lineDiscount),$r['group_member_name']??null,$r['group_member_id']??null]);
        $itemIds[$key]=(int)$pdo->lastInsertId();
    }
    // Đơn có combo: ghi thêm chi tiết combo / nhóm món (order_items ở trên vẫn đủ dữ liệu như trước).
    occ_save($oid,$cart,$coupon,$itemIds);
    if(($coupon['source']??'')==='game' && $couponCode!==''){
        if(!mark_customer_coupon_used($couponCode,$oid)) throw new RuntimeException('Coupon already used.');
    }
    foreach($gameCash['codes'] as $gameCode)if(!mark_customer_coupon_used($gameCode,$oid))throw new RuntimeException('Coupon already used.');
    // Free drink/topping prizes are saved as 0đ gift lines, tagged with their coupon, and spent with this order.
    $giftSt=$pdo->prepare('INSERT INTO order_items(order_id,product_id,standalone_topping_id,product_name,cup_size,base_price,ice_choice,sugar_choice,toppings_text,unit_price,discount_amount,qty,line_total,game_reward_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach(hcgs_gift_rewards(hcgs_row($gameSessionId)) as $gift){
        $pick=$gift['item'];$tea=$gift['kind']==='tea';
        $giftSt->execute([$oid,$tea?$pick['id']:null,$tea?null:$pick['id'],'🎁 Quà Mini Game: '.$pick['name'],$pick['cup_size']?:null,$pick['price'],'','','',0,$pick['price']*$gift['qty'],$gift['qty'],0,$gift['code']]);
        $usedGifts[$gift['code']]=true;
    }
    // One coupon may hold several gift items; it is spent once.
    foreach(array_keys($usedGifts??[]) as $giftCode)if(!mark_customer_coupon_used($giftCode,$oid))throw new RuntimeException('Coupon already used.');
    if($groupCheckout){
        hyg_finish($groupCheckout,$oid,$pay);
        // Members become children of the leader; the leader's parent earns points once the order is done (see member_referral.php).
        hym_group_referral_after_order((int)$groupCheckout['room']['id'],$oid);
    }
    htp_initialize_order($oid);
    // Chances already granted at checkout are kept by this order and never awarded twice.
    if($gameSessionId!=='')hcgs_complete_locked($gameSessionId,$oid);
    // This server-generated reference also authorizes recovery if COMMIT loses its reply.
    if($orderMemberId===null)htp_remember_customer_order($no);
    $commitStarted=true;
    $pdo->commit();
}catch(Throwable $e){
    if($commitStarted&&$no!==''){
        if($orderMemberId===null)htp_remember_customer_order($no);
        hcq_saved($checkoutToken,$no,'unknown');
        try{if($pdo->inTransaction())$pdo->rollBack();}catch(Throwable $ignored){}
        hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));
    }
    try{if($pdo->inTransaction()&&!$pdo->rollBack())throw new RuntimeException('rollback_failed');hcq_restore_existing($checkoutToken,$checkoutContext,$groupId);}catch(Throwable $ignored){hcq_fail($checkoutContext,$groupId,htp_ux('unknown'));}
    hcq_saved($checkoutToken,'','open');if($groupId!=='')hyg_error_page($e);hcq_fail($checkoutContext,$groupId,t('order_failed'));
}

// The order is committed. A notification failure must never turn it into a failed checkout.
hcq_saved($checkoutToken,$no);unset($_SESSION['checkout_drafts'][$checkoutContext],$_SESSION['checkout_errors'][$checkoutContext]);
if($gameSessionId!=='')unset($_SESSION[HCGS_KEY]);
if($groupId===''){$_SESSION['cart']=[]; clear_coupon();unset($_SESSION['promotion_mode']);}
$_SESSION['last_order_no']=$no;
$_SESSION['last_order_expected_game_chances']=$expectedGameChances;
$_SESSION['last_order_eligible_drinks']=$eligibleDrinkQty;
$_SESSION['last_order_promotion']=$choice;
if($groupId !== '') $_SESSION['hyg_order_success']=true;
try{$pushResult=send_new_order_push_notifications($oid,false);}catch(Throwable $e){error_log('[checkout] Notification enqueue unavailable for committed order '.$oid);}
header('Location: '.($groupId!==''?hyg_room_url($groupId):base_url('success.php')),true,303);
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
ignore_user_abort(true);
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
// Locate the committed order after releasing the session; maps failure never fails checkout.
try{hds_recommend_order($oid);}catch(Throwable $e){error_log('[dispatch] Recommendation unavailable for order '.$oid);}
// Báo hệ mới (Laravel): tự giao cửa hàng gần nhất + bắn socket cho partner-web. Chạy sau khi đã có tọa độ.
hq_bridge_notify('order.created',$oid);
try{hqp_process_queue(20,9,$oid);}catch(Throwable $e){hqp_log('post_order_push_error',$e);}
try{$mailDetail='';send_order_notification_by_id($oid,$mailDetail);}catch(Throwable $e){hqp_log('post_order_mail_error',$e);}
exit;
