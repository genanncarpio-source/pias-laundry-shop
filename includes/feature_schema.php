<?php
/** Creates customer account and session storage used by the mobile API and staff customer records. */
function ensure_customer_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, customer_id INT UNSIGNED NOT NULL, email VARCHAR(120) NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_customer_account_customer (customer_id), UNIQUE KEY uq_customer_account_email (email), CONSTRAINT fk_customer_account_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $customerColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) $customerColumns[$columnInfo['Field']] = true;
    foreach (['location_latitude' => "ALTER TABLE customers ADD COLUMN location_latitude DECIMAL(10,7) DEFAULT NULL AFTER address", 'location_longitude' => "ALTER TABLE customers ADD COLUMN location_longitude DECIMAL(10,7) DEFAULT NULL AFTER location_latitude", 'profile_photo' => "ALTER TABLE customers ADD COLUMN profile_photo MEDIUMTEXT DEFAULT NULL AFTER phone"] as $column => $sql) if (!isset($customerColumns[$column])) $pdo->exec($sql);
    $accountColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM customer_accounts')->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) $accountColumns[$columnInfo['Field']] = true;
    if (!isset($accountColumns['email_verified_at'])) {
        $pdo->exec('ALTER TABLE customer_accounts ADD COLUMN email_verified_at DATETIME DEFAULT NULL AFTER password_hash');
        $pdo->exec('UPDATE customer_accounts SET email_verified_at = created_at WHERE email_verified_at IS NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_registration_otps (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(120) NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        address VARCHAR(255) NOT NULL,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        last_sent_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_customer_registration_otp_email (email),
        KEY idx_customer_registration_otp_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_sessions (
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_password_reset_otps (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id INT UNSIGNED NOT NULL,
        email VARCHAR(120) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        last_sent_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_customer_password_reset_customer (customer_id),
        KEY idx_customer_password_reset_email (email),
        KEY idx_customer_password_reset_expiry (expires_at),
        CONSTRAINT fk_customer_password_reset_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $otpCodeColumn = $pdo->query('SHOW COLUMNS FROM customer_registration_otps LIKE \'code_hash\'')->fetch(PDO::FETCH_ASSOC);
    if ($otpCodeColumn && stripos((string)$otpCodeColumn['Type'], 'varchar') !== 0) {
        $pdo->exec('ALTER TABLE customer_registration_otps MODIFY code_hash VARCHAR(255) NOT NULL');
    }
    $ready = true;
}

/** Shared customer, payment, notification and audit storage for the full order flow. */
function ensure_workflow_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $pdo = db();
    // Add the verified shop pin to existing databases without overwriting a
    // shop location that an administrator has already configured.
    $pdo->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES
        ('shop_latitude', '15.4905338'),
        ('shop_longitude', '120.6641949')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_drivers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        full_name VARCHAR(100) NOT NULL,
        username VARCHAR(50) NOT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_delivery_driver_username (username),
        KEY idx_delivery_driver_active (is_active),
        CONSTRAINT fk_delivery_driver_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_driver_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        driver_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_delivery_driver_session_token (token_hash),
        KEY idx_delivery_driver_session_driver (driver_id),
        KEY idx_delivery_driver_session_expiry (expires_at),
        CONSTRAINT fk_delivery_driver_session_driver FOREIGN KEY (driver_id) REFERENCES delivery_drivers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $statements = [
        "CREATE TABLE IF NOT EXISTS payments (id INT UNSIGNED NOT NULL AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL, amount DECIMAL(10,2) NOT NULL, payment_method VARCHAR(30) NOT NULL, reference_number VARCHAR(120) DEFAULT NULL, status ENUM('paid','refunded') NOT NULL DEFAULT 'paid', received_by INT UNSIGNED DEFAULT NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_payments_order (order_id, paid_at), CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id), CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notifications (id INT UNSIGNED NOT NULL AUTO_INCREMENT, audience ENUM('customer','staff') NOT NULL, audience_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED DEFAULT NULL, title VARCHAR(160) NOT NULL, message VARCHAR(500) NOT NULL, type VARCHAR(30) NOT NULL DEFAULT 'info', is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_notifications_audience (audience, audience_id, is_read, created_at), KEY idx_notifications_order (order_id), CONSTRAINT fk_notifications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS gcash_payment_submissions (id INT UNSIGNED NOT NULL AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL, amount DECIMAL(10,2) NOT NULL, reference_number VARCHAR(120) NOT NULL, status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending', submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME DEFAULT NULL, reviewed_by INT UNSIGNED DEFAULT NULL, review_note VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY uq_gcash_submission_reference (reference_number), KEY idx_gcash_submission_order (order_id, id), CONSTRAINT fk_gcash_submission_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, CONSTRAINT fk_gcash_submission_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED DEFAULT NULL, role VARCHAR(20) DEFAULT NULL, action VARCHAR(120) NOT NULL, module VARCHAR(60) NOT NULL, record_id BIGINT UNSIGNED DEFAULT NULL, old_value TEXT DEFAULT NULL, new_value TEXT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_audit_module_record (module, record_id, created_at), KEY idx_audit_user (user_id, created_at), CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) $pdo->exec($sql);

    $columns = [
        'actual_weight' => "ALTER TABLE orders ADD COLUMN actual_weight DECIMAL(10,2) DEFAULT NULL AFTER total",
        'pickup_address' => "ALTER TABLE orders ADD COLUMN pickup_address VARCHAR(255) DEFAULT NULL AFTER customer_name",
        'delivery_address' => "ALTER TABLE orders ADD COLUMN delivery_address VARCHAR(255) DEFAULT NULL AFTER pickup_address",
        'assigned_staff_id' => "ALTER TABLE orders ADD COLUMN assigned_staff_id INT UNSIGNED DEFAULT NULL AFTER created_by",
        'assigned_driver_id' => "ALTER TABLE orders ADD COLUMN assigned_driver_id INT UNSIGNED DEFAULT NULL AFTER assigned_staff_id",
        'handoff_type' => "ALTER TABLE orders ADD COLUMN handoff_type VARCHAR(30) NOT NULL DEFAULT 'dropoff_delivery' AFTER order_type",
        'staff_notes' => "ALTER TABLE orders ADD COLUMN staff_notes VARCHAR(1000) DEFAULT NULL AFTER notes",
        'completed_at' => "ALTER TABLE orders ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER updated_at",
        'delivery_fee' => "ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal",
        'delivery_latitude' => "ALTER TABLE orders ADD COLUMN delivery_latitude DECIMAL(10,7) DEFAULT NULL AFTER delivery_address",
        'delivery_longitude' => "ALTER TABLE orders ADD COLUMN delivery_longitude DECIMAL(10,7) DEFAULT NULL AFTER delivery_latitude",
    ];
    $existingColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) $existingColumns[$columnInfo['Field']] = true;
    foreach ($columns as $column => $sql) {
        if (!isset($existingColumns[$column])) $pdo->exec($sql);
    }
    $statusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusColumn && stripos($statusColumn['Type'], 'enum(') === 0) {
        $pdo->exec("ALTER TABLE orders MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'");
    }
    $orderTypeColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->fetch(PDO::FETCH_ASSOC);
    if ($orderTypeColumn && stripos($orderTypeColumn['Type'], 'enum(') === 0) {
        $pdo->exec("ALTER TABLE orders MODIFY order_type VARCHAR(30) NOT NULL DEFAULT 'walk_in'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_tracking (
        order_id INT UNSIGNED NOT NULL,
        driver_code_hash CHAR(64) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        latitude DECIMAL(10,7) DEFAULT NULL,
        longitude DECIMAL(10,7) DEFAULT NULL,
        accuracy DECIMAL(8,2) DEFAULT NULL,
        heading DECIMAL(7,2) DEFAULT NULL,
        speed DECIMAL(8,2) DEFAULT NULL,
        customer_location_shared TINYINT(1) NOT NULL DEFAULT 0,
        customer_latitude DECIMAL(10,7) DEFAULT NULL,
        customer_longitude DECIMAL(10,7) DEFAULT NULL,
        customer_accuracy DECIMAL(8,2) DEFAULT NULL,
        customer_location_updated_at DATETIME DEFAULT NULL,
        customer_shared_at DATETIME DEFAULT NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        stopped_at DATETIME DEFAULT NULL,
        PRIMARY KEY (order_id),
        KEY idx_delivery_tracking_active (is_active, updated_at),
        CONSTRAINT fk_delivery_tracking_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $driverIndex = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'idx_orders_driver'")->fetch(PDO::FETCH_ASSOC);
    if (!$driverIndex) $pdo->exec("ALTER TABLE orders ADD KEY idx_orders_driver (assigned_driver_id, status)");
    $trackingColumns = [
        'customer_location_shared' => "ALTER TABLE delivery_tracking ADD COLUMN customer_location_shared TINYINT(1) NOT NULL DEFAULT 0 AFTER speed",
        'customer_latitude' => "ALTER TABLE delivery_tracking ADD COLUMN customer_latitude DECIMAL(10,7) DEFAULT NULL AFTER customer_location_shared",
        'customer_longitude' => "ALTER TABLE delivery_tracking ADD COLUMN customer_longitude DECIMAL(10,7) DEFAULT NULL AFTER customer_latitude",
        'customer_accuracy' => "ALTER TABLE delivery_tracking ADD COLUMN customer_accuracy DECIMAL(8,2) DEFAULT NULL AFTER customer_longitude",
        'customer_location_updated_at' => "ALTER TABLE delivery_tracking ADD COLUMN customer_location_updated_at DATETIME DEFAULT NULL AFTER customer_accuracy",
        'customer_shared_at' => "ALTER TABLE delivery_tracking ADD COLUMN customer_shared_at DATETIME DEFAULT NULL AFTER customer_location_updated_at",
    ];
    $existingTrackingColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM delivery_tracking')->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) {
        $existingTrackingColumns[$columnInfo['Field']] = $columnInfo;
    }
    foreach ($trackingColumns as $column => $sql) {
        if (!isset($existingTrackingColumns[$column])) $pdo->exec($sql);
    }
    if (isset($existingTrackingColumns['driver_code_hash']) && $existingTrackingColumns['driver_code_hash']['Null'] === 'NO') {
        $pdo->exec("ALTER TABLE delivery_tracking MODIFY driver_code_hash CHAR(64) DEFAULT NULL");
    }
    ensure_review_schema();
    $ready = true;
}

/** Creates the customer review table before either review page reads or writes it. */
function ensure_review_schema(): void
{
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        comment VARCHAR(1000) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_review_order (order_id),
        CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id),
        CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

/** Idempotently adds tables used by the optional operations features. */
function ensure_feature_schema(): void
{
    static $ready = false;
    if ($ready) return;
    $statements = [
        "CREATE TABLE IF NOT EXISTS inventory_items (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL, unit VARCHAR(20) NOT NULL DEFAULT 'piece', current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00, reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_inventory_active (is_active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS inventory_movements (id INT UNSIGNED NOT NULL AUTO_INCREMENT, item_id INT UNSIGNED NOT NULL, movement_type ENUM('in','out') NOT NULL, quantity DECIMAL(10,2) NOT NULL, unit_cost DECIMAL(10,2) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_inventory_movement_item (item_id, created_at), CONSTRAINT fk_inventory_movement_item FOREIGN KEY (item_id) REFERENCES inventory_items(id), CONSTRAINT fk_inventory_movement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS cash_closings (id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL, business_date DATE NOT NULL, opening_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00, cash_counted DECIMAL(10,2) DEFAULT NULL, expected_cash DECIMAL(10,2) DEFAULT NULL, difference DECIMAL(10,2) DEFAULT NULL, notes VARCHAR(255) DEFAULT NULL, status ENUM('open','closed') NOT NULL DEFAULT 'open', opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY uq_cash_closing_user_day (user_id, business_date), CONSTRAINT fk_cash_closing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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
