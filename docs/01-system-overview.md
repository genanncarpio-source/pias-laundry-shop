# System Overview

## Project Title

**P.O.S. AND LAUNDRY SHOP MANAGEMENT SYSTEM FOR PIA'S LAUNDRY SHOP**

---

## 1. Project Context

Pia's Laundry Shop currently records transactions manually. This produces several
recurring problems:

- Sales, expenses and income are tracked on paper, so daily and monthly figures take
  time to compute and are prone to arithmetic error.
- When a customer asks whether their laundry is ready, staff must physically look
  for the bundle because there is no record of each order's current stage.
- Drop-off orders rely on a handwritten ticket. If the ticket is lost, matching the
  finished laundry to its owner becomes difficult.
- There is no record of which customer spent how much, so loyal customers cannot be
  identified.

This system addresses those problems with a web-based Point-of-Sale and shop
management system that records every transaction, tracks each order through a
defined status workflow, and produces reports automatically.

---

## 2. Objectives

### 2.1 General objective

To develop a Point-of-Sale and laundry shop management system for Pia's Laundry Shop.

### 2.2 Specific objectives

1. To provide a Point-of-Sale module that records transactions, computes totals,
   discount and change automatically, and prints a receipt.
2. To manage the laundry service catalogue with per-kilogram and per-piece pricing.
3. To record and track orders through a defined status workflow — Pending, In
   Progress, Ready for Pickup, Picked Up, and Cancelled — with a full audit trail.
4. To manage customers, employees, and expenses.
5. To generate a dashboard and reports covering sales, expenses, net income and
   top-performing services.
6. To enforce role-based access control separating administrators from cashiers.

---

## 3. Scope and Limitations

### 3.1 In scope

| Module | Capabilities |
|---|---|
| Authentication & roles | Login, logout, session management, Admin and Staff roles |
| Point of Sale | Service catalogue, cart, walk-in and drop-off, four payment methods, discount, change, printable receipt |
| Order management | Order list with filters, order detail, status workflow, status timeline, receipt reprint |
| Customers | Records with contact details, order count and total spent |
| Services | Price list maintenance, activate/deactivate |
| Expenses | Recording by category and date |
| Reports | Sales, expenses, net income, top services, payment-method breakdown, 7-day revenue chart |
| Refund controls | Cancellation reasons and administrator-recorded refunds |
| Customer status lookup | Public order status lookup using receipt number and registered phone |
| Backup & restore | Administrator JSON backup download and guarded restore |
| Employees | User account management (admin only) |
| Settings | Shop information and receipt footer |

### 3.2 Limitations

1. **Manual SMS sending.** The app prepares a ready-for-pickup message and opens the
   phone's messaging app; staff review and send it. No SMS provider is connected.
2. **Single-branch.** The system models one shop location.
3. **No online payment.** Payment methods are recorded, not processed.
4. **Local-network deployment.** The system is designed to run on the shop computer
   under XAMPP or Laragon; it is not hardened for exposure to the public internet.

---

## 4. Users of the System

### 4.1 Administrator

Full access. Manages employees, services, expenses and settings, views all
reports, and may delete records.

### 4.2 Staff / Cashier

Operational access. Creates service tickets, updates ticket status, and manages
customers. Staff cannot access expenses, reports, employee management,
backup/restore or settings.

### 4.3 Permission matrix

| Area | Admin | Staff |
|---|:--:|:--:|
| Point of Sale | ✅ | ✅ |
| Orders & status | ✅ | ✅ |
| Customers | ✅ | ✅ |
| Services / price list | ✅ | ✅ |
| Expenses | ✅ | ❌ |
| Reports & dashboard | ✅ | ❌ |
| Employees | ✅ | ❌ |
| Settings | ✅ | ❌ |
| Delete customer | ✅ | ❌ |

---

## 5. System Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                          CLIENT                              │
│                                                              │
│              ┌────────────────────────────┐                  │
│              │  Shop computer (browser)   │                  │
│              └─────────────┬──────────────┘                  │
│                            │ HTML/CSS/JS + session cookie    │
└────────────────────────────┼─────────────────────────────────┘
                             │
