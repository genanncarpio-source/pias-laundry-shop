# Database Design

**System:** Laundry Shop Management System for Pia's Laundry Shop
**Database:** `laundry_pos`
**Engine:** InnoDB · **Charset:** utf8mb4 / utf8mb4_unicode_ci
**Tables:** 10

---

## 1. Entity-Relationship Diagram

```
                 ┌──────────────┐
                 │    users     │  staff & admin accounts
                 └──────┬───────┘
                        │ 1
           ┌────────────┼──────────────┐
           │ N          │ N            │ N
  ┌────────▼───────┐ ┌──▼───────────┐ ┌▼───────────────┐
  │    orders      │ │   expenses   │ │ order_history  │
  │ (created_by)   │ │ (created_by) │ │  (changed_by)  │
  └────────┬───────┘ └──────────────┘ └────────▲───────┘
           │ 1                                 │ N
           ├───────────────────────────────────┘
           │ 1
           │ N
  ┌────────▼───────┐        ┌──────────────┐
  │  order_items   │  N   1 │   services   │
  │ (service_id)   │───────▶│              │  (snapshot, no FK)
  └────────────────┘        └──────────────┘

  ┌──────────────┐ 1      N ┌──────────────┐
  │  customers   │─────────▶│    orders    │
  └──────────────┘          │(customer_id) │
                            └──────────────┘

  ┌──────────────┐
  │   settings   │  (standalone key/value store)
  └──────────────┘
```

### Relationship summary

| Parent | Child | Type | On delete |
|---|---|---|---|
| `users` | `orders.created_by` | 1 : N | SET NULL |
| `users` | `expenses.created_by` | 1 : N | SET NULL |
| `users` | `order_history.changed_by` | 1 : N | SET NULL |
| `customers` | `orders.customer_id` | 1 : N | SET NULL |
| `orders` | `order_items.order_id` | 1 : N | CASCADE |
| `orders` | `order_history.order_id` | 1 : N | CASCADE |
| `services` | `order_items.service_id` | 1 : N | (nullable, no FK constraint) |

`order_items.service_id` intentionally has no foreign key: a line item keeps its own
`service_name`, `unit` and `unit_price` snapshot so that historical receipts stay
correct even if the service is later renamed, repriced or deleted.

---

## 2. Table definitions

### 2.1 `users` — staff and administrator accounts

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `full_name` | varchar(100) | NO | | | Shown in the sidebar and audit trails |
| `username` | varchar(50) | NO | UNI | | Login name |
| `password` | varchar(255) | NO | | | bcrypt hash, cost 12 |
| `email` | varchar(120) | YES | | | |
| `phone` | varchar(30) | YES | | | |
| `role` | enum('admin','staff') | NO | | 'staff' | Drives page-level authorisation |
| `is_active` | tinyint(1) | NO | | 1 | 0 blocks login |
| `created_at` | datetime | NO | | current_timestamp | |

**Indexes:** `PRIMARY (id)`, `UNIQUE uq_users_username (username)`

---

### 2.2 `customers` — customer records and mobile account credentials

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `name` | varchar(100) | NO | MUL | | |
| `phone` | varchar(30) | YES | | | Used for service updates and customer records |
| `email` | varchar(120) | YES | UNI | | Optional; unique when present |
| `address` | varchar(255) | YES | | | |
| `password_hash` | varchar(255) | YES | | NULL | Customer app password hash; NULL for staff-entered guest records |
| `is_active` | tinyint(1) | NO | | 1 | |
| `created_at` | datetime | NO | | current_timestamp | |

**Indexes:** `PRIMARY (id)`, `KEY idx_customers_name (name)`, `UNIQUE uq_customers_email (email)`

> The email is optional — customers without one remain valid walk-in records. When
> given, it must be unique so the same person is not recorded twice.

---

### 2.3 `services` — price list

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `name` | varchar(120) | NO | | | |
| `category` | varchar(60) | NO | | 'Wash & Fold' | Groups services in the staff and customer apps |
| `unit` | enum('kg','piece') | NO | | 'kg' | Pricing basis |
| `price` | decimal(10,2) | NO | | 0.00 | |
| `description` | varchar(255) | YES | | | |
| `is_active` | tinyint(1) | NO | | 1 | Inactive services are hidden from service intake |
| `created_at` | datetime | NO | | current_timestamp | |

Seeded with 8 services across 3 categories.

---

### 2.4 `orders` — transactions

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `order_number` | varchar(40) | NO | UNI | | Staff tickets use `ORD-…`; mobile requests use `TKT-…` |
| `customer_id` | int unsigned | YES | MUL | NULL | Customer who requested the service, when linked |
| `customer_name` | varchar(100) | YES | | | Denormalised name snapshot |
| `order_type` | enum('walk_in','drop_off') | NO | | 'walk_in' | |
| `status` | enum('pending','washing','ready','picked_up','cancelled') | NO | MUL | 'pending' | Workflow state |
| `subtotal` | decimal(10,2) | NO | | 0.00 | Sum of line items |
| `discount` | decimal(10,2) | NO | | 0.00 | |
| `total` | decimal(10,2) | NO | | 0.00 | subtotal − discount |
| `amount_paid` | decimal(10,2) | NO | | 0.00 | |
| `change_due` | decimal(10,2) | NO | | 0.00 | |
| `payment_method` | varchar(30) | NO | | 'cash' | cash / gcash / maya / card / unpaid |
| `paid_at` | datetime | YES | | NULL | When payment was recorded; NULL for unpaid tickets |
| `expected_pickup` | datetime | YES | | NULL | Drop-off orders |
| `notes` | varchar(255) | YES | | | |
| `created_by` | int unsigned | YES | MUL | NULL | FK → `users.id` |
| `created_at` | datetime | NO | MUL | current_timestamp | |
| `updated_at` | datetime | NO | | current_timestamp ON UPDATE | |

