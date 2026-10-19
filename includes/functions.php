<?php
/**
 * Shared helper functions.
 */

/** HTML-escape a value for safe output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format an amount as Philippine peso currency. */
function money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/**
 * Redirect and stop execution.
 *
 * Relative targets are resolved against the APPLICATION ROOT rather than the
 * current directory, so a page living in a sub-folder (e.g. a sub-folder page)
 * still sends the user to /dashboard.php instead of /subfolder/dashboard.php.
 * Absolute URLs and protocol-relative URLs are passed through untouched.
 */
function redirect(string $url): void
{
    $isAbsolute = preg_match('~^(https?:)?//~i', $url) === 1
        || stripos($url, 'mailto:') === 0
        || $url === ''
        || $url[0] === '/';

    header('Location: ' . ($isAbsolute ? $url : asset_prefix() . $url));
    exit;
}

/* ------------------------------ Flash messages ------------------------------ */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Return and clear queued flash messages. */
function flash_get(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

/* ------------------------------ CSRF protection ------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

/* ------------------------------ JSON input ------------------------------ */

/** Read a JSON request body (used by fetch()/AJAX endpoints). */
function json_input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function json_out($payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/* ------------------------------ Settings ------------------------------ */

/** Read a setting value from the settings table (cached per request). */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("SELECT `key`, `value` FROM settings") as $row) {
            $cache[$row['key']] = (string) $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Upsert multiple settings at once.
 *
 * Uses a single atomic INSERT ... ON DUPLICATE KEY UPDATE per row. The older
 * "UPDATE then INSERT if rowCount()==0" approach was buggy: MySQL reports 0
 * *changed* rows when the new value equals the old one, so re-saving an
 * unchanged setting tried to INSERT and failed with a duplicate-key error.
 */
function settings_update(array $pairs): void
{
    $pdo  = db();
    $stmt = $pdo->prepare(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    foreach ($pairs as $key => $value) {
        $stmt->execute([(string)$key, (string)$value]);
    }
}

/* ------------------------------ Misc ------------------------------ */

/** Current date/time in Y-m-d H:i:s (Asia/Manila). */
function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Number of "../" needed to reach the application root from the directory
 * of the currently executing script. Lets pages in sub-folders (if any)
 * reuse the shared header/footer without breaking asset URLs.
 */
function asset_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }

    $appRoot   = realpath(__DIR__ . '/..');
    $scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));

    $prefix = '';
    if ($appRoot && $scriptDir && strpos($scriptDir, $appRoot) === 0) {
        $rel = trim(str_replace('\\', '/', substr($scriptDir, strlen($appRoot))), '/');
        if ($rel !== '') {
            $prefix = str_repeat('../', substr_count($rel, '/') + 1);
        }
    }
    return $prefix;
}

/** Build a URL for an asset that lives under the application root. */
function asset(string $path): string
{
    return asset_prefix() . ltrim($path, '/');
}

/* ----------------------------------------------------------------------
 *  mbstring fallbacks
 *
 *  Laragon ships mbstring, but it is not guaranteed on every PHP build.
 *  These polyfills keep the system running either way.
 * ---------------------------------------------------------------------- */

if (!function_exists('mb_substr')) {
    function mb_substr(string $str, int $start, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $str, string $encoding = 'UTF-8'): int
    {
        return function_exists('utf8_decode') ? strlen(utf8_decode($str)) : strlen($str);
    }
}

/* ------------------------------------------------------------
   Order status helpers
   ------------------------------------------------------------ */
function order_status_labels(): array
{
    return [
        'pending'   => 'Pending',
        'confirmed' => 'Accepted',
        'picked_up_for_service' => 'Picked Up by Driver',
        'received' => 'Received at Shop',
        'weighing' => 'Weighing',
        'washing'   => 'Washing',
        'drying' => 'Drying',
        'folding' => 'Folding',
        'ready_for_pickup' => 'Ready for Pickup',
        'out_for_delivery' => 'Out for Delivery',
        'completed' => 'Completed',
        'ready'     => 'Ready for Pickup',
        'picked_up' => 'Completed',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
    ];
}

