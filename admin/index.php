<?php
session_name(defined('ADMIN_SESSION_NAME') ? ADMIN_SESSION_NAME : 'bot_admin');
session_start();

// Load config if not loaded
if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once BASE_PATH . '/bot/storage.php';

// -------------------------------------------------------
// Auth
// -------------------------------------------------------
$isLoggedIn = !empty($_SESSION['admin_logged_in']);

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $login    = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($login === ADMIN_LOGIN && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $loginError = 'Invalid credentials.';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (!$isLoggedIn) {
    // Show login page
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>body{background:#f0f2f5}.card{border:none;box-shadow:0 4px 24px rgba(0,0,0,.08)}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card p-4" style="width:360px">
  <h4 class="mb-4 text-center fw-bold">🛒 Admin Panel</h4>
  <?php if (!empty($loginError)): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($loginError) ?></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <div class="mb-3"><label class="form-label">Login</label>
      <input class="form-control" type="text" name="login" required autofocus></div>
    <div class="mb-3"><label class="form-label">Password</label>
      <input class="form-control" type="password" name="password" required></div>
    <button class="btn btn-primary w-100" type="submit">Login</button>
  </form>
</div>
</body></html>
    <?php
    exit;
}

// -------------------------------------------------------
// Handle POST actions (CRUD)
// -------------------------------------------------------
$page   = $_GET['page'] ?? 'dashboard';
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$flash  = '';

// --- Category actions ---
if ($action === 'save_category') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $active = isset($_POST['active']) ? true : false;
    if ($name) {
        if (!$id) $id = Storage::nextCategoryId();
        Storage::saveCategory($id, [
            'id' => $id, 'name' => $name, 'name_en' => $nameEn,
            'description' => $desc, 'active' => $active,
            'created_at' => Storage::getCategory($id)['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $flash = 'Category saved.';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=categories&flash=' . urlencode($flash));
    exit;
}

if ($action === 'delete_category') {
    Storage::deleteCategory((int)($_GET['id'] ?? 0));
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=categories&flash=Category+deleted.');
    exit;
}

if ($action === 'toggle_category') {
    $id  = (int)($_GET['id'] ?? 0);
    $cat = Storage::getCategory($id);
    if ($cat) {
        $cat['active'] = !($cat['active'] ?? false);
        Storage::saveCategory($id, $cat);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=categories');
    exit;
}

// --- Product actions ---
if ($action === 'save_product') {
    $id     = (int)($_POST['id'] ?? 0);
    $catId  = (int)($_POST['category_id'] ?? 0);
    $title  = trim($_POST['title'] ?? '');
    $titleEn = trim($_POST['title_en'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $descEn = trim($_POST['description_en'] ?? '');
    $price  = (float)($_POST['price'] ?? 0);
    $active = isset($_POST['active']) ? true : false;

    if ($title && $catId && $price >= 0) {
        if (!$id) $id = Storage::nextProductId();

        $existing = Storage::getProduct($id) ?? [];
        $filePath = $existing['file_path'] ?? '';
        $fileName = $existing['file_name'] ?? '';

        // Handle file upload
        if (!empty($_FILES['product_file']['name'])) {
            $uploadDir = STORAGE_PATH . '/files/' . $id . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $origName = basename($_FILES['product_file']['name']);
            $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $origName);
            $destPath = $uploadDir . $safeName;

            if (move_uploaded_file($_FILES['product_file']['tmp_name'], $destPath)) {
                $filePath = 'storage/files/' . $id . '/' . $safeName;
                $fileName = $origName;
                // Clear old tg_file_id since file changed
                $existing['tg_file_id'] = '';
            }
        }

        Storage::saveProduct($id, array_merge($existing, [
            'id'             => $id,
            'category_id'    => $catId,
            'title'          => $title,
            'title_en'       => $titleEn,
            'description'    => $desc,
            'description_en' => $descEn,
            'price'          => $price,
            'file_path'      => $filePath,
            'file_name'      => $fileName,
            'active'         => $active,
            'created_at'     => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        ]));
        $flash = 'Product saved.';
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=products&flash=' . urlencode($flash));
    exit;
}

if ($action === 'delete_product') {
    Storage::deleteProduct((int)($_GET['id'] ?? 0));
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=products&flash=Product+deleted.');
    exit;
}

if ($action === 'toggle_product') {
    $id  = (int)($_GET['id'] ?? 0);
    $p   = Storage::getProduct($id);
    if ($p) {
        $p['active'] = !($p['active'] ?? false);
        Storage::saveProduct($id, $p);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=products');
    exit;
}

// --- Settings action ---
if ($action === 'save_settings') {
    $settings = Storage::getSettings();
    $settings['bot_name']           = trim($_POST['bot_name'] ?? '');
    $settings['currency']           = trim($_POST['currency'] ?? 'RUB');
    $settings['currency_symbol']    = trim($_POST['currency_symbol'] ?? '₽');
    $settings['min_deposit']        = (float)($_POST['min_deposit'] ?? 50);
    $settings['welcome_message_ru'] = trim($_POST['welcome_message_ru'] ?? '');
    $settings['welcome_message_en'] = trim($_POST['welcome_message_en'] ?? '');
    Storage::saveSettings($settings);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=settings&flash=Settings+saved.');
    exit;
}

if (isset($_GET['flash'])) $flash = htmlspecialchars($_GET['flash']);

// -------------------------------------------------------
// Data for current page
// -------------------------------------------------------
$categories = Storage::getCategories();
$products   = Storage::getProducts();
$users      = Storage::getUsers();
$orders     = Storage::getOrders();
$settings   = Storage::getSettings();

$stats = [
    'users'    => count($users),
    'products' => count($products),
    'orders'   => count($orders),
    'revenue'  => array_sum(array_column(array_filter($orders, fn($o) => $o['status'] === 'paid'), 'price')),
];

$editCat     = null;
$editProduct = null;
if ($page === 'categories' && isset($_GET['edit'])) {
    $editCat = Storage::getCategory((int)$_GET['edit']);
}
if ($page === 'products' && isset($_GET['edit'])) {
    $editProduct = Storage::getProduct((int)$_GET['edit']);
}

$currencySymbol = $settings['currency_symbol'] ?? '₽';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Panel — <?= htmlspecialchars($settings['bot_name'] ?? 'Digital Shop') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
body{background:#f0f2f5}
.sidebar{min-height:100vh;background:#1e293b;width:220px;position:fixed;left:0;top:0;bottom:0;z-index:100}
.sidebar .nav-link{color:#94a3b8;padding:.55rem 1.2rem;border-radius:6px;margin:.1rem .5rem}
.sidebar .nav-link:hover,.sidebar .nav-link.active{color:#fff;background:#334155}
.sidebar .brand{color:#fff;padding:1.2rem 1.2rem .7rem;font-weight:700;font-size:1.1rem}
.main{margin-left:220px;padding:2rem}
.stat-card{background:#fff;border:none;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07)}
table thead{background:#f8fafc}
</style>
</head>
<body>
<div class="sidebar d-flex flex-column">
  <div class="brand">🛒 Admin Panel</div>
  <nav class="nav flex-column">
    <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
    <a class="nav-link <?= $page==='categories'?'active':'' ?>" href="?page=categories"><i class="bi bi-folder me-2"></i>Categories</a>
    <a class="nav-link <?= $page==='products'?'active':'' ?>" href="?page=products"><i class="bi bi-box me-2"></i>Products</a>
    <a class="nav-link <?= $page==='users'?'active':'' ?>" href="?page=users"><i class="bi bi-people me-2"></i>Users</a>
    <a class="nav-link <?= $page==='orders'?'active':'' ?>" href="?page=orders"><i class="bi bi-receipt me-2"></i>Orders</a>
    <a class="nav-link <?= $page==='settings'?'active':'' ?>" href="?page=settings"><i class="bi bi-gear me-2"></i>Settings</a>
  </nav>
  <div class="mt-auto p-3">
    <a href="?logout=1" class="btn btn-sm btn-outline-secondary w-100 text-white border-secondary">Logout</a>
  </div>
</div>

<div class="main">
<?php if ($flash): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= $flash ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($page === 'dashboard'): ?>
<h4 class="mb-4 fw-bold">Dashboard</h4>
<div class="row g-3 mb-4">
  <?php
  $statItems = [
    ['icon'=>'bi-people','label'=>'Users','value'=>$stats['users'],'color'=>'primary'],
    ['icon'=>'bi-box','label'=>'Products','value'=>$stats['products'],'color'=>'success'],
    ['icon'=>'bi-receipt','label'=>'Orders','value'=>$stats['orders'],'color'=>'warning'],
    ['icon'=>'bi-currency-exchange','label'=>'Revenue','value'=>number_format($stats['revenue'],2).' '.$currencySymbol,'color'=>'danger'],
  ];
  foreach ($statItems as $s): ?>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card p-3">
      <div class="d-flex align-items-center">
        <div class="flex-shrink-0 me-3 bg-<?= $s['color'] ?> bg-opacity-10 text-<?= $s['color'] ?> rounded-3 p-2 fs-4">
          <i class="bi <?= $s['icon'] ?>"></i>
        </div>
        <div>
          <div class="text-muted small"><?= $s['label'] ?></div>
          <div class="fw-bold fs-5"><?= $s['value'] ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
      <h6 class="fw-bold mb-3">Recent Orders</h6>
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>ID</th><th>User</th><th>Product</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach (array_slice(array_reverse($orders), 0, 10) as $o): ?>
          <tr>
            <td><small><?= htmlspecialchars($o['order_id']) ?></small></td>
            <td><?= (int)$o['user_id'] ?></td>
            <td><?= htmlspecialchars(Storage::getProduct((int)$o['product_id'])['title'] ?? '#'.$o['product_id']) ?></td>
            <td><?= number_format((float)$o['price'],2) ?> <?= $currencySymbol ?></td>
            <td><small><?= htmlspecialchars($o['created_at']) ?></small></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
      <h6 class="fw-bold mb-3">Recent Users</h6>
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>ID</th><th>Name</th><th>Balance</th><th>Purchases</th></tr></thead>
        <tbody>
          <?php foreach (array_slice(array_reverse(array_values($users)), 0, 10) as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars(trim(($u['first_name']??'').' '.($u['last_name']??''))) ?></td>
            <td><?= number_format((float)($u['balance']??0),2) ?> <?= $currencySymbol ?></td>
            <td><?= (int)($u['purchases_count']??0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($page === 'categories'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="fw-bold mb-0">Categories</h4>
  <a href="?page=categories&edit=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Category</a>
</div>

<?php if ($editCat !== null || isset($_GET['edit'])): ?>
<?php $isNew = !$editCat; if ($isNew) $editCat = ['id'=>0,'name'=>'','name_en'=>'','description'=>'','active'=>true]; ?>
<div class="card p-4 mb-4" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <h6 class="fw-bold mb-3"><?= $isNew ? 'Add Category' : 'Edit Category' ?></h6>
  <form method="post" action="<?= $_SERVER['PHP_SELF'] ?>">
    <input type="hidden" name="action" value="save_category">
    <input type="hidden" name="id" value="<?= (int)$editCat['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Name (RU) <span class="text-danger">*</span></label>
        <input class="form-control" name="name" value="<?= htmlspecialchars($editCat['name']??'') ?>" required></div>
      <div class="col-md-6"><label class="form-label">Name (EN)</label>
        <input class="form-control" name="name_en" value="<?= htmlspecialchars($editCat['name_en']??'') ?>"></div>
      <div class="col-12"><label class="form-label">Description</label>
        <input class="form-control" name="description" value="<?= htmlspecialchars($editCat['description']??'') ?>"></div>
      <div class="col-12"><div class="form-check">
        <input class="form-check-input" type="checkbox" name="active" id="cat_active" <?= ($editCat['active']??true)?'checked':'' ?>>
        <label class="form-check-label" for="cat_active">Active</label>
      </div></div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save</button>
        <a href="?page=categories" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <table class="table table-hover mb-0">
    <thead><tr><th>ID</th><th>Name</th><th>Name EN</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($categories as $cat): ?>
      <tr>
        <td><?= (int)$cat['id'] ?></td>
        <td><?= htmlspecialchars($cat['name']) ?></td>
        <td><?= htmlspecialchars($cat['name_en']??'') ?></td>
        <td><span class="badge bg-<?= ($cat['active']??false)?'success':'secondary' ?>"><?= ($cat['active']??false)?'Active':'Inactive' ?></span></td>
        <td>
          <a href="?page=categories&edit=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
          <a href="?page=categories&action=toggle_category&id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-<?= ($cat['active']??false)?'warning':'success' ?>"><?= ($cat['active']??false)?'Disable':'Enable' ?></a>
          <a href="?page=categories&action=delete_category&id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($categories)): ?><tr><td colspan="5" class="text-center text-muted py-4">No categories yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($page === 'products'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="fw-bold mb-0">Products</h4>
  <a href="?page=products&edit=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
</div>

<?php if ($editProduct !== null || isset($_GET['edit'])): ?>
<?php $isNew = !$editProduct; if ($isNew) $editProduct = ['id'=>0,'category_id'=>0,'title'=>'','title_en'=>'','description'=>'','description_en'=>'','price'=>0,'active'=>true,'file_name'=>'']; ?>
<div class="card p-4 mb-4" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <h6 class="fw-bold mb-3"><?= $isNew ? 'Add Product' : 'Edit Product' ?></h6>
  <form method="post" action="<?= $_SERVER['PHP_SELF'] ?>" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_product">
    <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Title (RU) <span class="text-danger">*</span></label>
        <input class="form-control" name="title" value="<?= htmlspecialchars($editProduct['title']??'') ?>" required></div>
      <div class="col-md-6"><label class="form-label">Title (EN)</label>
        <input class="form-control" name="title_en" value="<?= htmlspecialchars($editProduct['title_en']??'') ?>"></div>
      <div class="col-md-6"><label class="form-label">Description (RU)</label>
        <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($editProduct['description']??'') ?></textarea></div>
      <div class="col-md-6"><label class="form-label">Description (EN)</label>
        <textarea class="form-control" name="description_en" rows="2"><?= htmlspecialchars($editProduct['description_en']??'') ?></textarea></div>
      <div class="col-md-4"><label class="form-label">Category <span class="text-danger">*</span></label>
        <select class="form-select" name="category_id" required>
          <option value="">— choose —</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (int)$editProduct['category_id']===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label">Price (<?= htmlspecialchars($currencySymbol) ?>) <span class="text-danger">*</span></label>
        <input class="form-control" type="number" step="0.01" min="0" name="price" value="<?= (float)($editProduct['price']??0) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Upload File</label>
        <input class="form-control" type="file" name="product_file">
        <?php if (!empty($editProduct['file_name'])): ?>
          <div class="form-text text-success"><i class="bi bi-paperclip"></i> Current: <?= htmlspecialchars($editProduct['file_name']) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-12"><div class="form-check">
        <input class="form-check-input" type="checkbox" name="active" id="prod_active" <?= ($editProduct['active']??true)?'checked':'' ?>>
        <label class="form-check-label" for="prod_active">Active</label>
      </div></div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save</button>
        <a href="?page=products" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <table class="table table-hover mb-0">
    <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Price</th><th>File</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($products as $p): ?>
      <tr>
        <td><?= (int)$p['id'] ?></td>
        <td><?= htmlspecialchars($p['title']) ?></td>
        <td><?= htmlspecialchars(Storage::getCategory((int)$p['category_id'])['name'] ?? '—') ?></td>
        <td><?= number_format((float)$p['price'],2) ?> <?= $currencySymbol ?></td>
        <td><?= !empty($p['file_name']) ? '<span class="text-success"><i class="bi bi-check-circle"></i> '.htmlspecialchars($p['file_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
        <td><span class="badge bg-<?= ($p['active']??false)?'success':'secondary' ?>"><?= ($p['active']??false)?'Active':'Inactive' ?></span></td>
        <td>
          <a href="?page=products&edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
          <a href="?page=products&action=toggle_product&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-<?= ($p['active']??false)?'warning':'success' ?>"><?= ($p['active']??false)?'Disable':'Enable' ?></a>
          <a href="?page=products&action=delete_product&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($products)): ?><tr><td colspan="7" class="text-center text-muted py-4">No products yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($page === 'users'): ?>
<h4 class="fw-bold mb-4">Users</h4>
<div class="card" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <table class="table table-hover mb-0">
    <thead><tr><th>ID</th><th>Name</th><th>Username</th><th>Lang</th><th>Balance</th><th>Purchases</th><th>Spent</th><th>Deposited</th><th>Joined</th></tr></thead>
    <tbody>
      <?php foreach (array_reverse(array_values($users)) as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= htmlspecialchars(trim(($u['first_name']??'').' '.($u['last_name']??''))) ?></td>
        <td><?= $u['username'] ? '@'.htmlspecialchars($u['username']) : '—' ?></td>
        <td><?= htmlspecialchars($u['language']??'ru') ?></td>
        <td><?= number_format((float)($u['balance']??0),2) ?> <?= $currencySymbol ?></td>
        <td><?= (int)($u['purchases_count']??0) ?></td>
        <td><?= number_format((float)($u['total_spent']??0),2) ?> <?= $currencySymbol ?></td>
        <td><?= number_format((float)($u['total_deposited']??0),2) ?> <?= $currencySymbol ?></td>
        <td><small><?= htmlspecialchars($u['created_at']??'') ?></small></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?><tr><td colspan="9" class="text-center text-muted py-4">No users yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($page === 'orders'): ?>
<h4 class="fw-bold mb-4">Orders</h4>
<div class="card" style="border:none;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <table class="table table-hover mb-0">
    <thead><tr><th>Order ID</th><th>User ID</th><th>Product</th><th>Price</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach (array_reverse($orders) as $o): ?>
      <tr>
        <td><small><?= htmlspecialchars($o['order_id']) ?></small></td>
        <td><?= (int)$o['user_id'] ?></td>
        <td><?= htmlspecialchars(Storage::getProduct((int)$o['product_id'])['title'] ?? '#'.$o['product_id']) ?></td>
        <td><?= number_format((float)$o['price'],2) ?> <?= $currencySymbol ?></td>
        <td><span class="badge bg-<?= $o['status']==='paid'?'success':'secondary' ?>"><?= htmlspecialchars($o['status']) ?></span></td>
        <td><small><?= htmlspecialchars($o['created_at']) ?></small></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?><tr><td colspan="6" class="text-center text-muted py-4">No orders yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($page === 'settings'): ?>
<h4 class="fw-bold mb-4">Settings</h4>
<div class="card p-4" style="border:none;max-width:700px;box-shadow:0 2px 12px rgba(0,0,0,.07)">
  <form method="post" action="<?= $_SERVER['PHP_SELF'] ?>?page=settings">
    <input type="hidden" name="action" value="save_settings">
    <div class="row g-3">
      <div class="col-12"><label class="form-label">Bot Name</label>
        <input class="form-control" name="bot_name" value="<?= htmlspecialchars($settings['bot_name']??'Digital Shop') ?>"></div>
      <div class="col-md-6"><label class="form-label">Currency Code (e.g. RUB, USD)</label>
        <input class="form-control" name="currency" value="<?= htmlspecialchars($settings['currency']??'RUB') ?>"></div>
      <div class="col-md-6"><label class="form-label">Currency Symbol (e.g. ₽, $)</label>
        <input class="form-control" name="currency_symbol" value="<?= htmlspecialchars($settings['currency_symbol']??'₽') ?>"></div>
      <div class="col-md-6"><label class="form-label">Min Deposit Amount</label>
        <input class="form-control" type="number" step="1" min="1" name="min_deposit" value="<?= (float)($settings['min_deposit']??50) ?>"></div>
      <div class="col-12"><label class="form-label">Welcome Message (Russian)</label>
        <textarea class="form-control" name="welcome_message_ru" rows="3"><?= htmlspecialchars($settings['welcome_message_ru']??'') ?></textarea></div>
      <div class="col-12"><label class="form-label">Welcome Message (English)</label>
        <textarea class="form-control" name="welcome_message_en" rows="3"><?= htmlspecialchars($settings['welcome_message_en']??'') ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Save Settings</button></div>
    </div>
  </form>
</div>
<?php endif; ?>

</div><!-- .main -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
