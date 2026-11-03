<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/feature_schema.php';
ensure_workflow_schema();
ensure_review_schema();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) flash_set('error', 'This form has expired. Refresh the page and try again.');
    else {
        $id = (int)($_POST['review_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT order_id FROM reviews WHERE id = ?');
        $stmt->execute([$id]);
        $orderId = (int)$stmt->fetchColumn();
        if ($orderId) {
            $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
            add_audit_log('Review removed by administrator', 'reviews', $id, ['order_id' => $orderId]);
            flash_set('success', 'Review removed.');
        }
    }
    redirect('reviews.php');
}
$reviews = $pdo->query('SELECT r.*, c.name AS customer_name, o.order_number FROM reviews r JOIN customers c ON c.id = r.customer_id JOIN orders o ON o.id = r.order_id ORDER BY r.id DESC LIMIT 300')->fetchAll();
$ratingSummary = $pdo->query('SELECT rating, COUNT(*) AS rating_count FROM reviews GROUP BY rating')->fetchAll(PDO::FETCH_KEY_PAIR);
$ratingSummary = array_map('intval', $ratingSummary);
$ratingCount = array_sum($ratingSummary);
$averageRating = $ratingCount ? (float)$pdo->query('SELECT AVG(rating) FROM reviews')->fetchColumn() : 0.0;
$highestRatingCount = max(1, ...array_values($ratingSummary ?: [0]));
$pageTitle = 'Customer Ratings';
$pageSubtitle = 'Ratings and optional feedback from customer bookings';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="display:grid;grid-template-columns:minmax(130px,0.7fr) minmax(240px,2fr);gap:24px;align-items:center;margin-bottom:20px">
  <div><div style="font-size:42px;font-weight:800;line-height:1"><?= number_format($averageRating, 1) ?></div><div style="color:#d5a62f;font-size:21px;letter-spacing:2px;margin-top:7px"><?= str_repeat('★', (int)round($averageRating)) ?><?= str_repeat('☆', 5 - (int)round($averageRating)) ?></div><div class="muted"><?= (int)$ratingCount ?> <?= $ratingCount === 1 ? 'rating' : 'ratings' ?></div></div>
  <div><?php for ($rating = 5; $rating >= 1; $rating--): $count = (int)($ratingSummary[$rating] ?? 0); $width = $ratingCount ? round($count / $highestRatingCount * 100) : 0; ?><div style="display:grid;grid-template-columns:18px minmax(80px,1fr) 32px;align-items:center;gap:9px;margin:5px 0"><span><?= $rating ?></span><div style="height:8px;background:#eee8ef;border-radius:8px;overflow:hidden"><div style="height:100%;width:<?= $width ?>%;background:#e4b832"></div></div><span class="muted"><?= $count ?></span></div><?php endfor; ?></div>
</div><div class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Customer</th><th>Order</th><th>Rating</th><th>Comment</th><th></th></tr></thead><tbody><?php foreach ($reviews as $review): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($review['created_at']))) ?></td><td><?= e($review['customer_name']) ?></td><td><a href="order_view.php?id=<?= (int)$review['order_id'] ?>"><?= e($review['order_number']) ?></a></td><td><?= str_repeat('★', (int)$review['rating']) ?> (<?= (int)$review['rating'] ?>/5)</td><td><?= nl2br(e($review['comment'] ?? '')) ?></td><td><form method="post" onsubmit="return confirm('Remove this review?');"><?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int)$review['id'] ?>"><button class="btn btn-danger btn-sm" name="delete_review" value="1">Remove</button></form></td></tr><?php endforeach; ?><?php if (!$reviews): ?><tr><td colspan="6" class="muted">No customer reviews yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