┌────────────────────────────▼─────────────────────────────────┐
│           WEB SERVER  (Apache — XAMPP or Laragon)            │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │   WEB APPLICATION  (PHP pages)                         │  │
│  │                                                        │  │
│  │  index.php      login          expenses.php   (admin)  │  │
│  │  dashboard.php                 reports.php    (admin)  │  │
│  │  pos.php        + AJAX         employees.php  (admin)  │  │
│  │  orders.php                    settings.php   (admin)  │  │
│  │  order_view.php                profile.php             │  │
│  │  receipt.php                   customers.php           │  │
│  │  services.php                                          │  │
│  └───────────────────────────┬────────────────────────────┘  │
│                              │                               │
│  ┌───────────────────────────▼────────────────────────────┐  │
│  │           SHARED APPLICATION LAYER  (includes/)        │  │
│  │                                                        │  │
│  │  auth.php       session bootstrap, cookie flags,       │  │
│  │                 current_user(), role guards            │  │
│  │  functions.php  e(), money(), redirect(), flash,       │  │
│  │                 CSRF, json_out(), settings, order      │  │
│  │                 status labels and history helpers      │  │
│  │  header.php     layout + sidebar                       │  │
│  │  footer.php     layout close + app.js                  │  │
│  └───────────────────────────┬────────────────────────────┘  │
│                              │                               │
│                ┌─────────────▼─────────────┐                 │
│                │   config/database.php     │                 │
│                │   PDO connection          │                 │
│                └─────────────┬─────────────┘                 │
└──────────────────────────────┼───────────────────────────────┘
                               │
                     ┌─────────▼──────────┐
                     │  MySQL / MariaDB   │
                     │  `laundry_pos`     │
                     │  12 tables         │
                     └────────────────────┘
```

### 5.1 Request flow

1. The browser requests a page. `includes/auth.php` starts the session and
   `require_login()` / `require_admin()` guard the page by role.
2. The page runs its queries through the shared PDO connection using prepared
   statements, then includes `header.php`, renders its HTML, and includes
   `footer.php`.
3. The POS screen is the one AJAX-driven page: the cart is managed in JavaScript,
   and **Complete Sale** posts JSON to `pos.php?action=create`, which writes the
   order and its line items inside a single database transaction and returns the
   new order id so the receipt can open in a new tab.
4. Every status change on an order appends a row to `order_history`, producing the
   Status Timeline shown on the order page.

---

## 6. Technologies Used

| Layer | Technology | Why |
|---|---|---|
| Server-side logic | **PHP 8** (compatible from 7.4) | Widely available, runs natively on XAMPP and Laragon, no build step |
| Database | **MySQL / MariaDB** | Bundled with XAMPP and Laragon; InnoDB gives transactions and foreign keys |
| Data access | **PDO with prepared statements** | Prevents SQL injection throughout |
| Styling | **CSS3 (custom, hand-written)** | No framework dependency; responsive down to small phones |
| Client-side behaviour | **Vanilla JavaScript (ES6)** | Cart, AJAX checkout, modals, toasts — no libraries to load |
| Password storage | **bcrypt via `password_hash()` (cost 12)** | Industry standard, salted, adaptive |
| Local environment | **XAMPP** or **Laragon** (Apache + MySQL + PHP) | The shop's target deployment platform; both are supported with identical configuration |

**Deliberately not used:** Composer, a PHP framework, a CSS framework,
jQuery, or any build/bundling tool. The project deploys by copying files — there is
nothing to compile and no dependency to install, which suits a small shop's
maintenance capability.

---

## 7. Feature Detail

### 7.1 Point of Sale

Services are displayed grouped by category as tap targets. Tapping adds to the cart;
quantity is adjusted inline. The screen computes subtotal, discount, total and change
live. Two order types are supported:

- **Walk-in** — customer waits or returns; no pickup time needed.
- **Drop-off** — an expected pickup date and time is captured.

The customer selector lists saved customers; a brand-new customer can be created
inline with a name and phone number, or the sale can be recorded as an anonymous
walk-in.

On **Complete Sale** the order and its line items are written inside a database
transaction, so a partial write cannot occur. The order number follows
`ORD-YYYYMMDD-NNN`, derived from the highest existing sequence for today plus one.
The receipt then opens in a new tab, formatted for an 80 mm thermal printer.

### 7.2 Order status workflow

```
 Pending ──► In Progress ──► Ready for Pickup ──► Picked Up
    │              │                  │
    └──────────────┴──────────────────┴────► Cancelled
