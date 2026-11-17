<?php
/**
 * Pia's Laundry Shop — System Settings (admin only).
 *
 * Sections: Shop Information, About.
 */
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_archived_all']) || isset($_POST['delete_archived_order']))) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('settings.php#history-service-detail');
    }
    $pdo->beginTransaction();
    try {
        if (isset($_POST['delete_archived_all'])) {
            $pdo->exec('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE archived_at IS NOT NULL)');
            $pdo->exec('DELETE FROM reviews WHERE order_id IN (SELECT id FROM orders WHERE archived_at IS NOT NULL)');
            $pdo->exec('DELETE FROM orders WHERE archived_at IS NOT NULL');
            flash_set('success', 'Archived service bookings and their records were permanently deleted.');
        } else {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $paymentDelete = $pdo->prepare('DELETE FROM payments WHERE order_id = ? AND EXISTS (SELECT 1 FROM orders WHERE id = ? AND archived_at IS NOT NULL)');
            $paymentDelete->execute([$orderId, $orderId]);
            $reviewDelete = $pdo->prepare('DELETE FROM reviews WHERE order_id = ? AND EXISTS (SELECT 1 FROM orders WHERE id = ? AND archived_at IS NOT NULL)');
            $reviewDelete->execute([$orderId, $orderId]);
            $orderDelete = $pdo->prepare('DELETE FROM orders WHERE id = ? AND archived_at IS NOT NULL');
            $orderDelete->execute([$orderId]);
            flash_set('success', $orderDelete->rowCount() ? 'Service booking was permanently deleted.' : 'Archived booking was not found.');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    redirect('settings.php#history-service-detail');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'This form has expired. Refresh the page and try again.');
        redirect('settings.php');
    }

    $gcashName = trim((string)($_POST['gcash_account_name'] ?? ''));
    $gcashNumber = trim((string)($_POST['gcash_number'] ?? ''));
    $gcashQrPath = get_setting('gcash_qr_path');
    if (isset($_POST['remove_gcash_qr'])) $gcashQrPath = '';

    if (strlen($gcashName) > 100 || strlen($gcashNumber) > 30 || (($gcashName === '') !== ($gcashNumber === '')) || ($gcashNumber !== '' && !preg_match('/^[0-9+()\s-]{7,30}$/', $gcashNumber))) {
        flash_set('error', 'Enter both a valid GCash account name and number, or leave both blank to disable GCash.');
        redirect('settings.php');
    }

    $shopLatitude = trim((string)($_POST['shop_latitude'] ?? ''));
    $shopLongitude = trim((string)($_POST['shop_longitude'] ?? ''));
    if (($shopLatitude === '') !== ($shopLongitude === '')
        || ($shopLatitude !== '' && (!is_numeric($shopLatitude) || !is_numeric($shopLongitude)
            || (float)$shopLatitude < -90 || (float)$shopLatitude > 90
            || (float)$shopLongitude < -180 || (float)$shopLongitude > 180))) {
        flash_set('error', 'Enter valid latitude and longitude coordinates for the shop delivery origin, or leave both blank.');
        redirect('settings.php');
    }
    $upload = $_FILES['gcash_qr'] ?? null;
    if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($upload['size'] ?? 0) > 3 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            flash_set('error', 'The GCash QR image must be a valid file no larger than 3 MB.');
            redirect('settings.php');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            flash_set('error', 'Upload the GCash QR as a PNG, JPG, or WebP image.');
            redirect('settings.php');
        }
        $uploadDirectory = __DIR__ . '/uploads/gcash';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            flash_set('error', 'Could not create the GCash QR upload folder.');
            redirect('settings.php');
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($upload['tmp_name'], $uploadDirectory . '/' . $fileName)) {
            flash_set('error', 'Could not save the GCash QR image.');
            redirect('settings.php');
        }
        $gcashQrPath = 'uploads/gcash/' . $fileName;
    }

    settings_update([
        'shop_name' => trim($_POST['shop_name'] ?? ''),
        'shop_address' => trim($_POST['shop_address'] ?? ''),
        'shop_latitude' => trim($_POST['shop_latitude'] ?? ''),
        'shop_longitude' => trim($_POST['shop_longitude'] ?? ''),
        'shop_phone' => trim($_POST['shop_phone'] ?? ''),
        'receipt_note' => trim($_POST['receipt_note'] ?? ''),
        'gcash_account_name' => $gcashName,
        'gcash_number' => $gcashNumber,
        'gcash_qr_path' => $gcashQrPath,
    ]);

    flash_set('success', 'Settings saved.');
    redirect('settings.php');
}

$pageTitle = 'Settings';
$pageSubtitle = 'Shop information';
$archivedOrders = $pdo->query("SELECT o.id, o.order_number, o.customer_name, o.status, o.total, o.created_at, o.archived_at, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count FROM orders o WHERE o.archived_at IS NOT NULL ORDER BY o.archived_at DESC, o.id DESC")->fetchAll();
$historyStatusLabels = order_status_labels();
include __DIR__ . '/includes/header.php';
?>

