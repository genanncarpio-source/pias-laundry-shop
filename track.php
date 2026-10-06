<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
date_default_timezone_set('Asia/Manila');
$found = null;
$error = '';
$formOrderNumber = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formOrderNumber = is_string($_POST['order_number'] ?? null) ? trim($_POST['order_number']) : '';
    $orderNumber = strtoupper($formOrderNumber);
    $phoneInput = is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '';
    $phone = preg_replace('/\D+/', '', $phoneInput);
    if ($orderNumber !== '' && $phone !== '') {
        $stmt = db()->prepare('SELECT o.order_number, o.status, o.expected_pickup, o.created_at, c.phone FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch();
        $savedPhone = $row ? preg_replace('/\D+/', '', (string)$row['phone']) : '';
        if (strlen($phone) >= 10) $phone = substr($phone, -10);
        if (strlen($savedPhone) >= 10) $savedPhone = substr($savedPhone, -10);
        if ($row && $savedPhone !== '' && hash_equals($savedPhone, $phone)) $found = $row;
        else $error = 'We could not find a laundry ticket with those details. Check the ticket number and customer phone.';
    } else $error = 'Enter both the ticket number and the customer phone number.';
}
$labels = ['pending' => 'Received', 'washing' => 'In Progress', 'ready' => 'Ready for Pickup', 'picked_up' => 'Picked Up', 'cancelled' => 'Cancelled'];
$shopName = get_setting('shop_name', "Pia's Laundry Shop");
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Track a laundry ticket · <?= e($shopName) ?></title><link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>?v=4"></head>
<body class="track-page"><main class="track-card"><a class="track-brand" href="index.php"><?= e($shopName) ?></a><h1>Track your laundry</h1><p class="muted">Enter the ticket number from your receipt and the phone number registered with the service.</p>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($found): ?><section class="track-result"><div class="muted">Ticket <?= e($found['order_number']) ?></div><h2><span class="badge badge-<?= e($found['status']) ?>"><?= e($labels[$found['status']] ?? $found['status']) ?></span></h2><?php if ($found['expected_pickup']): ?><p>Expected pickup: <strong><?= e(date('M j, Y g:i A', strtotime($found['expected_pickup']))) ?></strong></p><?php endif; ?><?php if ($found['status'] === 'cancelled'): ?><p>Contact the shop if you have questions about this cancelled service.</p><?php elseif ($found['status'] === 'ready'): ?><p>Your laundry is ready. Please bring your receipt when you pick it up.</p><?php endif; ?></section><?php endif; ?>
<form method="post"><label for="orderNumber">Ticket number</label><input id="orderNumber" name="order_number" required placeholder="ORD-YYYYMMDD-001" value="<?= e($formOrderNumber) ?>"><label for="phone">Customer phone number</label><input id="phone" name="phone" type="tel" required autocomplete="tel" placeholder="Phone number registered with the service"><button class="btn btn-primary btn-block mt-16" type="submit">Check service status</button></form>
<p class="muted track-foot">For help, contact <?= e(get_setting('shop_phone')) ?>.</p></main></body></html>
