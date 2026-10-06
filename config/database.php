<?php
/**
 * Database connection (PDO).
 *
 * The defaults match a fresh XAMPP **and** a fresh Laragon install — both ship
 * MySQL/MariaDB with the same out-of-the-box credentials:
 *
 *   host = localhost, user = root, password = (empty), database = laundry_pos
 *
 * So normally nothing in this file needs editing. If your setup differs, either
 * change the fallback values below or set environment variables (DB_HOST,
 * DB_NAME, DB_USER, DB_PASS), which take precedence.
 *
 * If you moved MySQL off the default port (a common XAMPP fix for port 3306
 * conflicts), set DB_HOST to "127.0.0.1:3307" style — PDO accepts host:port.
 */

// Defaults shared by XAMPP and Laragon; override via environment variables.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'laundry_pos');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            // Friendly message for the developer; details in the log.
            exit('<h2>Database connection failed</h2>'
                . '<p>Could not connect to MySQL. Please check that:</p>'
                . '<ul>'
                . '<li>The MySQL service is <strong>running</strong> - start it from the XAMPP Control Panel, or click Start All in Laragon.</li>'
                . '<li>The database <code>' . htmlspecialchars(DB_NAME) . '</code> exists (import <code>database/laundry_pos.sql</code>).</li>'
                . '<li>Credentials in <code>config/database.php</code> match your setup.</li>'
                . '</ul>'
                . '<p style="color:#888"><small>' . htmlspecialchars($e->getMessage()) . '</small></p>');
        }
    }

    return $pdo;
}
