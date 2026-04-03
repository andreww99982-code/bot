<?php
require_once __DIR__ . '/_auth.php';
$pageTitle = 'Products';

$msg   = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $catId       = (int)($_POST['category_id'] ?? 0);
        $nameRu      = trim($_POST['name_ru']      ?? '');
        $nameEn      = trim($_POST['name_en']      ?? '');
        $descRu      = trim($_POST['description_ru'] ?? '');
        $descEn      = trim($_POST['description_en'] ?? '');
        $price       = (float)str_replace(',', '.', $_POST['price'] ?? '0');
        $fileContent = trim($_POST['file_content'] ?? '');
        $enabled     = isset($_POST['enabled']);

        if ($nameRu === '' || $catId === 0 || $price <= 0) {
            $error = 'Russian name, category, and a positive price are required.';
        } else {
            // Fetch existing product to preserve file_path
            $existing = $id ? Storage::getProduct((string)$id) : null;

            $product = [
                'id'             => $id ?: Storage::nextProductId(),
                'category_id'    => $catId,
                'name_ru'        => $nameRu,
                'name_en'        => $nameEn ?: $nameRu,
                'description_ru' => $descRu,
                'description_en' => $descEn ?: $descRu,
                'price'          => $price,
                'file_path'      => $existing['file_path'] ?? '',
                'file_content'   => $fileContent,
                'enabled'        => $enabled,
                'created_at'     => $existing['created_at'] ?? time(),
            ];

            // Handle file upload
            if (!empty($_FILES['product_file']['tmp_name'])) {
                $ext      = pathinfo($_FILES['product_file']['name'], PATHINFO_EXTENSION);
                $allowed  = ['zip','rar','7z','pdf','txt','jpg','jpeg','png','gif','mp3','mp4','xlsx','docx','csv'];
                if (!in_array(strtolower($ext), $allowed)) {
                    $error = 'File type not allowed.';
                } elseif ($_FILES['product_file']['size'] > 50 * 1024 * 1024) {
                    $error = 'File must be under 50 MB.';
                } else {
                    $filename = 'product_' . $product['id'] . '_' . time() . '.' . $ext;
                    $dest     = UPLOAD_DIR . '/' . $filename;
                    if (move_uploaded_file($_FILES['product_file']['tmp_name'], $dest)) {
                        // Remove old file if it exists
                        if (!empty($existing['file_path'])) {
                            @unlink(UPLOAD_DIR . '/' . $existing['file_path']);
                        }
                        $product['file_path'] = $filename;
                    } else {
                        $error = 'File upload failed. Check uploads/ directory permissions.';
                    }
                }
            }

            if (!$error) {
                Storage::saveProduct($product);
                $msg = 'Product saved.';
            }
        }

    } elseif ($action === 'toggle') {
        $id   = (string)($_POST['id'] ?? '');
        $prod = Storage::getProduct($id);
        if ($prod) {
            $prod['enabled'] = !$prod['enabled'];
            Storage::saveProduct($prod);
            $msg = 'Status updated.';
        }
    }
}

$editProd = null;
$editId   = $_GET['edit'] ?? null;
if ($editId !== null) {
    $editProd = Storage::getProduct($editId);
}

$products   = Storage::allProducts();
$categories = Storage::allCategories();
require_once __DIR__ . '/_header.php';
?>

<?php if ($msg):  ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Add / Edit Form -->
<div class="card">
  <div class="card-header"><?= $editProd ? 'Edit Product' : 'Add Product' ?></div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editProd['id'] ?? 0) ?>">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-row">
          <label>Name (RU) *</label>
          <input type="text" name="name_ru" value="<?= htmlspecialchars($editProd['name_ru'] ?? '') ?>" required>
        </div>
        <div class="form-row">
          <label>Name (EN)</label>
          <input type="text" name="name_en" value="<?= htmlspecialchars($editProd['name_en'] ?? '') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-row">
          <label>Description (RU)</label>
          <textarea name="description_ru"><?= htmlspecialchars($editProd['description_ru'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
          <label>Description (EN)</label>
          <textarea name="description_en"><?= htmlspecialchars($editProd['description_en'] ?? '') ?></textarea>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-row">
          <label>Category *</label>
          <select name="category_id" required>
            <option value="">— Select —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"
                <?= (string)($editProd['category_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name_ru']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label>Price (<?= CURRENCY_SIGN ?>) *</label>
          <input type="number" name="price" step="0.01" min="0.01"
                 value="<?= htmlspecialchars($editProd['price'] ?? '') ?>" required>
        </div>
        <div class="form-row">
          <label>Upload File</label>
          <input type="file" name="product_file">
          <?php if (!empty($editProd['file_path'])): ?>
            <small style="color:#6b7280">Current: <?= htmlspecialchars($editProd['file_path']) ?></small>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-row">
        <label>Text/Key Content (delivered as text after purchase)</label>
        <textarea name="file_content" rows="3"><?= htmlspecialchars($editProd['file_content'] ?? '') ?></textarea>
      </div>

      <div style="display:flex;align-items:center;gap:24px;margin-top:8px">
        <label style="margin:0;display:flex;align-items:center;gap:6px;font-weight:400">
          <input type="checkbox" name="enabled"
            <?= !empty($editProd['enabled']) || $editProd === null ? 'checked' : '' ?>>
          Enabled
        </label>
        <button type="submit" class="btn btn-primary">💾 Save</button>
        <?php if ($editProd): ?>
          <a href="products.php" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- List -->
<div class="card">
  <div class="card-header">All Products</div>
  <div class="card-body" style="padding:0">
    <table>
      <thead>
        <tr><th>ID</th><th>Name (RU)</th><th>Category</th><th>Price</th><th>File</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($products as $p):
        $cat = Storage::getCategory((string)$p['category_id']);
      ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= htmlspecialchars($p['name_ru']) ?></td>
          <td><?= htmlspecialchars($cat['name_ru'] ?? '—') ?></td>
          <td><?= htmlspecialchars($p['price']) ?> <?= CURRENCY_SIGN ?></td>
          <td><?= !empty($p['file_path']) ? '📎' : (!empty($p['file_content']) ? '🔑' : '—') ?></td>
          <td>
            <span class="badge <?= $p['enabled'] ? 'badge-green' : 'badge-red' ?>">
              <?= $p['enabled'] ? 'Enabled' : 'Disabled' ?>
            </span>
          </td>
          <td>
            <a href="?edit=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">✏️ Edit</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn <?= $p['enabled'] ? 'btn-danger' : 'btn-success' ?> btn-sm">
                <?= $p['enabled'] ? '🚫 Disable' : '✅ Enable' ?>
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
