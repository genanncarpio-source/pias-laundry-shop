<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_customer_auth_schema();

$pdo = db();

/* ---------- Today's summary ---------- */
$todayRevenue = (float)$pdo->query(
    "SELECT COALESCE(SUM(GREATEST(amount_paid - change_due, 0)),0) FROM orders WHERE status <> 'cancelled' AND DATE(COALESCE(paid_at, created_at)) = CURDATE()"
)->fetchColumn();

$todayOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();

$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$inProgress = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'washing'")->fetchColumn();

$readyPickup = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status = 'ready'"
)->fetchColumn();

$monthRevenue = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(amount_paid - change_due, 0)),0) FROM orders WHERE status <> 'cancelled' AND COALESCE(paid_at, created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND COALESCE(paid_at, created_at) < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)")->fetchColumn();
$monthExpenses = is_admin() ? (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND expense_date <= LAST_DAY(CURDATE())")->fetchColumn() : 0.0;
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();

/* ---------- Revenue last 7 days (for chart) ---------- */
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i day"))] = 0;
}
$revRows = $pdo->query(
    "SELECT DATE(COALESCE(paid_at, created_at)) d, SUM(GREATEST(amount_paid - change_due, 0)) total
     FROM orders
     WHERE status <> 'cancelled' AND GREATEST(amount_paid - change_due, 0) > 0 AND COALESCE(paid_at, created_at) >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY DATE(COALESCE(paid_at, created_at))"
)->fetchAll();
$maxRev = 1;
foreach ($revRows as $r) {
    $days[$r['d']] = (float)$r['total'];
    $maxRev = max($maxRev, (float)$r['total']);
}

/* ---------- Recent orders ---------- */
$recent = $pdo->query(
    "SELECT o.id, o.order_number, o.customer_name, o.order_type, o.status, o.total, o.created_at,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o
     ORDER BY o.id DESC
     LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard';
$pageSubtitle = 'Overview of your laundry operations';
include __DIR__ . '/includes/header.php';
?>

<div class="grid stats">
  <div class="card stat-card">
    <span class="stat-icon">💰</span>
    <div class="stat-label">Today's Revenue</div>
    <div class="stat-value"><?= money($todayRevenue) ?></div>
    <div class="stat-sub"><?= $todayOrders ?> service ticket(s) today</div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">P</span>
    <div class="stat-label">Pending</div>
    <div class="stat-value"><?= $pendingOrders ?></div>
    <div class="stat-sub"><a href="orders.php?status=pending">View tickets awaiting processing</a></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">🔄</span>
    <div class="stat-label">In Progress</div>
    <div class="stat-value"><?= $inProgress ?></div>
    <div class="stat-sub"><a href="orders.php?status=washing">View tickets in progress</a></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">✅</span>
    <div class="stat-label">Ready for Pickup</div>
    <div class="stat-value"><?= $readyPickup ?></div>
    <div class="stat-sub"><a href="orders.php?status=ready">View pickup queue</a></div>
  </div>
  <div class="card stat-card">
    <span class="stat-icon">M</span>
    <div class="stat-label">This Month's Revenue</div>
    <div class="stat-value"><?= money($monthRevenue) ?></div>
    <div class="stat-sub">Excludes cancelled service tickets</div>
  </div>
  <?php if (is_admin()): ?><div class="card stat-card">
    <span class="stat-icon">E</span>
    <div class="stat-label">This Month's Expenses</div>
    <div class="stat-value"><?= money($monthExpenses) ?></div>
    <div class="stat-sub"><a href="expenses.php">Review expenses</a></div>
  </div><?php endif; ?>
  <div class="card stat-card">
    <span class="stat-icon">👥</span>
    <div class="stat-label">Total Customers</div>
    <div class="stat-value"><?= $totalCustomers ?></div>
    <div class="stat-sub">registered customers</div>
  </div>
</div>

<div class="grid two mt-16">
  <div class="card">
    <div class="card-head">
      <h2>Revenue — Last 7 Days</h2>
    </div>
    <div class="bar-chart">
      <?php foreach ($days as $d => $v):
          $h = $v > 0 ? max(4, round(($v / $maxRev) * 100)) : 0;
      ?>
      <div class="bar-col">
        <div class="bar" style="height: <?= $h ?>%" title="<?= money($v) ?>"></div>
        <div class="bar-lbl"><?= date('D', strtotime($d)) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="muted mb-0" style="font-size:12px;">Excludes cancelled service tickets. Hover a bar for the amount.</p>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Recent Service Tickets</h2>
      <a class="btn btn-outline btn-sm" href="orders.php">View all</a>
    </div>
    <?php if (!$recent): ?>
      <div class="empty-state">
        <div class="big">🧺</div>
        <p>No service tickets yet. <a href="pos.php">Create your first service ticket</a>.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Ticket #</th><th>Customer</th><th>Services</th><th class="num">Total</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $o): ?>
          <tr>
            <td><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td>
            <td><?= e($o['customer_name'] ?: 'Walk-in') ?></td>
            <td><?= (int)$o['item_count'] ?></td>
            <td class="num"><?= money($o['total']) ?></td>
            <td><span class="badge badge-<?= e($o['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $o['status']))) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
