<?php
require_once __DIR__ . '/bootstrap.php';
$customer = api_current_customer();
$pdo = db();
$customerId = (int)$customer['id'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $stmt = $pdo->prepare("SELECT n.id, n.order_id, n.title, n.message, n.type, n.is_read, n.created_at, o.order_number FROM notifications n LEFT JOIN orders o ON o.id = n.order_id AND o.customer_id = n.audience_id WHERE n.audience = 'customer' AND n.audience_id = ? ORDER BY n.id DESC LIMIT 100");
    $stmt->execute([$customerId]);
    $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE audience = 'customer' AND audience_id = ? AND is_read = 0");
    $unread->execute([$customerId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notifications as &$notification) {
        $notification['id'] = (int)$notification['id'];
        $notification['order_id'] = $notification['order_id'] === null ? null : (int)$notification['order_id'];
        $notification['is_read'] = (int)$notification['is_read'];
    }
    unset($notification);
    api_json(200, ['success' => true, 'notifications' => $notifications, 'unread_count' => (int)$unread->fetchColumn()]);
}
api_require_method('POST');
$data = api_input();
if (($data['action'] ?? '') === 'delete_all') {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE audience = 'customer' AND audience_id = ?");
    $stmt->execute([$customerId]);
} elseif (($data['action'] ?? '') === 'delete') {
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) api_json(422, ['success' => false, 'error' => 'Choose a valid notification.']);
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND audience = 'customer' AND audience_id = ?");
    $stmt->execute([$id, $customerId]);
} elseif (!empty($data['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE audience = 'customer' AND audience_id = ? AND is_read = 0");
    $stmt->execute([$customerId]);
} else {
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) api_json(422, ['success' => false, 'error' => 'Choose a valid notification.']);
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND audience = 'customer' AND audience_id = ?");
    $stmt->execute([$id, $customerId]);
}
api_json(200, ['success' => true]);
