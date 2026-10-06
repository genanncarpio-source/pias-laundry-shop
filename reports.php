<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_feature_schema();
ensure_customer_auth_schema();

$pdo = db();

/* Date range (default: this month) */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$params = [$from, $to . ' 23:59:59'];

/* Revenue includes only payments actually collected. */
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(total),0) AS billed,
            COALESCE(SUM(discount),0) AS discounts
     FROM orders
     WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?"
);
$stmt->execute($params);
$summary = $stmt->fetch();
$collectionStmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(amount_paid - change_due, 0)),0) FROM orders WHERE status <> 'cancelled' AND GREATEST(amount_paid - change_due, 0) > 0 AND COALESCE(paid_at, created_at) BETWEEN ? AND ?");
$collectionStmt->execute($params);
$summary['revenue'] = (float)$collectionStmt->fetchColumn();
$avgOrder = $summary['orders'] > 0 ? $summary['billed'] / $summary['orders'] : 0;
$refundStmt = $pdo->prepare("SELECT COALESCE(SUM(a.amount),0) FROM order_adjustments a JOIN orders o ON o.id = a.order_id WHERE a.adjustment_type = 'refund' AND o.status <> 'cancelled' AND a.created_at BETWEEN ? AND ?");
$refundStmt->execute($params);
$refundTotal = (float)$refundStmt->fetchColumn();
$netSales = (float)$summary['revenue'] - $refundTotal;

/* Payment method breakdown */
$stmt = $pdo->prepare(
    "SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(GREATEST(amount_paid - change_due, 0)),0) AS total
     FROM orders
     WHERE status <> 'cancelled' AND COALESCE(paid_at, created_at) BETWEEN ? AND ? AND GREATEST(amount_paid - change_due, 0) > 0
     GROUP BY payment_method"
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

/* Top services */
$stmt = $pdo->prepare(
    "SELECT oi.service_name, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status <> 'cancelled' AND COALESCE(o.paid_at, o.created_at) BETWEEN ? AND ? AND GREATEST(o.amount_paid - o.change_due, 0) >= o.total
     GROUP BY oi.service_name
     ORDER BY revenue DESC
     LIMIT 10"
);
$stmt->execute($params);
$topServices = $stmt->fetchAll();

/* Daily breakdown */
$stmt = $pdo->prepare(
    "SELECT DATE(COALESCE(paid_at, created_at)) d, COUNT(*) AS orders, COALESCE(SUM(GREATEST(amount_paid - change_due, 0)),0) AS revenue
     FROM orders
     WHERE status <> 'cancelled' AND GREATEST(amount_paid - change_due, 0) > 0 AND COALESCE(paid_at, created_at) BETWEEN ? AND ?
     GROUP BY DATE(COALESCE(paid_at, created_at))
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
        <button type="submit" class="btn btn-primary">Apply</button>
      </div>
    </div>
    <button type="button" class="btn btn-outline" data-print>🖨️ Print Report</button>
  </form>
</div>

<div class="grid stats">
  <div class="card stat-card">
    <span class="stat-icon">💰</span>
    <div class="stat-label">Payments Collected</div>
    <div class="stat-value"><?= money($summary['revenue']) ?></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">↩</span>
    <div class="stat-label">Refunds</div>
    <div class="stat-value"><?= money($refundTotal) ?></div>
    <div class="stat-sub">Refunds recorded for active service tickets</div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">🧾</span>
    <div class="stat-label">Service Tickets</div>
    <div class="stat-value"><?= (int)$summary['orders'] ?></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">📐</span>
    <div class="stat-label">Average Ticket Charge</div>
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
    <h2>Payments by Method</h2>
    <div class="table-wrap">
      <table>
      <thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Collected</th></tr></thead>
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
      <thead><tr><th>Date</th><th class="num">Service Tickets</th><th class="num">Payments Collected</th><th class="num">Refunds</th><th class="num">Net Collected</th></tr></thead>
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
