<?php

/** Pure group settlement calculations. All amounts originate in resolved server-side cart rows. */
function hyg_money(float $amount): float
{
    return round(max(0.0, $amount), 2);
}
function hyg_checkout_mode(array $room): string
{
    return ($room['checkout_mode'] ?? 'together') === 'split' ? 'split' : 'together';
}
function hyg_payment_method(array $member, bool $host = false): string
{
    $method = (string)($member['payment_method'] ?? 'bank_transfer');
    if (!in_array($method, ['bank_transfer', 'cash', 'host_advance'], true) || ($host && $method === 'host_advance')) return 'bank_transfer';
    return $method;
}
function hyg_host_member_id(array $room, array $members): int
{
    foreach ($members as $member) if (isset($member['device_hash']) && hash_equals((string)$room['owner_hash'], (string)$member['device_hash'])) return (int)$member['id'];
    throw new RuntimeException('group_host_missing');
}
/** Monetary liability follows the checkout mode. Approval state is overlaid separately. */
function hyg_build_payment_summary(array $room, array $members, array $cart, array $coupon): array
{
    $hostId = hyg_host_member_id($room, $members);
    $mode = hyg_checkout_mode($room);
    $people = [];
    foreach ($members as $member) {
        $id = (int)$member['id'];
        $host = $id === $hostId;
        $method = $mode === 'together' && !$host ? 'host_advance' : hyg_payment_method($member, $host);
        $people[$id] = [
            'id' => $id,
            'nickname' => (string)$member['nickname'],
            'is_host' => $host,
            'subtotal' => 0.0,
            'discount' => 0.0,
            'due' => 0.0,
            'covered_amount' => 0.0,
            'method' => $method,
            'status' => in_array($member['payment_status'] ?? 'pending', ['pending', 'requested', 'paid'], true) ? ($member['payment_status'] ?? 'pending') : 'pending',
            'paid_at' => $member['payment_completed_at'] ?? null,
            'payer_member_id' => ($host || $method !== 'host_advance') ? $id : $hostId
        ];
    }
    $subtotal = 0.0;
    foreach ($cart as $row) {
        $id = (int)($row['group_member_id'] ?? 0);
        if (!isset($people[$id])) throw new RuntimeException('group_cart_member_missing');
        $amount = hyg_money((float)($row['unit_price'] ?? 0) * max(0, (int)($row['qty'] ?? 0)));
        $people[$id]['subtotal'] = hyg_money($people[$id]['subtotal'] + $amount);
        $subtotal = hyg_money($subtotal + $amount);
    }
    $hostSubtotal = $people[$hostId]['subtotal'];
    $advance = 0.0;
    foreach ($people as $id => &$person) {
        if ($id === $hostId) continue;
        if ($person['method'] === 'host_advance') {
            $advance = hyg_money($advance + $person['subtotal']);
            $person['covered_amount'] = $person['subtotal'];
        } else $person['due'] = $person['subtotal'];
    }
    unset($person);
    $obligation = $mode === 'together' ? $subtotal : hyg_money($hostSubtotal + $advance);
    $discount = hyg_money(min($obligation, (float)($coupon['discount'] ?? 0)));
    $people[$hostId]['discount'] = $discount;
    $people[$hostId]['due'] = hyg_money($obligation - $discount);
    if ($mode === 'together') foreach ($people as $id => &$person) if ($id !== $hostId) {
        $person['status'] = $people[$hostId]['status'];
        $person['paid_at'] = $people[$hostId]['paid_at'];
    }
    unset($person);
    return [
        'mode' => $mode,
        'collection_mode' => ($room['collection_mode'] ?? 'store') === 'host' ? 'host' : 'store',
        'frozen' => false,
        'legacy' => false,
        'protocol_version' => (int)($room['payment_protocol_version'] ?? 2),
        'has_items' => (bool)$cart,
        'host_member_id' => $hostId,
        'host_subtotal' => $hostSubtotal,
        'host_advance' => $advance,
        'host_obligation' => $obligation,
        'host_discount' => $discount,
        'host_due' => $people[$hostId]['due'],
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => hyg_money($subtotal - $discount),
        'has_paid' => false,
        'paid_amount' => 0.0,
        'members' => $people
    ];
}
/**
 * For each complete set of five drinks, discount the group's lowest base drink price.
 * Eligibility never includes toppings. The financial reduction belongs exclusively to
 * the host, capped at their own order plus advances; line reductions follow that payer.
 */
