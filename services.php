<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid request.');
        redirect('services.php');
    }
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $unit        = in_array($_POST['unit'] ?? 'kg', ['kg', 'piece', 'load'], true) ? $_POST['unit'] : 'kg';
    $price       = max(0, (float)($_POST['price'] ?? 0));
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $category === '' || $price <= 0) {
        flash_set('error', 'Name, category and a price greater than 0 are required.');
        redirect('services.php' . ($id ? "?id=$id" : ''));
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE services SET name = ?, category = ?, unit = ?, price = ?, description = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $category, $unit, $price, $description, $is_active, $id]);
        flash_set('success', 'Service updated.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO services (name, category, unit, price, description, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $unit, $price, $description, $is_active]);
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

$services = $pdo->query("SELECT * FROM services ORDER BY category, name")->fetchAll();
$categories = array_values(array_unique(array_column($services, 'category')));

$pageTitle = 'Services & Pricing';
$pageSubtitle = 'Manage your price list';
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
          <input type="text" id="category" name="category" list="categoryList" required
                 value="<?= e($editing['category'] ?? 'Wash & Fold') ?>">
          <datalist id="categoryList">
            <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label for="unit">Unit</label>
          <select id="unit" name="unit">
            <option value="kg" <?= ($editing['unit'] ?? 'kg') === 'kg' ? 'selected' : '' ?>>Per kilogram (kg)</option>
            <option value="piece" <?= ($editing['unit'] ?? '') === 'piece' ? 'selected' : '' ?>>Per piece</option>
            <option value="load" <?= ($editing['unit'] ?? '') === 'load' ? 'selected' : '' ?>>Per load</option>
          </select>
        </div>
      </div>

      <label for="price">Price (₱) *</label>
      <input type="number" id="price" name="price" min="0" step="0.01" required
             value="<?= e($editing['price'] ?? '') ?>">

      <label for="description">Description</label>
      <input type="text" id="description" name="description" value="<?= e($editing['description'] ?? '') ?>">

      <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
        <input type="checkbox" name="is_active" value="1" style="width:auto;"
               <?= !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : '' ?>>
        Active (available for service tickets)
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
            <td class="num"><?= money($s['price']) ?></td>
            <td><span class="badge badge-<?= $s['is_active'] ? 'active' : 'inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="right"><a class="btn btn-outline btn-sm" href="services.php?id=<?= (int)$s['id'] ?>">Edit</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
