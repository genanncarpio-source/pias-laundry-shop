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
        flash_set('error', 'Invalid request.');
        redirect('settings.php');
    }

    $trackingUrl = trim($_POST['tracking_url'] ?? '');
    if ($trackingUrl !== '' && (!filter_var($trackingUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($trackingUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
        flash_set('error', 'Enter a valid public HTTP or HTTPS tracking page URL.');
        redirect('settings.php');
    }
    settings_update([
        'shop_name'    => trim($_POST['shop_name'] ?? ''),
        'shop_address' => trim($_POST['shop_address'] ?? ''),
        'shop_phone'   => trim($_POST['shop_phone'] ?? ''),
        'receipt_note' => trim($_POST['receipt_note'] ?? ''),
        'tracking_url' => $trackingUrl,
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
  <form method="post" action="settings.php">
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

    <label for="tracking_url">Public laundry ticket tracking URL (optional)</label>
    <input type="url" id="tracking_url" name="tracking_url" maxlength="255" value="<?= e(get_setting('tracking_url')) ?>" placeholder="https://your-shop.example/track.php">
    <p class="field-help">Customers use this link to check laundry service progress. Leave blank to use the current website address.</p>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>
</div>

<!-- ================= About ================= -->
<div class="card mt-16" style="max-width:720px;">
  <h2>About this system</h2>
  <p class="muted">
    <strong>Laundry Shop Management System for Pia's Laundry Shop</strong>
  </p>
  <p class="muted mb-0">
    Built with PHP, MySQL, CSS and JavaScript. Database: <code>laundry_pos</code>.
    See <code>README.md</code> for setup instructions and <code>docs/</code> for the
    project documentation.
  </p>
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
