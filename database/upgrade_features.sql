-- Safe, additive migration for an existing Laundry pias installation.
-- Back up the database before importing this file.
-- Select the target database before importing. This keeps the migration usable
-- for both existing installations and new laundry_pias installations.

CREATE TABLE IF NOT EXISTS customer_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_account_customer (customer_id),
  UNIQUE KEY uq_customer_account_email (email),
  CONSTRAINT fk_customer_account_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'piece',
  current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_inventory_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  movement_type ENUM('in','out') NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_cost DECIMAL(10,2) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_inventory_movement_item (item_id, created_at),
  CONSTRAINT fk_inventory_movement_item FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_inventory_movement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_closings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  opening_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cash_counted DECIMAL(10,2) DEFAULT NULL,
  expected_cash DECIMAL(10,2) DEFAULT NULL,
  difference DECIMAL(10,2) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_cash_closing_user_day (user_id, business_date),
  CONSTRAINT fk_cash_closing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- Customer/staff workflow, transaction ledger and audit history.
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(30) NOT NULL,
  reference_number VARCHAR(120) DEFAULT NULL,
  status ENUM('paid','refunded') NOT NULL DEFAULT 'paid',
  received_by INT UNSIGNED DEFAULT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_payments_order (order_id, paid_at),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  audience ENUM('customer','staff') NOT NULL,
  audience_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED DEFAULT NULL,
  title VARCHAR(160) NOT NULL,
  message VARCHAR(500) NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_notifications_audience (audience, audience_id, is_read, created_at),
  KEY idx_notifications_order (order_id),
  CONSTRAINT fk_notifications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_review_order (order_id),
  CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED DEFAULT NULL,
  role VARCHAR(20) DEFAULT NULL,
  action VARCHAR(120) NOT NULL,
  module VARCHAR(60) NOT NULL,
  record_id BIGINT UNSIGNED DEFAULT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_audit_module_record (module, record_id, created_at),
  KEY idx_audit_user (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @order_status_type = (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'status'
);
SET @order_status_sql = IF(LEFT(@order_status_type, 5) = 'enum(',
  "ALTER TABLE orders MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'",
  'SELECT 1');
PREPARE order_status_stmt FROM @order_status_sql;
EXECUTE order_status_stmt;
DEALLOCATE PREPARE order_status_stmt;

SET @order_actual_weight = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'actual_weight');
SET @order_actual_weight_sql = IF(@order_actual_weight = 0, 'ALTER TABLE orders ADD COLUMN actual_weight DECIMAL(10,2) DEFAULT NULL AFTER total', 'SELECT 1');
PREPARE order_actual_weight_stmt FROM @order_actual_weight_sql;
EXECUTE order_actual_weight_stmt;
DEALLOCATE PREPARE order_actual_weight_stmt;

SET @order_item_estimated_weight = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'estimated_weight_kg');
SET @order_item_estimated_weight_sql = IF(@order_item_estimated_weight = 0, 'ALTER TABLE order_items ADD COLUMN estimated_weight_kg DECIMAL(10,2) DEFAULT NULL AFTER quantity', 'SELECT 1');
PREPARE order_item_estimated_weight_stmt FROM @order_item_estimated_weight_sql;
EXECUTE order_item_estimated_weight_stmt;
DEALLOCATE PREPARE order_item_estimated_weight_stmt;

SET @order_assigned_staff = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'assigned_staff_id');
SET @order_assigned_staff_sql = IF(@order_assigned_staff = 0, 'ALTER TABLE orders ADD COLUMN assigned_staff_id INT UNSIGNED DEFAULT NULL AFTER created_by', 'SELECT 1');
PREPARE order_assigned_staff_stmt FROM @order_assigned_staff_sql;
EXECUTE order_assigned_staff_stmt;
DEALLOCATE PREPARE order_assigned_staff_stmt;

SET @order_staff_notes = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'staff_notes');
SET @order_staff_notes_sql = IF(@order_staff_notes = 0, 'ALTER TABLE orders ADD COLUMN staff_notes VARCHAR(1000) DEFAULT NULL AFTER notes', 'SELECT 1');
PREPARE order_staff_notes_stmt FROM @order_staff_notes_sql;
EXECUTE order_staff_notes_stmt;
DEALLOCATE PREPARE order_staff_notes_stmt;

SET @order_completed_at = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'completed_at');
SET @order_completed_at_sql = IF(@order_completed_at = 0, 'ALTER TABLE orders ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER updated_at', 'SELECT 1');
PREPARE order_completed_at_stmt FROM @order_completed_at_sql;
EXECUTE order_completed_at_stmt;
DEALLOCATE PREPARE order_completed_at_stmt;