function hyg_calculate_group_coupon(array $cart, ?array $record, array $room, array $members): array
{
    $plan = hyg_build_payment_summary($room, $members, $cart, ['discount' => 0]);
    $giftLineKeys = null;
    if (($record['type'] ?? '') === 'game_reward') {
        $giftLineKeys = [];
        foreach ($cart as $key => $row) if ((int)$plan['members'][(int)$row['group_member_id']]['payer_member_id'] === $plan['host_member_id']) $giftLineKeys[] = (string)$key;
    }
    $result = calculate_cart_coupon_summary($cart, $record, $giftLineKeys);
    $result['line_discounts'] = [];
    $result['original_amount'] = (float)($record['amount'] ?? 0);
    $result['host_obligation'] = $plan['host_obligation'];
    $result['unapplied_reason'] = '';
    $result['requested_discount'] = 0.0;
    if (!$record) return $result;
    if (($record['type'] ?? '') === 'buy4get1') {
        $lowest = null;
        $qty = 0;
        foreach ($cart as $row) {
            if (empty($row['product_id']) || !empty($row['standalone_topping_id']) || !empty($row['combo_drink'])) continue;
            $count = max(0, (int)($row['qty'] ?? 0));
            if (!$count) continue;
            $base = hyg_money((float)($row['base_price'] ?? 0));
            $qty += $count;
            $lowest = $lowest === null ? $base : min($lowest, $base);
        }
        $result['eligible_qty'] = $qty;
        $result['free_qty'] = (int)floor($qty / 5);
        $result['discount'] = hyg_money(($lowest ?? 0) * $result['free_qty']);
    }
    $result['requested_discount'] = hyg_money((float)$result['discount']);
    // New random prizes must be applied in full; keep the coupon when host liability is too small.
    if (($record['type'] ?? '') === 'game_reward' && $plan['host_obligation'] < $result['requested_discount']) {
        $result['discount'] = 0.0;
        $result['free_qty'] = 0;
        $result['unapplied_reason'] = 'coupon_no_host_amount';
        return $result;
    }
    if ($plan['host_obligation'] <= 0) {
        $result['valid'] = false;
        $result['discount'] = 0.0;
        $result['unapplied_reason'] = 'coupon_no_host_amount';
        return $result;
    }
    $remaining = hyg_money(min($plan['host_obligation'], (float)$result['discount']));
    $result['discount'] = $remaining;
    foreach ($cart as $key => $row) {
        $member = $plan['members'][(int)$row['group_member_id']];
        if ((int)$member['payer_member_id'] !== $plan['host_member_id']) continue;
        $line = hyg_money((float)($row['unit_price'] ?? 0) * max(0, (int)($row['qty'] ?? 0)));
        $take = hyg_money(min($line, $remaining));
        if ($take > 0) $result['line_discounts'][(string)$key] = $take;
        $remaining = hyg_money($remaining - $take);
        if ($remaining <= 0) break;
    }
    if ($remaining > 0.001) throw new RuntimeException('group_discount_allocation_failed');
    return $result;
}
/** Only immutable amounts and payer choices are signed; later paid reports do not change the signature. */
function hyg_settlement_signature_parts(array $summary): array
{
    $members = [];
    foreach ($summary['members'] as $id => $person) $members[(string)$id] = [$person['subtotal'], $person['discount'], $person['due'], $person['covered_amount'], $person['method'], $person['payer_member_id']];
    $parts = [$summary['mode'], $summary['host_member_id'], $summary['subtotal'], $summary['discount'], $summary['total'], $members];
    if (($summary['collection_mode'] ?? 'store') === 'host') $parts[] = 'host_collection_v1';
    return $parts;
}
function hyg_member_payment(array $summary, int $memberId): ?array
{
    return $summary['members'][$memberId] ?? null;
}
/** Compatible read of older in-memory summaries; persisted rooms use the audited receipt columns. */
function hyg_member_ledger_amounts(array $member): array
{
    $received = hyg_money((float)($member['payment_received_amount'] ?? (!empty($member['payment_confirmed_at']) ? ($member['payment_confirmed_amount'] ?? 0) : 0)));
    $refunded = hyg_money((float)($member['payment_refunded_amount'] ?? 0));
    return ['received_amount' => $received, 'refunded_amount' => $refunded, 'net_received' => hyg_money($received - $refunded)];
}
function hyg_room_has_payment_work(array $members): bool
{
    foreach ($members as $member) if (in_array($member['payment_status'], ['requested', 'paid'], true) || hyg_member_ledger_amounts($member)['net_received'] > 0) return true;
    return false;
}
/** Ordering expiry cannot abandon a payment already reported or received. */
function hyg_room_payment_active(array $room, array $members = []): bool
{
    if (($room['status'] ?? 'locked') !== 'locked') return false;
    $deadline = $room['payment_expires_at'] ?? $room['expires_at'] ?? null;
    return $deadline === null || strtotime($deadline . ' UTC') > time() || hyg_room_has_payment_work($members);
}
/** Apply reports and host approvals to frozen liabilities; covered people follow their actual payer. */
function hyg_overlay_payment_reports(array $summary, array $members, array $room = []): array
{
    $protocol = (int)($room['payment_protocol_version'] ?? $summary['protocol_version'] ?? 2);
    $summary['protocol_version'] = $protocol;
    $summary['approval_legacy'] = $protocol < 2;
    $summary['has_paid'] = false;
    $summary['paid_amount'] = 0.0;
    $summary['has_reports'] = false;
    $summary['has_requested'] = false;
    $summary['required_payers'] = 0;
    $summary['confirmed_payers'] = 0;
    $active = ($room['status'] ?? 'locked') === 'locked' ? hyg_room_payment_active($room, $members) : (($room['status'] ?? 'open') === 'open' && (!isset($room['expires_at']) || strtotime($room['expires_at'] . ' UTC') > time()));
    $locked = $active && ($room['status'] ?? 'locked') === 'locked' && !empty($summary['frozen']) && !$summary['legacy'] && $protocol >= 2;
    foreach ($members as $member) {
        $id = (int)$member['id'];
        if (!isset($summary['members'][$id])) continue;
        $person = &$summary['members'][$id];
        $due = (float)$person['due'];
        $raw = (string)($member['payment_status'] ?? 'pending');
        $requested = $member['payment_requested_at'] ?? null;
        $reported = isset($member['payment_requested_amount']) ? (float)$member['payment_requested_amount'] : null;
        $confirmedAt = $member['payment_confirmed_at'] ?? null;
        $confirmedBy = (int)($member['payment_confirmed_by_member_id'] ?? 0);
        $ledger = hyg_member_ledger_amounts($member);
        $person = array_merge($person, $ledger);
        $person['outstanding'] = hyg_money($due - $ledger['net_received']);
        $person['refund_due'] = hyg_money($ledger['net_received'] - $due);
        $confirmed = $protocol >= 2 && $raw === 'paid' && $confirmedAt && $confirmedBy === (int)$summary['host_member_id']
            && isset($member['payment_confirmed_amount']) && hyg_money((float)$member['payment_confirmed_amount']) === hyg_money($due) && hyg_money($ledger['net_received']) === hyg_money($due) && $due > 0;
        $status = $protocol < 2 ? ($raw === 'paid' ? 'paid' : 'pending') : ($confirmed ? 'paid' : (in_array($raw, ['paid', 'requested'], true) ? 'requested' : (in_array($raw, ['rejected', 'refunded'], true) ? $raw : 'pending')));
        $person['status'] = $status;
        $person['confirmed'] = (bool)$confirmed;
        $person['raw_status'] = $raw;
        $person['requested_at'] = $requested;
        $person['requested_amount'] = $reported;
        $person['confirmed_at'] = $confirmed ? $confirmedAt : null;
        $person['confirmed_by_member_id'] = $confirmed ? $confirmedBy : null;
        $person['last_receipt_confirmed_at'] = $confirmedAt;
        $person['paid_at'] = $protocol < 2 ? ($status === 'paid' ? ($member['payment_completed_at'] ?? null) : null) : $person['confirmed_at'];
        $person['invalidated_at'] = $member['payment_invalidated_at'] ?? null;
        $person['invalidation_reason'] = $member['payment_invalidation_reason'] ?? '';
        $person['amount_changed'] = $protocol >= 2 && $status === 'requested' && ($reported === null || hyg_money($reported) !== hyg_money($person['outstanding']) || $person['refund_due'] > 0);
        if (in_array($raw, ['paid', 'requested'], true)) $summary['has_reports'] = true;
        if ($status === 'requested') $summary['has_requested'] = true;
        if ($due > 0) {
            $summary['required_payers']++;
            if ($confirmed) $summary['confirmed_payers']++;
            if ($status === 'paid') {
                $summary['has_paid'] = true;
                $summary['paid_amount'] = hyg_money($summary['paid_amount'] + $due);
            }
        }
        unset($person);
    }
    foreach ($summary['members'] as $id => &$person) {
        $covered = $protocol >= 2 && (int)$person['payer_member_id'] !== $id && (float)$person['covered_amount'] > 0;
        $legacyCovered = $protocol < 2 && $summary['mode'] === 'together' && $id !== $summary['host_member_id'];
        if ($covered || $legacyCovered) {
            $payer = $summary['members'][(int)$person['payer_member_id']];
            foreach (['status', 'paid_at', 'confirmed', 'requested_at', 'requested_amount', 'confirmed_at', 'confirmed_by_member_id', 'invalidated_at', 'invalidation_reason', 'amount_changed'] as $key) $person[$key] = $payer[$key] ?? null;
        }
        $person['can_request'] = $locked && !$person['is_host'] && (float)$person['due'] > 0 && $person['refund_due'] <= 0 && (in_array($person['status'], ['pending', 'rejected', 'refunded'], true) || $person['amount_changed']);
        $person['can_confirm'] = $locked && (float)$person['due'] > 0 && $person['refund_due'] <= 0 && $person['status'] !== 'paid' && ($person['is_host'] || ($person['status'] === 'requested' && !$person['amount_changed']));
        $validPaid = $person['status'] === 'paid' && ((float)$person['due'] > 0 || (float)$person['covered_amount'] > 0) && ($protocol < 2 || $person['confirmed']);
        $person['can_remove'] = $active && !$person['is_host'] && !$validPaid && $person['status'] !== 'requested' && $person['net_received'] <= 0;
        $person['can_reject'] = ($room['status'] ?? 'locked') === 'locked' && $person['status'] === 'requested' && ($person['raw_status'] === 'requested' || $person['net_received'] <= 0) && (int)$person['payer_member_id'] === $id;
        $pendingIncrement = $person['raw_status'] === 'requested' && !$person['amount_changed'] && (float)($person['requested_amount'] ?? 0) > 0;
        $person['can_refund'] = ($room['status'] ?? 'locked') === 'locked' && $person['net_received'] > 0 && (int)$person['payer_member_id'] === $id && !$pendingIncrement;
        $canCorrect = ($room['status'] ?? 'locked') === 'locked' && (int)$person['payer_member_id'] === $id && !empty($person['last_receipt_confirmed_at']) && !$pendingIncrement;
        $person['can_correct_received'] = $canCorrect && $person['received_amount'] > $person['refunded_amount'];
        $person['can_correct_refunded'] = $canCorrect && $person['refunded_amount'] > 0;
        $person['can_correct'] = $person['can_correct_received'] || $person['can_correct_refunded'];
    }
    unset($person);
    $summary['refund_due'] = hyg_money(array_sum(array_column($summary['members'], 'refund_due')));
    $summary['received_amount'] = hyg_money(array_sum(array_column($summary['members'], 'received_amount')));
    $summary['refunded_amount'] = hyg_money(array_sum(array_column($summary['members'], 'refunded_amount')));
    $summary['all_confirmed'] = $protocol >= 2 && $summary['confirmed_payers'] === $summary['required_payers'] && $summary['refund_due'] <= 0;
    $summary['can_checkout'] = $locked && !empty($summary['has_items']) && $summary['all_confirmed'];
    return $summary;
}
/** Only genuine payer liabilities gate checkout; zero balances and covered members never add requirements. */
function hyg_payments_confirmed(array $summary): bool
{
    if ((int)($summary['protocol_version'] ?? 0) < 2) return false;
    foreach ($summary['members'] as $person) if (($person['refund_due'] ?? 0) > 0 || ((float)$person['due'] > 0 && ($person['status'] !== 'paid' || empty($person['confirmed'])))) return false;
    return true;
}
/** Preserve audit reports when removal changes an actual payer's amount. */
function hyg_payment_amount_changed(array $before, array $after): bool
{
    return hyg_money((float)$before['due']) !== hyg_money((float)$after['due'])
        || (int)$before['payer_member_id'] !== (int)$after['payer_member_id'] || $before['method'] !== $after['method'];
}
function hyg_frozen_coupon_record(array $room, array $settlement): ?array
{
    if (array_key_exists('coupon_record', $settlement)) return $settlement['coupon_record'];
    $coupon = $settlement['coupon'];
    $code = (string)($coupon['code'] ?? $room['coupon_code'] ?? '');
    if ($code === '') return null;
    $type = (string)($coupon['type'] ?? $room['coupon_type'] ?? '');
    // A removal cannot increase eligible drink bases. For old fixed coupons, the
    // frozen uncapped reduction is therefore an equivalent upper limit to the original amount.
    $amount = $coupon['original_amount'] ?? ($type === 'fixed_drink' ? ($coupon['requested_discount'] ?? $room['coupon_amount'] ?? 0) : ($room['coupon_amount'] ?? 0));
    return ['code' => $code, 'type' => $type, 'source' => (string)($coupon['source'] ?? $room['coupon_source'] ?? ''), 'amount' => (float)$amount];
}
function hyg_payment_quote_signature_valid(array $room, mixed $posted): bool
{
    return is_string($posted) && preg_match('/^[a-f0-9]{64}$/D', $posted) && is_string($room['lock_signature'] ?? null) && hash_equals($room['lock_signature'], $posted);
}
/** Administrative/order email read helper; legacy orders have no synthetic payment snapshot. */
function hyg_order_payment_summary(array $order): ?array
{
    $raw = $order['group_payment_summary'] ?? null;
    if (!is_string($raw) || $raw === '') return null;
    $summary = json_decode($raw, true);
    return is_array($summary) && isset($summary['members'], $summary['mode']) ? $summary : null;
}
