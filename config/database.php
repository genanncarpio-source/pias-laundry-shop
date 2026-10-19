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

// Defaults shared by XAMPP and Laragon. Railway's standard MySQL variables are
// also supported so the same source can run locally and in production.
define('DB_HOST', getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: 'localhost'));
define('DB_PORT', getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306'));
define('DB_NAME', getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'laundry_pos'));
define('DB_USER', getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root'));
$databasePassword = getenv('DB_PASS');
if ($databasePassword === false) {
    $databasePassword = getenv('MYSQLPASSWORD');
}
define('DB_PASS', $databasePassword !== false ? $databasePassword : '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
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
