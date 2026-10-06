<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid request.');
        redirect('expenses.php');
    }
    $description = trim($_POST['description'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $amount      = max(0, (float)($_POST['amount'] ?? 0));
    $date        = $_POST['expense_date'] ?? date('Y-m-d');
    $paymentMethod = $_POST['payment_method'] ?? 'cash';

    if ($description === '' || $category === '' || $amount <= 0 || !in_array($paymentMethod, ['cash', 'gcash', 'maya', 'card'], true)) {
        flash_set('error', 'Description, category, amount and a valid payment method are required.');
        redirect('expenses.php');
    }

    $stmt = $pdo->prepare("INSERT INTO expenses (description, category, amount, payment_method, expense_date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$description, $category, $amount, $paymentMethod, $date, $_SESSION['user_id']]);
    flash_set('success', 'Expense recorded.');
    redirect('expenses.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid request.');
    } else {
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        flash_set('success', 'Expense deleted.');
    }
    redirect('expenses.php');
}

/* ---------- Filtering ---------- */
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$whereSql  = "WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?";
$params    = [$month];

$stmt = $pdo->prepare("SELECT e.*, u.full_name AS created_by_name
                       FROM expenses e LEFT JOIN users u ON u.id = e.created_by
                       $whereSql ORDER BY e.expense_date DESC, e.id DESC");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$monthTotal = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = " . $pdo->quote($month)
)->fetchColumn();

$categoryTotals = [];
foreach ($expenses as $e) {
    $categoryTotals[$e['category']] = ($categoryTotals[$e['category']] ?? 0) + (float)$e['amount'];
}

$pageTitle = 'Expenses';
$pageSubtitle = 'Track shop expenses';
include __DIR__ . '/includes/header.php';
?>

<div class="grid two">
  <div class="card">
    <h2>Record Expense</h2>
    <form method="post" action="expenses.php">
      <?= csrf_field() ?>
      <input type="hidden" name="save" value="1">

      <label for="description">Description *</label>
      <input type="text" id="description" name="description" required placeholder="e.g. Detergent refill">

      <label for="category">Category *</label>
      <input type="text" id="category" name="category" list="expCategory" required value="Supplies">
      <datalist id="expCategory">
        <option value="Supplies"><option value="Utilities"><option value="Rent">
        <option value="Maintenance"><option value="Salaries"><option value="Other">
      </datalist>

      <div class="form-row">
        <div>
          <label for="amount">Amount (₱) *</label>
          <input type="number" id="amount" name="amount" min="0" step="0.01" required>
        </div>
        <div>
          <label for="expense_date">Date</label>
          <input type="date" id="expense_date" name="expense_date" value="<?= e(date('Y-m-d')) ?>">
        </div>
      </div>

      <label for="payment_method">Paid using</label>
      <select id="payment_method" name="payment_method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card">Card</option></select>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Expense</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Summary — <?= e(date('F Y', strtotime($month . '-01'))) ?></h2>
      <form method="get" action="expenses.php">
        <input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()" style="width:160px;">
      </form>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Expenses</div>
      <div class="stat-value"><?= money($monthTotal) ?></div>
    </div>
    <div class="mt-16">
      <?php foreach ($categoryTotals as $cat => $amt): ?>
        <div class="summary-row"><span><?= e($cat) ?></span><span><?= money($amt) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card mt-16">
  <h2>Expense Entries</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Description</th><th>Category</th><th class="num">Amount</th><th>Recorded by</th><th></th></tr></thead>
      <tbody>
        <?php if (!$expenses): ?>
          <tr><td colspan="6" class="empty-state">No expenses for this month.</td></tr>
        <?php endif; ?>
        <?php foreach ($expenses as $e): ?>
        <tr>
          <td><?= e(date('M j, Y', strtotime($e['expense_date']))) ?></td>
          <td><?= e($e['description']) ?></td>
          <td class="muted"><?= e($e['category']) ?></td>
          <td class="num"><?= money($e['amount']) ?></td>
          <td class="muted"><?= e($e['created_by_name'] ?: '—') ?></td>
          <td class="right">
            <form method="post" action="expenses.php" style="display:inline;"
                  onsubmit="return confirm('Delete this expense?');">
              <?= csrf_field() ?>
              <input type="hidden" name="delete" value="1">
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
