<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();

$pdo = db();
$isAdmin = is_admin();
$revenueExpression = "CASE
    WHEN EXISTS (SELECT 1 FROM payments p WHERE p.order_id = orders.id)
      THEN (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.order_id = orders.id AND p2.status = 'paid')
    WHEN orders.payment_method <> 'pending'
      THEN GREATEST(0, orders.amount_paid - orders.change_due)
    ELSE 0 END";

/* ---------- Service workflow summary ---------- */
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$readyPickup = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status IN ('ready','ready_for_pickup')"
)->fetchColumn();
$completedToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('completed','picked_up') AND DATE(completed_at) = CURDATE()")->fetchColumn();
$expectedRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('pending','confirmed','received','weighing','washing','drying','folding','ready','ready_for_pickup','out_for_delivery')")->fetchColumn();
$actualEarningsToday = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('completed','picked_up') AND DATE(completed_at) = CURDATE()")->fetchColumn();
$unpaidCashPickup = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders o WHERE o.status IN ('ready','ready_for_pickup') AND o.payment_method = 'cash'
       AND (CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)
                 THEN (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.order_id = o.id AND p2.status = 'paid')
                 ELSE GREATEST(0, o.amount_paid - o.change_due) END) < o.total"
)->fetchColumn();

$todayRevenue = $monthRevenue = $monthExpenses = 0;
$totalCustomers = 0;
$days = [];
$maxRev = 1;

/* ---------- Admin financial summaries ---------- */
if ($isAdmin) {
    $monthRevenue = (float)$pdo->query("SELECT COALESCE(SUM($revenueExpression),0) FROM orders WHERE status NOT IN ('cancelled','rejected') AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND created_at < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)")->fetchColumn();
    $monthExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND expense_date <= LAST_DAY(CURDATE())")->fetchColumn();
    $totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();
    /* Revenue chart remains based on payments received, separate from service earnings cards. */
    for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = 0;
    $revRows = $pdo->query(
        "SELECT DATE(created_at) d, SUM($revenueExpression) total
         FROM orders
         WHERE status NOT IN ('cancelled','rejected') AND created_at >= (CURDATE() - INTERVAL 6 DAY)
         GROUP BY DATE(created_at)"
    )->fetchAll();
    foreach ($revRows as $r) {
        $days[$r['d']] = (float)$r['total'];
        $maxRev = max($maxRev, (float)$r['total']);
    }
}

/* ---------- Recent orders ---------- */
$recentTotalColumn = $isAdmin ? 'o.total,' : '';
$recent = $pdo->query(
    "SELECT o.id, o.order_number, o.customer_name, o.order_type, o.status, $recentTotalColumn o.created_at,
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
  <?php if ($isAdmin): ?>
  <a class="card stat-card stat-card-link" href="orders.php?status=expected"><div class="stat-label">Expected Revenue</div><div class="stat-value"><?= money($expectedRevenue) ?></div><div class="stat-sub">Expected value from services still in process.</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=completed_today"><div class="stat-label">Actual Earnings Today</div><div class="stat-value"><?= money($actualEarningsToday) ?></div><div class="stat-sub">Total value of services completed today.</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=completed_today"><div class="stat-label">Completed Services Today</div><div class="stat-value"><?= $completedToday ?></div><div class="stat-sub">View completed services today &rarr;</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=pending"><div class="stat-label">Pending Requests</div><div class="stat-value"><?= $pendingOrders ?></div><div class="stat-sub">Open pending requests &rarr;</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=ready"><div class="stat-label">Ready for Pickup</div><div class="stat-value"><?= $readyPickup ?></div><div class="stat-sub">Open pickup queue &rarr;</div></a>
  <div class="card stat-card"><div class="stat-label">This Month's Revenue</div><div class="stat-value"><?= money($monthRevenue) ?></div><div class="stat-sub">Excludes cancelled orders</div></div>
  <div class="card stat-card"><div class="stat-label">This Month's Expenses</div><div class="stat-value"><?= money($monthExpenses) ?></div><div class="stat-sub"><a href="expenses.php">Review expenses</a></div></div>
  <div class="card stat-card"><div class="stat-label">Customers</div><div class="stat-value"><?= $totalCustomers ?></div><div class="stat-sub">Active customer accounts</div></div>
  <?php else: ?>
  <a class="card stat-card stat-card-link" href="orders.php?status=pending"><div class="stat-label">Pending Requests</div><div class="stat-value"><?= $pendingOrders ?></div><div class="stat-sub">Open service requests &rarr;</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=ready"><div class="stat-label">Ready for Pickup</div><div class="stat-value"><?= $readyPickup ?></div><div class="stat-sub">Open pickup list &rarr;</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=completed_today"><div class="stat-label">Completed Today</div><div class="stat-value"><?= $completedToday ?></div><div class="stat-sub">View today's completed services &rarr;</div></a>
  <a class="card stat-card stat-card-link" href="orders.php?status=cash_unpaid_pickup"><div class="stat-label">Cash to Collect at Pickup</div><div class="stat-value"><?= $unpaidCashPickup ?></div><div class="stat-sub">Ready orders with cash balance &rarr;</div></a>
  <?php endif; ?>
</div>
<?php if ($isAdmin): ?>
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
    <p class="muted mb-0" style="font-size:12px;">Excludes cancelled orders. Hover a bar for the amount.</p>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Recent Orders</h2>
      <a class="btn btn-outline btn-sm" href="orders.php">View all</a>
    </div>
    <?php if (!$recent): ?>
      <div class="empty-state">
        <div class="big">🧺</div>
        <p>No orders yet. New pick-up and drop-off requests will appear here.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Order #</th><th>Customer</th><th>Items</th><th class="num">Total</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $o): ?>
          <tr>
            <td><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td>
            <td><?= e($o['customer_name'] ?: 'Walk-in') ?></td>
            <td><?= (int)$o['item_count'] ?></td>
            <td class="num"><?= money($o['total']) ?></td>
            <td><span class="badge badge-<?= e($o['status']) ?>"><?= e(order_status_labels()[$o['status']] ?? ucwords(str_replace('_', ' ', $o['status']))) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card mt-16"><div class="card-head"><h2>Recent Service Requests</h2><a class="btn btn-outline btn-sm" href="orders.php">View all</a></div>
<?php if (!$recent): ?><div class="empty-state"><p>No orders yet. New service requests will appear here.</p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Order #</th><th>Customer</th><th>Items</th><th>Service Status</th></tr></thead><tbody>
<?php foreach ($recent as $o): ?><tr><td><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td><td><?= e($o['customer_name'] ?: 'Walk-in') ?></td><td><?= (int)$o['item_count'] ?></td><td><span class="badge badge-<?= e($o['status']) ?>"><?= e(order_status_labels()[$o['status']] ?? ucwords(str_replace('_', ' ', $o['status']))) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
