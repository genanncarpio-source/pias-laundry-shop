<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_feature_schema();
ensure_workflow_schema();

$pdo = db();

/* Date range (default: this month) */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$params = [$from, $to . ' 23:59:59'];

/* Overall sales */
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(total),0) AS revenue,
            COALESCE(SUM(discount),0) AS discounts
     FROM orders
     WHERE status NOT IN ('cancelled','rejected') AND payment_method <> 'pending' AND created_at BETWEEN ? AND ?"
);
$stmt->execute($params);
$summary = $stmt->fetch();
$avgOrder = $summary['orders'] > 0 ? $summary['revenue'] / $summary['orders'] : 0;
$paidStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(
       CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)
            THEN (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.order_id = o.id AND p2.status = 'paid')
            ELSE GREATEST(0, o.amount_paid - o.change_due) END
     ),0)
     FROM orders o
     WHERE o.status <> 'cancelled' AND o.created_at BETWEEN ? AND ?"
);
$paidStmt->execute($params);
$paidTotal = (float)$paidStmt->fetchColumn();
$balanceStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(GREATEST(0, o.total -
       CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)
            THEN (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.order_id = o.id AND p2.status = 'paid')
            ELSE GREATEST(0, o.amount_paid - o.change_due) END
     )),0)
     FROM orders o
     WHERE o.status NOT IN ('cancelled','rejected') AND o.created_at BETWEEN ? AND ?"
);
$balanceStmt->execute($params);
$outstandingTotal = (float)$balanceStmt->fetchColumn();
$refundStmt = $pdo->prepare("SELECT COALESCE(SUM(a.amount),0) FROM order_adjustments a JOIN orders o ON o.id = a.order_id WHERE a.adjustment_type = 'refund' AND o.status <> 'cancelled' AND a.created_at BETWEEN ? AND ?");
$refundStmt->execute($params);
$refundTotal = (float)$refundStmt->fetchColumn();
$netSales = (float)$summary['revenue'] - $refundTotal;

/* Payment method breakdown */
$stmt = $pdo->prepare(
    "SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
     FROM (
       SELECT p.payment_method, p.amount
       FROM payments p JOIN orders o ON o.id = p.order_id
       WHERE p.status = 'paid' AND o.status NOT IN ('cancelled','rejected') AND o.created_at BETWEEN ? AND ?
       UNION ALL
       SELECT o.payment_method, o.total AS amount FROM orders o
       WHERE o.status NOT IN ('cancelled','rejected') AND o.payment_method <> 'pending' AND o.created_at BETWEEN ? AND ?
         AND NOT EXISTS (SELECT 1 FROM payments p2 WHERE p2.order_id = o.id)
     ) payment_rows
     GROUP BY payment_method"
);
$stmt->execute(array_merge($params, $params));
$payments = $stmt->fetchAll();

/* Top services */
$stmt = $pdo->prepare(
    "SELECT oi.service_name, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status NOT IN ('cancelled','rejected') AND o.payment_method <> 'pending' AND o.created_at BETWEEN ? AND ?
     GROUP BY oi.service_name
     ORDER BY revenue DESC
     LIMIT 10"
);
$stmt->execute($params);
$topServices = $stmt->fetchAll();

/* Daily breakdown */
$stmt = $pdo->prepare(
    "SELECT DATE(created_at) d, COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue
     FROM orders
     WHERE status NOT IN ('cancelled','rejected') AND payment_method <> 'pending' AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at)
     ORDER BY d"
);
$stmt->execute($params);
$daily = $stmt->fetchAll();
$dailyRefundStmt = $pdo->prepare("SELECT DATE(a.created_at) AS d, COALESCE(SUM(a.amount),0) AS refunds FROM order_adjustments a JOIN orders o ON o.id = a.order_id WHERE a.adjustment_type = 'refund' AND o.status <> 'cancelled' AND a.created_at BETWEEN ? AND ? GROUP BY DATE(a.created_at)");
$dailyRefundStmt->execute($params);
$dailyRefunds = [];
foreach ($dailyRefundStmt->fetchAll() as $row) $dailyRefunds[$row['d']] = (float)$row['refunds'];

/* Expenses in range */
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE expense_date BETWEEN ? AND ?"
);
$stmt->execute([$from, $to]);
$expenseTotal = (float)$stmt->fetchColumn();

$net = $netSales - $expenseTotal;

