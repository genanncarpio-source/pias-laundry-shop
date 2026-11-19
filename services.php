<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_pricing.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();

$pdo = db();
// Preserve the two add-ons previously supplied directly by the mobile API.
foreach ([['Fabcon (Downy)', 'Downy fabric conditioner'], ['Detergent (Surf)', 'Surf detergent']] as [$legacyName, $legacyDescription]) {
    $check = $pdo->prepare("SELECT id FROM services WHERE name = ? AND category LIKE 'Add%' LIMIT 1");
    $check->execute([$legacyName]);
    if (!$check->fetchColumn()) {
        $pdo->prepare("INSERT INTO services (name, category, unit, price, description, is_active) VALUES (?, 'Add-ons', 'load', 10.00, ?, 1)")->execute([$legacyName, $legacyDescription]);
    }
}
$processes = ['Wash', 'Dry', 'Full Service'];
$unitColumn = $pdo->query("SHOW COLUMNS FROM services LIKE 'unit'")->fetch(PDO::FETCH_ASSOC);
if ($unitColumn && stripos((string)$unitColumn['Type'], 'enum(') === 0) {
    $pdo->exec("ALTER TABLE services MODIFY unit VARCHAR(30) NOT NULL DEFAULT 'kg'");
}
$orderUnitColumn = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'unit'")->fetch(PDO::FETCH_ASSOC);
if ($orderUnitColumn && preg_match('/varchar\((\d+)\)/i', (string)$orderUnitColumn['Type'], $unitLength) && (int)$unitLength[1] < 30) {
    $pdo->exec("ALTER TABLE order_items MODIFY unit VARCHAR(30) NOT NULL DEFAULT 'kg'");
}

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
            flash_set('success', 'Service removed from Offers. It was kept because it is linked to past orders.');
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
    $unit        = trim((string)($_POST['unit'] ?? 'kg'));
    $rawPrice    = trim((string)($_POST['price'] ?? ''));
    $price       = filter_var($rawPrice, FILTER_VALIDATE_FLOAT);
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $category === '' || mb_strlen($category) > 60 || $unit === '' || mb_strlen($unit) > 30 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]{0,29}$/u', $unit) || $price === false || $price <= 0) {
        flash_set('error', 'Enter a service name, category, unit, and a valid price greater than 0.');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_addon'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('services.php#addons');
    }
    $addonId = (int)($_POST['addon_id'] ?? 0);
    $addonName = trim($_POST['addon_name'] ?? '');
    $addonPrice = filter_var(trim((string)($_POST['addon_price'] ?? '')), FILTER_VALIDATE_FLOAT);
    if ($addonName === '' || mb_strlen($addonName) > 120 || $addonPrice === false || $addonPrice <= 0) {
        flash_set('error', 'Enter an add-on name and a valid price greater than 0.');
        redirect('services.php#addons');
    }
    if ($addonId > 0) {
        $beforeStmt = $pdo->prepare("SELECT name, category, unit, price, is_active FROM services WHERE id = ? AND category LIKE 'Add%'");
        $beforeStmt->execute([$addonId]);
        $before = $beforeStmt->fetch();
        if (!$before) { flash_set('error', 'Add-on not found.'); redirect('services.php#addons'); }
        $pdo->prepare("UPDATE services SET name = ?, category = 'Add-ons', unit = 'load', price = ?, description = '', is_active = 1 WHERE id = ?")->execute([$addonName, $addonPrice, $addonId]);
        add_audit_log('Add-on updated', 'services', $addonId, $before, ['name' => $addonName, 'category' => 'Add-ons', 'unit' => 'load', 'price' => $addonPrice, 'is_active' => 1]);
        flash_set('success', 'Add-on updated.');
    } else {
        $pdo->prepare("INSERT INTO services (name, category, unit, price, description, is_active) VALUES (?, 'Add-ons', 'load', ?, '', 1)")->execute([$addonName, $addonPrice]);
        $addonId = (int)$pdo->lastInsertId();
        add_audit_log('Add-on added', 'services', $addonId, null, ['name' => $addonName, 'category' => 'Add-ons', 'unit' => 'load', 'price' => $addonPrice]);
        flash_set('success', 'Add-on added.');
    }
    redirect('services.php#addons');
}

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

