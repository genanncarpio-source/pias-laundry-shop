<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_feature_schema();
ensure_customer_auth_schema();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

/* ---------- Handle status update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_status']) || isset($_POST['cancel_order']) || isset($_POST['refund_order']) || isset($_POST['collect_payment']))) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid request.');
    } else {
        try {
            $current = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $current->execute([$id]);
            $currentOrder = $current->fetch();
            if (!$currentOrder) throw new InvalidArgumentException('Order not found.');
            if (isset($_POST['collect_payment'])) {
                if ($currentOrder['status'] === 'cancelled') throw new InvalidArgumentException('Cancelled service tickets cannot receive payment.');
                $method = $_POST['payment_method'] ?? '';
                if (!in_array($method, ['cash', 'gcash', 'maya', 'card'], true)) throw new InvalidArgumentException('Choose a valid payment method.');
                $alreadyPaid = max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                $due = round((float)$currentOrder['total'] - $alreadyPaid, 2);
                if ($due <= 0) throw new InvalidArgumentException('This service ticket is already paid.');
                if ($method === 'cash') {
                    $tendered = round((float)($_POST['amount_tendered'] ?? 0), 2);
                    if ($tendered < $due) throw new InvalidArgumentException('Cash received must cover the remaining balance.');
                    $paidAmount = $tendered;
                    $changeDue = round($tendered - $due, 2);
                } else {
                    $paidAmount = $due;
                    $changeDue = 0;
                }
                $pdo->prepare('UPDATE orders SET amount_paid = ?, change_due = ?, payment_method = ?, paid_at = NOW() WHERE id = ?')->execute([$alreadyPaid + $paidAmount, $changeDue, $method, $id]);
                flash_set('success', 'Payment recorded for the service ticket.');
            } elseif (isset($_POST['refund_order'])) {
                if (!is_admin()) throw new InvalidArgumentException('Only an administrator can record a refund.');
                $amount = round((float)($_POST['refund_amount'] ?? 0), 2);
                $reason = trim($_POST['refund_reason'] ?? '');
                $refundMethod = $_POST['refund_method'] ?? $currentOrder['payment_method'];
                if (!in_array($refundMethod, ['cash', 'gcash', 'maya', 'card'], true)) throw new InvalidArgumentException('Choose a valid refund method.');
                $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_adjustments WHERE order_id = ? AND adjustment_type = 'refund'");
                $sum->execute([$id]);
                $paid = max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                $remaining = $paid - (float)$sum->fetchColumn();
                if ($currentOrder['status'] === 'cancelled' || $amount <= 0 || $amount > $remaining || $reason === '') throw new InvalidArgumentException('Enter a refund amount up to the remaining refundable balance and a reason.');
                $pdo->prepare("INSERT INTO order_adjustments (order_id, adjustment_type, amount, payment_method, reason, created_by) VALUES (?, 'refund', ?, ?, ?, ?)")->execute([$id, $amount, $refundMethod, $reason, $_SESSION['user_id']]);
                flash_set('success', 'Refund recorded.');
            } elseif (isset($_POST['cancel_order'])) {
                $reason = trim($_POST['cancel_reason'] ?? '');
                $refund = round((float)($_POST['cancel_refund'] ?? 0), 2);
                $cancelRefundMethod = $_POST['cancel_refund_method'] ?? $currentOrder['payment_method'];
                if ($currentOrder['status'] === 'cancelled' || $currentOrder['status'] === 'picked_up' || $reason === '') throw new InvalidArgumentException('Enter a reason. Picked-up or already cancelled orders cannot be cancelled.');
                if ($refund > 0 && !is_admin()) throw new InvalidArgumentException('An administrator must record a refund.');
                if (!in_array($cancelRefundMethod, ['cash', 'gcash', 'maya', 'card'], true)) throw new InvalidArgumentException('Choose a valid refund method.');
                $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_adjustments WHERE order_id = ? AND adjustment_type = 'refund'");
                $sum->execute([$id]);
                $paid = max(0, (float)$currentOrder['amount_paid'] - (float)$currentOrder['change_due']);
                if ($refund < 0 || $refund > $paid - (float)$sum->fetchColumn()) throw new InvalidArgumentException('Refund cannot exceed the amount already paid.');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO order_adjustments (order_id, adjustment_type, amount, reason, created_by) VALUES (?, 'cancellation', 0, ?, ?)")->execute([$id, $reason, $_SESSION['user_id']]);
                if ($refund > 0) $pdo->prepare("INSERT INTO order_adjustments (order_id, adjustment_type, amount, payment_method, reason, created_by) VALUES (?, 'refund', ?, ?, ?, ?)")->execute([$id, $refund, $cancelRefundMethod, 'Refund on cancellation: ' . $reason, $_SESSION['user_id']]);
                $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$id]);
                add_order_history($id, 'cancelled', 'Cancelled: ' . $reason, (int)$_SESSION['user_id']);
                $pdo->commit();
                flash_set('success', 'Service ticket cancelled and reason recorded.');
            } else {
                $allowed = ['pending', 'washing', 'ready', 'picked_up'];
                $status = $_POST['status'] ?? '';
                if (!in_array($status, $allowed, true) || $currentOrder['status'] === 'cancelled') throw new InvalidArgumentException('Invalid status update.');
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
                $label = order_status_labels()[$status] ?? ucwords(str_replace('_', ' ', $status));
                add_order_history($id, $status, 'Status changed to ' . $label, (int)$_SESSION['user_id']);
                flash_set('success', 'Ticket status updated to ' . $label . '.');
            }
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('error', 'Could not save the service ticket change.');
        }
    }
    redirect("order_view.php?id=$id");
}

$stmt = $pdo->prepare("SELECT o.*, u.full_name AS created_by_name, c.phone AS customer_phone
                       FROM orders o LEFT JOIN users u ON u.id = o.created_by
                       LEFT JOIN customers c ON c.id = o.customer_id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'Service ticket not found.');
    redirect('orders.php');
}

$items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$lines = $items->fetchAll();

$statusFlow   = ['pending', 'washing', 'ready', 'picked_up'];
$statusLabels = order_status_labels();

/* ---------- Status timeline for this order ---------- */
$history = order_history($id);
$adjustmentStmt = $pdo->prepare('SELECT a.*, u.full_name FROM order_adjustments a LEFT JOIN users u ON u.id = a.created_by WHERE a.order_id = ? ORDER BY a.id DESC');
$adjustmentStmt->execute([$id]);
$adjustments = $adjustmentStmt->fetchAll();
$refundTotal = 0.0;
foreach ($adjustments as $adjustment) if ($adjustment['adjustment_type'] === 'refund') $refundTotal += (float)$adjustment['amount'];
$paidTotal = max(0, (float)$order['amount_paid'] - (float)$order['change_due']);
$balanceDue = max(0, round((float)$order['total'] - $paidTotal, 2));
$smsMessage = 'Hi ' . ($order['customer_name'] ?: 'there') . ', your laundry is ready for pickup. Ticket ' . $order['order_number'] . ' at ' . get_setting('shop_name', "Pia's Laundry Shop") . '.';
$smsPhone = preg_replace('/[^0-9+]/', '', (string)($order['customer_phone'] ?? ''));

