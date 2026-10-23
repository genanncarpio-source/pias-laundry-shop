# P.O.S. and Laundry Shop Management System for Pia's Laundry Shop

A complete Point-of-Sale and laundry shop management system built with **PHP, MySQL, CSS and JavaScript** — no frameworks, no Composer, no build step. It runs directly on **XAMPP** or **Laragon** (Apache + MySQL) with no conversion needed.

---

## Table of contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation (XAMPP or Laragon)](#installation)
4. [Default logins](#default-logins)
5. [Project structure](#project-structure)
6. [Using the system](#using-the-system)
7. [Troubleshooting](#troubleshooting)
8. [Documentation for the capstone manuscript](#documentation-for-the-capstone-manuscript)

---

## Features

### Customer service requests
- Customers register, sign in, book pickup or delivery, and track orders in the Expo mobile app
- Customer accounts and service records remain in the shared database for the app and staff POS
- Weight-based service rates use a 7 kg minimum load
- Staff can print order receipts

### Order management
- Mobile app bookings appear in the staff/admin Orders list and remain unpaid until confirmed at the shop
- Customer sign-in and private order history are provided by the mobile app
- Order list with status, type and date filters
- Status workflow: **Pending → Accepted → Received → Wash → Dry → Ready for Pick-up**; Full Service automatically records Fold before Ready
- Full status timeline, in-app notifications and customer order history
- Actual-weight billing, payment ledger with partial payments, with staff payment records
- Reprint receipt at any time
- Delivery location tracking is handled by the mobile app
- Ready-for-pickup SMS text prepared for staff to send from a phone

### Management
- Customers (name, phone, email, address, order history and total spent)
- Services / price list
- Expenses
- Inventory stock and low-stock alerts
- Admin JSON backup download and protected restore
- Reports and dashboard (revenue, expenses, net, top services, 7-day chart)
- Employees / user accounts
- Settings (shop information and receipt footer)

### Roles
| Capability | Admin | Staff |
|---|---:|---:|
| View dashboard, orders, customers and services | ✅ | ✅ |
| Update laundry process status and record payments | ❌ | ✅ |
| Reports, employees, settings and backup/restore | ✅ | ❌ |


---

## Requirements

- **PHP 7.4 or newer** (developed and tested on PHP 8.4). Extensions used: `pdo_mysql`, `json`. `mbstring` is optional — the code includes fallbacks.
- **MySQL 5.7+ / MariaDB 10.3+**
- **Apache** (or any web server)
- **XAMPP** and **Laragon** are both fully supported. WAMP works too.

> **No conversion is needed between XAMPP and Laragon.** The SQL file uses only
> portable syntax (`utf8mb4_unicode_ci`, InnoDB, standard date functions) and has
> been verified to import cleanly under `STRICT_TRANS_TABLES`, which is what both
> stacks ship with. The default database credentials are also identical
> (`root` / empty password / `localhost`).

---

## Installation

Pick your stack: [XAMPP](#xampp) or [Laragon](#laragon). Steps 2–4 are the same idea in both.

### XAMPP

#### 1. Copy the files

Extract this archive into XAMPP's web root:

```
C:\xampp\htdocs\laundry-pias
```

`index.php` and the `config` folder must sit **directly** inside `laundry-pias` — not inside a nested sub-folder.

#### 2. Start Apache and MySQL

Open the **XAMPP Control Panel** and click **Start** next to **Apache** and **MySQL**.

If MySQL will not start, port 3306 is usually taken — see [Troubleshooting](#troubleshooting).

#### 3. Create the database

**Option A — phpMyAdmin (easiest)**

1. In the XAMPP Control Panel click **Admin** next to MySQL, or open `http://localhost/phpmyadmin`
2. Click the **Import** tab
3. **Choose File** → `laundry-pias\database\laundry_pos.sql`
4. Click **Go**

The file creates the `laundry_pias` database itself, with core tables and seed data. New customer and workflow tables are added automatically the first time the related pages are opened; existing installations can also import `database/upgrade_features.sql`.

Existing databases are upgraded additively when an administrator or cashier first
opens a feature page. You can also import `database/upgrade_features.sql`; it creates
only the new feature tables and keeps existing shop records.

**Option B — one-click script**

Double-click `install.bat` in the project folder. It auto-detects `C:\xampp\mysql\bin\mysql.exe`, imports the file, then prints a verification summary.

**Option C — command line**

```bat
cd C:\xampp\mysql\bin
mysql.exe -u root < C:\xampp\htdocs\laundry-pias\database\laundry_pos.sql
```

> ⚠️ **Re-importing this file drops and recreates every table.** Back up first if the system already holds real data.

#### 4. Open the system

```
http://localhost/laundry-pias/
```

---

### Laragon

#### 1. Copy the files

Extract this archive into Laragon's web root:

```
C:\laragon\www\laundry-pias
```

#### 2. Start the stack

Click **Start All** in Laragon (starts Apache and MySQL).

#### 3. Create the database

**Option A — phpMyAdmin**

1. Click **Database** in Laragon (opens `http://localhost/phpmyadmin`)
2. **Import** → choose `database/laundry_pos.sql` → **Go**

**Option B — one-click script**

Double-click `install.bat`. It auto-detects the versioned Laragon MySQL folder
(`C:\laragon\bin\mysql\mysql-*\bin`).

**Option C — command line**

Laragon tray icon → **MySQL**, then:

```sql
SOURCE C:/laragon/www/laundry-pias/database/laundry_pos.sql;
```

#### 4. Open the system

```
http://localhost/laundry-pias/
```

---

### Database credentials

XAMPP and Laragon use the **same defaults**, so normally no editing is needed:

| Setting | Default |
|---|---|
| Host | `localhost` |
| Database | `laundry_pias` |
| User | `root` |
| Password | *(empty)* |

If yours differ, either edit `config/database.php` or set environment variables
(the code reads these first): `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

New local installs use the `laundry_pias` database. Existing installations that
still have only `laundry_pos` continue to connect to it automatically, so this
change does not interrupt their data. The Railway-provided `MYSQLDATABASE` setting
also remains authoritative. Move an existing database only after backing it up,
copying its tables and data to `laundry_pias`, and verifying the copy; then update
`DB_NAME` (or Railway's database setting) to `laundry_pias`.

### Moving between XAMPP and Laragon

1. Copy the whole `laundry-pias` folder to the other stack's web root
   (`C:\xampp\htdocs` ↔ `C:\laragon\www`).
2. Export your data from phpMyAdmin (**Export → Go**) and import it into the other
   stack — or just re-import `database/laundry_pos.sql` if you do not need the data.
3. Nothing in the code needs changing. There are no hard-coded absolute paths;
   asset URLs are computed at runtime, so the app works from any folder name or depth.

---

---

## Default logins

| Username | Password | Role |
|---|---|---|
| `admin` | `admin123` | Administrator |
| `staff` | `staff123` | Cashier / Staff |

> 🔒 **Change these before real use.** Admin → **Employees** for staff accounts, **Profile** for your own password.
>
> Password resets require an administrator login through Employees. The old public reset utility is disabled.

---

## Project structure

```
laundry-pias/
├── index.php                 Login (with show/hide password)
├── logout.php
├── dashboard.php             Stats, revenue chart, recent orders
├── orders.php                Order list + filters
├── order_view.php            Order detail, status, timeline
├── receipt.php               Printable 80 mm receipt
├── customers.php             Staff customer records
├── services.php              Price list
├── expenses.php              Expenses
├── inventory.php             Supply stock (admin)
├── backup.php                Database backup / restore (admin)
├── reports.php               Sales / expense / net reports
├── employees.php             User accounts (admin)
├── settings.php              Shop info (admin)
├── profile.php               Own account + password
├── reset_password.php        Admin-only redirect to secure account tools
├── api/                     JSON endpoints for the mobile app
├── mobile_app/              Expo and Flutter customer apps
├── install.bat               One-click database import
│
├── config/
│   └── database.php          PDO connection (env-var overridable)
├── includes/
│   ├── auth.php              Session, CSRF-safe cookie flags, role guards
│   ├── functions.php         Helpers: e(), money(), flash, CSRF, settings, order history
│   ├── feature_schema.php    Idempotent feature-table upgrade
│   ├── header.php            Sidebar + topbar layout
│   └── footer.php
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── database/
│   ├── laundry_pos.sql       Schema + seed data (12 core tables)
│   └── upgrade_features.sql  Additive feature migration
└── docs/                     Capstone documentation
    ├── 01-system-overview.md
    ├── 02-database.md
    ├── 03-user-guide.md
    ├── 04-system-analysis.md
    └── 05-system-design.md
```

---

## Using the system

### Customer bookings
1. Customers register or sign in through the Expo mobile app
2. Choose available laundry services and pickup or delivery options
3. Submit the booking; it appears in the staff Orders list
4. Staff confirms the service and updates its status; customer accounts and order history stay in the shared database

### Updating an order
1. **Orders** → open an order
2. Click the next status button (the workflow cannot move backwards)
3. The timeline records the change (who, what, when)

---

## Troubleshooting

**"Database connection failed"**
Check that MySQL is running (XAMPP Control Panel → **Start** next to MySQL, or Laragon → **Start All**), that the `laundry_pias` database exists in phpMyAdmin, and that the credentials in `config/database.php` match. Both XAMPP and Laragon default to `root` with an **empty** password.

**"could not find driver"**
The `pdo_mysql` extension is disabled. Open `php.ini` and make sure this line is uncommented, then restart Apache:

```ini
extension=pdo_mysql
```

- **XAMPP:** `C:\xampp\php\php.ini` (Control Panel → **Config** next to Apache → PHP → php.ini)
- **Laragon:** Menu → PHP → php.ini

**Login page reloads without an error**
Sessions are not being written. Confirm `session.save_path` points to a writable folder, and that you are visiting via `http://localhost/...` rather than a `file://` path.

**XAMPP: MySQL will not start (port 3306 in use)**
Another MySQL service is already running — often a Laragon or standalone MySQL
install. Either stop that service (`services.msc` → look for MySQL/MariaDB → Stop),
or change XAMPP's port: Control Panel → **Config** next to MySQL → `my.ini`, set
`port=3307` under both `[client]` and `[mysqld]`, restart, then set
`DB_HOST` to `127.0.0.1:3307` in `config/database.php`.

**XAMPP: Apache will not start (port 80 in use)**
Skype, IIS or World Wide Web Publishing Service commonly hold port 80. Stop it, or
switch Apache to port 8080 (Control Panel → **Config** → `http.conf`, change
`Listen 80`), then use `http://localhost:8080/laundry-pias/`.

**"Access denied for user 'root'@'localhost'"**
Your root user has a password. Either put it in `config/database.php`, or reset it.
XAMPP's root has no password by default, so this usually means a previous install
set one.

**Forgot the admin password**
Sign in as an administrator and reset staff passwords from Employees. Users can change their own password from My Account.

**Order numbers look wrong after importing**
Sample orders are numbered by their own date (`ORD-YYYYMMDD-NNN`). New orders continue the sequence for today using `MAX(sequence) + 1`, so the sample data will not collide with live sales.

---

## Documentation for the capstone manuscript

- [System analysis](docs/04-system-analysis.md)
- [System design](docs/05-system-design.md)
- [System overview](docs/01-system-overview.md)
- [Database design](docs/02-database.md)
- [User guide](docs/03-user-guide.md)

| File | Contents |
|---|---|
| [docs/01-system-overview.md](docs/01-system-overview.md) | Project title, objectives, scope and limitations, features, users, architecture, technologies |
| [docs/02-database.md](docs/02-database.md) | Database tables, relationships, and a text ERD |
| [docs/03-user-guide.md](docs/03-user-guide.md) | Step-by-step guide for administrators and cashiers |
| [docs/05-system-design.md](docs/05-system-design.md) | Application architecture, modules, data, workflows, security and deployment |
| [docs/04-system-analysis.md](docs/04-system-analysis.md) | Current system scope, roles, processes, requirements, data and business rules |

---

## Security notes

- All passwords are hashed with `bcrypt` (`password_hash`, cost 12). Plain passwords are never stored.
- Every query uses **PDO prepared statements** — there is no string-built SQL with user input.
- All output is escaped with `e()` (`htmlspecialchars`) to prevent XSS.
- Every state-changing form and AJAX call is protected by a **CSRF token**.

> For a production deployment also enable HTTPS.

---

**Built for:** Pia's Laundry Shop
**Stack:** PHP · MySQL · CSS · JavaScript (no frameworks)
