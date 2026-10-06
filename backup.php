<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_feature_schema();
ensure_customer_auth_schema();

$tables = ['users', 'customers', 'services', 'orders', 'order_items', 'expenses', 'settings', 'order_history', 'order_adjustments'];
$optionalLegacyTables = ['inventory_items', 'inventory_movements', 'cash_closings'];
$pdo = db();
$existingLegacyTables = [];
$tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
foreach ($optionalLegacyTables as $table) {
    $tableCheck->execute([$table]);
    if ((int)$tableCheck->fetchColumn() > 0) $existingLegacyTables[] = $table;
}
$backupTables = array_merge($tables, $existingLegacyTables);

if (isset($_GET['download'])) {
    $data = [];
    foreach ($backupTables as $table) {
        $data[$table] = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    }
    $backup = ['format' => 'laundry-pos-backup', 'version' => 1, 'created_at' => date('c'), 'tables' => $data];
    $filename = 'pias-laundry-shop-backup-' . date('Y-m-d-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid request.');
        redirect('backup.php');
    }
    $upload = $_FILES['backup_file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > 50 * 1024 * 1024) {
        flash_set('error', 'Choose a valid backup file smaller than 50 MB.');
        redirect('backup.php');
    }
    if (($_POST['confirm_text'] ?? '') !== 'RESTORE') {
        flash_set('error', 'Type RESTORE exactly to confirm.');
        redirect('backup.php');
    }
    $decoded = json_decode((string)file_get_contents($upload['tmp_name']), true);
    if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'laundry-pos-backup' || ($decoded['version'] ?? 0) !== 1 || !is_array($decoded['tables'] ?? null)) {
        flash_set('error', 'This is not a supported Pia\'s Laundry Shop backup file.');
        redirect('backup.php');
    }
    foreach ($tables as $table) {
        if (!isset($decoded['tables'][$table]) || !is_array($decoded['tables'][$table])) {
            flash_set('error', 'The backup is incomplete. No data was changed.');
            redirect('backup.php');
        }
    }

    $restoreTables = array_merge($tables, array_values(array_intersect($existingLegacyTables, array_keys($decoded['tables']))));
    $insertOrder = $restoreTables;
    $deleteOrder = array_reverse($insertOrder);
    try {
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DELETE FROM customer_sessions');
        foreach ($deleteOrder as $table) $pdo->exec("DELETE FROM `$table`");
        foreach ($insertOrder as $table) {
            $columns = [];
            foreach ($pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[$column['Field']] = true;
            foreach ($decoded['tables'][$table] as $row) {
                if (!is_array($row) || !$row) continue;
                $keys = array_keys($row);
                foreach ($keys as $key) if (!isset($columns[$key])) throw new RuntimeException('Backup columns do not match this installation.');
                $quoted = array_map(static fn($key) => '`' . str_replace('`', '``', $key) . '`', $keys);
                $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($row));
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();
        flash_set('success', 'Backup restored. Sign in again if your saved account is not available.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        flash_set('error', 'Restore failed and was rolled back. Check that this backup matches the current system version.');
    }
    redirect('backup.php');
}

$pageTitle = 'Backup & Restore';
$pageSubtitle = 'Protect and recover your shop records';
include __DIR__ . '/includes/header.php';
?>
<div class="grid two">
  <section class="card">
    <h2>Create a backup</h2>
    <p class="muted">Downloads a JSON file containing shop settings, accounts, customers, laundry service tickets, expenses and related records. Keep a copy somewhere separate from this computer.</p>
    <a class="btn btn-primary" href="backup.php?download=1">Download backup</a>
  </section>
  <section class="card">
    <h2>Restore a backup</h2>
    <div class="alert alert-danger mb-16">Restoring replaces all current records with the selected backup. Create a fresh backup first.</div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="restore" value="1">
      <label for="backupFile">Backup file (.json)</label>
      <input type="file" id="backupFile" name="backup_file" accept=".json,application/json" required>
      <label for="confirmText">Type RESTORE to confirm</label>
      <input type="text" id="confirmText" name="confirm_text" autocomplete="off" required>
      <div class="form-actions"><button class="btn btn-danger" type="submit" data-confirm="This replaces all current shop records. Continue?">Restore backup</button></div>
    </form>
  </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
