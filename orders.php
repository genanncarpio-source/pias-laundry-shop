<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();

$pdo = db();

$status  = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$search  = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$type = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$validDate = static function ($value) { return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) && date('Y-m-d', strtotime($value)) === $value; };
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo)) $dateTo = '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($status === 'expected') {
    $where[] = "o.status IN ('pending','confirmed','received','weighing','washing','drying','folding','ready','ready_for_pickup','out_for_delivery')";
} elseif ($status === 'completed_today') {
    $where[] = "o.status IN ('completed','picked_up') AND DATE(o.completed_at) = CURDATE()";
} elseif ($status === 'cash_unpaid_pickup') {
    $where[] = "o.status IN ('ready','ready_for_pickup') AND o.payment_method = 'cash' AND
      (CASE WHEN EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)
            THEN (SELECT COALESCE(SUM(p2.amount),0) FROM payments p2 WHERE p2.order_id = o.id AND p2.status = 'paid')
            ELSE GREATEST(0, o.amount_paid - o.change_due) END) < o.total";
} elseif (in_array($status, ['ready', 'ready_for_pickup'], true)) {
    $where[] = "o.status IN ('ready','ready_for_pickup')";
} elseif (in_array($status, array_keys(order_status_labels()), true)) {
    $where[] = "o.status = ?";
    $params[] = $status;
} else { $status = ''; }
if ($search !== '') {
    $where[] = "(o.order_number LIKE ? OR o.customer_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (in_array($type, ['walk_in', 'drop_off', 'pickup_request', 'delivery_request'], true)) {
    $where[] = 'o.order_type = ?';
    $params[] = $type;
} else { $type = ''; }
if ($dateFrom !== '') { $where[] = 'o.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $dateTo; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Count matching rows with bound params */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o
     $whereSql
     ORDER BY o.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$totalPages = max(1, (int)ceil($total / $perPage));

$tabs = [
    ''          => 'All',
    'pending'   => 'Pending',
    'expected'  => 'Services in Process',
    'confirmed' => 'Accepted',
    'received'  => 'Received',
    'washing'   => 'Washing',
    'drying'    => 'Drying',
    'folding'   => 'Folding',
    'ready'     => 'Ready for Pickup',
    'completed' => 'Completed',
    'completed_today' => 'Completed Today',
    'cancelled' => 'Cancelled',
];

$statusLabels = order_status_labels();

$pageTitle = 'Avail Service';
$pageSubtitle = 'Track and manage service requests';
include __DIR__ . '/includes/header.php';
?>

<div class="row-between mb-16">
  <div class="pill-tabs">
    <?php foreach ($tabs as $val => $label): ?>
      <a class="pill <?= $status === $val ? 'active' : '' ?>"
         href="orders.php?<?= $val === '' ? '' : 'status=' . e($val) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?><?= $type !== '' ? '&type=' . urlencode($type) : '' ?><?= $dateFrom !== '' ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo !== '' ? '&date_to=' . urlencode($dateTo) : '' ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<form method="get" action="orders.php" class="card order-filters mb-16">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div><label for="orderSearch">Search order or customer</label><input id="orderSearch" type="search" name="search" placeholder="Order number or customer name" value="<?= e($search) ?>"></div>
  <div><label for="orderTypeFilter">Order type</label><select id="orderTypeFilter" name="type"><option value="">All types</option><option value="walk_in" <?= $type === 'walk_in' ? 'selected' : '' ?>>Walk-in</option><option value="pickup_request" <?= $type === 'pickup_request' ? 'selected' : '' ?>>Pick-up Request</option><option value="delivery_request" <?= $type === 'delivery_request' ? 'selected' : '' ?>>Delivery Request</option><option value="drop_off" <?= $type === 'drop_off' ? 'selected' : '' ?>>Drop-off</option></select></div>
  <div><label for="dateFrom">From date</label><input type="date" id="dateFrom" name="date_from" value="<?= e($dateFrom) ?>"></div>
  <div><label for="dateTo">To date</label><input type="date" id="dateTo" name="date_to" value="<?= e($dateTo) ?>"></div>
  <div class="filter-actions"><button class="btn btn-primary btn-sm" type="submit">Apply filters</button><a class="btn btn-outline btn-sm" href="orders.php">Clear</a></div>
</form>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Order #</th><th>Customer</th><th>Type</th><th>Items</th>
          <th class="num">Total</th><th>Status</th><th>Date</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$orders): ?>
          <tr><td colspan="8" class="empty-state">No orders found.</td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td>
          <td><?= e($o['customer_name'] ?: 'Walk-in') ?></td>
          <td><span class="badge badge-<?= e($o['order_type']) ?>"><?= $o['order_type'] === 'pickup_request' ? 'Pick-up Request' : ($o['order_type'] === 'delivery_request' ? 'Delivery Request' : ($o['order_type'] === 'drop_off' ? 'Drop-off' : 'Walk-in')) ?></span></td>
          <td><?= (int)$o['item_count'] ?></td>
          <td class="num"><?= money($o['total']) ?></td>
          <td><span class="badge badge-<?= e($o['status']) ?>"><?= e($o['status'] === 'ready_for_pickup' && $o['order_type'] === 'delivery_request' ? 'Ready for Delivery' : ($statusLabels[$o['status']] ?? $o['status'])) ?></span></td>
          <td class="muted"><?= e(date('M j, Y', strtotime($o['created_at']))) ?></td>
          <td class="right">
            <a class="btn btn-outline btn-sm" href="order_view.php?id=<?= (int)$o['id'] ?>">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php
      $qs = function ($p) use ($status, $search, $type, $dateFrom, $dateTo) {
          return 'orders.php?page=' . $p
               . ($status !== '' ? '&status=' . urlencode($status) : '')
               . ($search !== '' ? '&search=' . urlencode($search) : '')
               . ($type !== '' ? '&type=' . urlencode($type) : '')
               . ($dateFrom !== '' ? '&date_from=' . urlencode($dateFrom) : '')
               . ($dateTo !== '' ? '&date_to=' . urlencode($dateTo) : '');
      };
    ?>
    <?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>">&laquo; Prev</a><?php endif; ?>
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= e($qs($i)) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?= e($qs($page + 1)) ?>">Next &raquo;</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
