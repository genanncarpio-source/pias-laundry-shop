<?php
/**
 * Page header + sidebar navigation.
 * Expects optional $pageTitle and $pageSubtitle set before inclusion.
 */
$user     = current_user();
$shopName = get_setting('shop_name', "Pia's Laundry Shop");
$active   = basename($_SERVER['PHP_SELF'] ?? '');
$prefix   = asset_prefix();   // '' at root, '../' inside sub-folders

$nav = [
    ['Backup & Restore', 'backup.php', '💾', true],
    ['Dashboard',    'dashboard.php',  '📊', false],
    ['New Service Ticket','pos.php',        '🧺', false],
    ['Laundry Tickets',   'orders.php',     '🧾', false],
    ['Customers',    'customers.php',  '👥', false],
    ['Services',     'services.php',   '👕', false],
    ['Expenses',     'expenses.php',   '💸', true],
    ['Reports',      'reports.php',    '📈', true],
    ['Employees',    'employees.php',  '👤', true],
    ['Settings',     'settings.php',   '⚙️', true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' · ' . e($shopName) : e($shopName) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>?v=5">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-icon">🧺</span>
      <div class="brand-text">
        <div class="brand-name"><?= e($shopName) ?></div>
        <div class="brand-sub">Laundry Shop Management</div>
      </div>
    </div>

    <nav class="nav">
      <?php foreach ($nav as $item):
          [$label, $href, $icon, $adminOnly] = $item;
          if ($adminOnly && !is_admin()) continue;
          $isActive = ($active === $href || $active === basename($href)) ? ' active' : '';
      ?>
      <a class="nav-link<?= $isActive ?>" href="<?= e($prefix . $href) ?>">
        <span class="nav-icon"><?= $icon ?></span>
        <span><?= e($label) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div>
        <div class="user-meta">
          <div class="user-name"><?= e($user['full_name']) ?></div>
          <div class="user-role"><?= $user['role'] === 'admin' ? 'Administrator' : 'Cashier / Staff' ?></div>
        </div>
      </div>
      <a class="nav-link small" href="<?= e($prefix) ?>profile.php"><span class="nav-icon">🔑</span><span>My Account</span></a>
      <a class="nav-link small" href="<?= e($prefix) ?>logout.php"><span class="nav-icon">⏻</span><span>Logout</span></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">☰</button>
      <div class="topbar-title">
        <h1><?= isset($pageTitle) ? e($pageTitle) : 'Dashboard' ?></h1>
        <?php if (isset($pageSubtitle)): ?><p><?= e($pageSubtitle) ?></p><?php endif; ?>
      </div>
      <div class="topbar-right">
        <span class="today"><?= e(date('l, F j, Y')) ?></span>
      </div>
    </header>

    <?php foreach (flash_get() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>" data-auto-dismiss><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <main class="content">
