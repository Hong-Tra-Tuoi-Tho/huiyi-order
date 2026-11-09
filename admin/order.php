<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/group_payment_views.php';
require_once __DIR__ . '/../lib/dispatch_views.php';
require_once __DIR__ . '/../lib/order_workflow.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
hds_view_require_staff(base_url('admin/login.php' . ($id > 0 ? '?return_order=' . $id : '')));
$msg = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  try {
    $action = $_POST['action'] ?? null;
    if (!is_string($action)) throw new InvalidArgumentException('invalid_action');
    if (in_array($action, ['dispatch_refresh', 'dispatch_assign', 'dispatch_adopt', 'dispatch_clear'], true)) {
      $_SESSION['dispatch_order_flash'] = ['id' => $id, 'key' => hds_view_order_action($id, $action, $_POST, (int)$_SESSION['admin_id'])];
      redirect(base_url('admin/order.php?id=' . $id . (admin_is_embed() ? '&embed=1' : '') . '#hds-dispatch-heading'));
    } elseif ($action === 'confirm_merchant_payment') {
      htp_confirm($id, $_POST['payment_version'] ?? null, (int)$_SESSION['admin_id']);
      $msg = htp('staff_saved');
    } elseif (in_array($action, ['payment_receive', 'payment_correct', 'payment_reject', 'payment_refund'], true)) {
      htp_staff_action($id, $action, $_POST, (int)$_SESSION['admin_id']);
      $msg = pwf('action_saved');
    } elseif ($action === 'resend_email') {
      $detail = '';
      if (send_order_notification_by_id($id, $detail)) $msg = t('order_email_resent');
      else $error = t('order_email_resend_failed', ['detail' => $detail ?: 'unknown']);
    } elseif ($action === 'status') {
      htow_change($id, $_POST['status'] ?? null, $_POST['status_version'] ?? null, $_POST['status_reason'] ?? null, (int)$_SESSION['admin_id']);
      $msg = pwf('status_saved');
    } else throw new InvalidArgumentException('invalid_action');
  } catch (Throwable $exception) {
    $error = htow_error($exception);
  }
}
$dispatchFlash = $_SESSION['dispatch_order_flash'] ?? null;
unset($_SESSION['dispatch_order_flash']);
if (is_array($dispatchFlash) && (int)($dispatchFlash['id'] ?? 0) === $id && in_array($dispatchFlash['key'] ?? '', ['dispatch_saved', 'dispatch_refreshed'], true)) $msg = dt($dispatchFlash['key']);
$st = db()->prepare('SELECT * FROM orders WHERE id=?');
$st->execute([$id]);
$o = $st->fetch();
if (!$o) exit(e(t('order_not_found')));
// Dòng đơn theo thứ tự hiển thị: dòng combo, rồi các ly nước của combo, rồi dòng thường (lib/order_combo.php).
$items = order_lines_flat($id);
$eligibleQty = order_eligible_drink_qty($id);
admin_header(t('order_no') . ' ' . $o['order_no']);
?>
<?php if ($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?><?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<?= hds_render_order_dispatch($o) ?>
<?= htp_render_staff($o) ?>
<?php if (!empty($o['group_title'])): ?><section class="panel">
    <h2><?= e(gt('title')) ?></h2><strong><?= e($o['group_title']) ?></strong>
    <p class="muted"><?= e(gt('rules')) ?></p>
  </section><?php endif; ?>
<?php $groupPayment = hyg_order_payment_summary($o);
if ($groupPayment): ?><section class="panel"><?= hyg_render_payment_overview($groupPayment) ?></section><?php endif; ?>
<div class="admin-grid two">
  <section class="panel">
    <h2><?= e(t('customer_info')) ?></h2>
    <dl class="meta">
      <dt><?= e(t('name')) ?></dt>
      <dd><?= e($o['customer_name']) ?></dd>
      <dt><?= e(t('phone')) ?></dt>
      <dd><?= e($o['phone']) ?></dd>
      <dt><?= e(t('address')) ?></dt>
      <dd><?= nl2br(e($o['address'])) ?></dd>
      <dt><?= e(t('payment')) ?></dt>
      <dd><?= e(payment_label($o['payment_method'])) ?></dd>
      <dt><?= e(t('invoice')) ?></dt>
      <dd><?= $o['invoice_required'] ? e(t('needed')) : e(t('not_needed')) ?></dd>
      <dt><?= e(t('customer_note')) ?></dt>
      <dd><?= !empty($o['order_note']) ? nl2br(e($o['order_note'])) : '—' ?></dd>
      <dt><?= e(t('time')) ?></dt>
      <dd><?= e($o['created_at']) ?></dd>
    </dl>
  </section>
  <section class="panel">
    <h2><?= e(t('order_status')) ?></h2>
    <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="status_version" value="<?= (int)$o['status_version'] ?>">
      <select name="status">
        <option value="<?= e($o['status']) ?>"><?= e(status_label($o['status'])) ?></option>
        <?php foreach (htow_targets($o['status']) as $nextStatus): ?><option value="<?= e($nextStatus) ?>"><?= e(status_label($nextStatus)) ?></option><?php endforeach; ?>
      </select>
      <label><?= e(pwf('status_reason')) ?><input name="status_reason" maxlength="1000"></label>
      <button class="btn primary" type="submit" name="action" value="status" <?= htow_targets($o['status']) ? '' : 'disabled' ?>><?= e(t('update_status')) ?></button>
    </form>
    <?php if (minigame_enabled()): ?><div class="game-order-summary">
        <div><span><?= e(t('game_eligible_qty')) ?></span><strong><?= number_format($eligibleQty) ?></strong></div>
        <div><span><?= e(t('game_awarded')) ?></span><strong><?= number_format((int)($o['game_chances_awarded'] ?? 0)) ?></strong></div>
      </div><?php endif; ?>
  </section>
</div>
<?= htow_render_history($o) ?>

<section class="panel">
  <h2><?= e(t('order_email_delivery')) ?></h2>
  <dl class="meta">
    <dt><?= e(t('email_recipient')) ?></dt>
    <dd><?php $mailRecipients = order_notification_recipients();
        if ($mailRecipients): ?><?php foreach ($mailRecipients as $mailRecipient): ?><div class="email-recipient-display"><?= e($mailRecipient) ?></div><?php endforeach; ?><?php else: ?>—<?php endif; ?></dd>
    <dt><?= e(t('email_status')) ?></dt>
    <dd><span class="status <?= e(($o['notification_email_status'] ?? 'pending') === 'sent' ? 'done' : (($o['notification_email_status'] ?? 'pending') === 'failed' ? 'cancelled' : (($o['notification_email_status'] ?? 'pending') === 'partial' ? 'accepted' : 'new'))) ?>"><?= e(order_notification_status_label((string)($o['notification_email_status'] ?? 'pending'))) ?></span></dd>
    <dt><?= e(t('email_attempts')) ?></dt>
    <dd><?= number_format((int)($o['notification_email_attempts'] ?? 0)) ?></dd>
    <dt><?= e(t('email_sent_at')) ?></dt>
    <dd><?= e(($o['notification_email_sent_at'] ?? '') ?: '—') ?></dd>
    <dt><?= e(t('email_diagnostic')) ?></dt>
    <dd><code><?= e(($o['notification_email_detail'] ?? '') ?: '—') ?></code></dd>
  </dl>
  <form method="post" class="form-grid"><?= csrf_field() ?><button class="btn" type="submit" name="action" value="resend_email"><?= e(t('resend_order_email')) ?></button></form>
</section>

<?php if (!empty($o['invoice_required'])): ?>
  <section class="panel">
    <h2><?= e(t('invoice_info')) ?></h2>
    <dl class="meta invoice-meta">
      <dt><?= e(t('company_name')) ?></dt>
      <dd><?= e($o['invoice_company_name'] ?? '') ?></dd>
      <dt><?= e(t('tax_id')) ?></dt>
      <dd><?= e($o['invoice_tax_id'] ?? '') ?></dd>
      <dt><?= e(t('company_address')) ?></dt>
      <dd><?= nl2br(e($o['invoice_company_address'] ?? '')) ?></dd>
      <dt><?= e(t('company_representative')) ?></dt>
      <dd><?= e(($o['invoice_company_representative'] ?? '') ?: '—') ?></dd>
      <dt><?= e(t('company_phone')) ?></dt>
      <dd><?= e($o['invoice_company_phone'] ?? '') ?></dd>
      <dt><?= e(t('invoice_email')) ?></dt>
      <dd><?php if (!empty($o['invoice_email'])): ?><a href="mailto:<?= e($o['invoice_email']) ?>"><?= e($o['invoice_email']) ?></a><?php else: ?>—<?php endif; ?></dd>
    </dl>
  </section>
<?php endif; ?>

<section class="panel">
  <h2><?= e(t('order_details')) ?></h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= e(t('drink')) ?></th>
          <th><?= e(t('cup_size')) ?></th>
          <th><?= e(t('ice')) ?></th>
          <th><?= e(t('sugar')) ?></th>
          <th><?= e(t('toppings')) ?></th>
          <th><?= e(t('quantity')) ?></th>
          <th><?= e(t('unit_price')) ?></th>
          <th><?= e(t('subtotal')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i): ?><tr>
            <td><?= e($i['product_name']) ?><?php if (($i['_kind'] ?? 'item') !== 'item'): ?><small class="hyg-order-tag"><?= e(occ_label($i)) ?></small><?php endif; ?><?php if (!empty($i['group_member_name'])): ?><small class="hyg-order-tag"><?= e(gt('ordered_by')) ?>：<?= e($i['group_member_name']) ?></small><?php endif; ?></td>
            <td><?= e($i['cup_size'] ?? '—') ?></td>
            <td><?= e($i['ice_choice'] ?: '—') ?></td>
            <td><?= e($i['sugar_choice'] ?: '—') ?></td>
            <td><?= e($i['toppings_text'] ?: '—') ?></td>
            <td><?= $i['qty'] ?></td>
            <td><?= e(currency()) ?><?= money($i['unit_price']) ?></td>
            <td><?= e(currency()) ?><?= money($i['line_total']) ?></td>
          </tr><?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="order-total-breakdown">
    <?php if (!empty($o['coupon_code'])): ?><div><span><?= e(t('coupon')) ?> · <?= e($o['coupon_code']) ?></span><strong><?= e(t('applied')) ?></strong></div><?php endif; ?>
    <?php if (isset($o['subtotal_amount']) && (float)$o['subtotal_amount'] > 0): ?><div><span><?= e(t('subtotal')) ?></span><strong><?= e(currency()) ?><?= money($o['subtotal_amount']) ?></strong></div><?php endif; ?>
    <?php if (!empty($o['discount_amount'])): ?><div class="discount-line"><span><?= e(t('coupon_discount')) ?></span><strong>-<?= e(currency()) ?><?= money($o['discount_amount']) ?></strong></div><?php endif; ?>
    <div class="grand-total"><?= e(t('grand_total')) ?> <strong><?= e(currency()) ?><?= money($o['total_amount']) ?></strong></div>
  </div>
</section>
<?php admin_footer(); ?>