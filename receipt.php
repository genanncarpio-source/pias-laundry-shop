<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();
$staffUser = current_user();
if (!$staffUser) redirect('index.php');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT o.*, c.phone AS customer_phone FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo '<p style="padding:30px;text-align:center;">Order not found.</p>';
    exit;
}

$items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$lines = $items->fetchAll();
$paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'paid'");
$paidStmt->execute([$id]);
$paidTotal = (float)$paidStmt->fetchColumn();
if ($paidTotal <= 0) $paidTotal = max(0, (float)$order['amount_paid'] - (float)$order['change_due']);
$balance = max(0, (float)$order['total'] - $paidTotal);

$shopName    = get_setting('shop_name', "Pia's Laundry Shop");
$shopAddress = get_setting('shop_address');
$shopPhone   = get_setting('shop_phone');
$receiptNote = get_setting('receipt_note');
$isNew = isset($_GET['new']);
$barcodeValue = strtoupper(preg_replace('/[^0-9A-Z .\- $\/+%]/', '', (string)$order['order_number']));
$code39 = [
    '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw','5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn',
    'A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn','F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
    'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn','P'=>'nnwnwnnwn','Q'=>'nnnnnnwwn','R'=>'wnnnnnwnn','S'=>'nnwnnnwnn','T'=>'nnnnwnwnn',
    'U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn','Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','$'=>'nwnwnwnnn','/'=>'nwnwnnnwn','+'=>'nwnnnwnwn','%'=>'nnnwnwnwn','*'=>'nwnnwnwnn',
];
$barcodeText = '*' . $barcodeValue . '*';
$barcodeWidth = 20;
foreach (str_split($barcodeText) as $barcodeChar) $barcodeWidth += array_sum(array_map(static fn($width) => $width === 'w' ? 2 : 1, str_split($code39[$barcodeChar] ?? '')))+1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt · <?= e($order['order_number']) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=9">
</head>
<body class="receipt-page" style="background:#fffaf2;padding:30px 12px;">

