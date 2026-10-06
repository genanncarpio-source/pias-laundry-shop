<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT o.*, c.phone AS customer_phone FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    echo '<p style="padding:30px;text-align:center;">Order not found.</p>';
    exit;
}

$items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$lines = $items->fetchAll();

$shopName    = get_setting('shop_name', "Pia's Laundry Shop");
$shopAddress = get_setting('shop_address');
$shopPhone   = get_setting('shop_phone');
$receiptNote = get_setting('receipt_note');
$paidTotal = max(0, (float)$order['amount_paid'] - (float)$order['change_due']);
$balanceDue = max(0, round((float)$order['total'] - $paidTotal, 2));

$isNew = isset($_GET['new']);
$trackUrl = get_setting('tracking_url');
if ($trackUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $trackUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath . '/track.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt · <?= e($order['order_number']) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=5">
</head>
<body style="background:#fff8fb;padding:30px 12px;">

<div class="receipt">
  <div class="rc-center rc-shop"><?= e($shopName) ?></div>
  <div class="rc-center"><?= e($shopAddress) ?></div>
  <?php if ($shopPhone): ?><div class="rc-center"><?= e($shopPhone) ?></div><?php endif; ?>

  <div class="rc-divider"></div>

  <div class="rc-line"><span>Ticket #:</span><span><?= e($order['order_number']) ?></span></div>
  <div class="rc-line"><span>Date:</span><span><?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></span></div>
  <div class="rc-line"><span>Type:</span><span><?= $order['order_type'] === 'drop_off' ? 'Drop-off' : 'Walk-in' ?></span></div>
  <?php if ($order['customer_name']): ?>
    <div class="rc-line"><span>Customer:</span><span><?= e($order['customer_name']) ?></span></div>
  <?php endif; ?>
  <?php if ($order['expected_pickup']): ?>
    <div class="rc-line"><span>Pickup:</span><span><?= e(date('M j, Y g:i A', strtotime($order['expected_pickup']))) ?></span></div>
  <?php endif; ?>

  <div class="rc-divider"></div>

  <table>
    <thead>
      <tr><th style="text-align:left;">Service</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Amount</th></tr>
    </thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= e($l['service_name']) ?></td>
        <td style="text-align:right;"><?= e(rtrim(rtrim(number_format($l['quantity'], 2), '0'), '.')) ?> <?= e($l['unit']) ?></td>
        <td style="text-align:right;"><?= money($l['subtotal']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="rc-divider"></div>

  <div class="rc-line"><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
  <?php if ($order['discount'] > 0): ?>
    <div class="rc-line"><span>Discount</span><span>-<?= money($order['discount']) ?></span></div>
  <?php endif; ?>
  <div class="rc-line rc-bold"><span>TOTAL</span><span><?= money($order['total']) ?></span></div>
  <div class="rc-line"><span>Payment</span><span><?= e(strtoupper($order['payment_method'])) ?></span></div>
  <div class="rc-line"><span>Paid</span><span><?= money($paidTotal) ?></span></div>
  <?php if ($balanceDue > 0): ?><div class="rc-line rc-bold"><span>Balance Due</span><span><?= money($balanceDue) ?></span></div><?php endif; ?>
  <?php if ($order['payment_method'] === 'cash'): ?>
    <div class="rc-line"><span>Amount Tendered</span><span><?= money($order['amount_paid']) ?></span></div>
    <div class="rc-line"><span>Change</span><span><?= money($order['change_due']) ?></span></div>
  <?php endif; ?>

  <div class="rc-divider"></div>

  <div class="rc-center"><?= e($receiptNote) ?></div>
  <?php if (!empty($order['customer_phone'])): ?><div class="rc-divider"></div><div class="rc-center rc-track-url">Check ticket status: <?= e($trackUrl) ?><br>Use this ticket number and your phone number.</div><?php endif; ?>
  <div class="rc-center muted" style="font-size:11px;"><?= e($shopName) ?> · Laundry Shop Management</div>
</div>

<div class="no-print">
  <button class="btn btn-primary" data-print>🖨️ Print Receipt</button>
  <?php if (!$isNew): ?>
    <a class="btn btn-outline" href="order_view.php?id=<?= (int)$id ?>">← Back to order</a>
  <?php endif; ?>
  <a class="btn btn-outline" href="pos.php">New Service Ticket</a>
</div>

<?php if ($isNew): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
<?php endif; ?>
</body>
</html>
