-- ============================================================
--  P.O.S. AND LAUNDRY SHOP MANAGEMENT SYSTEM
--  FOR PIA'S LAUNDRY SHOP
--
--  Database schema + seed data
--
--  HOW TO IMPORT (Laragon):
--   1. Start Laragon, click "Database" (or open http://localhost/phpmyadmin)
--   2. Import this file directly (phpMyAdmin > Import > choose this file > Go)
--      The database `laundry_pos` is created automatically.
--   3. Default logins created below:
--          admin  /  admin123     (Administrator)
--          staff  /  staff123     (Cashier)
--
--  NOTE: Importing this file DROPS and RECREATES all tables.
--        Back up your data first if the system is already in use.
-- ============================================================

CREATE DATABASE IF NOT EXISTS laundry_pos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE laundry_pos;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_history;
DROP TABLE IF EXISTS order_adjustments;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS cash_closings;
DROP TABLE IF EXISTS inventory_items;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Users (admins & staff)
-- ------------------------------------------------------------
CREATE TABLE users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name   VARCHAR(100) NOT NULL,
  username    VARCHAR(50)  NOT NULL,
  password    VARCHAR(255) NOT NULL,
  email       VARCHAR(120) DEFAULT NULL,
  phone       VARCHAR(30)  DEFAULT NULL,
  role        ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Customers
-- ------------------------------------------------------------
CREATE TABLE customers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  phone       VARCHAR(30)  DEFAULT NULL,
  email       VARCHAR(120) DEFAULT NULL,
  address     VARCHAR(255) DEFAULT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_name (name),
  UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Services (price list)
-- ------------------------------------------------------------
CREATE TABLE services (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  category    VARCHAR(60)  NOT NULL DEFAULT 'Wash & Fold',
  unit        ENUM('kg','piece') NOT NULL DEFAULT 'kg',
  price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  description VARCHAR(255) DEFAULT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Orders
-- ------------------------------------------------------------
CREATE TABLE orders (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number    VARCHAR(40)  NOT NULL,
  customer_id     INT UNSIGNED DEFAULT NULL,
  customer_name   VARCHAR(100) DEFAULT NULL,
  pickup_address  VARCHAR(255) DEFAULT NULL,
  delivery_address VARCHAR(255) DEFAULT NULL,
  order_type      VARCHAR(30) NOT NULL DEFAULT 'walk_in',
  status          ENUM('pending','washing','ready','picked_up','cancelled') NOT NULL DEFAULT 'pending',
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_paid     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  change_due      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method  VARCHAR(30)  NOT NULL DEFAULT 'cash',
  expected_pickup DATETIME     DEFAULT NULL,
  notes           VARCHAR(255) DEFAULT NULL,
  created_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_status (status),
  KEY idx_orders_created (created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_user     FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Live delivery tracking (latest driver location while en route)
-- ------------------------------------------------------------
CREATE TABLE delivery_tracking (
  order_id         INT UNSIGNED NOT NULL,
  driver_code_hash CHAR(64) NOT NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  latitude         DECIMAL(10,7) DEFAULT NULL,
  longitude        DECIMAL(10,7) DEFAULT NULL,
  accuracy         DECIMAL(8,2) DEFAULT NULL,
  heading          DECIMAL(7,2) DEFAULT NULL,
  speed            DECIMAL(8,2) DEFAULT NULL,
  started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME DEFAULT NULL,
  stopped_at       DATETIME DEFAULT NULL,
  PRIMARY KEY (order_id),
  KEY idx_delivery_tracking_active (is_active, updated_at),
  CONSTRAINT fk_delivery_tracking_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Order items (line items)
-- ------------------------------------------------------------
CREATE TABLE order_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     INT UNSIGNED NOT NULL,
  service_id   INT UNSIGNED DEFAULT NULL,
  service_name VARCHAR(120) NOT NULL,
  unit         VARCHAR(10)  NOT NULL DEFAULT 'kg',
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  subtotal     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_orderitems_order (order_id),
  CONSTRAINT fk_orderitems_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Expenses
-- ------------------------------------------------------------
CREATE TABLE expenses (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  description  VARCHAR(255) NOT NULL,
  category     VARCHAR(60)  NOT NULL DEFAULT 'Supplies',
  amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
  expense_date DATE         NOT NULL,
  created_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expenses_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Settings (key/value store)
-- ------------------------------------------------------------
CREATE TABLE settings (
  `key`   VARCHAR(60) NOT NULL,
  `value` TEXT,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Order history (status timeline shown on the order page)
-- ------------------------------------------------------------
CREATE TABLE order_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    INT UNSIGNED NOT NULL,
  status      VARCHAR(30)  NOT NULL,
  note        VARCHAR(255) DEFAULT NULL,
  changed_by  INT UNSIGNED DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_history_order (order_id),
  CONSTRAINT fk_history_order FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_history_user  FOREIGN KEY (changed_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Inventory, cash closing, and order adjustment records
-- ------------------------------------------------------------
CREATE TABLE inventory_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(120) NOT NULL,
  unit           VARCHAR(20) NOT NULL DEFAULT 'piece',
  current_stock  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  reorder_level  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inventory_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_movements (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id       INT UNSIGNED NOT NULL,
  movement_type ENUM('in','out') NOT NULL,
  quantity      DECIMAL(10,2) NOT NULL,
  unit_cost     DECIMAL(10,2) DEFAULT NULL,
  note          VARCHAR(255) DEFAULT NULL,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inventory_movement_item (item_id, created_at),
  CONSTRAINT fk_inventory_movement_item FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_inventory_movement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_closings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  business_date  DATE NOT NULL,
  opening_cash   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cash_counted   DECIMAL(10,2) DEFAULT NULL,
  expected_cash  DECIMAL(10,2) DEFAULT NULL,
  difference     DECIMAL(10,2) DEFAULT NULL,
  notes          VARCHAR(255) DEFAULT NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at      DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cash_closing_user_day (user_id, business_date),
  CONSTRAINT fk_cash_closing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_adjustments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       INT UNSIGNED NOT NULL,
  adjustment_type ENUM('cancellation','refund') NOT NULL,
  amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(30) DEFAULT NULL,
  reason         VARCHAR(255) NOT NULL,
  created_by     INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_adjustment_order (order_id, created_at),
  KEY idx_adjustment_type_date (adjustment_type, created_at),
  CONSTRAINT fk_adjustment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_adjustment_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  SEED DATA
-- ============================================================

INSERT INTO settings (`key`, `value`) VALUES
  ('shop_name',    'Pia''s Laundry Shop'),
  ('shop_address', '123 Main Street, Quezon City, Metro Manila'),
  ('shop_phone',   '0912 345 6789'),
  ('receipt_note', 'Thank you for choosing Pia''s Laundry Shop! Please present this receipt when claiming your laundry.');

-- Passwords (bcrypt):
--   admin123 -> admin account
--   staff123 -> staff account
INSERT INTO users (id, full_name, username, password, email, phone, role, is_active) VALUES
  (1, 'Administrator', 'admin', '$2y$12$MPVmaS4xcm.sd0QWdnn45OvRCh7o1KYJCjikNbIBjyMh0Rx2.lg.q', 'admin@piaslaundry.com', '0917 000 0001', 'admin', 1),
  (2, 'Maria Santos',  'staff', '$2y$12$.87TirGnp.1pZuqglcX7oOehlOxYWt9f0xdilQTGDgVSCMXo/6m6i', 'maria@piaslaundry.com', '0917 000 0002', 'staff', 1);

INSERT INTO customers (id, name, phone, email, address, is_active) VALUES
  (1, 'Juan Dela Cruz', '0917 111 2233', 'juan@email.com', 'Quezon City', 1),
  (2, 'Ana Reyes',      '0918 222 3344', 'ana@email.com',  'Caloocan City', 1),
  (3, 'Pedro Lim',      '0919 333 4455', 'pedro@email.com','Pasig City', 1);

INSERT INTO services (id, name, category, unit, price, description, is_active) VALUES
  (1, 'Wash & Fold',        'Full Service',     'kg',    35.00, 'Complete wash, dry and fold service',   1),
  (2, 'Wash, Dry & Fold',   'Full Service',     'kg',    45.00, 'Complete wash, dry and fold with softener', 1),
  (3, 'Bed Sheet',          'Full Service',     'piece', 40.00, 'Wash, dry and fold per piece',           1),
  (4, 'Towel',              'Full Service',     'piece', 25.00, 'Wash, dry and fold per piece',           1),
  (5, 'Ironing / Press',    'Dry',              'kg',    30.00, 'Dry-only finishing and pressing',         1),
  (6, 'Dry Cleaning',       'Dry',              'piece', 100.00, 'Dry clean per garment',                  1),
  (7, 'Comforter / Blanket','Full Service',     'piece', 150.00, 'Wash, dry and fold thick items',         1),
  (8, 'Curtain',            'Full Service',     'piece', 80.00, 'Wash, dry and fold per panel',            1);

-- ------------------------------------------------------------
-- Sample orders (so the dashboard and reports have
-- demo data on first install). Delete this block if you want a
-- clean, empty database.
-- ------------------------------------------------------------
INSERT INTO orders
  (id, order_number, customer_id, customer_name, order_type, status,
   subtotal, discount, total, amount_paid, change_due, payment_method,
   expected_pickup, notes, created_by, created_at) VALUES
  (1, CONCAT('ORD-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 2 DAY), '%Y%m%d'), '-001'), 1, 'Juan Dela Cruz', 'drop_off', 'picked_up',
       210.00, 0.00, 210.00, 250.00, 40.00, 'cash',
       DATE_ADD(CURDATE(), INTERVAL -1 DAY), NULL, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (2, CONCAT('ORD-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '%Y%m%d'), '-001'), 2, 'Ana Reyes', 'drop_off', 'ready',
       180.00, 0.00, 180.00, 200.00, 20.00, 'gcash',
       DATE_ADD(CURDATE(), INTERVAL 1 DAY), NULL, 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (3, CONCAT('ORD-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-001'), 3, 'Pedro Lim', 'walk_in', 'washing',
        90.00, 0.00,  90.00,  90.00,  0.00, 'cash',
       DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Handle with care - delicates', 2, NOW()),
  (4, CONCAT('ORD-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-002'), 1, 'Juan Dela Cruz', 'drop_off', 'pending',
       350.00, 0.00, 350.00, 500.00, 150.00, 'maya',
       DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL, 1, NOW());

INSERT INTO order_items (order_id, service_id, service_name, unit, quantity, unit_price, subtotal) VALUES
  (1, 1, 'Wash & Fold',      'kg',    6.00,  35.00, 210.00),
  (2, 2, 'Wash, Dry & Fold', 'kg',    4.00,  45.00, 180.00),
  (3, 5, 'Ironing / Press',  'kg',    3.00,  30.00,  90.00),
  (4, 6, 'Dry Cleaning',     'piece', 2.00, 100.00, 200.00),
  (4, 7, 'Comforter / Blanket','piece',1.00, 150.00, 150.00);

INSERT INTO order_history (order_id, status, note, changed_by, created_at) VALUES
  (1, 'pending',   'Order created',        1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1, 'washing',   'Started washing',      2, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1, 'ready',     'Ready for pickup',     2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (1, 'picked_up', 'Claimed by customer',  1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (2, 'pending',   'Order created',        2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (2, 'washing',   'Started washing',      2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (2, 'ready',     'Ready for pickup',     2, NOW()),
  (3, 'pending',   'Order created',        2, NOW()),
  (3, 'washing',   'Started washing',      2, NOW()),
  (4, 'pending',   'Order created',        1, NOW());

INSERT INTO expenses (description, category, amount, expense_date, created_by) VALUES
  ('Laundry detergent (5kg)',   'Supplies',   450.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 1),
  ('Fabric softener (3L)',      'Supplies',   320.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 1),
  ('Electricity bill',          'Utilities', 2800.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 1),
  ('Water bill',                'Utilities',  750.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 1);
