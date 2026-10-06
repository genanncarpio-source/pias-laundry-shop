-- Safe, additive migration for an existing Laundry Shop Management installation.
-- Back up the database before importing this file.
USE laundry_pos;

SET @has_expense_payment_method = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'payment_method'
);
SET @expense_method_sql = IF(@has_expense_payment_method = 0,
  "ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER amount",
  'SELECT 1');
PREPARE expense_method_stmt FROM @expense_method_sql;
EXECUTE expense_method_stmt;
DEALLOCATE PREPARE expense_method_stmt;

SET @has_customer_password = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'password_hash'
);
SET @customer_password_sql = IF(@has_customer_password = 0,
  "ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER address",
  'SELECT 1');
PREPARE customer_password_stmt FROM @customer_password_sql;
EXECUTE customer_password_stmt;
DEALLOCATE PREPARE customer_password_stmt;

SET @has_order_paid_at = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'paid_at'
);
SET @order_paid_at_sql = IF(@has_order_paid_at = 0,
  'ALTER TABLE orders ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER payment_method',
  'SELECT 1');
PREPARE order_paid_at_stmt FROM @order_paid_at_sql;
EXECUTE order_paid_at_stmt;
DEALLOCATE PREPARE order_paid_at_stmt;

CREATE TABLE IF NOT EXISTS customer_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_adjustments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  adjustment_type ENUM('cancellation','refund') NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(30) DEFAULT NULL,
  reason VARCHAR(255) NOT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_adjustment_order (order_id, created_at),
  KEY idx_adjustment_type_date (adjustment_type, created_at),
  CONSTRAINT fk_adjustment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_adjustment_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
