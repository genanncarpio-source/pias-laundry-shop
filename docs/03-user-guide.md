# User Guide

**System:** P.O.S. and Laundry Shop Management System for Pia's Laundry Shop

This guide is written for the people who will actually operate the shop: the
**Administrator** (owner/manager) and the **Cashier/Staff**.

---

## Contents

1. [Signing in](#1-signing-in)
2. [Understanding the screen](#2-understanding-the-screen)
3. [Recording a sale (Point of Sale)](#3-recording-a-sale-point-of-sale)
4. [Managing orders](#4-managing-orders)
5. [Managing customers](#5-managing-customers)
6. [Managing services and prices](#6-managing-services-and-prices)
7. [Recording expenses](#7-recording-expenses-admin-only)
8. [Reports and dashboard](#8-reports-and-dashboard)
9. [Managing employees](#9-managing-employees-admin-only)
10. [Settings](#10-settings-admin-only)
11. [Additional shop tools](#11-additional-shop-tools)
12. [Daily routine](#12-daily-routine)
13. [Common problems](#13-common-problems)

---

## 1. Signing in

1. Open the browser and go to `http://localhost/laundry-pos/`
2. Enter your username and password.
3. Click the 👁️ icon to reveal the password if you want to check it.
4. Click **Sign In**.

| Account | Username | Password | Access |
|---|---|---|---|
| Administrator | `admin` | `admin123` | Everything |
| Cashier | `staff` | `staff123` | POS, orders, customers, services |

> 🔒 **Change these passwords on the first day of operation.**
> - Your own password: click **My Account** at the bottom of the sidebar.
> - Other people's passwords: **Employees** (administrator only).

To sign out, click **Logout** at the bottom of the sidebar.

If sign-in fails, check that the Caps Lock key is off and that MySQL is running in
XAMPP or Laragon. See [Common problems](#14-common-problems).

---

## 2. Understanding the screen

```
┌───────────────────────────────────────────────────────────────┐
│ 🧺 Pia's Laundry Shop     │ ☰  Page Title            Friday…  │
│    POS & Management       │─────────────────────────────────── │
│                           │  [green/red/blue message banners]  │
│  📊 Dashboard             │                                    │
│  🛒 Point of Sale         │        MAIN WORKING AREA           │
│  🧾 Orders                │                                    │
│  👥 Customers             │                                    │
│  👕 Services              │                                    │
│  💸 Expenses      (admin) │                                    │
│  📈 Reports       (admin) │                                    │
│  👤 Employees     (admin) │                                    │
│  ⚙️ Settings      (admin) │                                    │
│                           │                                    │
│  (A) Maria Santos         │                                    │
│  Administrator            │                                    │
│  🔑 My Account  ⏻ Logout  │                                    │
└───────────────────────────────────────────────────────────────┘
```

- **Sidebar** — the menu. Items marked *(admin)* are hidden from cashiers.
- **☰** — on a narrow screen or tablet, tap this to show or hide the sidebar.
- **Message banners** — appear at the top after every action. Green = success,
  red = error, blue = information. They fade away automatically.
- The **highlighted** menu item is the page you are on.

---

## 3. Recording a sale (Point of Sale)

Go to **🛒 Point of Sale**.

### Step 1 — Add services

Tap a service button to add it to the cart. Services are grouped by category
(Wash & Fold, Ironing & Press, Dry Cleaning).

- Tapping the same service again increases its quantity.
- Use the **−** and **+** buttons to adjust, or type a number directly.
- For kilogram services enter the weight (decimals are allowed, e.g. `4.5`).
- Use **✕** to remove a line.

The right-hand panel updates the subtotal as you go.

### Step 2 — Choose the order type

| Type | When to use |
|---|---|
| **Walk-in** | The customer is present, or will return without a set appointment |
| **Drop-off** | You should record an **Expected Pickup** date and time |

### Step 3 — Choose the customer

Open the **Customer** dropdown.

- Pick a saved customer so the sale counts towards their order history and total
  spent.
- **"— Walk-in customer —"** creates the order with no linked customer. The sale
  will not count towards any customer's history.
- **"+ New customer…"** reveals fields to create a customer on the spot — name and
  phone. They are saved to **Customers** automatically.

### Step 4 — Payment

Choose **Cash**, **GCash**, **Maya** or **Card**.

- For **Cash**, type the amount tendered. The **Change** figure appears
  automatically. The system will not accept a tendered amount smaller than the total.
- For GCash, Maya and Card, the amount paid is taken as the full total and change is
  zero.

### Step 5 — Discount and notes

- Enter a **Discount** in pesos if you are giving one. It cannot exceed the subtotal.
- Use **Notes** for anything the person doing the laundry must know, for example
  *"Handle with care — delicates"*. Notes are internal; they are never shown to a
  guest tracking an order.

### Step 6 — Complete the sale

Click **💵 Complete Sale**.

What happens:

1. The order is saved with its line items.
2. An order number is generated: `ORD-YYYYMMDD-NNN` (e.g. `ORD-20260918-003`).
3. A **status timeline** entry "Order created via POS" is written.
4. The **receipt** opens in a new browser tab, ready to print.

The cart then clears itself and the screen is ready for the next customer.

### Printing the receipt

The receipt is formatted for an **80 mm thermal printer**. In the print dialog choose
that printer, and disable headers and footers if your browser adds them.

To reprint later: **Orders** → open the order → **🖨️ Receipt**.

---

## 4. Managing orders

### Finding an order

Go to **🧾 Orders**. Use the filters at the top:

- **Status** — Pending, In Progress, Ready for Pickup, Picked Up, Cancelled
- **Type** — Walk-in or Drop-off
- **Date range** — from and to
- **Search** — order number or customer name

The list shows the order number, date, customer, type, status, total and payment
method. Click any row's **View** to open it.

### Status colours

| Badge | Meaning |
|---|---|
| Pending | Received, not started |
| In Progress | Being washed, dried or ironed |
| Ready for Pickup | Finished, waiting for the customer |
| Picked Up | Collected — the order is complete |
| Cancelled | Will not be processed |

### Changing an order's status

1. Open the order.
2. In **Update Status**, click the next stage.

```
 Pending ──► In Progress ──► Ready for Pickup ──► Picked Up
```

Buttons for stages you have already passed are disabled — the workflow cannot move
backwards.

Each click will:

- Save the new status
- Add a **timeline** entry recording who made the change and when
- Show a green banner confirming the change

### Cancelling an order

Click **✕ Cancel order** and confirm. Cancelled orders keep their record for
reporting but are excluded from sales totals.

### The Status Timeline

Every order shows its complete history:

```
 ● Pending            Order created via POS
   Sep 18, 2026 1:44 PM · by Maria Santos

 ● In Progress        Status changed to In Progress
   Sep 18, 2026 1:50 PM · by Maria Santos

 ● Ready for Pickup   Status changed to Ready for Pickup
   Sep 18, 2026 3:10 PM · by Administrator
```

This settles any dispute about when an order was received or finished, and who
handled it.

---

## 5. Managing customers

Go to **👥 Customers**.

### Adding a customer

Fill in the form on the left:

| Field | Notes |
|---|---|
| Full Name * | Required |
| Phone | Shown beside the name in the POS customer dropdown |
| Email | Optional. Must be valid and unique when given |
| Address | Optional |
| Active customer | Untick to disable the record without deleting it |

Click **Save Customer**.

### Editing

Click **Edit** on any row. The form loads their details; change what you need and
click **Update Customer**.

### Reading the list

Inactive records are marked **inactive** beside the name. The **Orders** and **Total Spent** columns identify your regular customers.

### Searching

Type a name, phone number or email fragment into **Search…** and press Enter.

### Deleting

Administrators only. Click **Delete** and confirm. The customer's past orders are
**kept** — they simply lose the link to the customer record — so your sales history
stays intact.

---

## 6. Managing services and prices

Go to **👕 Services**.

| Field | Notes |
|---|---|
| Name | Shown on the POS button and on receipts |
| Category | Groups the POS display, e.g. Wash & Fold, Dry Cleaning |
| Unit | **kg** or **piece** — decides how quantity is entered |
| Price | In pesos |
| Description | A short helper line |
| Active | Untick to hide it from the POS without deleting it |

**Changing a price does not rewrite past orders.** Each line item stores its own copy
of the service name, unit and price at the moment of sale, so old receipts stay
accurate.

Deactivating is better than deleting: it keeps history clean and the service can be
re-activated later.

---

## 7. Recording expenses (Admin only)

Go to **💸 Expenses**.

1. Enter the **Description**, e.g. *Laundry detergent (5kg)*.
2. Choose or type a **Category**, e.g. Supplies, Utilities, Rent, Salaries,
   Repairs, Transportation.
3. Enter the **Amount**, **Expense date** and **Paid using** method. Choose Cash when
   paying from the till so the payment method is recorded accurately.
4. Click **Save**.

The page groups the month's expenses by category and shows the monthly total. Use the
month selector to review earlier periods. Expenses feed directly into the net income
figure on Reports and the Dashboard.

Consistent category names make the reports far more useful — try to reuse existing
categories rather than inventing new spellings.

---

## 8. Reports and dashboard

### Dashboard

Shown immediately after signing in:

| Card | Shows |
|---|---|
| Today's Revenue | Non-cancelled sales and number of orders so far today |
| Pending | Orders received but not yet started, with a link to those orders |
| In Progress | Orders being processed, with a link to those orders |
| Ready for Pickup | Orders waiting to be claimed, with a link to the pickup queue |
| This Month's Revenue | Non-cancelled revenue for the current month |
| This Month's Expenses (admin) | Expenses recorded for the current month |
| Total Customers | Active customer records |

Plus a **7-day revenue chart** and a list of **recent orders**.

### Reports

Go to **📈 Reports** and choose a date range. You get:

- **Sales** — total revenue and order count for the period
- **Expenses** — total for the period
- **Net income** — the difference
- **Top services** — by revenue and by number of times sold
- **Payment methods** — how customers are paying
- **Expense breakdown** — by category

Use this for the shop's daily closing, weekly review and monthly accounting.

---

## 9. Managing employees (Admin only)

Go to **👤 Employees**.

### Adding a user

| Field | Notes |
|---|---|
| Full Name | Appears in the sidebar and in every audit trail entry |
| Username | What they type to sign in. Must be unique |
| Password | Give them something temporary and ask them to change it |
| Email / Phone | Optional |
| Role | **Admin** = everything. **Staff** = POS, orders, customers, services |
| Active | Untick to block sign-in immediately without deleting the account |

### Choosing a role

Give **Staff** to cashiers. They can do every daily task but cannot see reports,
expenses, other people's accounts, or the shop settings. Reserve **Admin** for the
owner or manager.

### Someone left the shop

Untick **Active** rather than deleting. Their past actions stay attributed to them in
the timeline and logs, but they can no longer sign in.

---

## 10. Settings (Admin only)

Go to **⚙️ Settings**.

### Shop Information

Shop name, address, phone and the receipt footer note. These appear on receipts, in
the sidebar and in the browser title — so getting the shop name right here updates
the whole system at once. Click **Save Settings**. Set the optional **Public order
tracking URL** to the internet address customers can reach; it is printed on receipts.

### About this System

Shows the project title and points to `README.md` and the `docs/` folder for setup
instructions and documentation.

---

## 11. Additional shop tools

### Backup and restore (Admin)

Open **Backup & Restore** and download a JSON backup regularly. Keep the file in a
separate, private location. Restoring replaces the current database records, so make
a fresh backup first. Select the file and type `RESTORE` to confirm.

### Cancellations and refunds

When cancelling an order, enter a reason. Administrators can also record a refund at
that time, or record a partial refund from the order page later. Each refund requires
an amount, method and reason. The order page keeps the adjustment history; Reports
subtract refunds from active-order sales and exclude cancelled orders.

### Customer notifications and order lookup

When an order is **Ready for Pickup**, open it and choose **Open SMS app**. Review and
send the prepared message from the phone's messaging app; the system does not send
texts automatically. The receipt also shows the order lookup page. Customers enter
the order number and the phone number linked to the order to see its status. The shop
website must be reachable from the customer's phone for lookup to work.

## 12. Daily routine

### Opening

1. Start the stack:
   - **XAMPP** — open the XAMPP Control Panel and click **Start** next to Apache and MySQL.
   - **Laragon** — click **Start All**.
2. Open `http://localhost/laundry-pos/` and sign in.
3. Check the **Dashboard** — note any orders left **Pending** or **Ready for Pickup**
   from yesterday.

### During the day

| When | Do this |
|---|---|
| A customer drops off laundry | Record it in the POS as **Drop-off**, choose the customer, set the expected pickup time |
| Laundry goes into the machine | Open the order → **In Progress** |
| Laundry is finished and folded | Open the order → **Ready for Pickup** |
| A customer collects their laundry | Open the order → **Picked Up** |
| You buy supplies or pay a bill | **Expenses** → record it (admin) |
| A regular customer is not yet on file | **Customers** → add them so their orders are tracked |

### Closing

1. Check **Orders** for anything still **Ready for Pickup** and set the bundles
   aside for tomorrow.
2. Review the **Dashboard** for today's sales and order count.
3. Run **Reports** for the day if you keep a cash book.
4. Sign out.

### Weekly

- Review **Reports** for the week: sales, expenses, net income, top services.
- Look at **Customers → Total Spent** to identify regulars.

---

## 13. Common problems

### "I can't sign in even though the password is correct"

- Check Caps Lock.
- Confirm MySQL is running (XAMPP Control Panel, or Laragon **Start All**).
- Confirm your account is **Active** (an administrator can check under **Employees**).
- As a last resort an administrator can open `reset_password.php` in the browser to
  restore the default passwords. **Delete that file afterwards.**

### "The page says the database connection failed"

MySQL is not running, or the credentials do not match. Start MySQL (XAMPP Control
Panel → **Start** next to MySQL, or Laragon → **Start All**), then check
`config/database.php`. Both XAMPP and Laragon default to user `root` with an
**empty** password and database `laundry_pos`.

### "The POS says the order could not be saved"

This normally means the database was interrupted mid-write. Reload the page and try
again. If it persists, check that MySQL is still running and that the disk is not
full.

### "I can't see Reports or Expenses"

You are signed in as **Staff**. Those areas are for administrators only.

### "The receipt prints too wide"

Choose the 80 mm thermal printer in the print dialog, set margins to *None* or
*Minimum*, and turn off headers and footers.

### "I deleted a customer by mistake"

Their past orders are still in the system — only the link to the customer record was
removed. Re-create the customer; the historical sales remain in your reports.

### "I re-imported the SQL file and lost my data"

Importing `database/laundry_pos.sql` **drops and recreates every table**. Only do it
for a fresh installation. To keep data, export a backup from phpMyAdmin
(**Export → Go**) before any re-import.

---

## Quick reference card

| I want to… | Go to |
|---|---|
| Record a sale | 🛒 Point of Sale |
| Reprint a receipt | 🧾 Orders → open order → 🖨️ Receipt |
| Mark laundry as finished | 🧾 Orders → open order → **Ready for Pickup** |
| Update a customer's details | 👥 Customers → Edit |
| Change a price | 👕 Services |
| Record a purchase or a bill | 💸 Expenses |
| See today's sales and net income | 📊 Dashboard |
| See a period's full report | 📈 Reports |
| Add a cashier | 👤 Employees |
| Change the shop name on receipts | ⚙️ Settings |
| Change my own password | 🔑 My Account |
