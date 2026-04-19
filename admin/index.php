<?php

declare(strict_types=1);

require_once '../config.php';

session_start();

function jsonResponse(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonFile(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function writeJsonFile(string $file, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = tempnam($dir, 'json_');
    if ($tmp === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function requireAuth(): void
{
    if (!($_SESSION['admin_auth'] ?? false)) {
        jsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function uuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function normalizeSettings(array $settings): array
{
    return [
        'admin_username' => (string) ($settings['admin_username'] ?? 'admin'),
        'currency' => strtoupper(substr(trim((string) ($settings['currency'] ?? 'USD')), 0, 5)) ?: 'USD',
        'currency_symbol' => substr(trim((string) ($settings['currency_symbol'] ?? '$')), 0, 5) ?: '$',
        'help_text' => [
            'ru' => (string) ($settings['help_text']['ru'] ?? ''),
            'en' => (string) ($settings['help_text']['en'] ?? ''),
        ],
    ];
}

function normalizeCategory(array $category): array
{
    $parentRaw = $category['parent_id'] ?? null;
    $parentId = ($parentRaw === null || $parentRaw === '') ? null : (string) $parentRaw;

    return [
        'id' => (string) ($category['id'] ?? ''),
        'parent_id' => $parentId,
        'name' => [
            'ru' => trim((string) ($category['name']['ru'] ?? '')),
            'en' => trim((string) ($category['name']['en'] ?? '')),
        ],
        'description' => [
            'ru' => trim((string) ($category['description']['ru'] ?? '')),
            'en' => trim((string) ($category['description']['en'] ?? '')),
        ],
    ];
}

function categoryStats(array $categories, array $products): array
{
    $productsCount = [];
    foreach ($products as $product) {
        $categoryId = (string) ($product['category_id'] ?? '');
        if ($categoryId !== '') {
            $productsCount[$categoryId] = (int) ($productsCount[$categoryId] ?? 0) + 1;
        }
    }

    $childrenCount = [];
    foreach ($categories as $category) {
        $parent = $category['parent_id'] ?? null;
        if ($parent !== null && $parent !== '') {
            $parentId = (string) $parent;
            $childrenCount[$parentId] = (int) ($childrenCount[$parentId] ?? 0) + 1;
        }
    }

    return [$productsCount, $childrenCount];
}

function collectStats(array $users, array $products): array
{
    $sales = 0;
    $revenue = 0.0;
    $latest = [];

    foreach ($users as $user) {
        $userId = (string) ($user['id'] ?? '');
        $userName = (string) ($user['first_name'] ?? 'User');
        foreach ((array) ($user['purchases'] ?? []) as $purchase) {
            $amount = (float) ($purchase['price'] ?? 0);
            $sales++;
            $revenue += $amount;
            $latest[] = [
                'date' => (string) ($purchase['date'] ?? ''),
                'user_id' => $userId,
                'user_name' => $userName,
                'product_name' => (string) ($purchase['product_name'] ?? ''),
                'amount' => round($amount, 2),
            ];
        }
    }

    usort($latest, static function (array $a, array $b): int {
        return strcmp((string) $b['date'], (string) $a['date']);
    });

    $activeProducts = 0;
    foreach ($products as $product) {
        if ((bool) ($product['active'] ?? false)) {
            $activeProducts++;
        }
    }

    return [
        'users_total' => count($users),
        'sales_total' => $sales,
        'revenue_total' => round($revenue, 2),
        'active_products' => $activeProducts,
        'latest_purchases' => array_slice($latest, 0, 10),
    ];
}

function topupAtomic(string $userId, float $amount, string &$error, float &$newBalance): bool
{
    $error = '';
    $newBalance = 0.0;

    $fp = @fopen(USERS_FILE, 'c+');
    if ($fp === false) {
        $error = 'users_file_open_failed';
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        $error = 'users_file_lock_failed';
        return false;
    }

    $raw = stream_get_contents($fp);
    $users = json_decode((string) $raw, true);
    if (!is_array($users)) {
        $users = [];
    }

    if (!isset($users[$userId])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $error = 'user_not_found';
        return false;
    }

    $users[$userId]['balance'] = round((float) ($users[$userId]['balance'] ?? 0) + $amount, 2);
    $newBalance = (float) $users[$userId]['balance'];

    $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $error = 'users_json_encode_failed';
        return false;
    }

    rewind($fp);
    if (!ftruncate($fp, 0) || fwrite($fp, $json) === false || !fflush($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $error = 'users_file_write_failed';
        return false;
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

if (isset($_GET['api'])) {
    $api = (string) $_GET['api'];

    if ($api === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && hash_equals((string) ADMIN_PASSWORD, $password)) {
            $_SESSION['admin_auth'] = true;
            jsonResponse(['ok' => true]);
        }
        jsonResponse(['ok' => false, 'error' => 'wrong_password'], 403);
    }

    if ($api === 'logout') {
        session_destroy();
        jsonResponse(['ok' => true]);
    }

    requireAuth();

    if ($api === 'get_categories') {
        $categories = array_map('normalizeCategory', array_values(readJsonFile(CATEGORIES_FILE)));
        $products = array_values(readJsonFile(PRODUCTS_FILE));
        [$productsCount, $childrenCount] = categoryStats($categories, $products);

        foreach ($categories as &$category) {
            $id = $category['id'];
            $category['products_count'] = (int) ($productsCount[$id] ?? 0);
            $category['children_count'] = (int) ($childrenCount[$id] ?? 0);
            $category['deletable'] = $category['products_count'] === 0 && $category['children_count'] === 0;
        }
        unset($category);

        jsonResponse(['ok' => true, 'categories' => $categories]);
    }

    if ($api === 'create_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categories = readJsonFile(CATEGORIES_FILE);
        $nameRu = trim((string) ($_POST['name_ru'] ?? ''));
        $nameEn = trim((string) ($_POST['name_en'] ?? ''));
        $parentId = trim((string) ($_POST['parent_id'] ?? ''));

        if ($nameRu === '' || $nameEn === '') {
            jsonResponse(['ok' => false, 'error' => 'category_name_required'], 400);
        }
        if ($parentId !== '' && !isset($categories[$parentId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_parent_category'], 400);
        }

        $id = uuidV4();
        $categories[$id] = [
            'id' => $id,
            'parent_id' => $parentId === '' ? null : $parentId,
            'name' => ['ru' => $nameRu, 'en' => $nameEn],
            'description' => [
                'ru' => trim((string) ($_POST['description_ru'] ?? '')),
                'en' => trim((string) ($_POST['description_en'] ?? '')),
            ],
        ];

        if (!writeJsonFile(CATEGORIES_FILE, $categories)) {
            jsonResponse(['ok' => false, 'error' => 'categories_save_failed'], 500);
        }

        jsonResponse(['ok' => true, 'message' => 'Категория создана ✅']);
    }

    if ($api === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categoryId = (string) ($_POST['id'] ?? '');
        $categories = readJsonFile(CATEGORIES_FILE);

        if (!isset($categories[$categoryId])) {
            jsonResponse(['ok' => false, 'error' => 'category_not_found'], 404);
        }

        foreach ($categories as $category) {
            if ((string) ($category['parent_id'] ?? '') === $categoryId) {
                jsonResponse(['ok' => false, 'error' => 'category_has_subcategories'], 400);
            }
        }

        foreach (readJsonFile(PRODUCTS_FILE) as $product) {
            if ((string) ($product['category_id'] ?? '') === $categoryId) {
                jsonResponse(['ok' => false, 'error' => 'category_has_products'], 400);
            }
        }

        unset($categories[$categoryId]);
        if (!writeJsonFile(CATEGORIES_FILE, $categories)) {
            jsonResponse(['ok' => false, 'error' => 'categories_save_failed'], 500);
        }

        jsonResponse(['ok' => true, 'message' => 'Категория удалена ✅']);
    }

    if ($api === 'get_products') {
        jsonResponse(['ok' => true, 'products' => array_values(readJsonFile(PRODUCTS_FILE))]);
    }

    if ($api === 'create_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categories = readJsonFile(CATEGORIES_FILE);
        $categoryId = trim((string) ($_POST['category_id'] ?? ''));
        $nameRu = trim((string) ($_POST['name_ru'] ?? ''));
        $nameEn = trim((string) ($_POST['name_en'] ?? ''));

        if ($categoryId === '' || !isset($categories[$categoryId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_category'], 400);
        }
        if ($nameRu === '' || $nameEn === '') {
            jsonResponse(['ok' => false, 'error' => 'product_name_required'], 400);
        }
        if (!isset($_FILES['files'])) {
            jsonResponse(['ok' => false, 'error' => 'no_files'], 400);
        }

        $id = uuidV4();
        $archiveName = 'archive_' . $id . '.zip';
        $archiveAbs = FILES_DIR . '/' . $archiveName;

        $zip = new ZipArchive();
        if ($zip->open($archiveAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonResponse(['ok' => false, 'error' => 'zip_create_failed'], 500);
        }

        $files = $_FILES['files'];
        $total = is_array($files['name']) ? count($files['name']) : 0;
        $added = 0;

        for ($i = 0; $i < $total; $i++) {
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }

            $name = basename((string) ($files['name'][$i] ?? ('file_' . $i)));
            $content = @file_get_contents($tmp);
            if ($content === false) {
                continue;
            }

            $zip->addFromString(($i + 1) . '_' . $name, $content);
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($archiveAbs);
            jsonResponse(['ok' => false, 'error' => 'no_valid_files'], 400);
        }

        $stock = (int) ($_POST['stock'] ?? -1);
        if ($stock < -1) {
            $stock = -1;
        }

        $products = readJsonFile(PRODUCTS_FILE);
        $products[$id] = [
            'id' => $id,
            'category_id' => $categoryId,
            'name' => ['ru' => $nameRu, 'en' => $nameEn],
            'description' => [
                'ru' => trim((string) ($_POST['description_ru'] ?? '')),
                'en' => trim((string) ($_POST['description_en'] ?? '')),
            ],
            'price' => round((float) ($_POST['price'] ?? 0), 2),
            'file' => 'files/' . $archiveName,
            'stock' => $stock,
            'sold' => 0,
            'active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (!writeJsonFile(PRODUCTS_FILE, $products)) {
            @unlink($archiveAbs);
            jsonResponse(['ok' => false, 'error' => 'products_save_failed'], 500);
        }

        jsonResponse(['ok' => true, 'message' => 'Товар создан ✅']);
    }

    if ($api === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $productId = (string) ($_POST['id'] ?? '');
        $products = readJsonFile(PRODUCTS_FILE);
        if (!isset($products[$productId])) {
            jsonResponse(['ok' => false, 'error' => 'product_not_found'], 404);
        }

        $file = (string) ($products[$productId]['file'] ?? '');
        if (strpos($file, 'files/') === 0) {
            $abs = FILES_DIR . '/' . ltrim(substr($file, 6), '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        unset($products[$productId]);
        if (!writeJsonFile(PRODUCTS_FILE, $products)) {
            jsonResponse(['ok' => false, 'error' => 'products_save_failed'], 500);
        }
        jsonResponse(['ok' => true, 'message' => 'Товар удалён ✅']);
    }

    if ($api === 'get_users') {
        jsonResponse(['ok' => true, 'users' => array_values(readJsonFile(USERS_FILE))]);
    }

    if ($api === 'topup_balance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = trim((string) ($_POST['user_id'] ?? ''));
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        if ($userId === '') {
            jsonResponse(['ok' => false, 'error' => 'user_required'], 400);
        }
        if ($amount <= 0) {
            jsonResponse(['ok' => false, 'error' => 'bad_amount'], 400);
        }

        $settings = normalizeSettings(readJsonFile(SETTINGS_FILE));
        $error = '';
        $newBalance = 0.0;
        if (!topupAtomic($userId, $amount, $error, $newBalance)) {
            $status = $error === 'user_not_found' ? 404 : 500;
            jsonResponse(['ok' => false, 'error' => $error], $status);
        }

        jsonResponse([
            'ok' => true,
            'message' => 'Баланс пополнен на ' . number_format($amount, 2, '.', '') . ' ' . $settings['currency_symbol'] . ' ✅',
            'new_balance' => $newBalance,
        ]);
    }

    if ($api === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $settings = [
            'admin_username' => preg_replace('/[^a-zA-Z0-9_]/', '', trim((string) ($_POST['admin_username'] ?? 'admin'))) ?: 'admin',
            'currency' => strtoupper(substr(trim((string) ($_POST['currency'] ?? 'USD')), 0, 5)) ?: 'USD',
            'currency_symbol' => substr(trim((string) ($_POST['currency_symbol'] ?? '$')), 0, 5) ?: '$',
            'help_text' => [
                'ru' => trim((string) ($_POST['help_text_ru'] ?? '')),
                'en' => trim((string) ($_POST['help_text_en'] ?? '')),
            ],
        ];

        if (!writeJsonFile(SETTINGS_FILE, $settings)) {
            jsonResponse(['ok' => false, 'error' => 'settings_save_failed'], 500);
        }
        jsonResponse(['ok' => true, 'message' => 'Настройки сохранены ✅']);
    }

    if ($api === 'get_settings') {
        jsonResponse(['ok' => true, 'settings' => normalizeSettings(readJsonFile(SETTINGS_FILE))]);
    }

    if ($api === 'get_stats') {
        $users = readJsonFile(USERS_FILE);
        $products = readJsonFile(PRODUCTS_FILE);
        $settings = normalizeSettings(readJsonFile(SETTINGS_FILE));
        jsonResponse([
            'ok' => true,
            'stats' => collectStats($users, $products),
            'currency_symbol' => $settings['currency_symbol'],
        ]);
    }

    jsonResponse(['ok' => false, 'error' => 'unknown_api'], 404);
}

$auth = (bool) ($_SESSION['admin_auth'] ?? false);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #0f0f0f; color: #e5e7eb; }
        input, select, textarea, button { font: inherit; border-radius: 8px; border: 1px solid #2a2a2a; background: #111; color: #fff; padding: 9px 10px; }
        button { cursor: pointer; background: #6366f1; border-color: #6366f1; }
        button.secondary { background: #1a1a1a; border-color: #2a2a2a; }
        button.danger { background: #dc2626; border-color: #dc2626; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #2a2a2a; text-align: left; padding: 8px 6px; font-size: 14px; vertical-align: top; }
        th { color: #9ca3af; }
        .muted { color: #9ca3af; }
        .italic { font-style: italic; }
        .auth-wrap { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
        .auth-card { width: min(420px, 100%); background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 18px; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 250px 1fr; }
        .sidebar { background: #151515; border-right: 1px solid #2a2a2a; padding: 14px; }
        .sidebar .nav { display: block; width: 100%; margin: 6px 0; text-align: left; background: transparent; border-color: transparent; color: #d1d5db; }
        .sidebar .nav.active, .sidebar .nav:hover { border-color: #6366f1; background: rgba(99, 102, 241, .15); color: #fff; }
        .content { padding: 16px; }
        .section { display: none; }
        .section.active { display: block; }
        .card { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 14px; margin-bottom: 12px; }
        .cards { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 12px; }
        .kpi { color: #9ca3af; font-size: 14px; }
        .kpi b { display: block; color: #fff; margin-top: 4px; font-size: 22px; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; }
        .field { flex: 1 1 220px; }
        .field.small { flex: 0 0 140px; }
        .field-inline { display: flex; align-items: center; gap: 8px; }
        .field-inline input { width: auto; }
        .drop { border: 1px dashed #3f3f46; border-radius: 10px; text-align: center; padding: 12px; color: #9ca3af; }
        .drop.drag { border-color: #6366f1; color: #fff; }
        .pagination { margin-top: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .history-box { margin-top: 8px; padding: 8px; border: 1px solid #2a2a2a; border-radius: 8px; background: #111; }
        .modal-bg { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 100; background: rgba(0, 0, 0, .6); }
        .modal { width: min(420px, calc(100% - 20px)); background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 14px; }
        .mobile-toggle { display: none; margin-bottom: 10px; }
        .toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
        .toast { padding: 12px 20px; border-radius: 8px; color: #fff; font-size: 14px; opacity: 0; transform: translateY(10px); transition: all 0.3s; pointer-events: none; }
        .toast.success { background: #22c55e; }
        .toast.error { background: #ef4444; }
        .toast.info { background: #3b82f6; }
        .toast.visible { opacity: 1; transform: translateY(0); pointer-events: auto; }
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: 250px; transform: translateX(-100%); transition: transform .2s ease; z-index: 90; }
            .layout.menu-open .sidebar { transform: translateX(0); }
            .mobile-toggle { display: inline-flex; }
        }
    </style>
</head>
<body>
<?php if (!$auth): ?>
    <div class="auth-wrap">
        <div class="auth-card">
            <h2 style="margin-top:0;">Вход в админку</h2>
            <form id="login-form">
                <input type="password" name="password" placeholder="Пароль" required style="width:100%;">
                <button type="submit" style="margin-top:10px;">Войти</button>
            </form>
            <p id="login-error" class="muted"></p>
        </div>
    </div>
    <script>
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const r = await fetch('?api=login', { method: 'POST', body: new FormData(e.currentTarget) });
            const j = await r.json();
            if (j.ok) { location.reload(); return; }
            document.getElementById('login-error').textContent = 'Неверный пароль';
        });
    </script>
<?php else: ?>
    <div id="app" class="layout">
        <aside class="sidebar">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
                <b>⚙️ Admin</b>
                <button id="logout-btn" class="secondary">Выйти</button>
            </div>
            <button class="nav active" data-section="dashboard">📊 Дашборд</button>
            <button class="nav" data-section="categories">📦 Категории</button>
            <button class="nav" data-section="products">🗂 Товары</button>
            <button class="nav" data-section="users">👥 Пользователи</button>
            <button class="nav" data-section="settings">⚙️ Настройки</button>
        </aside>
        <main class="content">
            <button id="mobile-toggle" class="secondary mobile-toggle">☰ Меню</button>

            <section id="sec-dashboard" class="section active">
                <div id="stats-cards" class="cards"></div>
                <div class="card">
                    <h3 style="margin-top:0;">Последние 10 покупок</h3>
                    <div id="latest-wrap"></div>
                </div>
            </section>

            <section id="sec-categories" class="section">
                <div class="card">
                    <h3 style="margin-top:0;">Создать категорию</h3>
                    <form id="category-form">
                        <div class="row">
                            <div class="field"><input name="name_ru" placeholder="Название RU" required></div>
                            <div class="field"><input name="name_en" placeholder="Название EN" required></div>
                            <div class="field">
                                <select name="parent_id" id="category-parent">
                                    <option value="" selected>— Корневая категория (без родителя) —</option>
                                </select>
                            </div>
                            <div class="field"><input name="description_ru" placeholder="Описание RU"></div>
                            <div class="field"><input name="description_en" placeholder="Описание EN"></div>
                        </div>
                        <button type="submit">Создать категорию</button>
                    </form>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;">Список категорий</h3>
                    <div id="categories-wrap"></div>
                </div>
            </section>

            <section id="sec-products" class="section">
                <div class="card">
                    <h3 style="margin-top:0;">Добавить товар</h3>
                    <form id="product-form">
                        <div class="row">
                            <div class="field"><input name="name_ru" placeholder="Название RU" required></div>
                            <div class="field"><input name="name_en" placeholder="Название EN" required></div>
                            <div class="field"><input name="description_ru" placeholder="Описание RU"></div>
                            <div class="field"><input name="description_en" placeholder="Описание EN"></div>
                            <div class="field"><select name="category_id" id="product-category" required></select></div>
                            <div class="field small"><input type="number" min="0" step="0.01" name="price" placeholder="Цена" required></div>
                            <label class="field-inline">
                                <input type="checkbox" id="stock-unlimited" checked>
                                Неограничено ∞
                            </label>
                            <div class="field small" id="stock-box" style="display:none;">
                                <input id="stock-value" type="number" min="0" step="1" value="1" placeholder="Количество">
                            </div>
                        </div>
                        <div id="drop-zone" class="drop" style="margin-top:10px;">Перетащите файлы сюда</div>
                        <input id="file-input" type="file" name="files[]" multiple style="margin-top:10px;">
                        <p id="file-label" class="muted">Файлы не выбраны</p>
                        <button type="submit">Создать товар</button>
                    </form>
                </div>
                <div class="card">
                    <h3 style="margin-top:0;">Список товаров</h3>
                    <div id="products-wrap"></div>
                </div>
            </section>

            <section id="sec-users" class="section">
                <div class="card">
                    <h3 style="margin-top:0;">Пользователи</h3>
                    <div class="row" style="margin-bottom:10px;">
                        <div class="field"><input id="users-search" placeholder="Поиск по имени, username или ID"></div>
                        <button id="users-search-btn" type="button">Поиск</button>
                    </div>
                    <div id="users-wrap"></div>
                    <div class="pagination">
                        <button id="users-prev" type="button" class="secondary">Назад</button>
                        <span id="users-page" class="muted"></span>
                        <button id="users-next" type="button" class="secondary">Вперёд</button>
                    </div>
                </div>
            </section>

            <section id="sec-settings" class="section">
                <div class="card">
                    <h3 style="margin-top:0;">Настройки</h3>
                    <form id="settings-form">
                        <div class="row">
                            <div class="field"><input name="admin_username" id="settings-admin" placeholder="Username администратора"></div>
                            <div class="field small"><input name="currency" id="settings-currency" maxlength="5" placeholder="USD"></div>
                            <div class="field small"><input name="currency_symbol" id="settings-symbol" maxlength="5" placeholder="$"></div>
                            <div class="field"><textarea name="help_text_ru" id="settings-help-ru" rows="5" placeholder="Текст помощи RU"></textarea></div>
                            <div class="field"><textarea name="help_text_en" id="settings-help-en" rows="5" placeholder="Текст помощи EN"></textarea></div>
                        </div>
                        <button type="submit">Сохранить настройки</button>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <div id="topup-modal" class="modal-bg">
        <div class="modal">
            <h3 style="margin-top:0;">Пополнить баланс</h3>
            <p id="topup-user" class="muted"></p>
            <input id="topup-amount" type="number" min="0.01" step="0.01" placeholder="Сумма">
            <div class="row" style="margin-top:10px;">
                <button id="topup-confirm" type="button">Подтвердить</button>
                <button id="topup-cancel" type="button" class="secondary">Отмена</button>
            </div>
        </div>
    </div>

    <div id="toast-wrap" class="toast-wrap"></div>

    <script>
        const state = {
            categories: [],
            products: [],
            users: [],
            settings: {admin_username: 'admin', currency: 'USD', currency_symbol: '$', help_text: {ru: '', en: ''}},
            stats: {users_total: 0, sales_total: 0, revenue_total: 0, active_products: 0, latest_purchases: []},
            usersSearch: '',
            usersPage: 1,
            usersPerPage: 20,
            topupUserId: '',
        };

        function esc(v) {
            return String(v ?? '').replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
        }

        function toast(msg, type = 'success') {
            const wrap = document.getElementById('toast-wrap');
            const el = document.createElement('div');
            el.className = `toast ${type}`;
            el.textContent = msg;
            wrap.appendChild(el);
            requestAnimationFrame(() => el.classList.add('visible'));
            setTimeout(() => {
                el.classList.remove('visible');
                setTimeout(() => el.remove(), 300);
            }, 3000);
        }

        async function apiGet(endpoint) {
            const r = await fetch(`?api=${endpoint}`);
            return r.json();
        }

        async function apiPost(endpoint, body) {
            const r = await fetch(`?api=${endpoint}`, {method:'POST', body});
            return r.json();
        }

        function categoryName(c) {
            return c?.name?.ru || c?.name?.en || '—';
        }

        function flattenCategories(parentId = null, depth = 0, out = [], seen = new Set()) {
            state.categories
                .filter((c) => (c.parent_id ?? null) === parentId)
                .forEach((c) => {
                    if (seen.has(c.id)) return;
                    seen.add(c.id);
                    out.push({category: c, depth});
                    flattenCategories(c.id, depth + 1, out, seen);
                });
            return out;
        }

        function usersFiltered() {
            const q = state.usersSearch.trim().toLowerCase();
            if (!q) return state.users.slice();
            return state.users.filter((u) => {
                const id = String(u.id ?? '').toLowerCase();
                const name = String(u.first_name ?? '').toLowerCase();
                const username = String(u.username ?? '').toLowerCase();
                return id.includes(q) || name.includes(q) || username.includes(q);
            });
        }

        function renderDashboard() {
            const symbol = state.settings.currency_symbol || '$';
            document.getElementById('stats-cards').innerHTML = `
                <div class="card"><span class="kpi">Всего пользователей<b>${state.stats.users_total || 0}</b></span></div>
                <div class="card"><span class="kpi">Всего продаж<b>${state.stats.sales_total || 0}</b></span></div>
                <div class="card"><span class="kpi">Общая выручка<b>${Number(state.stats.revenue_total || 0).toFixed(2)} ${esc(symbol)}</b></span></div>
                <div class="card"><span class="kpi">Активных товаров<b>${state.stats.active_products || 0}</b></span></div>
            `;

            const rows = (state.stats.latest_purchases || []).map((x) => `
                <tr>
                    <td>${esc(x.date)}</td>
                    <td>${esc(x.user_name)} (#${esc(x.user_id)})</td>
                    <td>${esc(x.product_name)}</td>
                    <td>${Number(x.amount || 0).toFixed(2)} ${esc(symbol)}</td>
                </tr>
            `).join('');

            document.getElementById('latest-wrap').innerHTML = `
                <table>
                    <tr><th>Дата</th><th>Пользователь</th><th>Товар</th><th>Сумма</th></tr>
                    ${rows || '<tr><td colspan="4" class="muted">Нет покупок</td></tr>'}
                </table>
            `;
        }

        function renderCategories() {
            const flat = flattenCategories();

            document.getElementById('category-parent').innerHTML = `
                <option value="" selected>— Корневая категория (без родителя) —</option>
                ${flat.map(({category, depth}) => `<option value="${esc(category.id)}">${'&nbsp;'.repeat(depth * 4)}${esc(categoryName(category))}</option>`).join('')}
            `;

            document.getElementById('categories-wrap').innerHTML = `
                <table>
                    <tr><th>Категория</th><th>Товаров</th><th></th></tr>
                    ${flat.map(({category, depth}) => `
                        <tr>
                            <td>${depth > 0 ? '<span class="italic">└─ ' : ''}${esc(categoryName(category))}${depth > 0 ? '</span>' : ''}</td>
                            <td>${Number(category.products_count || 0)}</td>
                            <td>${category.deletable ? `<button class="danger" type="button" data-del-cat="${esc(category.id)}">Удалить</button>` : '<span class="muted">—</span>'}</td>
                        </tr>
                    `).join('') || '<tr><td colspan="3" class="muted">Нет категорий</td></tr>'}
                </table>
            `;

            document.querySelectorAll('[data-del-cat]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const fd = new FormData();
                    fd.append('id', btn.getAttribute('data-del-cat'));
                    const j = await apiPost('delete_category', fd);
                    if (!j.ok) { toast('Не удалось удалить категорию', 'error'); return; }
                    toast(j.message || 'Категория удалена ✅');
                    await loadAll();
                });
            });
        }

        function renderProducts() {
            const flat = flattenCategories();
            const map = {};
            flat.forEach(({category, depth}) => {
                map[category.id] = `${'└─ '.repeat(depth)}${categoryName(category)}`;
            });

            document.getElementById('product-category').innerHTML = `
                <option value="">Выберите категорию</option>
                ${flat.map(({category, depth}) => `<option value="${esc(category.id)}">${'&nbsp;'.repeat(depth * 4)}${esc(categoryName(category))}</option>`).join('')}
            `;

            document.getElementById('products-wrap').innerHTML = `
                <table>
                    <tr><th>Название</th><th>Категория</th><th>Цена</th><th>Остаток</th><th></th></tr>
                    ${state.products.map((p) => `
                        <tr>
                            <td>${esc(p.name?.ru || p.name?.en || '—')}</td>
                            <td>${esc(map[p.category_id] || '—')}</td>
                            <td>${Number(p.price || 0).toFixed(2)} ${esc(state.settings.currency_symbol || '$')}</td>
                            <td>${Number(p.stock) === -1 ? '∞' : esc(p.stock)}</td>
                            <td><button class="danger" type="button" data-del-prod="${esc(p.id)}">Удалить</button></td>
                        </tr>
                    `).join('') || '<tr><td colspan="5" class="muted">Нет товаров</td></tr>'}
                </table>
            `;

            document.querySelectorAll('[data-del-prod]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const fd = new FormData();
                    fd.append('id', btn.getAttribute('data-del-prod'));
                    const j = await apiPost('delete_product', fd);
                    if (!j.ok) { toast('Не удалось удалить товар', 'error'); return; }
                    toast(j.message || 'Товар удалён ✅');
                    await loadAll();
                });
            });
        }

        function renderUsers() {
            const symbol = state.settings.currency_symbol || '$';
            const filtered = usersFiltered();
            const totalPages = Math.max(1, Math.ceil(filtered.length / state.usersPerPage));
            if (state.usersPage > totalPages) state.usersPage = totalPages;

            const start = (state.usersPage - 1) * state.usersPerPage;
            const pageItems = filtered.slice(start, start + state.usersPerPage);

            document.getElementById('users-page').textContent = `Страница ${state.usersPage} из ${totalPages}`;
            document.getElementById('users-prev').disabled = state.usersPage <= 1;
            document.getElementById('users-next').disabled = state.usersPage >= totalPages;

            document.getElementById('users-wrap').innerHTML = `
                <table>
                    <tr>
                        <th>ID</th><th>Имя</th><th>Username</th><th>Язык</th><th>Баланс</th><th>Кол-во покупок</th><th>Дата регистрации</th><th>Действия</th>
                    </tr>
                    ${pageItems.map((u) => {
                        const purchases = Array.isArray(u.purchases) ? u.purchases : [];
                        return `
                            <tr>
                                <td>${esc(u.id)}</td>
                                <td>${esc(u.first_name || '')}</td>
                                <td>${u.username ? '@' + esc(u.username) : '<span class="muted">—</span>'}</td>
                                <td>${esc(u.lang || '—')}</td>
                                <td>${Number(u.balance || 0).toFixed(2)} ${esc(symbol)}</td>
                                <td>${purchases.length}</td>
                                <td>${esc(u.created_at || u.registered_at || u.reg_date || '—')}</td>
                                <td>
                                    <button type="button" data-topup="${esc(u.id)}">Пополнить баланс</button>
                                    <button type="button" class="secondary" data-history="${esc(u.id)}">История</button>
                                </td>
                            </tr>
                            <tr id="history-${esc(u.id)}" style="display:none;">
                                <td colspan="8">
                                    <div class="history-box">
                                        ${purchases.length ? purchases.map((p) => `${esc(p.date || '')} — ${esc(p.product_name || '')} — ${Number(p.price || 0).toFixed(2)} ${esc(symbol)}`).join('<br>') : '<span class="muted">История пуста</span>'}
                                    </div>
                                </td>
                            </tr>
                        `;
                    }).join('') || '<tr><td colspan="8" class="muted">Пользователи не найдены</td></tr>'}
                </table>
            `;

            document.querySelectorAll('[data-topup]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    state.topupUserId = btn.getAttribute('data-topup') || '';
                    document.getElementById('topup-user').textContent = `ID пользователя: ${state.topupUserId}`;
                    document.getElementById('topup-amount').value = '';
                    document.getElementById('topup-modal').style.display = 'flex';
                });
            });

            document.querySelectorAll('[data-history]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-history');
                    const row = document.getElementById(`history-${id}`);
                    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
                });
            });
        }

        function renderSettings() {
            document.getElementById('settings-admin').value = state.settings.admin_username || 'admin';
            document.getElementById('settings-currency').value = (state.settings.currency || 'USD').toUpperCase();
            document.getElementById('settings-symbol').value = state.settings.currency_symbol || '$';
            document.getElementById('settings-help-ru').value = state.settings.help_text?.ru || '';
            document.getElementById('settings-help-en').value = state.settings.help_text?.en || '';
        }

        async function loadAll() {
            const [c, p, u, s, st] = await Promise.all([
                apiGet('get_categories'),
                apiGet('get_products'),
                apiGet('get_users'),
                apiGet('get_settings'),
                apiGet('get_stats'),
            ]);

            if (!c.ok || !p.ok || !u.ok || !s.ok || !st.ok) {
                toast('Ошибка загрузки данных', 'error');
                return;
            }

            state.categories = c.categories || [];
            state.products = p.products || [];
            state.users = u.users || [];
            state.settings = s.settings || state.settings;
            state.stats = st.stats || state.stats;

            renderDashboard();
            renderCategories();
            renderProducts();
            renderUsers();
            renderSettings();
        }

        document.querySelectorAll('.sidebar .nav').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.sidebar .nav').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                document.querySelectorAll('.section').forEach((s) => s.classList.remove('active'));
                const sec = btn.getAttribute('data-section');
                document.getElementById(`sec-${sec}`).classList.add('active');
                document.getElementById('app').classList.remove('menu-open');
            });
        });

        document.getElementById('mobile-toggle').addEventListener('click', () => {
            document.getElementById('app').classList.toggle('menu-open');
        });

        document.getElementById('logout-btn').addEventListener('click', async () => {
            await apiGet('logout');
            location.reload();
        });

        document.getElementById('category-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const j = await apiPost('create_category', new FormData(e.currentTarget));
            if (!j.ok) { toast('Не удалось создать категорию', 'error'); return; }
            toast(j.message || 'Категория создана ✅');
            e.currentTarget.reset();
            await loadAll();
        });

        const stockUnlimited = document.getElementById('stock-unlimited');
        const stockBox = document.getElementById('stock-box');
        const stockValue = document.getElementById('stock-value');
        stockUnlimited.addEventListener('change', () => {
            stockBox.style.display = stockUnlimited.checked ? 'none' : '';
            stockValue.disabled = stockUnlimited.checked;
        });

        const fileInput = document.getElementById('file-input');
        const fileLabel = document.getElementById('file-label');
        const dropZone = document.getElementById('drop-zone');
        const refreshFiles = () => {
            const names = Array.prototype.map.call(fileInput.files || [], (f) => f.name);
            fileLabel.textContent = names.length ? names.join(', ') : 'Файлы не выбраны';
        };
        fileInput.addEventListener('change', refreshFiles);
        ['dragenter', 'dragover'].forEach((evt) => {
            dropZone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropZone.classList.add('drag');
            });
        });
        ['dragleave', 'drop'].forEach((evt) => {
            dropZone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag');
            });
        });
        dropZone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files) {
                fileInput.files = e.dataTransfer.files;
                refreshFiles();
            }
        });

        document.getElementById('product-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!fileInput.files || fileInput.files.length === 0) {
                toast('Выберите файлы', 'error');
                return;
            }

            const fd = new FormData(e.currentTarget);
            if (stockUnlimited.checked) {
                fd.set('stock', '-1');
            } else {
                const v = Math.floor(Number(stockValue.value || 0));
                if (!Number.isFinite(v) || v < 0) {
                    toast('Введите корректное количество', 'error');
                    return;
                }
                fd.set('stock', String(v));
            }

            const j = await apiPost('create_product', fd);
            if (!j.ok) { toast('Не удалось создать товар', 'error'); return; }
            toast(j.message || 'Товар создан ✅');
            e.currentTarget.reset();
            stockUnlimited.checked = true;
            stockBox.style.display = 'none';
            stockValue.disabled = true;
            fileLabel.textContent = 'Файлы не выбраны';
            await loadAll();
        });

        const usersSearch = document.getElementById('users-search');
        document.getElementById('users-search-btn').addEventListener('click', () => {
            state.usersSearch = usersSearch.value || '';
            state.usersPage = 1;
            renderUsers();
        });
        usersSearch.addEventListener('input', () => {
            state.usersSearch = usersSearch.value || '';
            state.usersPage = 1;
            renderUsers();
        });
        document.getElementById('users-prev').addEventListener('click', () => {
            state.usersPage = Math.max(1, state.usersPage - 1);
            renderUsers();
        });
        document.getElementById('users-next').addEventListener('click', () => {
            const pages = Math.max(1, Math.ceil(usersFiltered().length / state.usersPerPage));
            state.usersPage = Math.min(pages, state.usersPage + 1);
            renderUsers();
        });

        document.getElementById('topup-cancel').addEventListener('click', () => {
            document.getElementById('topup-modal').style.display = 'none';
        });
        document.getElementById('topup-confirm').addEventListener('click', async () => {
            const amount = Number(document.getElementById('topup-amount').value || 0);
            if (!state.topupUserId || !Number.isFinite(amount) || amount <= 0) {
                toast('Введите корректную сумму', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('user_id', state.topupUserId);
            fd.append('amount', amount.toFixed(2));
            const j = await apiPost('topup_balance', fd);
            if (!j.ok) { toast('Не удалось пополнить баланс', 'error'); return; }
            document.getElementById('topup-modal').style.display = 'none';
            toast(j.message || `Баланс пополнен на ${amount.toFixed(2)} ${state.settings.currency_symbol} ✅`);
            await loadAll();
        });

        document.getElementById('settings-currency').addEventListener('input', (e) => {
            e.currentTarget.value = String(e.currentTarget.value || '').toUpperCase().slice(0, 5);
        });
        document.getElementById('settings-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const j = await apiPost('save_settings', new FormData(e.currentTarget));
            if (!j.ok) { toast('Не удалось сохранить настройки', 'error'); return; }
            toast('Настройки сохранены ✅');
            await loadAll();
        });

        loadAll();
    </script>
<?php endif; ?>
</body>
</html>
