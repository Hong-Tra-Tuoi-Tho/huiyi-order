<?php
/**
 * v18: traceable order email delivery.
 * Order notifications are rebuilt from the saved order snapshot rather than the
 * browser/session cart, and each attempt is stored on the order for diagnostics.
 */
function ensure_v179_schema(): void {
    static $done=false;
    if($done) return;
    $done=true;
    $changes=[
        ['orders','notification_email_status',"VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER game_chances_awarded"],
        ['orders','notification_email_detail','VARCHAR(255) NULL AFTER notification_email_status'],
        ['orders','notification_email_attempts','INT NOT NULL DEFAULT 0 AFTER notification_email_detail'],
        ['orders','notification_email_sent_at','DATETIME NULL AFTER notification_email_attempts'],
    ];
    foreach($changes as [$table,$col,$def]){
        if(column_exists($table,$col)) continue;
        try { db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def"); } catch(Throwable $e) {}
    }
}

function order_notification_email_payload(int $orderId): ?array {
    if($orderId<=0) return null;
    try {
        $st=db()->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $st->execute([$orderId]);
        $order=$st->fetch();
        if(!$order) return null;
        $it=db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
        $it->execute([$orderId]);
        return ['order'=>$order,'items'=>order_lines_flat($orderId,$it->fetchAll())];
    } catch(Throwable $e){
        return null;
    }
}

function build_order_notification_email(array $order,array $items): array {
    $currencySymbol=currency();
    $moneyText=fn($v)=>$currencySymbol.money($v??0);
    $esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $orderNo=(string)($order['order_no']??'');
    $customer=(string)($order['customer_name']??'');
    $payment=($order['payment_method']??'')==='split'?'Thanh toán riêng / 分開結帳':((($order['payment_method']??'')==='transfer')?'Chuyển khoản / 轉帳':'Tiền mặt / 現金');
    $groupPayment=function_exists('hyg_order_payment_summary')?hyg_order_payment_summary($order):null;
    $memberPaymentLabel=fn($method)=>['bank_transfer'=>'Chuyển khoản / 轉帳','cash'=>'Tiền mặt / 現金','host_advance'=>'Trưởng nhóm ứng trước / 團長墊付'][$method]??'';
    $hostCollection=($groupPayment['collection_mode']??'store')==='host';
    $groupApproval=(int)($groupPayment['protocol_version']??1)>=2;
    $groupPaymentHint=$groupApproval
        ?'Thành viên báo đã trả tiền, trưởng nhóm xác nhận. Trưởng nhóm xác nhận khoản của mình và khoản trả hộ. / 團員付款後回報，由團主確認；團主自己的付款與墊付款由團主確認。'
        :'Trạng thái do người tham gia tự báo / 付款狀態由團員自行回報';
    if($hostCollection)$groupPaymentHint='Thành viên trả cho trưởng nhóm, trưởng nhóm xác nhận thu tiền. Trưởng nhóm trả toàn bộ tiền đơn cho cửa hàng sau khi gửi đơn; thu tiền hộ không xác nhận cửa hàng đã nhận tiền. / 團員付給團主，由團主確認代收；送單後團主再支付全團款給店家，代收確認不代表店家已入帳。';
    $memberPaymentStatus=fn($member)=>$member['status']==='paid'?($hostCollection?(!empty($member['is_host'])||$member['method']==='host_advance'?'Đã chuẩn bị tiền của trưởng nhóm / 團主出資已備妥':'Trưởng nhóm đã xác nhận thu tiền / 團主已確認代收'):'Đã thanh toán / 已付款'):((($groupPayment['mode']??'')==='together'&&(float)($groupPayment['host_due']??0)<=0)||((float)$member['due']<=0&&(float)$member['covered_amount']<=0)?'Không cần thanh toán / 無需付款':($groupApproval&&$member['status']==='requested'?'Chờ trưởng nhóm xác nhận / 待團主確認':'Chưa thanh toán / 未付款'));
    $invoice=!empty($order['invoice_required']);

    // Plain-text fallback retained for clients that cannot render HTML.
    $lines=[];
    $lines[]='HỒNG TRÀ TUỔI THƠ';
    $lines[]='ĐƠN HÀNG MỚI / 新訂單';
    $lines[]='========================================';
    $lines[]='Mã đơn / 訂單編號：'.$orderNo;
    if(!empty($order['created_at'])) $lines[]='Thời gian / 時間：'.(string)$order['created_at'];
    $lines[]='';
    $lines[]='[ THÔNG TIN KHÁCH HÀNG / 客戶資料 ]';
    $lines[]='Khách hàng / 客戶：'.$customer;
    $lines[]='Điện thoại / 電話：'.($order['phone']??'');
    $lines[]='Địa chỉ / 地址：'.($order['address']??'');
    $lines[]='Thanh toán / 付款：'.$payment;
    $lines[]='Hóa đơn / 發票：'.($invoice?'Có / 是':'Không / 否');
    if($invoice){
        $lines[]='';
        $lines[]='[ THÔNG TIN HÓA ĐƠN / 發票資料 ]';
        $lines[]='Công ty / 公司名稱：'.($order['invoice_company_name']??'');
        $lines[]='Mã số thuế / 統一稅號：'.($order['invoice_tax_id']??'');
        $lines[]='Địa chỉ / 公司地址：'.($order['invoice_company_address']??'');
        $lines[]='Đại diện / 公司代表人：'.(($order['invoice_company_representative']??'')?:'—');
        $lines[]='Điện thoại / 公司電話：'.($order['invoice_company_phone']??'');
        $lines[]='Email hóa đơn / 發票 Email：'.($order['invoice_email']??'');
    }
    $lines[]='';
    if(!empty($order['group_title']))$lines[]='Đặt nhóm / 團購：'.$order['group_title'];
    $lines[]='[ CHI TIẾT ĐƠN HÀNG / 訂購明細 ]';
    $itemNo=0;
    foreach($items as $item){
        $itemNo++;
        $name=(string)($item['product_name']??'');
        if(function_exists('occ_label')&&($label=occ_label($item))!=='')$name=($item['_kind']==='combo'?'['.$label.'] ':'').$name.($item['_kind']==='combo_drink'?' ['.$label.']':'');
        $size=trim((string)($item['cup_size']??''));
        $qty=max(1,(int)($item['qty']??1));
        if(!empty($item['group_member_name']))$lines[]='Người đặt / 訂購人：'.$item['group_member_name'];
        $lines[]=$itemNo.'. '.$name.($size!==''?' ('.$size.')':'').' × '.$qty;
        if(trim((string)($item['ice_choice']??''))!=='') $lines[]='   Đá / 冰量：'.(string)$item['ice_choice'];
        if(trim((string)($item['sugar_choice']??''))!=='') $lines[]='   Đường / 甜度：'.(string)$item['sugar_choice'];
        if(trim((string)($item['toppings_text']??''))!=='') $lines[]='   Topping / 配料：'.(string)$item['toppings_text'];
        $lines[]='   Thành tiền / 金額：'.$moneyText($item['line_total']??0);
        $lines[]='';
    }
    $lines[]='----------------------------------------';
    if(!empty($order['coupon_code'])) $lines[]='Coupon / 優惠券：'.($order['coupon_code']??'');
    $lines[]='Tạm tính / 小計：'.$moneyText($order['subtotal_amount']??0);
    if((float)($order['discount_amount']??0)>0) $lines[]='Giảm giá / 優惠折抵：-'.$moneyText($order['discount_amount']??0);
    $lines[]='TỔNG CỘNG / 總金額：'.$moneyText($order['total_amount']??0);
    if($groupPayment){
        $lines[]='';$lines[]='[ THANH TOÁN NHÓM / 團購付款 ]';
        $lines[]='Hình thức / 結帳方式：'.($groupPayment['mode']==='split'?'Thanh toán riêng / 分開結帳':'Thanh toán chung / 一起結帳');
        $lines[]='Trưởng nhóm tự đặt / 團長訂購：'.$moneyText($groupPayment['host_subtotal']);
        $lines[]='Trưởng nhóm ứng trước / 團長墊付：'.$moneyText($groupPayment['host_advance']);
        $lines[]='Giảm giá của trưởng nhóm / 團長優惠：-'.$moneyText($groupPayment['host_discount']);
        $lines[]='Trưởng nhóm cần trả / 團長應付：'.$moneyText($groupPayment['host_due']);
        foreach($groupPayment['members'] as $member){
            $lines[]=$member['nickname'].' · '.$memberPaymentLabel($member['method']).' · '.$moneyText($member['due']).' · '.$memberPaymentStatus($member);
        }
        $lines[]=$groupPaymentHint;
    }
    if(trim((string)($order['order_note']??''))!==''){
        $lines[]='';
        $lines[]='[ GHI CHÚ / 備註 ]';
        $lines[]=(string)$order['order_note'];
    }
    $lines[]='';
    $lines[]='Hồng Trà Tuổi Thơ · Online Order';
    $lines[]='Email template text fallback v19.2';

    // v19 mobile-first field: one label line, one value line.
    $field=function(string $label,string $value,bool $accent=false) use($esc): string {
        $valueColor=$accent?'#111827':'#101828';
        $valueSize=$accent?'18px':'15px';
        return '<div style="padding:12px 0;border-bottom:1px solid #edf0f3;">'
            .'<div style="font-size:11px;line-height:1.35;font-weight:800;color:#667085;letter-spacing:.02em;">'.$esc($label).'</div>'
            .'<div style="margin-top:5px;font-size:'.$valueSize.';line-height:1.55;font-weight:800;color:'.$valueColor.';word-break:break-word;overflow-wrap:anywhere;">'.$esc($value!==''?$value:'—').'</div>'
            .'</div>';
    };

    $customerFields='';
    if(!empty($order['group_title']))$customerFields.=$field('Đặt nhóm / 團購',(string)$order['group_title']);
    $customerFields.=$field('Khách hàng / 客戶',$customer);
    $customerFields.=$field('Điện thoại / 電話',(string)($order['phone']??''));
    $customerFields.=$field('Địa chỉ / 地址',(string)($order['address']??''));
    $customerFields.=$field('Thanh toán / 付款',$payment);
    $customerFields.=$field('Hóa đơn / 發票',$invoice?'Có / 是':'Không / 否');
    $groupPaymentHtml='';
    if($groupPayment){
        $groupPaymentHtml='<div style="margin-top:14px;padding:16px;background:#f8fafc;border:1px solid #e4e7ec;border-radius:16px;"><strong>THANH TOÁN NHÓM / 團購付款</strong>';
        $groupPaymentHtml.=$field('Hình thức / 結帳方式',$groupPayment['mode']==='split'?'Thanh toán riêng / 分開結帳':'Thanh toán chung / 一起結帳');
        $groupPaymentHtml.=$field('Trưởng nhóm tự đặt / 團長訂購',$moneyText($groupPayment['host_subtotal']));
        $groupPaymentHtml.=$field('Trưởng nhóm ứng trước / 團長墊付',$moneyText($groupPayment['host_advance']));
        $groupPaymentHtml.=$field('Giảm giá của trưởng nhóm / 團長優惠','-'.$moneyText($groupPayment['host_discount']));
        $groupPaymentHtml.=$field('Trưởng nhóm cần trả / 團長應付',$moneyText($groupPayment['host_due']),true);
        foreach($groupPayment['members'] as $member)$groupPaymentHtml.=$field((string)$member['nickname'],$memberPaymentLabel($member['method']).' · '.$moneyText($member['due']).' · '.$memberPaymentStatus($member));
        $groupPaymentHtml.='<p style="font-size:12px;color:#667085;">'.$esc($groupPaymentHint).'</p></div>';
    }

    $invoiceHtml='';
    if($invoice){
        $invoiceFields='';
        $invoiceFields.=$field('Công ty / 公司名稱',(string)($order['invoice_company_name']??''));
        $invoiceFields.=$field('Mã số thuế / 統一稅號',(string)($order['invoice_tax_id']??''));
        $invoiceFields.=$field('Địa chỉ công ty / 公司地址',(string)($order['invoice_company_address']??''));
        $invoiceFields.=$field('Đại diện / 公司代表人',(string)(($order['invoice_company_representative']??'')?:'—'));
        $invoiceFields.=$field('Điện thoại công ty / 公司電話',(string)($order['invoice_company_phone']??''));
        $invoiceFields.=$field('Email hóa đơn / 發票 Email',(string)($order['invoice_email']??''));
        $invoiceHtml='<div style="margin-top:14px;background:#ffffff;border:1px solid #e4e7ec;border-radius:16px;padding:16px;">'
            .'<div style="font-size:13px;font-weight:900;color:#344054;margin-bottom:2px;">THÔNG TIN HÓA ĐƠN / 發票資料</div>'
            .$invoiceFields.'</div>';
    }

    $itemsHtml='';
    $itemNo=0;
    foreach($items as $item){
        $itemNo++;
        $name=(string)($item['product_name']??'');
        if(function_exists('occ_label')&&($label=occ_label($item))!=='')$name=($item['_kind']==='combo'?'['.$label.'] ':'').$name.($item['_kind']==='combo_drink'?' ['.$label.']':'');
        $size=trim((string)($item['cup_size']??''));
        $qty=max(1,(int)($item['qty']??1));
        $ice=trim((string)($item['ice_choice']??''));
        $sugar=trim((string)($item['sugar_choice']??''));
        $toppings=trim((string)($item['toppings_text']??''));
        $itemsHtml.='<div style="margin-top:12px;padding:15px;background:#f8fafc;border:1px solid #e4e7ec;border-radius:14px;">'
            .'<div style="font-size:11px;font-weight:800;color:#98a2b3;">Sản phẩm '.$itemNo.' / 商品 '.$itemNo.'</div>'
            .'<div style="margin-top:4px;font-size:16px;line-height:1.5;font-weight:900;color:#101828;word-break:break-word;">'.$esc($name).'</div>'
            .(!empty($item['group_member_name'])?$field('Người đặt / 訂購人',(string)$item['group_member_name']):'')
            .($size!==''?$field('Kích cỡ / 杯型',$size):'')
            .$field('Số lượng / 數量',(string)$qty)
            .($ice!==''?$field('Đá / 冰量',$ice):'')
            .($sugar!==''?$field('Đường / 甜度',$sugar):'')
            .($toppings!==''?$field('Topping / 配料',$toppings):'')
            .$field('Thành tiền / 金額',$moneyText($item['line_total']??0),true)
            .'</div>';
    }

    $subtotal=$moneyText($order['subtotal_amount']??0);
    $discount=(float)($order['discount_amount']??0);
    $total=$moneyText($order['total_amount']??0);
    $coupon=trim((string)($order['coupon_code']??''));
    $note=trim((string)($order['order_note']??''));
    $site=defined('APP_BASE_URL')?APP_BASE_URL:'';
    $store=store_name();
    $created=trim((string)($order['created_at']??''));

    $summaryFields='';
    if($coupon!=='') $summaryFields.=$field('Coupon / 優惠券',$coupon);
    $summaryFields.=$field('Tạm tính / 小計',$subtotal);
    if($discount>0) $summaryFields.=$field('Giảm giá / 優惠折抵','-'.$moneyText($discount));

    $html='<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        .'<body style="margin:0;padding:0;background:#f2f4f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#101828;">'
        .'<div style="padding:12px 8px;">'
        .'<div style="max-width:560px;margin:0 auto;">'
        .'<div style="background:#111827;border-radius:18px 18px 0 0;padding:20px 18px;color:#ffffff;">'
        .'<div style="font-size:11px;line-height:1.4;letter-spacing:.10em;font-weight:800;color:#d0d5dd;">ĐƠN HÀNG MỚI / 新訂單</div>'
        .'<div style="margin-top:7px;font-size:21px;line-height:1.35;font-weight:900;word-break:break-all;">'.$esc($orderNo).'</div>'
        .($created!==''?'<div style="margin-top:6px;font-size:12px;line-height:1.5;color:#d0d5dd;">'.$esc($created).'</div>':'')
        .'</div>'
        .'<div style="background:#ffffff;border:1px solid #e4e7ec;border-top:0;border-radius:0 0 18px 18px;padding:16px;">'
        .'<div style="font-size:13px;font-weight:900;color:#344054;margin-bottom:2px;">THÔNG TIN KHÁCH HÀNG / 客戶資料</div>'
        .$customerFields
        .$groupPaymentHtml
        .$invoiceHtml
        .'<div style="margin-top:16px;font-size:13px;font-weight:900;color:#344054;">CHI TIẾT ĐƠN HÀNG / 訂購明細</div>'
        .$itemsHtml
        .'<div style="margin-top:16px;background:#f8fafc;border:1px solid #e4e7ec;border-radius:16px;padding:16px;">'
        .'<div style="font-size:13px;font-weight:900;color:#344054;margin-bottom:2px;">THANH TOÁN / 金額</div>'
        .$summaryFields
        .'<div style="margin-top:12px;padding:14px 0 2px;border-top:2px solid #d0d5dd;">'
        .'<div style="font-size:12px;font-weight:900;color:#667085;">TỔNG CỘNG / 總金額</div>'
        .'<div style="margin-top:5px;font-size:28px;line-height:1.2;font-weight:950;color:#111827;word-break:break-word;">'.$esc($total).'</div>'
        .'</div></div>'
        .($note!==''?'<div style="margin-top:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:15px;">'
            .'<div style="font-size:11px;font-weight:900;color:#9a3412;">GHI CHÚ / 備註</div>'
            .'<div style="margin-top:6px;font-size:15px;line-height:1.6;font-weight:700;color:#7c2d12;white-space:pre-wrap;word-break:break-word;">'.$esc($note).'</div></div>':'')
        .'<div style="text-align:center;padding:20px 6px 2px;color:#98a2b3;font-size:11px;line-height:1.7;">'.$esc($store).($site!==''?'<br>'.$esc($site):'').'</div>'
        .'<div style="text-align:center;padding:3px 6px 0;color:#c0c5ce;font-size:9px;">Email template mobile v19.2</div>'
        .'</div></div></div></body></html>';

    $subject='[Order '.$orderNo.'] '.$customer;
    return ['subject'=>$subject,'body'=>implode("\r\n",$lines),'html'=>$html];
}

/**
 * Send and persist the result. This never throws to the checkout flow.
 * Returns true only when PHP mail() accepted the message.
 */
function send_order_notification_by_id(int $orderId,?string &$detail=null): bool {
    ensure_v179_schema();
    $recipients=order_notification_recipients();
    $detail='';
    $status='failed';
    $allOk=false;
    $accepted=0;
    $failed=0;
    $recipientDetails=[];
    try {
        if(!$recipients){
            $detail='invalid_or_empty_recipient';
        } else {
            $payload=order_notification_email_payload($orderId);
            if(!$payload){
                $detail='order_snapshot_not_found';
            } else {
                $mail=build_order_notification_email($payload['order'],$payload['items']);
                foreach($recipients as $to){
                    $mailDetail='';
                    $ok=system_mail_send($to,$mail['subject'],$mail['body'],$mailDetail,$mail['html']??null);
                    $recipientDetails[]=$to.'='.($ok?'accepted':($mailDetail?:'failed'));
                    if($ok) $accepted++; else $failed++;
                }
                $allOk=$failed===0 && $accepted===count($recipients);
                $status=$allOk?'sent':($accepted>0?'partial':'failed');
                $detail='accepted_'.$accepted.'_of_'.count($recipients);
                if($failed>0) $detail.=';'.implode('|',$recipientDetails);
            }
        }
    } catch(Throwable $e){
        $detail='exception_'.substr(hash('sha256',$e->getMessage()),0,12);
        $allOk=false;
        $status='failed';
    }
    try {
        $hasAccepted=$accepted>0;
        $sql="UPDATE orders SET notification_email_status=?,notification_email_detail=?,notification_email_attempts=COALESCE(notification_email_attempts,0)+1,notification_email_sent_at=".($hasAccepted?'NOW()':'notification_email_sent_at')." WHERE id=?";
        db()->prepare($sql)->execute([$status,substr((string)$detail,0,255),$orderId]);
    } catch(Throwable $e) {}
    return $allOk;
}

function order_notification_status_label(string $status): string {
    return match($status){
        'sent'=>t('email_status_sent'),
        'failed'=>t('email_status_failed'),
        'partial'=>t('email_status_partial'),
        default=>t('email_status_pending'),
    };
}