$services = $pdo->query("SELECT * FROM services WHERE is_active = 1 AND category NOT LIKE 'Add%' ORDER BY category, name")->fetchAll();
$addons = $pdo->query("SELECT * FROM services WHERE category LIKE 'Add%' ORDER BY name")->fetchAll();
$editingAddon = null;
if (isset($_GET['addon_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ? AND category LIKE 'Add%'");
    $stmt->execute([(int)$_GET['addon_id']]);
    $editingAddon = $stmt->fetch() ?: null;
}
$categorySuggestions = $pdo->query("SELECT DISTINCT category FROM services WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

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
          <input type="text" id="category" name="category" list="category-options" maxlength="60" required value="<?= e($editing['category'] ?? 'Wash') ?>" placeholder="Choose or type a category">
          <datalist id="category-options">
            <?php foreach ($processes as $process): ?><option value="<?= e($process) ?>"><?php endforeach; ?>
            <?php foreach ($categorySuggestions as $suggestion): ?><option value="<?= e($suggestion) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label for="unit">Unit</label>
          <input type="text" id="unit" name="unit" list="unit-options" maxlength="30" required value="<?= e($editing['unit'] ?? 'kg') ?>" placeholder="Choose or type a unit">
          <datalist id="unit-options"><option value="load"><option value="kg"><option value="piece"></datalist>
        </div>
      </div>

      <label id="price-label" for="price">Price (&#8369; per <?= ($editing['unit'] ?? 'kg') === 'load' ? 'load, up to 7 kg' : e($editing['unit'] ?? 'kg') ?>) *</label>
      <input type="number" id="price" name="price" min="0" step="0.01" required
             value="<?= e($editing['price'] ?? '') ?>">

      <label for="description">Description</label>
      <input type="text" id="description" name="description" value="<?= e($editing['description'] ?? '') ?>">

      <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
        <input type="checkbox" name="is_active" value="1" style="width:auto;"
               <?= !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : '' ?>>
        Active (Available in Offers)
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
            <td class="num"><?= money($s['price']) ?> <span class="muted">/ <?= $s['unit'] === 'load' ? 'load (up to 7 kg)' : e($s['unit']) ?></span></td>
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
<div class="card" id="addons" style="margin-top:20px;">
  <h2><?= $editingAddon ? 'Edit Add-on' : 'Manage Add-ons' ?></h2>
  <p class="muted">Add-ons are charged per laundry load and appear in the customer booking screen.</p>
  <form method="post" action="services.php#addons" class="form-row" style="align-items:end;">
    <?= csrf_field() ?><input type="hidden" name="save_addon" value="1"><input type="hidden" name="addon_id" value="<?= (int)($editingAddon['id'] ?? 0) ?>">
    <div><label for="addon-name">Add-on Name *</label><input id="addon-name" name="addon_name" maxlength="120" required value="<?= e($editingAddon['name'] ?? '') ?>" placeholder="e.g. Fabric conditioner"></div>
    <div><label for="addon-price">Price (&#8369; per load) *</label><input id="addon-price" type="number" name="addon_price" min="0.01" step="0.01" required value="<?= e($editingAddon['price'] ?? '') ?>"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $editingAddon ? 'Update' : 'Add' ?> Add-on</button><?php if ($editingAddon): ?><a class="btn btn-outline" href="services.php#addons">Cancel</a><?php endif; ?></div>
  </form>
  <div class="table-wrap"><table><thead><tr><th>Add-on</th><th>Unit</th><th class="num">Price</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($addons as $addon): ?><tr><td><?= e($addon['name']) ?></td><td>per load</td><td class="num"><?= money($addon['price']) ?></td><td><span class="badge badge-<?= $addon['is_active'] ? 'active' : 'inactive' ?>"><?= $addon['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><a class="btn btn-outline btn-sm" href="services.php?addon_id=<?= (int)$addon['id'] ?>#addons">Edit</a></td></tr><?php endforeach; ?>
    <?php if (!$addons): ?><tr><td colspan="5" class="muted">No add-ons yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<script>
(function () {
  var unit = document.getElementById("unit");
  var label = document.getElementById("price-label");
  if (!unit || !label) return;
  unit.addEventListener("input", function () {
    var value = unit.value.trim();
    var basis = value.toLowerCase() === "load" ? "load, up to 7 kg" : (value || "unit");
    label.textContent = "Price (" + String.fromCharCode(8369) + " per " + basis + ") *";
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
