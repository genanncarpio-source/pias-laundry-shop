<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/feature_schema.php';
require_login();
ensure_workflow_schema();
$user = current_user();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? null)) {
    if (isset($_POST['mark_all'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE audience = 'staff' AND audience_id = ?");
        $stmt->execute([(int)$user['id']]);
    } elseif (isset($_POST['mark_read'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND audience = 'staff' AND audience_id = ?");
        $stmt->execute([(int)$_POST['notification_id'], (int)$user['id']]);
    }
}
$stmt = $pdo->prepare("SELECT n.*, o.order_number FROM notifications n LEFT JOIN orders o ON o.id = n.order_id WHERE n.audience = 'staff' AND n.audience_id = ? ORDER BY n.id DESC LIMIT 150");
$stmt->execute([(int)$user['id']]);
$notifications = $stmt->fetchAll();
$pageTitle = 'Notifications';
$pageSubtitle = 'Order and payment updates';
include __DIR__ . '/includes/header.php';
?>
<div class="row-between mb-16"><div><p class="muted">Notifications assigned to your account.</p></div><form method="post"><?= csrf_field() ?><button class="btn btn-outline" name="mark_all" value="1">Mark all read</button></form></div>
<div class="card"><?php if (!$notifications): ?><p class="muted">No notifications yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Notification</th><th>Order</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($notifications as $notice): ?><tr><td><strong><?= e($notice['title']) ?></strong><br><?= e($notice['message']) ?></td><td><?php if ($notice['order_id']): ?><a href="order_view.php?id=<?= (int)$notice['order_id'] ?>"><?= e($notice['order_number'] ?: 'View order') ?></a><?php else: ?>—<?php endif; ?></td><td><?= e(date('M j, Y g:i A', strtotime($notice['created_at']))) ?></td><td><?= $notice['is_read'] ? 'Read' : 'Unread' ?></td><td><?php if (!$notice['is_read']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="notification_id" value="<?= (int)$notice['id'] ?>"><button class="btn btn-outline btn-sm" name="mark_read" value="1">Mark read</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
