<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';

session_start();

function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAuth(): void
{
    if (!($_SESSION['admin_auth'] ?? false)) {
        jsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

if (isset($_GET['api'])) {
    $api = (string) $_GET['api'];

    if ($api === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && hash_equals(ADMIN_PASSWORD, $password)) {
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

    if ($api === 'bootstrap') {
        $users = readJson(USERS_FILE);
        $products = readJson(PRODUCTS_FILE);
        $settings = readJson(SETTINGS_FILE);

        $sales = 0;
        $revenue = 0.0;
        $latest = [];
        foreach ($users as $user) {
            foreach ((array) ($user['purchases'] ?? []) as $purchase) {
                $sales++;
                $revenue += (float) ($purchase['price'] ?? 0);
                $latest[] = [
                    'user_id' => $user['id'] ?? 0,
                    'user_name' => $user['first_name'] ?? 'User',
                    'date' => $purchase['date'] ?? '',
                    'product_name' => $purchase['product_name'] ?? '',
                    'price' => (float) ($purchase['price'] ?? 0),
                ];
            }
        }
        usort($latest, static fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        jsonResponse([
            'ok' => true,
            'stats' => [
                'users' => count($users),
                'sales' => $sales,
                'revenue' => round($revenue, 2),
                'latest' => array_slice($latest, 0, 10),
            ],
            'categories' => array_values(array_map(static function (array $category): array {
                if (!array_key_exists('parent_id', $category)) {
                    $category['parent_id'] = null;
                }
                return $category;
            }, readJson(CATEGORIES_FILE))),
            'products' => array_values($products),
            'users_list' => array_values($users),
            'settings' => $settings,
        ]);
    }

    if ($api === 'add_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categories = readJson(CATEGORIES_FILE);
        $id = generateId();
        $parentId = trim((string) ($_POST['parent_id'] ?? ''));
        if ($parentId !== '' && !isset($categories[$parentId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_parent_category'], 400);
        }

        $categories[$id] = [
            'id' => $id,
            'parent_id' => $parentId !== '' ? $parentId : null,
            'name' => [
                'ru' => trim((string) ($_POST['name_ru'] ?? '')),
                'en' => trim((string) ($_POST['name_en'] ?? '')),
            ],
            'description' => [
                'ru' => trim((string) ($_POST['description_ru'] ?? '')),
                'en' => trim((string) ($_POST['description_en'] ?? '')),
            ],
        ];
        writeJson(CATEGORIES_FILE, $categories);
        jsonResponse(['ok' => true, 'message' => 'Категория создана']);
    }

    if ($api === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (string) ($_POST['id'] ?? '');
        $categories = readJson(CATEGORIES_FILE);
        if (!isset($categories[$id])) {
            jsonResponse(['ok' => false, 'error' => 'category_not_found'], 404);
        }

        foreach ($categories as $category) {
            if ((string) ($category['parent_id'] ?? '') === $id) {
                jsonResponse(['ok' => false, 'error' => 'category_has_subcategories'], 400);
            }
        }

        $products = readJson(PRODUCTS_FILE);
        foreach ($products as $product) {
            if (($product['category_id'] ?? '') === $id) {
                jsonResponse(['ok' => false, 'error' => 'category_has_products'], 400);
            }
        }
        unset($categories[$id]);
        writeJson(CATEGORIES_FILE, $categories);
        jsonResponse(['ok' => true, 'message' => 'Категория удалена']);
    }

    if ($api === 'add_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categories = readJson(CATEGORIES_FILE);
        $categoryId = (string) ($_POST['category_id'] ?? '');
        if (!isset($categories[$categoryId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_category'], 400);
        }

        if (!isset($_FILES['files'])) {
            jsonResponse(['ok' => false, 'error' => 'no_files'], 400);
        }

        $id = generateId();
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
            $name = basename((string) ($files['name'][$i] ?? ('file_' . $i)));
            if ($tmp !== '' && is_uploaded_file($tmp)) {
                $content = @file_get_contents($tmp);
                if ($content !== false) {
                    $zip->addFromString(($i + 1) . '_' . $name, $content);
                    $added++;
                }
            }
        }

        $zip->close();
        if ($added === 0) {
            @unlink($archiveAbs);
            jsonResponse(['ok' => false, 'error' => 'no_valid_files'], 400);
        }

        $products = readJson(PRODUCTS_FILE);
        $products[$id] = [
            'id' => $id,
            'category_id' => $categoryId,
            'name' => [
                'ru' => trim((string) ($_POST['name_ru'] ?? '')),
                'en' => trim((string) ($_POST['name_en'] ?? '')),
            ],
            'price' => (float) ($_POST['price'] ?? 0),
            'file' => 'files/' . $archiveName,
            'stock' => (int) ($_POST['stock'] ?? -1),
            'sold' => 0,
            'active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        writeJson(PRODUCTS_FILE, $products);
        jsonResponse(['ok' => true, 'message' => 'Товар добавлен']);
    }

    if ($api === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (string) ($_POST['id'] ?? '');
        $products = readJson(PRODUCTS_FILE);
        $product = $products[$id] ?? null;
        if ($product) {
            $path = resolveSaleFilePath((string) ($product['file'] ?? ''));
            if ($path !== null) {
                @unlink($path);
            }
            unset($products[$id]);
            writeJson(PRODUCTS_FILE, $products);
        }
        jsonResponse(['ok' => true, 'message' => 'Товар удалён']);
    }

    if ($api === 'topup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (string) ($_POST['id'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            jsonResponse(['ok' => false, 'error' => 'bad_amount'], 400);
        }

        $users = readJson(USERS_FILE);
        if (!isset($users[$id])) {
            jsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        $users[$id]['balance'] = round((float) ($users[$id]['balance'] ?? 0) + $amount, 2);
        writeJson(USERS_FILE, $users);
        jsonResponse(['ok' => true, 'message' => 'Баланс пополнен']);
    }

    if ($api === 'user_history') {
        $id = (string) ($_GET['id'] ?? '');
        $users = readJson(USERS_FILE);
        $history = (array) ($users[$id]['purchases'] ?? []);
        jsonResponse(['ok' => true, 'history' => $history]);
    }

    if ($api === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $settings = readJson(SETTINGS_FILE);
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['admin_username'] ?? 'admin')) ?: 'admin';
        if (strlen($username) < 5) {
            jsonResponse(['ok' => false, 'error' => 'username_invalid_format', 'message' => 'Username must have at least 5 characters after sanitization (letters, numbers, underscore).'], 400);
        }
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
        $currencySymbol = trim((string) ($_POST['currency_symbol'] ?? ''));
        $settings['admin_username'] = $username;
        $settings['currency'] = mb_substr($currency, 0, 5) ?: 'USD';
        $settings['currency_symbol'] = mb_substr($currencySymbol, 0, 5) ?: '$';
        $settings['help_text'] = [
            'ru' => trim((string) ($_POST['help_text_ru'] ?? '')),
            'en' => trim((string) ($_POST['help_text_en'] ?? '')),
        ];
        writeJson(SETTINGS_FILE, $settings);
        jsonResponse(['ok' => true, 'message' => 'Настройки сохранены ✅']);
    }

    jsonResponse(['ok' => false, 'error' => 'unknown_api'], 404);
}

$auth = (bool) ($_SESSION['admin_auth'] ?? false);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin</title>
    <style>
        :root{color-scheme:dark}
        body{margin:0;font-family:system-ui,Segoe UI,Roboto,Arial;background:#0e1015;color:#e6e6e6}
        .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
        .card{background:#151922;border:1px solid #232a37;border-radius:12px;padding:16px;margin-bottom:16px}
        .row{display:flex;gap:12px;flex-wrap:wrap}
        input,select,button,textarea{background:#0f1320;border:1px solid #2b3342;color:#fff;padding:10px;border-radius:8px}
        button{cursor:pointer;background:#243046}
        button:hover{background:#2f3f5f}
        table{width:100%;border-collapse:collapse}
        th,td{border-bottom:1px solid #283041;padding:8px;text-align:left;font-size:14px}
        .tabs button{margin-right:8px}
        .hidden{display:none}
        .drop{border:1px dashed #3a465f;padding:14px;border-radius:10px;text-align:center}
        .muted{color:#9aa4b5;font-size:13px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
        .stat{background:#101624;border:1px solid #2a3345;border-radius:10px;padding:12px}
        .bar{height:8px;background:#1c2230;border-radius:100px;overflow:hidden}
        .bar>span{display:block;height:100%;background:#3f7cff;width:0}
        .toast-container{position:fixed;bottom:20px;right:20px;z-index:9999}
        .toast{padding:12px 20px;border-radius:8px;margin-top:8px;color:#fff;font-size:14px;opacity:0;transition:opacity .3s}
        .toast.success{background:#22c55e}
        .toast.error{background:#ef4444}
        .toast.show{opacity:1}
    </style>
</head>
<body>
<div class="wrap">
    <?php if (!$auth): ?>
        <div class="card" style="max-width:420px;margin:70px auto;">
            <h2>Вход в админку</h2>
            <form id="loginForm">
                <input type="password" name="password" placeholder="Пароль" required style="width:100%;box-sizing:border-box">
                <button style="margin-top:10px;width:100%">Войти</button>
            </form>
            <div id="loginError" class="muted"></div>
        </div>
        <script>
            loginForm.onsubmit = async (e) => {
                e.preventDefault();
                const form = new FormData(loginForm);
                const res = await fetch('?api=login',{method:'POST',body:form});
                const json = await res.json();
                if(json.ok){ location.reload(); return; }
                loginError.textContent = 'Неверный пароль';
            };
        </script>
    <?php else: ?>
        <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:10px">
            <h2 style="margin:0">Панель управления</h2>
            <button id="logoutBtn">Выйти</button>
        </div>

        <div class="card" id="stats"></div>

        <div class="card tabs">
            <button data-tab="categories">📦 Категории</button>
            <button data-tab="products">🗂 Товары</button>
            <button data-tab="users">👥 Пользователи</button>
            <button data-tab="settings">⚙️ Настройки</button>
        </div>

        <div id="categories" class="card"></div>
        <div id="products" class="card hidden"></div>
        <div id="users" class="card hidden"></div>
        <div id="settings" class="card hidden"></div>

        <script>
            const state = {categories:[],products:[],users:[],settings:{},stats:{}};
            const errorText = {
                unauthorized: 'Ошибка: требуется авторизация',
                wrong_password: 'Ошибка: неверный пароль',
                category_has_products: 'Ошибка: у категории есть товары',
                category_has_subcategories: 'Ошибка: у категории есть подкатегории',
                category_not_found: 'Ошибка: категория не найдена',
                bad_category: 'Ошибка: категория не найдена',
                bad_parent_category: 'Ошибка: родительская категория не найдена',
                bad_amount: 'Ошибка: некорректная сумма',
                user_not_found: 'Ошибка: пользователь не найден',
                no_files: 'Ошибка: выберите файлы',
                no_valid_files: 'Ошибка: нет валидных файлов',
                zip_create_failed: 'Ошибка: не удалось создать архив',
                username_invalid_format: 'Ошибка: username администратора некорректный',
                unknown_api: 'Ошибка: неизвестный API-метод',
            };

            const tabButtons = [...document.querySelectorAll('[data-tab]')];
            tabButtons.forEach(btn=>btn.onclick=()=>{
                ['categories','products','users','settings'].forEach(id=>document.getElementById(id).classList.add('hidden'));
                document.getElementById(btn.dataset.tab).classList.remove('hidden');
            });

            function showToast(message, type = 'success'){
                let container = document.getElementById('toast-container');
                if(!container){
                    container = document.createElement('div');
                    container.id = 'toast-container';
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.textContent = message;
                container.appendChild(toast);
                setTimeout(() => toast.classList.add('show'), 10);
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }

            function apiError(j, fallback = 'Ошибка запроса'){
                if(j?.message){ return `Ошибка: ${j.message}`; }
                if(j?.error && errorText[j.error]){ return errorText[j.error]; }
                if(j?.error){ return `Ошибка: ${j.error}`; }
                return `Ошибка: ${fallback}`;
            }

            logoutBtn.onclick = async()=>{
                try{
                    const res = await fetch('?api=logout');
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'не удалось выйти'), 'error'); return; }
                    location.reload();
                }catch(_){
                    showToast('Ошибка: не удалось выйти', 'error');
                }
            };

            function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[m]));}
            function categoryName(c){return c?.name?.ru||c?.name?.en||'—'}
            function withParent(category){ return {...category, parent_id: category?.parent_id || null}; }
            function flattenCategories(parentId = null, depth = 0, out = [], seen = new Set()){
                state.categories
                    .map(withParent)
                    .filter(c => c.parent_id === parentId)
                    .forEach(c => {
                        if(seen.has(c.id)){ return; }
                        seen.add(c.id);
                        out.push({category: c, depth});
                        flattenCategories(c.id, depth + 1, out, seen);
                    });
                return out;
            }
            function categoryOptions(allowOnlyRoot = false){
                return flattenCategories()
                    .filter(({category}) => !allowOnlyRoot || category.parent_id === null)
                    .map(({category, depth}) => {
                        const indent = '&nbsp;'.repeat(depth * 4);
                        return `<option value="${esc(category.id)}">${indent}${esc(categoryName(category))}</option>`;
                    }).join('');
            }

            function renderStats(){
                stats.innerHTML = `
                    <h3>📊 Статистика</h3>
                    <div class="stats">
                        <div class="stat">Всего пользователей<br><b>${state.stats.users||0}</b></div>
                        <div class="stat">Всего продаж<br><b>${state.stats.sales||0}</b></div>
                        <div class="stat">Общая выручка<br><b>${(state.stats.revenue||0).toFixed(2)} ${esc(state.settings.currency_symbol||'₽')}</b></div>
                    </div>
                    <h4>Последние покупки</h4>
                    <table><tr><th>Дата</th><th>Пользователь</th><th>Товар</th><th>Сумма</th></tr>
                    ${(state.stats.latest||[]).map(x=>`<tr><td>${esc(x.date)}</td><td>${esc(x.user_name)} (#${esc(x.user_id)})</td><td>${esc(x.product_name)}</td><td>${Number(x.price||0).toFixed(2)}</td></tr>`).join('') || '<tr><td colspan="4">Нет данных</td></tr>'}
                    </table>
                `;
            }

            function renderCategories(){
                const productCountByCategory = {};
                state.products.forEach(p=>productCountByCategory[p.category_id]=(productCountByCategory[p.category_id]||0)+1);
                const rows = flattenCategories();
                categories.innerHTML = `
                    <h3>📦 Категории</h3>
                    <form id="catForm" class="row">
                        <input name="name_ru" placeholder="Название RU" required>
                        <input name="name_en" placeholder="Название EN" required>
                        <select name="parent_id">
                            <option value="" selected>Без родителя (корневая категория)</option>
                            ${categoryOptions()}
                        </select>
                        <input name="description_ru" placeholder="Описание RU">
                        <input name="description_en" placeholder="Описание EN">
                        <button>Создать</button>
                    </form>
                    <table><tr><th>Название</th><th>Товаров</th><th></th></tr>
                        ${rows.map(({category, depth})=>`<tr>
                            <td>${depth > 0 ? `${'&nbsp;'.repeat((depth-1)*4)}└─ ` : ''}${esc(categoryName(category))}</td>
                            <td>${productCountByCategory[category.id]||0}</td>
                            <td><button data-del-cat="${esc(category.id)}">Удалить</button></td>
                        </tr>`).join('') || '<tr><td colspan="3">Нет категорий</td></tr>'}
                    </table>
                `;
                catForm.onsubmit = async (e)=>{
                    e.preventDefault();
                    const res = await fetch('?api=add_category',{method:'POST',body:new FormData(catForm)});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'не удалось создать категорию'), 'error'); return; }
                    await load();
                    showToast(j.message || 'Категория создана');
                };
                document.querySelectorAll('[data-del-cat]').forEach(btn=>btn.onclick=async()=>{
                    const fd = new FormData(); fd.append('id', btn.dataset.delCat);
                    const r = await fetch('?api=delete_category',{method:'POST',body:fd});
                    const j = await r.json();
                    if(!j.ok){ showToast(apiError(j, 'не удалось удалить категорию'), 'error'); return; }
                    await load();
                    showToast(j.message || 'Категория удалена');
                });
            }

            function renderProducts(){
                const categoryLabelById = {};
                flattenCategories().forEach(({category, depth}) => {
                    categoryLabelById[category.id] = `${'— '.repeat(depth)}${categoryName(category)}`;
                });

                products.innerHTML = `
                    <h3>🗂 Товары</h3>
                    <form id="productForm">
                        <div class="row">
                            <input name="name_ru" placeholder="Название RU" required>
                            <input name="name_en" placeholder="Название EN" required>
                            <select name="category_id" required>
                                <option value="">Категория</option>
                                ${categoryOptions()}
                            </select>
                            <input name="price" type="number" step="0.01" min="0" placeholder="Цена" required>
                            <input name="stock" type="number" placeholder="Количество (-1 = ∞)" value="-1" required>
                        </div>
                        <div id="dropZone" class="drop" style="margin-top:10px">Перетащите файлы сюда или выберите вручную</div>
                        <input id="fileInput" type="file" name="files[]" multiple style="margin-top:10px">
                        <div id="fileNames" class="muted"></div>
                        <div class="bar" style="margin:10px 0"><span id="uploadBar"></span></div>
                        <button>Добавить товар</button>
                    </form>
                    <table style="margin-top:16px"><tr><th>Название</th><th>Категория</th><th>Цена</th><th>Остаток</th><th>Продано</th><th></th></tr>
                        ${state.products.map(p=>`<tr>
                            <td>${esc(p.name?.ru||p.name?.en||'—')}</td>
                            <td>${esc(categoryLabelById[p.category_id]||'—')}</td>
                            <td>${Number(p.price||0).toFixed(2)}</td>
                            <td>${Number(p.stock) < 0 ? '∞' : esc(p.stock)}</td>
                            <td>${esc(p.sold||0)}</td>
                            <td><button data-del-prod="${esc(p.id)}">Удалить</button></td>
                        </tr>`).join('') || '<tr><td colspan="6">Нет товаров</td></tr>'}
                    </table>
                `;

                const input = document.getElementById('fileInput');
                const names = document.getElementById('fileNames');
                const dz = document.getElementById('dropZone');
                const refreshNames = ()=>{ names.textContent = [...input.files].map(f=>f.name).join(', '); };
                input.onchange = refreshNames;
                dz.ondragover = (e)=>{e.preventDefault(); dz.style.borderColor='#5c89ff';};
                dz.ondragleave = ()=>{dz.style.borderColor='';};
                dz.ondrop = (e)=>{
                    e.preventDefault(); dz.style.borderColor='';
                    input.files = e.dataTransfer.files;
                    refreshNames();
                };

                productForm.onsubmit = async (e)=>{
                    e.preventDefault();
                    if(!input.files.length){ showToast('Ошибка: выберите файлы', 'error'); return; }
                    const fd = new FormData(productForm);
                    const xhr = new XMLHttpRequest();
                    xhr.upload.onprogress = (ev)=>{ if(ev.lengthComputable){ uploadBar.style.width = ((ev.loaded/ev.total)*100).toFixed(1) + '%'; } };
                    xhr.onreadystatechange = async ()=>{
                        if(xhr.readyState === 4){
                            uploadBar.style.width = '0%';
                            try{
                                const j = JSON.parse(xhr.responseText || '{}');
                                if(!j.ok){ showToast(apiError(j, 'ошибка загрузки'), 'error'); return; }
                                await load();
                                showToast(j.message || 'Товар добавлен');
                            }catch(_){ showToast('Ошибка: некорректный ответ сервера', 'error'); }
                        }
                    };
                    xhr.open('POST','?api=add_product');
                    xhr.send(fd);
                };

                document.querySelectorAll('[data-del-prod]').forEach(btn=>btn.onclick=async()=>{
                    const fd = new FormData(); fd.append('id', btn.dataset.delProd);
                    const res = await fetch('?api=delete_product',{method:'POST',body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'не удалось удалить товар'), 'error'); return; }
                    await load();
                    showToast(j.message || 'Товар удалён');
                });
            }

            function renderUsers(){
                users.innerHTML = `
                    <h3>👥 Пользователи</h3>
                    <table><tr><th>ID</th><th>Имя</th><th>Язык</th><th>Баланс</th><th>Покупок</th><th>Действия</th></tr>
                        ${state.users.map(u=>`<tr>
                            <td>${esc(u.id)}</td>
                            <td>${esc(u.first_name||'')}</td>
                            <td>${esc(u.lang||'—')}</td>
                            <td>${Number(u.balance||0).toFixed(2)}</td>
                            <td>${(u.purchases||[]).length}</td>
                            <td>
                                <button data-topup="${esc(u.id)}">Пополнить</button>
                                <button data-history="${esc(u.id)}">История</button>
                            </td>
                        </tr>`).join('') || '<tr><td colspan="6">Нет пользователей</td></tr>'}
                    </table>
                    <div id="historyBox" class="muted" style="margin-top:12px"></div>
                `;

                document.querySelectorAll('[data-topup]').forEach(btn=>btn.onclick=async()=>{
                    const amount = prompt('Сумма пополнения:');
                    if(!amount) return;
                    const fd = new FormData(); fd.append('id', btn.dataset.topup); fd.append('amount', amount);
                    const res = await fetch('?api=topup',{method:'POST',body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'ошибка пополнения'), 'error'); return; }
                    await load();
                    showToast(j.message || 'Баланс пополнен');
                });

                document.querySelectorAll('[data-history]').forEach(btn=>btn.onclick=async()=>{
                    const res = await fetch('?api=user_history&id='+encodeURIComponent(btn.dataset.history));
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'не удалось загрузить историю'), 'error'); return; }
                    const list = (j.history||[]).map(x=>`${x.date} — ${x.product_name} — ${Number(x.price||0).toFixed(2)}`).join('<br>');
                    historyBox.innerHTML = list || 'История пуста';
                    showToast('История загружена');
                });
            }

            function renderSettings(){
                settings.innerHTML = `
                    <h3>⚙️ Настройки</h3>
                    <form id="settingsForm" class="row">
                        <input name="admin_username" value="${esc(state.settings.admin_username||'admin')}" placeholder="username администратора">
                        <input type="text" name="currency" value="${esc(state.settings.currency||'USD')}" placeholder="USD" maxlength="5">
                        <input type="text" name="currency_symbol" value="${esc(state.settings.currency_symbol||'$')}" placeholder="$" maxlength="5">
                        <textarea name="help_text_ru" placeholder="Текст помощи (RU)" rows="4" style="min-width:320px;flex:1 1 320px">${esc(state.settings.help_text?.ru||'')}</textarea>
                        <textarea name="help_text_en" placeholder="Help text (EN)" rows="4" style="min-width:320px;flex:1 1 320px">${esc(state.settings.help_text?.en||'')}</textarea>
                        <button>Сохранить</button>
                    </form>
                    <div class="muted">Этот username показывается пользователям в инструкции пополнения.</div>
                `;

                settingsForm.onsubmit = async (e)=>{
                    e.preventDefault();
                    const res = await fetch('?api=save_settings',{method:'POST',body:new FormData(settingsForm)});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'не удалось сохранить настройки'), 'error'); return; }
                    await load();
                    showToast(j.message || 'Настройки сохранены');
                };
            }

            async function load(){
                const res = await fetch('?api=bootstrap');
                const j = await res.json();
                if(!j.ok){ showToast(apiError(j, 'ошибка загрузки данных'), 'error'); return; }
                state.categories = (j.categories||[]).map(withParent);
                state.products = j.products||[];
                state.users = j.users_list||[];
                state.settings = j.settings||{};
                state.settings.help_text = state.settings.help_text || {ru:'',en:''};
                state.stats = j.stats||{};
                renderStats(); renderCategories(); renderProducts(); renderUsers(); renderSettings();
            }
            load();
        </script>
    <?php endif; ?>
</div>
<div id="toast-container" class="toast-container"></div>
</body>
</html>