<div class="receipt">
  <div class="rc-watermark" aria-hidden="true">PIA'S LAUNDRY - OFFICIAL RECEIPT - PIA'S LAUNDRY - OFFICIAL RECEIPT</div>
  <div class="rc-center rc-shop"><?= e($shopName) ?></div>
  <div class="rc-center"><?= e($shopAddress) ?></div>
  <?php if ($shopPhone): ?><div class="rc-center"><?= e($shopPhone) ?></div><?php endif; ?>

  <div class="rc-divider"></div>

  <div class="rc-center rc-claim">CLAIM CHECK</div>
  <div class="rc-line"><span>DATE:</span><span><?= e(date('m/d/Y', strtotime($order['created_at']))) ?></span></div>
  <div class="rc-line"><span>TIME:</span><span><?= e(date('g:i A', strtotime($order['created_at']))) ?></span></div>
  <?php
    $handoffType = (string)($order['handoff_type'] ?? '');
    $receiptType = match ($handoffType) {
      'pickup_delivery' => 'Pickup & Delivery',
      'dropoff_delivery' => 'Drop-off & Delivery',
      'dropoff_pickup' => 'Drop-off & Pick-up',
      default => match ((string)$order['order_type']) {
        'pickup_request' => 'Pick-up Request',
        'delivery_request' => 'Delivery Request',
        'drop_off' => 'Drop-off',
        default => 'Walk-in',
      },
    };
  ?>
  <div class="rc-line"><span>TYPE:</span><span><?= e($receiptType) ?></span></div>
  <?php if ($order['customer_name']): ?>
    <div class="rc-line"><span>CUSTOMER:</span><span><?= e($order['customer_name']) ?></span></div>
  <?php endif; ?>
  <?php if (!empty($order['customer_phone'])): ?><div class="rc-line"><span>PHONE:</span><span><?= e($order['customer_phone']) ?></span></div><?php endif; ?>
  <div class="rc-line rc-ticket"><span>TICKET #:</span><strong><?= e($order['order_number']) ?></strong></div>
  <?php if ($order['expected_pickup']): ?>
    <div class="rc-line"><span>Pickup:</span><span><?= e(date('M j, Y g:i A', strtotime($order['expected_pickup']))) ?></span></div>
  <?php endif; ?>
  <?php $receiptAddress = (string)(($handoffType === 'pickup_delivery') ? ($order['pickup_address'] ?? '') : (($order['delivery_address'] ?? '') ?: ($order['pickup_address'] ?? ''))); ?>
  <?php if ($receiptAddress !== ''): ?><div class="rc-small"><strong>ADDRESS:</strong> <?= e($receiptAddress) ?></div><?php endif; ?>

  <div class="rc-divider"></div>

  <table>
    <thead>
      <tr><th style="text-align:left;">SERVICE</th><th style="text-align:right;">QTY</th><th style="text-align:right;">AMOUNT</th></tr>
    </thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= e($l['service_name']) ?></td>
        <td style="text-align:right;"><?= e(rtrim(rtrim(number_format($l['quantity'], 2), '0'), '.')) ?> <?= e($l['unit']) ?><?php if (($l['estimated_weight_kg'] ?? null) !== null): ?> (<?= e(rtrim(rtrim(number_format($l['estimated_weight_kg'], 2), '0'), '.')) ?> kg est.)<?php endif; ?></td>
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
  <?php if ((float)($order['delivery_fee'] ?? 0) > 0): ?>
    <div class="rc-line"><span>Delivery fee</span><span><?= money($order['delivery_fee']) ?></span></div>
  <?php endif; ?>
  <div class="rc-line rc-bold"><span>TOTAL</span><span><?= money($order['total']) ?></span></div>
  <div class="rc-line"><span>Payment</span><span><?= e(strtoupper($order['payment_method'])) ?></span></div>
  <?php if ($order['payment_method'] === 'cash'): ?>
    <div class="rc-line"><span>Amount Tendered</span><span><?= money($order['amount_paid']) ?></span></div>
    <div class="rc-line"><span>Change</span><span><?= money($order['change_due']) ?></span></div>
    <div class="rc-line"><span>Amount Paid</span><span><?= money($paidTotal) ?></span></div>
    <div class="rc-line"><span>Balance</span><span><?= money($balance) ?></span></div>
  <?php endif; ?>

  <div class="rc-divider"></div>

  <div class="rc-center rc-ready">PLEASE KEEP THIS RECEIPT<br>FOR PICKUP / CLAIMING</div>
  <div class="rc-center"><?= e($receiptNote) ?></div>
  <div class="rc-divider"></div>
  <svg class="rc-barcode" role="img" aria-label="Barcode for ticket <?= e($order['order_number']) ?>" viewBox="0 0 <?= (int)$barcodeWidth ?> 48" preserveAspectRatio="none">
    <?php $barcodeX = 10; foreach (str_split($barcodeText) as $barcodeChar): $pattern = $code39[$barcodeChar] ?? ''; foreach (str_split($pattern) as $index => $barWidth): $drawWidth = $barWidth === 'w' ? 2 : 1; if ($index % 2 === 0): ?><rect x="<?= $barcodeX ?>" y="0" width="<?= $drawWidth ?>" height="48" fill="#111"/><?php endif; $barcodeX += $drawWidth; endforeach; $barcodeX++; endforeach; ?>
  </svg>
  <div class="rc-center rc-barcode-label"><?= e($order['order_number']) ?></div>
  <?php if (!empty($order['customer_phone'])): ?><div class="rc-divider"></div><div class="rc-center rc-track-url">Use the Pia's Laundry Shop mobile app to track this order.</div><?php endif; ?>
  <div class="rc-center muted" style="font-size:11px;"><?= e($shopName) ?> · Laundry Service</div>
</div>

<div class="no-print">
  <button class="btn btn-primary" type="button" id="print-receipt">Print / Save Receipt</button>
  <?php if (!$isNew): ?>
    <a class="btn btn-outline" href="order_view.php?id=<?= (int)$id ?>">← Back to order</a>
  <?php endif; ?>
</div>

<script>
  // This page is standalone and does not load the shared app.js print handler.
  // The browser print dialog lets staff print the receipt or save it as a PDF.
  document.getElementById('print-receipt')?.addEventListener('click', () => window.print());
  <?php if ($isNew): ?>
  window.addEventListener('load', () => setTimeout(() => window.print(), 300));
  <?php endif; ?>
</script>
</body>
</html>
