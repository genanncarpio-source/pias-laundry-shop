<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_feature_schema();
ensure_workflow_schema();

$user = current_user();
if (!$user || !in_array($user['role'], ['admin', 'staff'], true)) {
    flash_set('error', 'You do not have permission to manage delivery drivers.');
    redirect('dashboard.php');
}
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('delivery_drivers.php');
    }

    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Enter the driver name (up to 100 characters).');
            if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $username)) throw new InvalidArgumentException('Username must be 3-50 characters using letters, numbers, dots, underscores, or hyphens.');
            if (strlen($password) < 10) throw new InvalidArgumentException('Use a temporary password with at least 10 characters.');
            if (strlen($phone) > 30) throw new InvalidArgumentException('Phone number must be 30 characters or fewer.');
            $stmt = $pdo->prepare('INSERT INTO delivery_drivers (full_name, username, phone, password_hash, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $username, $phone !== '' ? $phone : null, password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
            $driverId = (int)$pdo->lastInsertId();
            add_audit_log('Delivery driver created', 'delivery_drivers', $driverId, null, ['name' => $name, 'username' => $username]);
            flash_set('success', 'Driver account created. Give the username and temporary password directly to the driver.');
        } elseif (in_array($action, ['deactivate', 'activate'], true)) {
            $driverId = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$driverId || $driverId < 1) throw new InvalidArgumentException('Choose a valid driver account.');
            if ($action === 'deactivate') {
                $activeOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE assigned_driver_id = ? AND order_type = 'delivery_request' AND status = 'out_for_delivery'");
                $activeOrders->execute([(int)$driverId]);
                if ((int)$activeOrders->fetchColumn() > 0) throw new InvalidArgumentException('Reassign the active deliveries before deactivating this driver.');
                $pdo->prepare('UPDATE delivery_drivers SET is_active = 0 WHERE id = ?')->execute([(int)$driverId]);
                $pdo->prepare('DELETE FROM delivery_driver_sessions WHERE driver_id = ?')->execute([(int)$driverId]);
                add_audit_log('Delivery driver deactivated', 'delivery_drivers', (int)$driverId, 'active', 'inactive');
                flash_set('success', 'Driver account deactivated. The driver can no longer sign in.');
            } else {
                $pdo->prepare('UPDATE delivery_drivers SET is_active = 1 WHERE id = ?')->execute([(int)$driverId]);
                add_audit_log('Delivery driver reactivated', 'delivery_drivers', (int)$driverId, 'inactive', 'active');
                flash_set('success', 'Driver account reactivated.');
            }
        } else {
            throw new InvalidArgumentException('Unsupported driver account action.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (PDOException $e) {
        flash_set('error', $e->getCode() === '23000' ? 'That driver username is already in use.' : 'Could not save the driver account.');
    } catch (Throwable $e) {
        flash_set('error', 'Could not save the driver account.');
    }
    redirect('delivery_drivers.php');
}

$drivers = $pdo->query(
    "SELECT d.id, d.full_name, d.username, d.phone, d.is_active, d.created_at,
            COUNT(DISTINCT CASE WHEN o.status = 'out_for_delivery' AND o.order_type = 'delivery_request' THEN o.id END) AS active_deliveries
       FROM delivery_drivers d
       LEFT JOIN orders o ON o.assigned_driver_id = d.id
      GROUP BY d.id
      ORDER BY d.is_active DESC, d.full_name"
)->fetchAll();

$pageTitle = 'Delivery Drivers';
$pageSubtitle = 'Create and manage driver-only app accounts';
include __DIR__ . '/includes/header.php';
?>

<div class="grid two">
  <section class="card">
    <h2>Add a delivery driver</h2>
    <p class="muted">Only staff and admin accounts can create these logins. Drivers use the separate Driver screen in the mobile app; customer accounts cannot view driver deliveries. On the driver's phone, open <a href="piaslaundry://driver">the Driver screen</a> in the installed app. In the web preview, add <code>/driver</code> to the app address.</p>
    <form method="post" action="delivery_drivers.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div><label for="driverName">Full name</label><input id="driverName" name="full_name" maxlength="100" required></div>
        <div><label for="driverPhone">Phone number (optional)</label><input id="driverPhone" name="phone" maxlength="30" autocomplete="tel"></div>
        <div><label for="driverUsername">Driver username</label><input id="driverUsername" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" autocomplete="off" required></div>
        <div><label for="driverPassword">Temporary password</label><input id="driverPassword" name="password" type="password" minlength="10" autocomplete="new-password" required><small class="muted">At least 10 characters. Give it directly to the driver.</small></div>
      </div>
      <div class="form-actions mt-16"><button class="btn btn-primary" type="submit">Create driver account</button></div>
    </form>
  </section>

  <section class="card">
    <h2>Driver accounts</h2>
    <?php if (!$drivers): ?>
      <p class="muted">No driver accounts yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Driver</th><th>Username</th><th>Status</th><th>Active deliveries</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($drivers as $driver): ?>
            <tr>
              <td><strong><?= e($driver['full_name']) ?></strong><?php if ($driver['phone']): ?><br><span class="muted"><?= e($driver['phone']) ?></span><?php endif; ?></td>
              <td><?= e($driver['username']) ?></td>
              <td><span class="badge badge-<?= (int)$driver['is_active'] === 1 ? 'completed' : 'cancelled' ?>"><?= (int)$driver['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
              <td><?= (int)$driver['active_deliveries'] ?></td>
              <td>
                <form method="post" action="delivery_drivers.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>">
                  <input type="hidden" name="action" value="<?= (int)$driver['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                  <button class="btn btn-outline btn-sm" type="submit"><?= (int)$driver['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