function order_process_statuses(array $items): array
{
    $needsWash = false;
    $needsDry = false;
    foreach ($items as $item) {
        $category = strtolower(trim((string)($item['category'] ?? '')));
        $serviceName = strtolower(trim((string)($item['service_name'] ?? '')));
        if ($category === 'wash') $needsWash = true;
        if ($category === 'dry') $needsDry = true;
        if (in_array($category, ['full service', 'wash & fold'], true)
            || strpos($serviceName, 'full service') !== false
            || strpos($serviceName, 'wash, dry') !== false
            || strpos($serviceName, 'wash & fold') !== false
            || strpos($serviceName, 'wash and fold') !== false) {
            return ['washing', 'drying', 'folding'];
        }
    }
    if ($needsWash || $needsDry) {
        $stages = [];
        if ($needsWash) $stages[] = 'washing';
        if ($needsDry) $stages[] = 'drying';
        return $stages;
    }
    return ['washing', 'drying'];
}

function order_next_statuses(string $status, array $items = []): array
{
    $processStages = order_process_statuses($items);
    $firstProcessStage = $processStages[0] ?? 'washing';
    $stageTransitions = [];
    foreach ($processStages as $index => $stage) {
        $nextStage = $processStages[$index + 1] ?? 'ready_for_pickup';
        $stageTransitions[$stage] = [$nextStage === 'folding' ? 'ready_for_pickup' : $nextStage];
    }
    $flow = [
        'pending' => ['confirmed', 'rejected'],
        'confirmed' => ['received', 'picked_up_for_service'],
        'picked_up_for_service' => ['received'],
        'received' => [$firstProcessStage],
        'weighing' => [$firstProcessStage],
        'folding' => ['ready_for_pickup'],
        'ready_for_pickup' => ['out_for_delivery', 'completed'],
        'out_for_delivery' => ['completed'],
        'ready' => ['completed'], // legacy states already in use
    ];
    $flow = array_merge($flow, $stageTransitions);
    return $flow[$status] ?? [];
}

/** Identify orders containing the Full Service (wash, dry, fold) process. */
function order_has_full_service(array $items): bool
{
    foreach ($items as $item) {
        $category = strtolower(trim((string)($item['category'] ?? '')));
        $serviceName = strtolower(trim((string)($item['service_name'] ?? '')));
        if (in_array($category, ['full service', 'wash & fold'], true)
            || strpos($serviceName, 'full service') !== false
            || strpos($serviceName, 'wash, dry') !== false
            || strpos($serviceName, 'wash & fold') !== false
            || strpos($serviceName, 'wash and fold') !== false) {
            return true;
        }
    }
    return false;
}

/** Append an entry to the order status timeline. */
function add_order_history(int $orderId, string $status, string $note = '', ?int $userId = null): void
{
    $stmt = db()->prepare(
        "INSERT INTO order_history (order_id, status, note, changed_by) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$orderId, $status, $note !== '' ? $note : null, $userId]);
}

/** Add an in-app notification for a customer or staff account. */
function add_notification(string $audience, int $audienceId, ?int $orderId, string $title, string $message, string $type = 'info'): void
{
    if (!in_array($audience, ['customer', 'staff'], true) || $audienceId < 1) return;
    $stmt = db()->prepare('INSERT INTO notifications (audience, audience_id, order_id, title, message, type) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$audience, $audienceId, $orderId, $title, $message, $type]);
}

/** Record an auditable change without storing secrets. */
function add_audit_log(string $action, string $module, ?int $recordId = null, $oldValue = null, $newValue = null): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, role, action, module, record_id, old_value, new_value, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $user['id'] ?? null, $user['role'] ?? (!empty($_SESSION['customer_id']) ? 'customer' : null),
        $action, $module, $recordId,
        $oldValue === null ? null : (is_scalar($oldValue) ? (string)$oldValue : json_encode($oldValue)),
        $newValue === null ? null : (is_scalar($newValue) ? (string)$newValue : json_encode($newValue)),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

/** Full status timeline for one order (oldest first). */
function order_history(int $orderId): array
{
    $stmt = db()->prepare(
        "SELECT h.*, u.full_name AS changed_by_name
           FROM order_history h
      LEFT JOIN users u ON u.id = h.changed_by
          WHERE h.order_id = ?
       ORDER BY h.created_at ASC, h.id ASC"
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}