$pageTitle = 'Ticket ' . $order['order_number'];
$pageSubtitle = 'Laundry service details';
include __DIR__ . '/includes/header.php';
?>

<div class="row-between mb-16">
  <a class="btn btn-outline btn-sm" href="orders.php">← All laundry tickets</a>
  <div>
    <a class="btn btn-outline btn-sm" href="receipt.php?id=<?= (int)$id ?>" target="_blank">🖨️ Receipt</a>
  </div>
</div>

<div class="grid two">
  <div class="card">
    <h2>Summary</h2>
    <table>
      <tbody>
        <tr><td class="muted">Ticket #</td><td><strong><?= e($order['order_number']) ?></strong></td></tr>
        <tr><td class="muted">Date</td><td><?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></td></tr>
        <tr><td class="muted">Type</td><td><span class="badge badge-<?= e($order['order_type']) ?>"><?= $order['order_type'] === 'drop_off' ? 'Drop-off' : 'Walk-in' ?></span></td></tr>
        <tr><td class="muted">Status</td><td><span class="badge badge-<?= e($order['status']) ?>"><?= e($statusLabels[$order['status']] ?? $order['status']) ?></span></td></tr>
        <tr><td class="muted">Customer</td><td><?= e($order['customer_name'] ?: 'Walk-in') ?></td></tr>
        <?php if ($order['expected_pickup']): ?>
          <tr><td class="muted">Expected Pickup</td><td><?= e(date('M j, Y g:i A', strtotime($order['expected_pickup']))) ?></td></tr>
        <?php endif; ?>
        <tr><td class="muted">Payment</td><td><?= e(strtoupper($order['payment_method'])) ?></td></tr>
        <tr><td class="muted">Paid</td><td><?= money($paidTotal) ?></td></tr>
        <tr><td class="muted">Balance due</td><td><strong><?= money($balanceDue) ?></strong></td></tr>
        <tr><td class="muted">Created by</td><td><?= e($order['created_by_name'] ?: '—') ?></td></tr>
        <?php if ($order['notes']): ?>
          <tr><td class="muted">Notes</td><td><?= e($order['notes']) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Services</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($lines as $l): ?>
          <tr>
            <td><?= e($l['service_name']) ?></td>
            <td><?= e(rtrim(rtrim(number_format($l['quantity'], 2), '0'), '.')) ?> <?= e($l['unit']) ?></td>
            <td class="num"><?= money($l['unit_price']) ?></td>
            <td class="num"><?= money($l['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="right"><strong>Subtotal</strong></td>
            <td class="num"><?= money($order['subtotal']) ?></td>
          </tr>
          <?php if ($order['discount'] > 0): ?>
          <tr>
            <td colspan="3" class="right muted">Discount</td>
            <td class="num">-<?= money($order['discount']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td colspan="3" class="right"><strong>Total</strong></td>
            <td class="num"><strong><?= money($order['total']) ?></strong></td>
          </tr>
          <?php if ($order['payment_method'] === 'cash'): ?>
          <tr>
            <td colspan="3" class="right muted">Tendered</td>
            <td class="num"><?= money($order['amount_paid']) ?></td>
          </tr>
          <tr>
            <td colspan="3" class="right muted">Change</td>
            <td class="num"><?= money($order['change_due']) ?></td>
          </tr>
          <?php endif; ?>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php if ($balanceDue > 0 && $order['status'] !== 'cancelled'): ?>
<div class="card mt-16">
  <h2>Record payment</h2>
  <p class="muted">Amount due: <strong><?= money($balanceDue) ?></strong></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?><input type="hidden" name="collect_payment" value="1">
    <div><label for="collectPaymentMethod">Payment method</label><select id="collectPaymentMethod" name="payment_method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card">Card</option></select></div>
    <div><label for="paymentTendered">Cash received</label><input id="paymentTendered" name="amount_tendered" type="number" min="<?= e($balanceDue) ?>" step="0.01" value="<?= e($balanceDue) ?>"></div>
    <div class="form-row-full form-actions"><button class="btn btn-primary" type="submit">Record full payment</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($order['status'] === 'ready' && $smsPhone !== ''): ?>
<div class="card mt-16">
  <h2>Notify customer</h2>
  <p class="muted">Open the phone's messaging app with a ready-for-pickup message, then review and send it.</p>
  <textarea id="readySms" rows="2" readonly><?= e($smsMessage) ?></textarea>
  <div class="form-actions"><a class="btn btn-primary" href="sms:<?= e($smsPhone) ?>?body=<?= e(rawurlencode($smsMessage)) ?>">Open SMS app</a><button class="btn btn-outline" type="button" onclick="navigator.clipboard.writeText(document.getElementById('readySms').value).then(()=>this.textContent='Copied')">Copy message</button></div>
</div>
<?php endif; ?>

<?php if ($order['status'] !== 'cancelled'): ?>
<div class="card mt-16">
  <h2>Update Status</h2>
  <div class="row-between">
    <?php foreach ($statusFlow as $st): ?>
      <?php
        $idx = array_search($order['status'], $statusFlow);
        $thisIdx = array_search($st, $statusFlow);
        $isCurrent = $order['status'] === $st;
      ?>
      <form method="post" action="order_view.php?id=<?= (int)$id ?>" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="status" value="<?= e($st) ?>">
        <button type="submit"
                class="btn <?= $isCurrent ? 'btn-primary' : 'btn-outline' ?> btn-sm"
                <?= ($thisIdx < $idx) ? 'disabled title="Cannot move backwards"' : '' ?>>
          <?= e($statusLabels[$st]) ?>
        </button>
      </form>
    <?php endforeach; ?>

    <?php if ($order['status'] !== 'picked_up'): ?><form method="post" action="order_view.php?id=<?= (int)$id ?>"
          onsubmit="return confirm('Cancel this service ticket? This cannot be undone.');">
      <?= csrf_field() ?><input type="hidden" name="cancel_order" value="1">
      <label for="cancelReason">Cancellation reason</label><input id="cancelReason" name="cancel_reason" maxlength="255" required placeholder="Reason for cancellation">
      <?php if (is_admin()): ?><label for="cancelRefund">Refund now (optional)</label><input id="cancelRefund" name="cancel_refund" type="number" min="0" max="<?= e(max(0, $paidTotal - $refundTotal)) ?>" step="0.01" value="0"><label for="cancelRefundMethod">Refund method</label><select id="cancelRefundMethod" name="cancel_refund_method"><?php foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card'] as $method => $methodLabel): ?><option value="<?= e($method) ?>" <?= $method === $order['payment_method'] ? 'selected' : '' ?>><?= e($methodLabel) ?></option><?php endforeach; ?></select><?php endif; ?>
      <button type="submit" class="btn btn-danger btn-sm">✕ Cancel ticket</button>
    </form><?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card mt-16">
  <p class="muted mb-0">This service ticket has been cancelled.</p>
</div>
<?php endif; ?>

<?php if (is_admin() && $order['status'] !== 'cancelled' && $refundTotal < $paidTotal): ?>
<div class="card mt-16">
  <h2>Record a refund</h2><p class="muted">Remaining refundable balance: <strong><?= money($paidTotal - $refundTotal) ?></strong></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?><input type="hidden" name="refund_order" value="1">
    <div><label for="refundAmount">Refund amount</label><input id="refundAmount" name="refund_amount" type="number" min="0.01" max="<?= e($paidTotal - $refundTotal) ?>" step="0.01" required></div>
    <div><label for="refundMethod">Refund method</label><select id="refundMethod" name="refund_method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card">Card</option></select></div>
    <div class="form-row-full"><label for="refundReason">Reason</label><input id="refundReason" name="refund_reason" maxlength="255" required placeholder="Reason for refund"></div>
    <div class="form-row-full form-actions"><button class="btn btn-danger" type="submit" data-confirm="Record this refund?">Record refund</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($adjustments): ?><div class="card mt-16"><h2>Cancellations &amp; refunds</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Action</th><th>Reason</th><th>Method</th><th class="num">Amount</th><th>Recorded by</th></tr></thead><tbody>
<?php foreach ($adjustments as $adj): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($adj['created_at']))) ?></td><td><?= $adj['adjustment_type'] === 'refund' ? 'Refund' : 'Cancelled' ?></td><td><?= e($adj['reason']) ?></td><td><?= e($adj['payment_method'] ? strtoupper($adj['payment_method']) : '—') ?></td><td class="num"><?= $adj['adjustment_type'] === 'refund' ? money($adj['amount']) : '—' ?></td><td><?= e($adj['full_name'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>

<!-- ================= Status Timeline ================= -->
<div class="card mt-16">
    <h2>Status Timeline</h2>
    <?php if (!$history): ?>
      <p class="muted mb-0">No history recorded for this ticket yet.</p>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($history as $h): ?>
          <div class="timeline-item">
            <span class="timeline-dot dot-<?= e($h['status']) ?>"></span>
            <div class="timeline-body">
              <div class="timeline-head">
                <strong><?= e($statusLabels[$h['status']] ?? $h['status']) ?></strong>
                <span class="muted small"><?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></span>
              </div>
              <?php if ($h['note']): ?><div class="small muted"><?= e($h['note']) ?></div><?php endif; ?>
              <?php if ($h['changed_by_name']): ?>
                <div class="small muted">by <?= e($h['changed_by_name']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
