<?php
/**
 * Page header + sidebar navigation.
 * Expects optional $pageTitle and $pageSubtitle set before inclusion.
 */
$user     = current_user();
$shopName = get_setting('shop_name', "Pia's Laundry Shop");
$active   = basename($_SERVER['PHP_SELF'] ?? '');
$prefix   = asset_prefix();   // '' at root, '../' inside sub-folders
$notificationSummary = db()->prepare("SELECT COALESCE(MAX(id), 0) AS latest_id, COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_count FROM notifications WHERE audience = 'staff' AND audience_id = ?");
$notificationSummary->execute([(int)$user['id']]);
$notificationState = $notificationSummary->fetch() ?: ['latest_id' => 0, 'unread_count' => 0];

$nav = [
    ['Dashboard',    'dashboard.php',  '&#128202;', false],
    ['Manage Service','orders.php',    '&#129534;', false],
    ['Notifications','notifications.php','&#128276;', false],
    ['Customers',    'customers.php',  '&#128101;', false],
    ['Edit Services','services.php',   '&#128085;', true],
    ['Reports',      'reports.php',    '&#128200;', true],
    ['Employees',    'employees.php',  '&#128100;', true],
    ['Delivery Drivers', 'delivery_drivers.php', '&#128666;', false],
    ['Reviews',      'reviews.php',     '&#11088;', true],
    ['Settings',     'settings.php',   '&#9881;', true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' Â· ' . e($shopName) : e($shopName) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>?v=booking-history-20261117">
</head>
<body>
<div class="app" data-notification-poll-url="<?= e($prefix) ?>notification_poll.php" data-notification-latest-id="<?= (int)$notificationState['latest_id'] ?>">
  <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="brand">
      <span class="brand-icon">&#129530;</span>
      <div class="brand-text">
        <div class="brand-name"><?= e($shopName) ?></div>
        <div class="brand-sub">Laundry Management</div>
      </div>
    </div>

    <nav class="nav">
      <?php foreach ($nav as $item):
          [$label, $href, $icon, $adminOnly] = $item; $iconClass = 'nav-icon-' . preg_replace('/[^a-z0-9_-]/i', '', pathinfo($href, PATHINFO_FILENAME));
          if ($adminOnly && !is_admin()) continue;
          $isActive = ($active === $href || $active === basename($href)) ? ' active' : '';
      ?>
      <a class="nav-link<?= $isActive ?>" href="<?= e($prefix . $href) ?>">
        <span class="nav-icon <?= e($iconClass) ?>"><?= $icon ?></span>
        <span><?= e($label) ?></span><?php if ($href === 'notifications.php'): ?><span class="notification-count" data-notification-count <?= (int)$notificationState['unread_count'] > 0 ? '' : 'hidden' ?>><?= (int)$notificationState['unread_count'] ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></div>
        <div class="user-meta">
          <div class="user-name"><?= e($user['full_name']) ?></div>
          <div class="user-role"><?= $user['role'] === 'admin' ? 'Administrator' : 'Staff' ?></div>
        </div>
      </div>
      <a class="nav-link small" href="<?= e($prefix) ?>profile.php"><span class="nav-icon nav-icon-profile">&#128273;</span><span>My Account</span></a>
      <a class="nav-link small" href="<?= e($prefix) ?>logout.php"><span class="nav-icon nav-icon-logout">&#9211;</span><span>Logout</span></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false"><span></span><span></span><span></span></button>
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