**Indexes:** `PRIMARY`, `UNIQUE uq_orders_number`, `idx_orders_status`, `idx_orders_created`

**Ticket-number generation.** The staff intake form computes
`MAX(CAST(SUBSTRING(order_number, 14) AS UNSIGNED)) + 1` for today's prefix,
rather than `COUNT(*)`. This keeps numbering collision-free when orders are
deleted or when the database was seeded with sample rows.

---

### 2.5 `order_items` — line items

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `order_id` | int unsigned | NO | MUL | | FK → `orders.id`, CASCADE |
| `service_id` | int unsigned | YES | | NULL | Originating service |
| `service_name` | varchar(120) | NO | | | Snapshot at time of sale |
| `unit` | varchar(10) | NO | | 'kg' | Snapshot |
| `quantity` | decimal(10,2) | NO | | 1.00 | kg or piece count |
| `unit_price` | decimal(10,2) | NO | | 0.00 | Snapshot |
| `subtotal` | decimal(10,2) | NO | | 0.00 | quantity × unit_price |

---

### 2.6 `order_history` — status timeline

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `order_id` | int unsigned | NO | MUL | | FK → `orders.id`, CASCADE |
| `status` | varchar(30) | NO | | | Status reached |
| `note` | varchar(255) | YES | | | Human-readable description |
| `changed_by` | int unsigned | YES | MUL | NULL | FK → `users.id` |
| `created_at` | datetime | NO | | current_timestamp | |

This table is rendered as the **Status Timeline** on the order detail page — who
moved the order to which stage, and when.

---

### 2.7 `expenses` — shop expenses

| Column | Type | Null | Key | Default | Notes |
|---|---|---|---|---|---|
| `id` | int unsigned | NO | PRI | auto_increment | |
| `description` | varchar(255) | NO | | | |
| `category` | varchar(60) | NO | | 'Supplies' | e.g. Supplies, Utilities, Rent |
| `amount` | decimal(10,2) | NO | | 0.00 | |
| `payment_method` | varchar(30) | NO | | 'cash' | Counts as till outflow only when paid in cash |
| `expense_date` | date | NO | MUL | | |
| `created_by` | int unsigned | YES | | NULL | FK → `users.id` |
| `created_at` | datetime | NO | | current_timestamp | |

Admin-only module. Used by Reports to compute net income.

---

### 2.8 `settings` — key/value configuration

| Column | Type | Null | Key | Default |
|---|---|---|---|---|
| `key` | varchar(60) | NO | PRI | |
| `value` | text | YES | | |

**Seeded keys**

| Key | Default | Purpose |
|---|---|---|
| `shop_name` | Pia's Laundry Shop | Receipts, sidebar, titles |
| `shop_address` | 123 Main Street, Quezon City, Metro Manila | Receipts |
| `shop_phone` | 0912 345 6789 | Receipts |
| `receipt_note` | Thank you for choosing Pia's Laundry Shop! … | Receipt footer |

Writes use `INSERT … ON DUPLICATE KEY UPDATE` so saving an unchanged value is safe.

---

### 2.9 `order_adjustments`

Stores cancellation reasons and refund amount, method, reason, staff account and time.
Reports subtract refunds from active orders; cancelled orders are excluded from sales.

### 2.10 `customer_sessions`

Stores SHA-256 hashes of random customer app bearer tokens with expiry dates. Raw
tokens are returned to the app at registration or login and are not stored in the
database or included in backups. Deleting a customer deletes their sessions.

## 3. Seed data

| Table | Rows | Notes |
|---|---|---|
| `settings` | 4 | Shop information and receipt footer |
| `users` | 2 | `admin` / `admin123`, `staff` / `staff123` |
| `customers` | 3 | Sample customers with contact details |
| `services` | 8 | 3 categories |
| `orders` | 4 | Spread over the last 3 days, mixed statuses |
| `order_items` | 5 | |
| `order_history` | 10 | Complete timelines for the sample orders |
| `expenses` | 4 | Supplies and utilities |

The sample orders block at the end of `laundry_pos.sql` is clearly commented and can
be deleted for an empty production database.

---

## 4. Data integrity rules enforced by the schema

1. `orders.order_number` is **unique** — no duplicate receipts.
2. `customers.email` is **unique** — the same person is not recorded twice.
3. `users.username` is **unique** — no duplicate logins.
4. `orders.status` and `order_type` are **ENUMs** — invalid values cannot be stored.
5. Deleting a customer sets `orders.customer_id` to NULL, so the order and its
   receipt survive.
6. Deleting an order cascades to its line items and history.
7. All monetary columns are `DECIMAL(10,2)` — never `FLOAT`, so no rounding drift.
8. `utf8mb4` throughout — the ₱ peso sign and emoji store and display correctly.
