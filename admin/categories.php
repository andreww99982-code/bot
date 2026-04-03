<?php
require_once __DIR__ . '/_auth.php';
$pageTitle = 'Categories';

$msg   = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $nameRu  = trim($_POST['name_ru']  ?? '');
        $nameEn  = trim($_POST['name_en']  ?? '');
        $sort    = (int)($_POST['sort_order'] ?? 0);
        $enabled = isset($_POST['enabled']);

        if ($nameRu === '') {
            $error = 'Russian name is required.';
        } else {
            $cat = [
                'id'         => $id ?: Storage::nextCategoryId(),
                'name_ru'    => $nameRu,
                'name_en'    => $nameEn ?: $nameRu,
                'enabled'    => $enabled,
                'sort_order' => $sort,
            ];
            Storage::saveCategory($cat);
            $msg = 'Category saved.';
        }

    } elseif ($action === 'toggle') {
        $id  = (string)($_POST['id'] ?? '');
        $cat = Storage::getCategory($id);
        if ($cat) {
            $cat['enabled'] = !$cat['enabled'];
            Storage::saveCategory($cat);
            $msg = 'Status updated.';
        }
    }
}

$editCat    = null;
$editId     = $_GET['edit'] ?? null;
if ($editId !== null) {
    $editCat = Storage::getCategory($editId);
}

$categories = Storage::allCategories();
require_once __DIR__ . '/_header.php';
?>

<?php if ($msg):  ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Add / Edit Form -->
<div class="card">
  <div class="card-header">
    <?= $editCat ? 'Edit Category' : 'Add Category' ?>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editCat['id'] ?? 0) ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:16px">
        <div class="form-row" style="margin:0">
          <label>Name (RU) *</label>
          <input type="text" name="name_ru" value="<?= htmlspecialchars($editCat['name_ru'] ?? '') ?>" required>
        </div>
        <div class="form-row" style="margin:0">
          <label>Name (EN)</label>
          <input type="text" name="name_en" value="<?= htmlspecialchars($editCat['name_en'] ?? '') ?>">
        </div>
        <div class="form-row" style="margin:0">
          <label>Sort</label>
          <input type="number" name="sort_order" value="<?= (int)($editCat['sort_order'] ?? 0) ?>">
        </div>
      </div>
      <div style="margin-top:12px;display:flex;align-items:center;gap:24px">
        <label style="margin:0;display:flex;align-items:center;gap:6px;font-weight:400">
          <input type="checkbox" name="enabled" <?= !empty($editCat['enabled']) || $editCat === null ? 'checked' : '' ?>>
          Enabled
        </label>
        <button type="submit" class="btn btn-primary">💾 Save</button>
        <?php if ($editCat): ?>
          <a href="categories.php" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- List -->
<div class="card">
  <div class="card-header">All Categories</div>
  <div class="card-body" style="padding:0">
    <table>
      <thead>
        <tr><th>ID</th><th>Name (RU)</th><th>Name (EN)</th><th>Sort</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $cat): ?>
        <tr>
          <td><?= (int)$cat['id'] ?></td>
          <td><?= htmlspecialchars($cat['name_ru']) ?></td>
          <td><?= htmlspecialchars($cat['name_en']) ?></td>
          <td><?= (int)$cat['sort_order'] ?></td>
          <td>
            <span class="badge <?= $cat['enabled'] ? 'badge-green' : 'badge-red' ?>">
              <?= $cat['enabled'] ? 'Enabled' : 'Disabled' ?>
            </span>
          </td>
          <td>
            <a href="?edit=<?= $cat['id'] ?>" class="btn btn-secondary btn-sm">✏️ Edit</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $cat['id'] ?>">
              <button type="submit" class="btn <?= $cat['enabled'] ? 'btn-danger' : 'btn-success' ?> btn-sm">
                <?= $cat['enabled'] ? '🚫 Disable' : '✅ Enable' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
