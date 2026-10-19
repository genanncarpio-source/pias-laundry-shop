<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_feature_schema();
$pdo = db();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) { flash_set('error', 'This form has expired. Refresh the page and try again.'); redirect('inventory.php'); }
    try {
        if (isset($_POST['add_item'])) {
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? 'piece');
            $stock = (float)($_POST['initial_stock'] ?? 0);
            $level = (float)($_POST['reorder_level'] ?? 0);
            if ($name === '' || !in_array($unit, ['piece', 'bottle', 'kg', 'liter', 'pack', 'roll'], true) || $stock < 0 || $level < 0) throw new InvalidArgumentException('Enter a name, supported unit and non-negative quantities.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO inventory_items (name, unit, current_stock, reorder_level) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $unit, $stock, $level]);
            $itemId = (int)$pdo->lastInsertId();
            if ($stock > 0) $pdo->prepare("INSERT INTO inventory_movements (item_id, movement_type, quantity, note, created_by) VALUES (?, 'in', ?, 'Opening stock', ?)")->execute([$itemId, $stock, $userId]);
            $pdo->commit();
            flash_set('success', 'Inventory item added.');
        } elseif (isset($_POST['move_stock'])) {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $type = $_POST['movement_type'] ?? '';
            $qty = (float)($_POST['quantity'] ?? 0);
            $costRaw = trim($_POST['unit_cost'] ?? '');
            $cost = $costRaw === '' ? null : (float)$costRaw;
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $note = trim($_POST['note'] ?? '');
            if (!in_array($type, ['in', 'out'], true) || !in_array($paymentMethod, ['cash', 'gcash', 'maya', 'card'], true) || $qty <= 0 || ($cost !== null && $cost < 0)) throw new InvalidArgumentException('Enter a valid stock movement, payment method and quantity.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ? AND is_active = 1 FOR UPDATE');
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) throw new InvalidArgumentException('Choose an active inventory item.');
            if ($type === 'out' && $qty > (float)$item['current_stock']) throw new InvalidArgumentException('The quantity used cannot exceed current stock.');
            $newStock = (float)$item['current_stock'] + ($type === 'in' ? $qty : -$qty);
            $pdo->prepare('UPDATE inventory_items SET current_stock = ? WHERE id = ?')->execute([$newStock, $itemId]);
            $pdo->prepare('INSERT INTO inventory_movements (item_id, movement_type, quantity, unit_cost, note, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$itemId, $type, $qty, $type === 'in' ? $cost : null, $note ?: null, $userId]);
            if ($type === 'in' && $cost !== null && $cost > 0) {
                $pdo->prepare("INSERT INTO expenses (description, category, amount, payment_method, expense_date, created_by) VALUES (?, 'Supplies', ?, ?, CURDATE(), ?)")->execute(['Inventory restock: ' . $item['name'], round($qty * $cost, 2), $paymentMethod, $userId]);
            }
            $pdo->commit();
            flash_set('success', $type === 'in' ? 'Stock added.' : 'Stock usage recorded.');
        } elseif (isset($_POST['deactivate'])) {
            $pdo->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = ?')->execute([(int)$_POST['item_id']]);
            flash_set('success', 'Inventory item archived. Its movement history was kept.');
        } elseif (isset($_POST['update_item'])) {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $currentItemStmt = $pdo->prepare('SELECT name, reorder_level FROM inventory_items WHERE id = ? AND is_active = 1');
            $currentItemStmt->execute([$itemId]);
            $currentItem = $currentItemStmt->fetch();
            if (!$currentItem) throw new InvalidArgumentException('Inventory item not found.');
            $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : $currentItem['name'];
            $level = array_key_exists('reorder_level', $_POST) ? (float)$_POST['reorder_level'] : (float)$currentItem['reorder_level'];
            if ($name === '' || $level < 0) throw new InvalidArgumentException('Enter a name and non-negative low-stock level.');
            $pdo->prepare('UPDATE inventory_items SET name = ?, reorder_level = ? WHERE id = ?')->execute([$name, $level, (int)$_POST['item_id']]);
            flash_set('success', 'Inventory item updated.');
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', 'Unable to save this inventory change. Please try again.');
    }
    redirect('inventory.php');
}

