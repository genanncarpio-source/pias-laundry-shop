<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_pricing.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();

$pdo = db();
$processes = ['Wash', 'Dry', 'Full Service'];

// Standardize kilogram service rates as a price per 7 kg load. Per-piece rates stay unchanged.
$pdo->exec("UPDATE services SET price = CASE category WHEN 'Full Service' THEN 199.00 ELSE 85.00 END WHERE unit = 'kg' AND category IN ('Wash', 'Dry', 'Full Service')");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('services.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        // Keep services attached to past orders so historical records remain intact.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE service_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = ?');
            $stmt->execute([$id]);
            add_audit_log('Service deactivated', 'services', $id);
            flash_set('success', 'Service removed from the POS. It was kept because it is linked to past orders.');
        } else {
            add_audit_log('Service deleted', 'services', $id);
            $stmt = $pdo->prepare('DELETE FROM services WHERE id = ?');
            $stmt->execute([$id]);
            flash_set('success', 'Service deleted.');
        }
    }
    redirect('services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('services.php');
    }
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $unit        = ($_POST['unit'] ?? 'kg') === 'piece' ? 'piece' : 'kg';
    $price       = max(0, (float)($_POST['price'] ?? 0));
    if ($unit === 'kg') $price = service_load_price($category) ?? $price;
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || !in_array($category, $processes, true) || $price <= 0) {
        flash_set('error', 'Enter a service name, choose one of the three processes, and enter a price greater than 0.');
        redirect('services.php' . ($id ? "?id=$id" : ''));
    }

    if ($id > 0) {
        $beforeStmt = $pdo->prepare('SELECT name, category, unit, price, is_active FROM services WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch();
        $stmt = $pdo->prepare("UPDATE services SET name = ?, category = ?, unit = ?, price = ?, description = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $category, $unit, $price, $description, $is_active, $id]);
        add_audit_log('Service updated', 'services', $id, $before, ['name' => $name, 'category' => $category, 'unit' => $unit, 'price' => $price, 'is_active' => $is_active]);
        flash_set('success', 'Service updated.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO services (name, category, unit, price, description, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $unit, $price, $description, $is_active]);
        add_audit_log('Service added', 'services', (int)$pdo->lastInsertId(), null, ['name' => $name, 'category' => $category, 'unit' => $unit, 'price' => $price]);
        flash_set('success', 'Service added.');
    }
    redirect('services.php');
}

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

$services = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category, name")->fetchAll();
foreach ($services as &$service) {
    if ($service['unit'] === 'kg') $service['price'] = service_load_price($service['category']) ?? $service['price'];
}
unset($service);
$legacyProcess = static function ($category) {
    if (in_array($category, ['Wash', 'Dry', 'Full Service'], true)) return $category;
    if ($category === 'Wash & Fold') return 'Full Service';
    return 'Dry'; // Ironing & Press and Dry Cleaning are dry-only processes.
};

$pageTitle = 'Edit Services';
$pageSubtitle = 'Manage your service list and prices';
include __DIR__ . '/includes/header.php';
?>

<div class="grid two">
  <div class="card">
    <h2><?= $editing ? 'Edit Service' : 'Add Service' ?></h2>
    <form method="post" action="services.php">
      <?= csrf_field() ?>
      <input type="hidden" name="save" value="1">
      <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

      <label for="name">Service Name *</label>
      <input type="text" id="name" name="name" required value="<?= e($editing['name'] ?? '') ?>">

      <div class="form-row">
        <div>
          <label for="category">Category *</label>
          <select id="category" name="category" required>
            <?php $selectedProcess = $editing ? $legacyProcess($editing['category']) : 'Wash'; ?>
            <?php foreach ($processes as $process): ?>
              <option value="<?= e($process) ?>" <?= $selectedProcess === $process ? 'selected' : '' ?>><?= e($process) ?><?= $process === 'Full Service' ? ' (Wash, Dry & Fold)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="unit">Unit</label>
          <select id="unit" name="unit">
            <option value="kg" <?= ($editing['unit'] ?? 'kg') === 'kg' ? 'selected' : '' ?>>Per kilogram (kg)</option>
            <option value="piece" <?= ($editing['unit'] ?? '') === 'piece' ? 'selected' : '' ?>>Per piece</option>
          </select>
        </div>
      </div>

      <label for="price">Price (₱<?= ($editing['unit'] ?? 'kg') === 'kg' ? ' per 7 kg' : ' per piece' ?>) *</label>
      <input type="number" id="price" name="price" min="0" step="0.01" required
             value="<?= e($editing['price'] ?? '') ?>">

      <label for="description">Description</label>
      <input type="text" id="description" name="description" value="<?= e($editing['description'] ?? '') ?>">

      <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
        <input type="checkbox" name="is_active" value="1" style="width:auto;"
               <?= !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : '' ?>>
        Active (available in POS)
      </label>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Update' : 'Save' ?> Service</button>
        <?php if ($editing): ?><a class="btn btn-outline" href="services.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Price List</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Service</th><th>Category</th><th>Unit</th><th class="num">Price</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($services as $s): ?>
          <tr>
            <td><?= e($s['name']) ?></td>
            <td class="muted"><?= e($s['category']) ?></td>
            <td><?= e($s['unit']) ?></td>
            <td class="num"><?= money($s['price']) ?> <span class="muted">/ <?= $s['unit'] === 'kg' ? '7 kg' : 'piece' ?></span></td>
            <td><span class="badge badge-<?= $s['is_active'] ? 'active' : 'inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="right" style="white-space:nowrap;">
              <a class="btn btn-outline btn-sm" href="services.php?id=<?= (int)$s['id'] ?>">Edit</a>
              <form method="post" action="services.php" style="display:inline;" onsubmit="return confirm('Delete this service? Existing order history will be preserved.');">
                <?= csrf_field() ?>
                <input type="hidden" name="delete" value="1">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete Service</button>
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
