<?php
/**
 * _header.php — Admin panel HTML header.
 * Variables expected: $pageTitle (string)
 */
$pageTitle = $pageTitle ?? 'Admin';
$settings  = Storage::getSettings();
$shopName  = htmlspecialchars($settings['shop_name'] ?? 'Digital Shop');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — <?= $shopName ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
       background: #f0f2f5; color: #1a1a2e; min-height: 100vh; }
a { color: #4f46e5; text-decoration: none; }
a:hover { text-decoration: underline; }

/* Layout */
.layout { display: flex; min-height: 100vh; }
.sidebar { width: 220px; background: #1a1a2e; color: #cdd5f0; flex-shrink: 0;
           display: flex; flex-direction: column; }
.sidebar .brand { padding: 20px 16px 12px; font-size: 17px; font-weight: 700;
                  color: #fff; border-bottom: 1px solid #2d2d4e; }
.sidebar .brand span { color: #818cf8; }
.sidebar nav { padding: 12px 0; flex: 1; }
.sidebar nav a { display: flex; align-items: center; gap: 10px; padding: 10px 16px;
                 color: #cdd5f0; font-size: 14px; transition: background 0.15s; }
.sidebar nav a:hover, .sidebar nav a.active { background: #2d2d4e; color: #fff; text-decoration: none; }
.sidebar nav a .icon { font-size: 16px; width: 20px; text-align: center; }
.sidebar .logout { padding: 12px 16px; border-top: 1px solid #2d2d4e; }
.sidebar .logout a { color: #f87171; font-size: 14px; }

.main { flex: 1; display: flex; flex-direction: column; }
.topbar { background: #fff; padding: 14px 24px; border-bottom: 1px solid #e5e7eb;
          font-size: 14px; color: #6b7280; }
.content { padding: 24px; }

/* Cards */
.card { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08);
        margin-bottom: 24px; overflow: hidden; }
.card-header { padding: 16px 20px; border-bottom: 1px solid #f3f4f6;
               font-weight: 600; font-size: 15px; display: flex; align-items: center;
               justify-content: space-between; }
.card-body { padding: 20px; }

/* Table */
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th { background: #f9fafb; text-align: left; padding: 10px 14px;
     font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; }
td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafafa; }

/* Forms */
.form-row { margin-bottom: 16px; }
label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #374151; }
input[type=text], input[type=password], input[type=number], input[type=email],
textarea, select {
  width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px;
  font-size: 14px; color: #1f2937; background: #fff; transition: border-color 0.15s; }
input:focus, textarea:focus, select:focus { outline: none; border-color: #4f46e5; }
textarea { resize: vertical; min-height: 80px; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
       border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;
       border: none; transition: background 0.15s; text-decoration: none; }
.btn-primary  { background: #4f46e5; color: #fff; }
.btn-primary:hover  { background: #4338ca; }
.btn-success  { background: #10b981; color: #fff; }
.btn-success:hover  { background: #059669; }
.btn-danger   { background: #ef4444; color: #fff; }
.btn-danger:hover   { background: #dc2626; }
.btn-secondary{ background: #e5e7eb; color: #374151; }
.btn-secondary:hover{ background: #d1d5db; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

/* Alerts */
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.alert-info    { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

/* Badges */
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.badge-green  { background: #d1fae5; color: #065f46; }
.badge-red    { background: #fee2e2; color: #991b1b; }
.badge-gray   { background: #f3f4f6; color: #374151; }

/* Stats grid */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; }
.stat-card { background: #fff; border-radius: 10px; padding: 20px;
             box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.stat-card .value { font-size: 28px; font-weight: 700; color: #4f46e5; }
.stat-card .label { font-size: 13px; color: #6b7280; margin-top: 4px; }
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <div class="brand"><?= $shopName ?> <span>Admin</span></div>
  <nav>
    <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
      <span class="icon">🏠</span> Dashboard
    </a>
    <a href="categories.php" class="<?= basename($_SERVER['PHP_SELF']) === 'categories.php' ? 'active' : '' ?>">
      <span class="icon">📂</span> Categories
    </a>
    <a href="products.php" class="<?= basename($_SERVER['PHP_SELF']) === 'products.php' ? 'active' : '' ?>">
      <span class="icon">📦</span> Products
    </a>
    <a href="users.php" class="<?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>">
      <span class="icon">👥</span> Users
    </a>
    <a href="orders.php" class="<?= basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'active' : '' ?>">
      <span class="icon">🛒</span> Orders
    </a>
    <a href="settings.php" class="<?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : '' ?>">
      <span class="icon">⚙️</span> Settings
    </a>
  </nav>
  <div class="logout"><a href="logout.php">🚪 Logout</a></div>
</aside>
<div class="main">
  <div class="topbar">Admin Panel &nbsp;›&nbsp; <?= htmlspecialchars($pageTitle) ?></div>
  <div class="content">