<!-- ================= Shop Information ================= -->
<div class="card" style="max-width:720px;" id="shop">
  <h2>Shop Information</h2>
  <form method="post" action="settings.php" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <label for="shop_name">Shop name</label>
    <input type="text" id="shop_name" name="shop_name" maxlength="120" value="<?= e(get_setting('shop_name')) ?>">
    <p class="field-help">Appears in the sidebar, page title, and printed receipts.</p>

    <label for="shop_address">Address</label>
    <input type="text" id="shop_address" name="shop_address" maxlength="255" value="<?= e(get_setting('shop_address')) ?>">
    <p class="field-help">Printed on receipts so customers can identify the shop.</p>
    <label for="shop_latitude">Delivery origin latitude</label>
    <input type="number" id="shop_latitude" name="shop_latitude" step="any" min="-90" max="90" value="<?= e(get_setting('shop_latitude')) ?>" placeholder="Example: 15.48">
    <label for="shop_longitude">Delivery origin longitude</label>
    <input type="number" id="shop_longitude" name="shop_longitude" step="any" min="-180" max="180" value="<?= e(get_setting('shop_longitude')) ?>" placeholder="Example: 120.59">
    <p class="field-help">Set both coordinates to the exact shop pin. Paid delivery uses driving distance from this point. You can copy coordinates from the shop pin in Google Maps.</p>

    <label for="shop_phone">Phone</label>
    <input type="tel" id="shop_phone" name="shop_phone" maxlength="40" value="<?= e(get_setting('shop_phone')) ?>">
    <p class="field-help">Contact number shown on receipts.</p>

    <label for="receipt_note">Receipt footer note</label>
    <textarea id="receipt_note" name="receipt_note" rows="3" maxlength="500"><?= e(get_setting('receipt_note')) ?></textarea>
    <p class="field-help">Optional message printed at the bottom, such as opening hours or a thank-you note.</p>

    <hr class="mt-16">
    <h2>Mobile App GCash Payments</h2>
    <p class="field-help">Customers can transfer the exact booking total to this account, then submit the GCash reference in the app. Staff must verify the transfer before processing the order. This does not charge GCash automatically.</p>
    <label for="gcash_account_name">GCash account name</label>
    <input type="text" id="gcash_account_name" name="gcash_account_name" maxlength="100" value="<?= e(get_setting('gcash_account_name')) ?>">
    <label for="gcash_number">GCash mobile number</label>
    <input type="tel" id="gcash_number" name="gcash_number" maxlength="30" value="<?= e(get_setting('gcash_number')) ?>" placeholder="09xx xxx xxxx">
    <label for="gcash_qr">GCash QR image (PNG, JPG, or WebP; max 3 MB)</label>
    <input type="file" id="gcash_qr" name="gcash_qr" accept="image/png,image/jpeg,image/webp">
    <?php $savedGcashQr = get_setting('gcash_qr_path'); ?>
    <?php if ($savedGcashQr !== ''): ?>
      <div class="mt-16"><img src="<?= e($savedGcashQr) ?>" alt="Saved GCash QR" style="display:block;max-width:240px;max-height:240px;object-fit:contain;border:1px solid #ddd;padding:8px;border-radius:8px;background:#fff;"></div>
      <label class="mt-16"><input type="checkbox" name="remove_gcash_qr" value="1"> Remove saved QR image</label>
    <?php endif; ?>
    <p class="field-help">Leave both account fields blank to disable GCash in the customer app. Upload the official Admin GCash QR so customers can scan it.</p>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>
</div>

<!-- ================= About ================= -->
<div class="card mt-16" style="max-width:720px;">
  <h2>About this system</h2>
  <p class="muted">
    <strong> Laundry Shop Management System for Pia's Laundry Shop</strong>
  </p>
  <!-- <p class="muted mb-0">
    Built with PHP, MySQL, CSS and JavaScript. Database: <code>laundry_pias</code>.
    See <code>README.md</code> for setup instructions and <code>docs/</code> for the
    project documentation.
  </p> -->
</div>

<!-- ================= Archived service bookings ================= -->
<div class="card mt-16" id="history-service-detail">
  <div class="row-between mb-16"><div><h2 class="mt-0">History Service Detail</h2><p class="muted mb-0">Cleared bookings are kept here until permanently deleted.</p></div><?php if ($archivedOrders): ?><form method="post" onsubmit="return confirm('Permanently delete every archived service booking and its related payment and review records? This cannot be undone.');"><?= csrf_field() ?><button class="btn btn-danger" name="delete_archived_all" value="1">Delete all permanently</button></form><?php endif; ?></div>
  <?php if (!$archivedOrders): ?><p class="empty-state">No cleared service bookings yet.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Order #</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Service date</th><th>Cleared</th><th></th></tr></thead><tbody>
    <?php foreach ($archivedOrders as $archived): ?><tr>
      <td><a href="order_view.php?id=<?= (int)$archived['id'] ?>"><?= e($archived['order_number']) ?></a></td><td><?= e($archived['customer_name'] ?: 'Walk-in') ?></td><td><?= (int)$archived['item_count'] ?></td><td><?= money($archived['total']) ?></td><td><?= e($historyStatusLabels[$archived['status']] ?? ucfirst(str_replace('_', ' ', $archived['status']))) ?></td><td><?= e(date('M j, Y', strtotime($archived['created_at']))) ?></td><td><?= e(date('M j, Y g:i A', strtotime($archived['archived_at']))) ?></td>
      <td><form method="post" onsubmit="return confirm('Permanently delete this service booking and its related payment and review records? This cannot be undone.');"><?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$archived['id'] ?>"><button class="btn btn-danger btn-sm" name="delete_archived_order" value="1">Delete</button></form></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
