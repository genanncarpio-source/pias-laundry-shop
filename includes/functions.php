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
        'washing'   => 'In Progress',
        'ready'     => 'Ready for Pickup',
        'picked_up' => 'Picked Up',
        'cancelled' => 'Cancelled',
    ];
}

/** Append an entry to the order status timeline. */
function add_order_history(int $orderId, string $status, string $note = '', ?int $userId = null): void
{
    $stmt = db()->prepare(
        "INSERT INTO order_history (order_id, status, note, changed_by) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$orderId, $status, $note !== '' ? $note : null, $userId]);
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
