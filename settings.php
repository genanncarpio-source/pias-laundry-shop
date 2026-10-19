<?php
/**
 * Pia's Laundry Shop — System Settings (admin only).
 *
 * Sections: Shop Information, About.
 */
require_once __DIR__ . '/includes/auth.php';
require_admin();

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
    Built with PHP, MySQL, CSS and JavaScript. Database: <code>laundry_pos</code>.
    See <code>README.md</code> for setup instructions and <code>docs/</code> for the
    project documentation.
  </p> -->
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
