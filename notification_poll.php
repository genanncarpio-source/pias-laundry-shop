<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$afterId = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT);
$afterId = $afterId === false ? 0 : max(0, (int)$afterId);
$userId = (int)current_user()['id'];
$query = db()->prepare("SELECT n.id, n.title, n.message, n.order_id, n.created_at, n.is_read, o.order_number
    FROM notifications n LEFT JOIN orders o ON o.id = n.order_id
    WHERE n.audience = 'staff' AND n.audience_id = ? AND n.id > ?
    ORDER BY n.id ASC LIMIT 50");
$query->execute([$userId, $afterId]);
$items = $query->fetchAll();
$count = db()->prepare("SELECT COALESCE(MAX(id), 0) AS latest_id, COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_count FROM notifications WHERE audience = 'staff' AND audience_id = ?");
$count->execute([$userId]);
$state = $count->fetch() ?: ['latest_id' => 0, 'unread_count' => 0];

echo json_encode([
    'success' => true,
    'latest_id' => $items ? (int)end($items)['id'] : max($afterId, (int)$state['latest_id']),
    'unread_count' => (int)$state['unread_count'],
    'notifications' => array_map(static function (array $item): array {
        return [
            'id' => (int)$item['id'],
            'title' => (string)$item['title'],
            'message' => (string)$item['message'],
            'order_id' => $item['order_id'] !== null ? (int)$item['order_id'] : null,
            'order_number' => $item['order_number'] !== null ? (string)$item['order_number'] : null,
            'created_at' => (string)$item['created_at'],
            'is_read' => (bool)$item['is_read'],
        ];
    }, $items),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