$items = $pdo->query('SELECT * FROM inventory_items WHERE is_active = 1 ORDER BY name')->fetchAll();
$movements = $pdo->query('SELECT m.*, i.name AS item_name, i.unit, u.full_name FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id LEFT JOIN users u ON u.id = m.created_by ORDER BY m.id DESC LIMIT 30')->fetchAll();
$pageTitle = 'Inventory';
$pageSubtitle = 'Track supplies and low-stock items';
include __DIR__ . '/includes/header.php';
?>
<div class="grid two">
  <section class="card">
    <h2>Add inventory item</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="add_item" value="1">
      <label for="itemName">Supply name</label><input id="itemName" name="name" maxlength="120" required placeholder="e.g. Laundry detergent">
      <div class="form-row">
        <div><label for="itemUnit">Unit</label><select id="itemUnit" name="unit"><option>piece</option><option>bottle</option><option>kg</option><option>liter</option><option>pack</option><option>roll</option></select></div>
        <div><label for="initialStock">Starting stock</label><input id="initialStock" type="number" name="initial_stock" min="0" step="0.01" value="0" required></div>
      </div>
      <label for="reorderLevel">Low-stock alert at</label><input id="reorderLevel" type="number" name="reorder_level" min="0" step="0.01" value="0" required>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Add item</button></div>
    </form>
  </section>
  <section class="card">
    <h2>Record stock movement</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="move_stock" value="1">
      <label for="movementItem">Item</label><select id="movementItem" name="item_id" required><?php foreach ($items as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e($item['name']) ?> (<?= e(rtrim(rtrim(number_format($item['current_stock'], 2), '0'), '.')) ?> <?= e($item['unit']) ?>)</option><?php endforeach; ?></select>
      <div class="form-row">
        <div><label for="movementType">Movement</label><select id="movementType" name="movement_type"><option value="in">Stock received</option><option value="out">Stock used</option></select></div>
        <div><label for="movementQty">Quantity</label><input id="movementQty" type="number" name="quantity" min="0.01" step="0.01" required></div>
      </div>
      <label for="unitCost">Cost per unit (optional)</label><input id="unitCost" type="number" name="unit_cost" min="0" step="0.01" placeholder="For received stock; added to Expenses"><p class="field-help">A stock receipt with a cost is added to Supplies expenses. Leave blank if already recorded.</p>
      <label for="stockPayment">Purchase payment method</label><select id="stockPayment" name="payment_method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card">Card</option></select>
      <label for="movementNote">Note</label><input id="movementNote" name="note" maxlength="255" placeholder="e.g. Used during morning shift">
      <div class="form-actions"><button class="btn btn-primary" type="submit" <?= !$items ? 'disabled' : '' ?>>Save movement</button></div>
    </form>
  </section>
</div>
<section class="card mt-16">
  <h2>Current stock</h2><div class="table-wrap"><table><thead><tr><th>Supply</th><th>Unit</th><th class="num">On hand</th><th class="num">Alert level</th><th>Status</th><th></th></tr></thead><tbody>
  <?php if (!$items): ?><tr><td colspan="6" class="empty-state">No active inventory items yet.</td></tr><?php endif; ?>
  <?php foreach ($items as $item): $low = (float)$item['current_stock'] <= (float)$item['reorder_level']; ?><tr><td><form method="post" class="inventory-edit"><?= csrf_field() ?><input type="hidden" name="update_item" value="1"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><input aria-label="Supply name" name="name" maxlength="120" value="<?= e($item['name']) ?>" required><button class="btn btn-outline btn-sm">Save</button></form></td><td><?= e($item['unit']) ?></td><td class="num"><strong><?= e(rtrim(rtrim(number_format($item['current_stock'], 2), '0'), '.')) ?></strong></td><td><form method="post" class="inventory-edit"><?= csrf_field() ?><input type="hidden" name="update_item" value="1"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><input aria-label="Low stock level" type="number" name="reorder_level" min="0" step="0.01" value="<?= e($item['reorder_level']) ?>" required><input type="hidden" name="name" value="<?= e($item['name']) ?>"><button class="btn btn-outline btn-sm">Save</button></form></td><td><span class="badge <?= $low ? 'badge-pending' : 'badge-active' ?>"><?= $low ? 'Low stock' : 'In stock' ?></span></td><td><form method="post" onsubmit="return confirm('Archive this item? Movement history will remain.');"><?= csrf_field() ?><input type="hidden" name="deactivate" value="1"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><button class="btn btn-outline btn-sm">Archive</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<section class="card mt-16"><h2>Recent movements</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Supply</th><th>Movement</th><th class="num">Qty</th><th>Note</th><th>Recorded by</th></tr></thead><tbody>
<?php if (!$movements): ?><tr><td colspan="6" class="empty-state">Stock changes will appear here.</td></tr><?php endif; ?>
<?php foreach ($movements as $move): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($move['created_at']))) ?></td><td><?= e($move['item_name']) ?></td><td><?= $move['movement_type'] === 'in' ? 'Received' : 'Used' ?></td><td class="num"><?= e(rtrim(rtrim(number_format($move['quantity'], 2), '0'), '.')) ?> <?= e($move['unit']) ?></td><td><?= e($move['note'] ?: '—') ?></td><td><?= e($move['full_name'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