if (($_GET['export'] ?? '') === 'csv') {
    $csvOrders = $pdo->prepare("SELECT o.order_number, o.customer_name, o.status, o.total, o.created_at,
        CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)
             THEN (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.order_id = o.id AND p2.status = 'paid')
             ELSE GREATEST(0, o.amount_paid - o.change_due) END AS paid_amount
        FROM orders o WHERE o.created_at BETWEEN ? AND ? ORDER BY o.created_at, o.id");
    $csvOrders->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laundry-sales-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Laundry Shop Sales Report', $from, $to]);
    fputcsv($out, ['Orders', (int)$summary['orders']]);
    fputcsv($out, ['Gross Sales', (float)$summary['revenue']]);
    fputcsv($out, ['Payments Received', $paidTotal]);
    fputcsv($out, ['Outstanding Balance', $outstandingTotal]);
    fputcsv($out, ['Refunds', $refundTotal]);
    fputcsv($out, []);
    fputcsv($out, ['Order Number', 'Customer', 'Status', 'Total', 'Paid', 'Created At']);
    foreach ($csvOrders->fetchAll() as $row) {
        $customerName = (string)($row['customer_name'] ?? '');
        if (preg_match('/^[=+\-@]/', $customerName)) $customerName = "'" . $customerName;
        fputcsv($out, [$row['order_number'], $customerName, $row['status'], $row['total'], $row['paid_amount'], $row['created_at']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Service', 'Quantity', 'Gross Sales']);
    foreach ($topServices as $service) {
        $serviceName = (string)$service['service_name'];
        if (preg_match('/^[=+\-@]/', $serviceName)) $serviceName = "'" . $serviceName;
        fputcsv($out, [$serviceName, $service['qty'], $service['revenue']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Reports';
$pageSubtitle = 'Sales and performance reports';
include __DIR__ . '/includes/header.php';
?>

<div class="card mb-16">
  <form method="get" action="reports.php" class="row-between">
    <div class="row-between">
      <div>
        <label for="from" class="mt-0">From</label>
        <input type="date" id="from" name="from" value="<?= e($from) ?>">
      </div>
      <div>
        <label for="to" class="mt-0">To</label>
        <input type="date" id="to" name="to" value="<?= e($to) ?>">
      </div>
      <div style="align-self:flex-end;">
        <button type="submit" class="btn btn-primary">Apply Filters</button>
      </div>
    </div>
    <div class="form-actions"><button type="button" class="btn btn-outline" data-print>Print Report</button><a class="btn btn-outline" href="reports.php?from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;export=csv">Export CSV</a></div>
  </form>
</div>

<div class="grid stats">
  <div class="card stat-card">
    <span class="stat-icon">💰</span>
    <div class="stat-label">Gross Sales</div>
    <div class="stat-value"><?= money($summary['revenue']) ?></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">↩</span>
    <div class="stat-label">Refunds</div>
    <div class="stat-value"><?= money($refundTotal) ?></div>
    <div class="stat-sub">For active orders in this date range</div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">🧾</span>
    <div class="stat-label">Orders</div>
    <div class="stat-value"><?= (int)$summary['orders'] ?></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">📐</span>
    <div class="stat-label">Average Order Value</div>
    <div class="stat-value"><?= money($avgOrder) ?></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">💸</span>
    <div class="stat-label">Net Income</div>
    <div class="stat-value" style="color:<?= $net >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= money($net) ?></div>
    <div class="stat-sub">After <?= money($refundTotal) ?> refunds and <?= money($expenseTotal) ?> expenses</div>
  </div>
</div>

<div class="grid two mt-16">
  <div class="card"><h2>Payments received</h2><p class="stat-value"><?= money($paidTotal) ?></p></div>
  <div class="card"><h2>Outstanding balances</h2><p class="stat-value"><?= money($outstandingTotal) ?></p></div>
</div>

<div class="grid two mt-16">
  <div class="card">
    <h2>Top Services</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th class="num">Qty Sold</th><th class="num">Revenue</th></tr></thead>
        <tbody>
          <?php if (!$topServices): ?><tr><td colspan="3" class="empty-state">No data in range.</td></tr><?php endif; ?>
          <?php foreach ($topServices as $s): ?>
          <tr>
            <td><?= e($s['service_name']) ?></td>
            <td class="num"><?= e(rtrim(rtrim(number_format($s['qty'], 2), '0'), '.')) ?></td>
            <td class="num"><?= money($s['revenue']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Payment Methods (gross sales)</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Method</th><th class="num">Transactions</th><th class="num">Total</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= e(strtoupper($p['payment_method'])) ?></td>
            <td class="num"><?= (int)$p['cnt'] ?></td>
            <td class="num"><?= money($p['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mt-16">
  <h2>Daily Breakdown</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th class="num">Orders</th><th class="num">Gross Sales</th><th class="num">Refunds</th><th class="num">Net Sales</th></tr></thead>
      <tbody>
        <?php if (!$daily): ?><tr><td colspan="5" class="empty-state">No data in range.</td></tr><?php endif; ?>
        <?php foreach ($daily as $d): ?>
          <?php $dayRefunds = $dailyRefunds[$d['d']] ?? 0; ?>
          <tr>
            <td><?= e(date('M j, Y', strtotime($d['d']))) ?></td>
            <td class="num"><?= (int)$d['orders'] ?></td>
            <td class="num"><?= money($d['revenue']) ?></td>
            <td class="num"><?= money($dayRefunds) ?></td>
            <td class="num"><?= money((float)$d['revenue'] - $dayRefunds) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
