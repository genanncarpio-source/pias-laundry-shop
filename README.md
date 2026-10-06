# Pia's Laundry Shop Management System

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
9. [Customer Android APK](#customer-android-apk)

---

## Features

### Laundry Ticket Intake
- Service catalogue grouped by category (Wash & Fold, Ironing & Press, Dry Cleaning)
- Per-kilogram and per-piece pricing
- Cart with live totals, discount, and change computation
- Walk-in and drop-off orders, with expected pickup date/time
- Payment methods: Cash, GCash, Maya, Card
- Thermal-style (80 mm) printable receipt

### Laundry Ticket Management
- Order list with status, type and date filters
- Status workflow: **Pending → In Progress → Ready for Pickup → Picked Up** (plus Cancelled)
- Full status timeline per order (who changed what, and when)
- Reprint receipt at any time
- Public customer status lookup from the order number and registered phone
- Ready-for-pickup SMS text prepared for staff to send from a phone

### Management
- Customers (name, phone, email, address, order history and total spent)
- Services / price list
- Expenses
- Cancellation reasons and partial/full refund records
- Admin JSON backup download and protected restore
- Reports and dashboard (revenue, expenses, net, top services, 7-day chart)
- Employees / user accounts
- Settings (shop information and receipt footer)
- Customer mobile web app for registration, login, laundry service requests and ticket tracking
- Customer API used by the Flutter customer app and optional browser prototype

### Roles
| Capability | Admin | Staff |
|---|---|---|
| Service tickets, customers, services | ✅ | ✅ |
| Expenses, reports, employees, settings, backup/restore | ✅ | ❌ |

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
C:\xampp\htdocs\laundry-pos
```

`index.php` and the `config` folder must sit **directly** inside `laundry-pos` — not inside a nested sub-folder.

#### 2. Start Apache and MySQL

Open the **XAMPP Control Panel** and click **Start** next to **Apache** and **MySQL**.

If MySQL will not start, port 3306 is usually taken — see [Troubleshooting](#troubleshooting).

#### 3. Create the database

**Option A — phpMyAdmin (easiest)**

1. In the XAMPP Control Panel click **Admin** next to MySQL, or open `http://localhost/phpmyadmin`
2. Click the **Import** tab
3. **Choose File** → `laundry-pos\database\laundry_pos.sql`
4. Click **Go**

The file creates the `laundry_pos` database itself, with all 12 tables and the seed data. You do **not** need to create the database by hand first.

Existing databases are upgraded additively when an administrator or cashier first
opens a feature page. You can also import `database/upgrade_features.sql`; it creates
only the new feature tables and keeps existing shop records.

**Option B — one-click script**

Double-click `install.bat` in the project folder. It auto-detects `C:\xampp\mysql\bin\mysql.exe`, imports the file, then prints a verification summary.

**Option C — command line**

```bat
cd C:\xampp\mysql\bin
mysql.exe -u root < C:\xampp\htdocs\laundry-pos\database\laundry_pos.sql
```

> ⚠️ **Re-importing this file drops and recreates every table.** Back up first if the system already holds real data.

#### 4. Open the system

```
http://localhost/laundry-pos/
```

---

### Laragon

#### 1. Copy the files

Extract this archive into Laragon's web root:

```
C:\laragon\www\laundry-pos
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
SOURCE C:/laragon/www/laundry-pos/database/laundry_pos.sql;
```

#### 4. Open the system

```
http://localhost/laundry-pos/
```

---

### Database credentials

XAMPP and Laragon use the **same defaults**, so normally no editing is needed:

| Setting | Default |
|---|---|
| Host | `localhost` |
| Database | `laundry_pos` |
| User | `root` |
| Password | *(empty)* |

If yours differ, either edit `config/database.php` or set environment variables
(the code reads these first): `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

### Moving between XAMPP and Laragon

1. Copy the whole `laundry-pos` folder to the other stack's web root
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
> 🗑️ **Delete `reset_password.php`** once you no longer need it. It can reset any password without authentication, so it must not stay on a live server.

---

## Project structure

```
laundry-pos/
├── index.php                 Login (with show/hide password)
├── logout.php
├── dashboard.php             Stats, revenue chart, recent orders
├── pos.php                   Staff service-ticket intake
├── orders.php                Order list + filters
├── order_view.php            Order detail, status, timeline
├── receipt.php               Printable 80 mm receipt
├── track.php                 Public order status lookup
├── mobile_app/               Pia's Laundry Shop Flutter customer app and APK build workflow
├── customer-app/             Optional browser prototype
├── api/                      Customer registration, login and service API
├── customers.php             Customers
├── services.php              Price list
├── expenses.php              Expenses
├── backup.php                Database backup / restore (admin)
├── reports.php               Sales / expense / net reports
├── employees.php             User accounts (admin)
├── settings.php              Shop info (admin)
├── profile.php               Own account + password
├── reset_password.php        ⚠️ Emergency tool — delete after use
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
│   ├── laundry_pos.sql       Schema + seed data (12 tables)
│   └── upgrade_features.sql  Additive feature migration
└── docs/                     Capstone documentation
    ├── 01-system-overview.md
    ├── 02-database.md
    └── 03-user-guide.md
```

---

## Using the system

### Recording a sale
1. **Create a service ticket**
2. Click services to add them; adjust quantities
3. Choose **Walk-in** or **Drop-off** (drop-off asks for an expected pickup time)
4. Pick a saved customer, add a new one, or leave as walk-in
5. Choose a payment method; for cash, enter the amount tendered
6. **💵 Complete Sale** → the receipt opens, ready to print

### Updating an order
1. **Orders** → open an order
2. Click the next status button (the workflow cannot move backwards)
3. The timeline records the change (who, what, when)

---

## Troubleshooting

**"Database connection failed"**
Check that MySQL is running (XAMPP Control Panel → **Start** next to MySQL, or Laragon → **Start All**), that the `laundry_pos` database exists in phpMyAdmin, and that the credentials in `config/database.php` match. Both XAMPP and Laragon default to `root` with an **empty** password.

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
`Listen 80`), then use `http://localhost:8080/laundry-pos/`.

**"Access denied for user 'root'@'localhost'"**
Your root user has a password. Either put it in `config/database.php`, or reset it.
XAMPP's root has no password by default, so this usually means a previous install
set one.

**Forgot the admin password**
Open `reset_password.php` in the browser and follow the instructions. **Delete the file afterwards.**

**Order numbers look wrong after importing**
Sample orders are numbered by their own date (`ORD-YYYYMMDD-NNN`). New orders continue the sequence for today using `MAX(sequence) + 1`, so the sample data will not collide with live sales.

---

## Customer Android APK

The customer app source is in `mobile_app/`. To build locally, follow
[`mobile_app/README.md`](mobile_app/README.md). It can run on a physical Android
phone without an emulator. The app's sign-in screen accepts the shop API address;
for a phone on the same Wi-Fi as XAMPP, use the computer's LAN IP, for example
`http://192.168.1.20/laundry-pos/api`.

If Flutter/Android SDK are not installed locally, push this folder to a GitHub
repository. The **Build Pia's Laundry Shop APK** workflow runs on pushes to
`main` and publishes a test/install APK artifact; Play Store distribution needs
a private release signing key.

---

## Documentation for the capstone manuscript

| File | Contents |
|---|---|
| [docs/01-system-overview.md](docs/01-system-overview.md) | Project title, objectives, scope and limitations, features, users, architecture, technologies |
| [docs/02-database.md](docs/02-database.md) | Database tables, relationships, and a text ERD |
| [docs/03-user-guide.md](docs/03-user-guide.md) | Step-by-step guide for administrators and cashiers |
| [docs/04-customer-app.md](docs/04-customer-app.md) | Customer app, API endpoints, and setup details |

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
