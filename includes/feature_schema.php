<?php
/** Idempotently adds the order adjustment table used by cancellation and refunds. */
function ensure_feature_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $statements = [
        "CREATE TABLE IF NOT EXISTS order_adjustments (id INT UNSIGNED NOT NULL AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL, adjustment_type ENUM('cancellation','refund') NOT NULL, amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, payment_method VARCHAR(30) DEFAULT NULL, reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_adjustment_order (order_id, created_at), KEY idx_adjustment_type_date (adjustment_type, created_at), CONSTRAINT fk_adjustment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, CONSTRAINT fk_adjustment_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) db()->exec($sql);
    $expenseMethod = db()->query("SHOW COLUMNS FROM expenses LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
    if (!$expenseMethod) {
        try {
            db()->exec("ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER amount");
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') throw $e;
        }
    }
    $ready = true;
}

/** Adds password storage and revocable sessions for mobile customer accounts. */
function ensure_customer_auth_schema(): void
{
    static $ready = false;
    if ($ready) return;

    $passwordColumn = db()->query("SHOW COLUMNS FROM customers LIKE 'password_hash'")->fetch(PDO::FETCH_ASSOC);
    if (!$passwordColumn) {
        try {
            db()->exec('ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER address');
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') throw $e;
        }
    }

    $paidAtColumn = db()->query("SHOW COLUMNS FROM orders LIKE 'paid_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$paidAtColumn) {
        try {
            db()->exec('ALTER TABLE orders ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER payment_method');
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S21') throw $e;
        }
    }

    db()->exec("CREATE TABLE IF NOT EXISTS customer_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_customer_session_token (token_hash),
        KEY idx_customer_session_customer (customer_id),
        KEY idx_customer_session_expiry (expires_at),
        CONSTRAINT fk_customer_session_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}