```

The interface only offers forward movement; buttons for earlier stages are disabled.
Cancelling is available from any non-cancelled state and asks for confirmation. Every
change appends a row to `order_history` recording the status, a note and the staff
member responsible. This is the audit trail shown as the Status Timeline.

### 7.3 Reports

- **Dashboard** — today's sales, order count, monthly revenue, monthly expenses, net
  income, pending and ready-for-pickup counters, a 7-day revenue chart, and recent
  orders.
- **Reports** — sales, expenses and net income for a chosen period; top services by
  revenue and by count; payment-method breakdown; expense breakdown by category.

---

## 8. Security Design

| Concern | Measure |
|---|---|
| SQL injection | Every query uses PDO prepared statements with bound parameters |
| Cross-site scripting | All output passes through `e()` (`htmlspecialchars`, UTF-8, quotes escaped) |
| CSRF | A per-session token is required by every state-changing form and AJAX call, verified with `hash_equals` |
| Password storage | bcrypt cost 12; never stored or logged in plain text |
| Session fixation | `session_regenerate_id(true)` on successful login |
| Session cookie | `HttpOnly`; `SameSite=Lax` over plain HTTP, `SameSite=None; Secure` when HTTPS is detected — so login works both on XAMPP/Laragon and behind a reverse proxy |
| Brute force | Login errors are identical for unknown username and wrong password |
| Deactivation | `is_active = 0` blocks staff login |

**Recommendations for production deployment:** serve over HTTPS, add rate limiting
to the login page, and delete `reset_password.php`.

---

## 9. Development Approach

The system was built incrementally and verified at each stage:

1. **Database** — schema, relationships and seed data; imported and verified on
   MariaDB.
2. **Foundation** — PDO connection, helpers, session/role guards, layout.
3. **Core POS** — services, cart, checkout, receipts.
4. **Management** — orders, customers, employees, expenses, reports, settings.
5. **Verification** — see below.

### 9.1 Verification performed

- Every PHP file passes `php -l` (20 files).
- All 12 authenticated web pages return HTTP 200 with no PHP warnings, notices or
  fatal errors.
- The POS checkout was exercised end to end for both a newly-created customer and an
  existing customer: order and line items written, totals and change correct, order
  number sequenced, and the initial `order_history` row created.
- The status workflow was exercised through In Progress → Ready for Pickup → Picked
  Up, verifying that each change produced a timeline entry.
- Customer create/update and Settings save were exercised and the rows verified in
  the database.

 verification

The schema was checked for portability between XAMPP and Laragon:

- No MySQL-8-only collation (`utf8mb4_0900_ai_ci`) — `utf8mb4_unicode_ci` is used,
  which every supported version provides.
- No window functions, CTEs, `CHECK` constraints, generated columns or `LATERAL`.
- Only portable date functions: `NOW`, `CURDATE`, `DATE_ADD`, `DATE_SUB`,
  `DATE_FORMAT`, `CONCAT`.
- The reserved word `key` (in the `settings` table) is back-ticked.
- The import was executed successfully under
  `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` — the
  SQL mode both stacks ship with — producing all 12 tables and the complete seed set.
- `install.bat` was rewritten to auto-detect `mysql.exe`/`mariadb.exe` across XAMPP,
  Laragon (versioned folder), WAMP and the system `PATH`, and to verify the result
  by querying the imported tables.

No code path contains a hard-coded absolute filesystem path; asset URLs are computed
at runtime from the script's own location, so the project runs unchanged from either
web root, or from a sub-folder of either.

### 9.3 Defects found and fixed during verification

| Defect | Impact | Fix |
|---|---|---|
| `settings_update()` inserted after an UPDATE that changed nothing | Saving Settings without changing a value raised a duplicate-key error (HTTP 500) | Replaced with an atomic `INSERT … ON DUPLICATE KEY UPDATE` |
| Order numbers generated from `COUNT(*)` | Collided with seeded sample orders, blocking the first live sale | Generate from `MAX(sequence) + 1` for today's prefix |
| Sample order numbers all dated today but back-dated `created_at` | Same collision | Numbered each sample order by its own date |
| `mb_substr()` / `mb_strlen()` used without checking for mbstring | Fatal error on PHP builds without mbstring | Added polyfills in `functions.php` |
| Session cookie rejected inside a cross-origin iframe | Login appeared to fail behind a reverse proxy | Detect HTTPS via forwarded headers and use `SameSite=None; Secure` |

---

## 10. Deployment Summary

| Item | Value |
|---|---|
| Target platform | XAMPP or Laragon on Windows (Apache + MySQL + PHP) |
| Document root | `C:\xampp\htdocs\laundry-pos` (XAMPP) or `C:\laragon\www\laundry-pos` (Laragon) |
| Application URL | `http://localhost/laundry-pos/` |
| Database | `laundry_pos` (12 tables) |
| Installation steps | Copy files → import `database/laundry_pos.sql` (phpMyAdmin or `install.bat`) → start Apache and MySQL |
| External dependency | None |
| Build step | None — no Composer, no bundler, no compilation |

Full instructions are in `README.md`.
