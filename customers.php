<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_customer_schema();
ensure_workflow_schema();

$pdo = db();

/* ---------- Save (add or update) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('customers.php');
    }
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        flash_set('error', 'Customer name is required.');
        redirect('customers.php' . ($id ? "?id=$id" : ''));
    }

    if ($id > 0) {
        $beforeStmt = $pdo->prepare('SELECT name, phone, email, address, is_active FROM customers WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch();
        if (!$before) {
            flash_set('error', 'Customer not found.');
            redirect('customers.php');
        }
        $stmt = $pdo->prepare(
            "UPDATE customers
                SET name = ?, phone = ?, address = ?
              WHERE id = ?"
        );
        $stmt->execute([$name, $phone, $address, $id]);
        add_audit_log('Customer updated', 'customers', $id, $before, ['name' => $name, 'phone' => $phone, 'email' => $before['email'], 'address' => $address, 'is_active' => (int)$before['is_active']]);
        flash_set('success', 'Customer updated.');
    } else {
        /* Validate and keep new customer emails unique. Existing customer emails are immutable. */
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'That email address is not valid.');
            redirect('customers.php');
        }
        if ($email !== '') {
            $dup = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                flash_set('error', 'Another customer is already using that email address.');
                redirect('customers.php');
            }
            $accountDup = $pdo->prepare('SELECT customer_id FROM customer_accounts WHERE email = ?');
            $accountDup->execute([$email]);
            if ($accountDup->fetch()) {
                flash_set('error', 'That email is already used by another customer login.');
                redirect('customers.php');
            }
        }
        $stmt = $pdo->prepare(
            "INSERT INTO customers
                (name, phone, email, address, is_active)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$name, $phone, $email !== '' ? $email : null, $address, $isActive]);
        add_audit_log('Customer created', 'customers', (int)$pdo->lastInsertId(), null, ['name' => $name, 'email' => $email, 'is_active' => $isActive]);
        flash_set('success', 'Customer added.');
    }
    redirect('customers.php');
}

/* ---------- Activate / deactivate customer ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_customer_status'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        $beforeStmt = $pdo->prepare('SELECT id, name, is_active FROM customers WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch();
        if (!$before) {
            flash_set('error', 'Customer not found.');
        } elseif ((int)$before['is_active'] === $isActive) {
            flash_set('info', 'Customer status is already up to date.');
        } else {
            try {
                $pdo->beginTransaction();
                $status = $pdo->prepare('UPDATE customers SET is_active = ? WHERE id = ?');
                $status->execute([$isActive, $id]);
                if (!$isActive) {
                    // Revoke existing mobile sessions immediately; a reactivated customer must sign in again.
                    $pdo->prepare('DELETE FROM customer_sessions WHERE customer_id = ?')->execute([$id]);
                }
                add_audit_log($isActive ? 'Customer reactivated' : 'Customer deactivated', 'customers', $id, ['is_active' => (int)$before['is_active']], ['is_active' => $isActive]);
                $pdo->commit();
                flash_set('success', $isActive ? 'Customer reactivated.' : 'Customer deactivated. Order history has been preserved.');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Customer status update failed: ' . $exception->getMessage());
                flash_set('error', 'Could not update customer status. Please try again.');
            }
        }
    }
    redirect('customers.php');
}

/* ---------- Load data ---------- */
$search = trim($_GET['search'] ?? '');
$editing = null;

$editId = (int)($_GET['id'] ?? $_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$params = [];
$whereSql = '';
$showCustomerSpending = true;
$spendingSelect = $showCustomerSpending
    ? "(SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.customer_id = c.id AND o.status <> 'cancelled') AS total_spent"
    : 'NULL AS total_spent';
if ($search !== '') {
    $whereSql = "WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}
$stmt = $pdo->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
            $spendingSelect
     FROM customers c $whereSql
     ORDER BY c.name"
);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
$pageSubtitle = 'Manage your customer records';
include __DIR__ . '/includes/header.php';
?>

<div class="grid two">
  <div class="card">
    <h2><?= $editing ? 'Edit Customer' : 'Add Customer' ?></h2>
    <form method="post" action="customers.php">
      <?= csrf_field() ?>
      <input type="hidden" name="save" value="1">
      <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

      <label for="name">Full Name *</label>
      <input type="text" id="name" name="name" required value="<?= e($editing['name'] ?? '') ?>">

      <label for="phone">Phone</label>
      <input type="text" id="phone" name="phone" value="<?= e($editing['phone'] ?? '') ?>">

      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($editing['email'] ?? '') ?>" <?= $editing ? 'readonly aria-readonly="true"' : '' ?>>
      <?php if ($editing): ?><small class="muted">Customer email cannot be changed here.</small><?php endif; ?>

      <label for="address">Address</label>
      <input type="text" id="address" name="address" value="<?= e($editing['address'] ?? '') ?>">

      <?php if (!$editing): ?>
      <label class="checkbox-row">
        <input type="checkbox" name="is_active" value="1"
               <?= (!isset($editing['is_active']) || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
        <span>Active customer</span>
      </label>
      <?php else: ?>
      <p class="muted">Status: <span class="badge badge-<?= (int)$editing['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int)$editing['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>. Change status using the customer list.</p>
      <?php endif; ?>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Update' : 'Save' ?> Customer</button>
        <?php if ($editing): ?><a class="btn btn-outline" href="customers.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Customer List</h2>
      <form method="get" action="customers.php" class="row-between">
        <input type="text" name="search" placeholder="Search…" value="<?= e($search) ?>" style="width:180px;">
        <button class="btn btn-outline btn-sm" type="submit">Search</button>
      </form>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Contact</th><th class="num">Orders</th><?php if ($showCustomerSpending): ?><th class="num">Total Spent</th><?php endif; ?><th></th></tr></thead>
        <tbody>
          <?php if (!$customers): ?>
            <tr><td colspan="<?= $showCustomerSpending ? 5 : 4 ?>" class="empty-state">No customers found.</td></tr>
          <?php endif; ?>
          <?php foreach ($customers as $c): ?>
          <tr>
            <td>
              <?= e($c['name']) ?>
              <?php if ((int)($c['is_active'] ?? 1) !== 1): ?><span class="badge badge-inactive">Inactive</span><?php endif; ?>
            </td>
            <td class="muted"><div><?= e($c['phone'] ?: '—') ?></div><?php if ($c['email']): ?><div><?= e($c['email']) ?></div><?php endif; ?><?php if ($c['address']): ?><div class="small"><?= e($c['address']) ?></div><?php endif; ?></td>
            <td class="num"><?= (int)$c['order_count'] ?></td>
            <?php if ($showCustomerSpending): ?><td class="num"><?= money($c['total_spent']) ?></td><?php endif; ?>
            <td class="right">
              <a class="btn btn-outline btn-sm" href="customers.php?id=<?= (int)$c['id'] ?>">Edit</a>
              <?php $customerActive = (int)($c['is_active'] ?? 1) === 1; ?>
              <form method="post" action="customers.php" style="display:inline;"
                    onsubmit="return confirm('<?= $customerActive ? 'Deactivate' : 'Reactivate' ?> this customer account?<?= $customerActive ? ' They will be signed out of the mobile app; order history will be preserved.' : '' ?>');">
                <?= csrf_field() ?>
                <input type="hidden" name="set_customer_status" value="1">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="is_active" value="<?= $customerActive ? '0' : '1' ?>">
                <button class="btn <?= $customerActive ? 'btn-danger' : 'btn-outline' ?> btn-sm" type="submit"><?= $customerActive ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
