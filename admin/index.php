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

function sendTelegramTopupNotification(string $userId, string $text): void
{
    $token = (string) (getenv('BOT_TOKEN') ?: '');
    if ($token === '' || $userId === '' || $text === '') {
        return;
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'chat_id' => $userId,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sendTelegramMessageByBotToken(string $chatId, string $text): void
{
    $token = (string) (getenv('BOT_TOKEN') ?: '');
    if ($token === '' || $chatId === '' || $text === '') {
        return;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode([
                'chat_id' => $chatId,
                'text' => $text,
            ], JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
        ],
    ]);

    @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage', false, $context);
}

function setWebhookEndpoint(): void
{
    $token = trim((string) (getenv('BOT_TOKEN') ?: ''));
    if ($token === '') {
        jsonResponse(['ok' => false, 'error' => 'bot_token_missing'], 500);
    }

    $webhookUrl = trim((string) ($_POST['webhook_url'] ?? ''));
    if ($webhookUrl === '' || !preg_match('/^https:\/\//i', $webhookUrl)) {
        jsonResponse(['ok' => false, 'error' => 'bad_webhook_url'], 400);
    }

    $secret = getenv('WEBHOOK_SECRET');
    $payload = ['url' => $webhookUrl];
    if ($secret !== false && trim((string) $secret) !== '') {
        $payload['secret_token'] = trim((string) $secret);
    }

    $apiUrl = 'https://api.telegram.org/bot' . $token . '/setWebhook';
    $ch = curl_init($apiUrl);
    if ($ch === false) {
        jsonResponse(['ok' => false, 'error' => 'webhook_request_failed'], 500);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        jsonResponse(['ok' => false, 'error' => 'webhook_request_failed', 'description' => $curlError !== '' ? $curlError : 'setWebhook request failed'], 502);
    }

    $telegram = json_decode((string) $raw, true);
    if (!is_array($telegram)) {
        jsonResponse(['ok' => false, 'error' => 'bad_telegram_response'], 502);
    }

    if (($telegram['ok'] ?? false) !== true) {
        $description = trim((string) ($telegram['description'] ?? 'unknown telegram error'));
        jsonResponse(['ok' => false, 'error' => 'webhook_set_failed', 'description' => $description, 'telegram' => $telegram], 400);
    }

    jsonResponse(['ok' => true, 'message' => 'Вебхук установлен!', 'telegram' => $telegram]);
}

function normalizeProductBundles(array $product): array
{
    $bundles = [];
    foreach ((array) ($product['bundles'] ?? []) as $bundle) {
        if (!is_array($bundle)) {
            continue;
        }
        $id = trim((string) ($bundle['id'] ?? ''));
        $file = trim((string) ($bundle['file'] ?? ''));
        if ($id === '' || $file === '') {
            continue;
        }
        $bundles[] = [
            'id' => $id,
            'file' => $file,
            'created_at' => (string) ($bundle['created_at'] ?? date('Y-m-d H:i:s')),
        ];
    }
    return array_values($bundles);
}

function recalculateProductStock(array &$product): void
{
    $product['bundles'] = normalizeProductBundles($product);
    $product['stock'] = count($product['bundles']);
    $product['active'] = $product['stock'] > 0;
}

function createBundleFromUpload(string $productId, array $files, ?string &$error = null): ?array
{
    $productDir = FILES_DIR . '/' . $productId;
    if (!is_dir($productDir) && !@mkdir($productDir, 0755, true) && !is_dir($productDir)) {
        $error = 'zip_create_failed';
        return null;
    }

    $bundleId = uniqid('', true);
    $bundleFileName = $bundleId . '.zip';
    $archiveAbs = $productDir . '/' . $bundleFileName;

    $zip = new ZipArchive();
    if ($zip->open($archiveAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $error = 'zip_create_failed';
        return null;
    }

    $total = is_array($files['name'] ?? null) ? count($files['name']) : 0;
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
        $error = 'no_valid_files';
        return null;
    }

    return [
        'id' => $bundleId,
        'file' => 'files/' . $productId . '/' . $bundleFileName,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

function saveProductPreviewImage(string $productId, array $file, ?string &$error = null): ?string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'preview_upload_failed';
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'preview_upload_failed';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    if ($mime === '' || !str_starts_with($mime, 'image/')) {
        $error = 'preview_invalid_type';
        return null;
    }

    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $ext = $extByMime[$mime] ?? null;
    if ($ext === null) {
        $error = 'preview_invalid_type';
        return null;
    }

    $productDir = FILES_DIR . '/' . $productId;
    if (!is_dir($productDir) && !@mkdir($productDir, 0755, true) && !is_dir($productDir)) {
        $error = 'preview_upload_failed';
        return null;
    }

    foreach ((array) glob($productDir . '/preview.*') as $existing) {
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    $targetAbs = $productDir . '/preview.' . $ext;
    if (!@move_uploaded_file($tmpName, $targetAbs)) {
        $error = 'preview_upload_failed';
        return null;
    }

    return 'files/' . $productId . '/preview.' . $ext;
}

function saveBotLogo(array $file, ?string &$error = null): ?string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'logo_upload_failed';
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'logo_upload_failed';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    if (!in_array($mime, ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp'], true)) {
        $error = 'logo_invalid_type';
        return null;
    }

    if (!is_dir(FILES_DIR) && !@mkdir(FILES_DIR, 0755, true) && !is_dir(FILES_DIR)) {
        $error = 'logo_upload_failed';
        return null;
    }

    $target = FILES_DIR . '/logo.jpg';
    if (!@move_uploaded_file($tmpName, $target)) {
        $error = 'logo_upload_failed';
        return null;
    }

    return 'files/logo.jpg';
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
        foreach ($products as &$product) {
            if (!is_array($product)) {
                continue;
            }
            recalculateProductStock($product);
        }
        unset($product);
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

    if (($api === 'add_category' || $api === 'create_category') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categories = readJson(CATEGORIES_FILE);
        $id = generateId();
        $parentId = trim((string) ($_POST['parent_id'] ?? ''));
        if ($parentId !== '' && !isset($categories[$parentId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_parent_category'], 400);
        }
        $nameRu = trim((string) ($_POST['name_ru'] ?? ''));
        $nameEn = trim((string) ($_POST['name_en'] ?? ''));
        if ($nameRu === '') {
            jsonResponse(['ok' => false, 'error' => 'name_required', 'message' => 'Название RU обязательно'], 400);
        }
        if ($nameEn === '') {
            $nameEn = $nameRu;
        }

        $categories[$id] = [
            'id' => $id,
            'parent_id' => $parentId !== '' ? $parentId : null,
            'name' => [
                'ru' => $nameRu,
                'en' => $nameEn,
            ],
            'description' => [
                'ru' => trim((string) ($_POST['description_ru'] ?? '')),
                'en' => trim((string) ($_POST['description_en'] ?? '')),
            ],
        ];
        writeJson(CATEGORIES_FILE, $categories);
        jsonResponse(['ok' => true, 'message' => 'Категория создана']);
    }

    if ($api === 'get_categories' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse([
            'ok' => true,
            'categories' => array_values(array_map(static function (array $category): array {
                if (!array_key_exists('parent_id', $category)) {
                    $category['parent_id'] = null;
                }
                return $category;
            }, readJson(CATEGORIES_FILE))),
        ]);
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

    if ($api === 'edit_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            jsonResponse(['ok' => false, 'error' => 'category_not_found'], 404);
        }
        $categories = readJson(CATEGORIES_FILE);
        if (!isset($categories[$id])) {
            jsonResponse(['ok' => false, 'error' => 'category_not_found'], 404);
        }
        $parentId = trim((string) ($_POST['parent_id'] ?? ''));
        if ($parentId === $id) {
            jsonResponse(['ok' => false, 'error' => 'bad_parent_category'], 400);
        }
        if ($parentId !== '' && !isset($categories[$parentId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_parent_category'], 400);
        }
        $nameRu = trim((string) ($_POST['name_ru'] ?? ($categories[$id]['name']['ru'] ?? '')));
        $nameEn = trim((string) ($_POST['name_en'] ?? ($categories[$id]['name']['en'] ?? '')));
        if ($nameRu === '') {
            jsonResponse(['ok' => false, 'error' => 'name_required', 'message' => 'Название RU обязательно'], 400);
        }
        if ($nameEn === '') {
            $nameEn = $nameRu;
        }
        $categories[$id]['parent_id'] = $parentId !== '' ? $parentId : null;
        $categories[$id]['name'] = [
            'ru' => $nameRu,
            'en' => $nameEn,
        ];
        $categories[$id]['description'] = [
            'ru' => trim((string) ($_POST['description_ru'] ?? ($categories[$id]['description']['ru'] ?? ''))),
            'en' => trim((string) ($_POST['description_en'] ?? ($categories[$id]['description']['en'] ?? ''))),
        ];
        writeJson(CATEGORIES_FILE, $categories);
        jsonResponse(['ok' => true, 'message' => 'Категория обновлена']);
    }

    if (($api === 'add_product' || $api === 'create_product') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $categories = readJson(CATEGORIES_FILE);
        $categoryId = (string) ($_POST['category_id'] ?? '');
        if (!isset($categories[$categoryId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_category'], 400);
        }

        $id = generateId();
        $products = readJson(PRODUCTS_FILE);
        $product = [
            'id' => $id,
            'category_id' => $categoryId,
            'name' => [
                'ru' => trim((string) ($_POST['name_ru'] ?? '')),
                'en' => trim((string) ($_POST['name_en'] ?? '')),
            ],
            'description' => [
                'ru' => trim((string) ($_POST['description_ru'] ?? '')),
                'en' => trim((string) ($_POST['description_en'] ?? '')),
            ],
            'price' => (float) ($_POST['price'] ?? 0),
            'bundles' => [],
            'preview' => null,
            'stock' => 0,
            'sold' => 0,
            'active' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (isset($_FILES['files'])) {
            $hasUploadedFiles = is_array($_FILES['files']['name'] ?? null) && count(array_filter((array) $_FILES['files']['name'], static fn ($v): bool => trim((string) $v) !== '')) > 0;
            $error = null;
            $bundle = createBundleFromUpload($id, $_FILES['files'], $error);
            if ($bundle === null && $error !== null) {
                $status = $error === 'no_valid_files' ? ($hasUploadedFiles ? 400 : 200) : 500;
                if ($status !== 200) {
                    jsonResponse(['ok' => false, 'error' => $error], $status);
                }
            }
            if ($bundle !== null) {
                $product['bundles'][] = $bundle;
            }
        }
        if (isset($_FILES['preview_image']) && (int) ($_FILES['preview_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $previewError = null;
            $previewPath = saveProductPreviewImage($id, (array) $_FILES['preview_image'], $previewError);
            if ($previewPath === null) {
                jsonResponse(['ok' => false, 'error' => $previewError ?? 'preview_upload_failed'], 400);
            }
            $product['preview'] = $previewPath;
        }

        recalculateProductStock($product);
        $products[$id] = $product;

        writeJson(PRODUCTS_FILE, $products);
        jsonResponse(['ok' => true, 'message' => 'Товар добавлен']);
    }

    if ($api === 'edit_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $productId = trim((string) ($_POST['product_id'] ?? ''));
        if ($productId === '') {
            jsonResponse(['ok' => false, 'error' => 'product_not_found'], 404);
        }

        $products = readJson(PRODUCTS_FILE);
        $product = $products[$productId] ?? null;
        if (!is_array($product)) {
            jsonResponse(['ok' => false, 'error' => 'product_not_found'], 404);
        }

        $categories = readJson(CATEGORIES_FILE);
        $categoryId = trim((string) ($_POST['category_id'] ?? ''));
        if ($categoryId === '' || !isset($categories[$categoryId])) {
            jsonResponse(['ok' => false, 'error' => 'bad_category'], 400);
        }

        $product['category_id'] = $categoryId;
        $product['name'] = [
            'ru' => trim((string) ($_POST['name_ru'] ?? (($product['name']['ru'] ?? '')))),
            'en' => trim((string) ($_POST['name_en'] ?? (($product['name']['en'] ?? '')))),
        ];
        $product['description'] = [
            'ru' => trim((string) ($_POST['desc_ru'] ?? ($_POST['description_ru'] ?? ($product['description']['ru'] ?? '')))),
            'en' => trim((string) ($_POST['desc_en'] ?? ($_POST['description_en'] ?? ($product['description']['en'] ?? '')))),
        ];
        $product['price'] = (float) ($_POST['price'] ?? ($product['price'] ?? 0));
        $product['preview'] = $product['preview'] ?? null;

        if (isset($_FILES['preview_image']) && (int) ($_FILES['preview_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $previewError = null;
            $previewPath = saveProductPreviewImage($productId, (array) $_FILES['preview_image'], $previewError);
            if ($previewPath === null) {
                jsonResponse(['ok' => false, 'error' => $previewError ?? 'preview_upload_failed'], 400);
            }
            $product['preview'] = $previewPath;
        }

        recalculateProductStock($product);
        $products[$productId] = $product;
        writeJson(PRODUCTS_FILE, $products);

        jsonResponse(['ok' => true, 'message' => 'Товар обновлён ✅']);
    }

    if ($api === 'add_bundle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $productId = (string) ($_POST['product_id'] ?? '');
        if ($productId === '' || !isset($_FILES['files'])) {
            jsonResponse(['ok' => false, 'error' => 'no_files'], 400);
        }

        $products = readJson(PRODUCTS_FILE);
        $product = $products[$productId] ?? null;
        if (!is_array($product)) {
            jsonResponse(['ok' => false, 'error' => 'product_not_found'], 404);
        }

        $error = null;
        $bundle = createBundleFromUpload($productId, $_FILES['files'], $error);
        if ($bundle === null) {
            jsonResponse(['ok' => false, 'error' => $error ?? 'zip_create_failed'], $error === 'no_valid_files' ? 400 : 500);
        }

        $product['bundles'] = normalizeProductBundles($product);
        $product['bundles'][] = $bundle;
        recalculateProductStock($product);
        $products[$productId] = $product;
        writeJson(PRODUCTS_FILE, $products);

        jsonResponse(['ok' => true, 'bundle_id' => $bundle['id'], 'stock' => $product['stock']]);
    }

    if ($api === 'delete_bundle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $productId = (string) ($_POST['product_id'] ?? '');
        $bundleId = (string) ($_POST['bundle_id'] ?? '');
        if ($productId === '' || $bundleId === '') {
            jsonResponse(['ok' => false, 'error' => 'bundle_not_found'], 400);
        }

        $products = readJson(PRODUCTS_FILE);
        $product = $products[$productId] ?? null;
        if (!is_array($product)) {
            jsonResponse(['ok' => false, 'error' => 'product_not_found'], 404);
        }

        $bundles = normalizeProductBundles($product);
        $nextBundles = [];
        $removed = null;
        foreach ($bundles as $bundle) {
            if ((string) $bundle['id'] === $bundleId && $removed === null) {
                $removed = $bundle;
                continue;
            }
            $nextBundles[] = $bundle;
        }
        if ($removed === null) {
            jsonResponse(['ok' => false, 'error' => 'bundle_not_found'], 404);
        }

        $path = resolveSaleFilePath((string) ($removed['file'] ?? ''));
        if ($path !== null) {
            @unlink($path);
        }

        $product['bundles'] = $nextBundles;
        recalculateProductStock($product);
        $products[$productId] = $product;
        writeJson(PRODUCTS_FILE, $products);

        jsonResponse(['ok' => true, 'stock' => $product['stock']]);
    }

    if ($api === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (string) ($_POST['id'] ?? '');
        $products = readJson(PRODUCTS_FILE);
        $product = $products[$id] ?? null;
        if ($product) {
            foreach (normalizeProductBundles($product) as $bundle) {
                $path = resolveSaleFilePath((string) ($bundle['file'] ?? ''));
                if ($path !== null) {
                    @unlink($path);
                }
            }
            $previewPath = resolveSaleFilePath((string) ($product['preview'] ?? ''));
            if ($previewPath !== null) {
                @unlink($previewPath);
            }
            $legacyPath = resolveSaleFilePath((string) ($product['file'] ?? ''));
            if ($legacyPath !== null) {
                @unlink($legacyPath);
            }
            unset($products[$id]);
            writeJson(PRODUCTS_FILE, $products);
        }
        jsonResponse(['ok' => true, 'message' => 'Товар удалён']);
    }

    if (($api === 'topup' || $api === 'topup_balance') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (string) ($_POST['id'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            jsonResponse(['ok' => false, 'error' => 'bad_amount'], 400);
        }

        $users = readJson(USERS_FILE);
        if (!isset($users[$id])) {
            jsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        $settings = readJson(SETTINGS_FILE);
        $users[$id]['balance'] = round((float) ($users[$id]['balance'] ?? 0) + $amount, 2);
        $symbol = (string) ($settings['currency_symbol'] ?? '₽');
        $referralPercent = max(0.0, (float) ($settings['referral_percent'] ?? REFERRAL_PERCENT));

        $referrerId = (string) ($users[$id]['referred_by'] ?? '');
        if ($referrerId !== '' && $referrerId !== $id && isset($users[$referrerId])) {
            $bonus = round($amount * $referralPercent / 100, 2);
            if ($bonus > 0) {
                $users[$referrerId]['balance'] = round((float) ($users[$referrerId]['balance'] ?? 0) + $bonus, 2);
                $users[$referrerId]['referral_earned'] = round((float) ($users[$referrerId]['referral_earned'] ?? 0) + $bonus, 2);

                $refLang = ((string) ($users[$referrerId]['lang'] ?? 'ru')) === 'en' ? 'en' : 'ru';
                $bonusText = formatPrice($bonus, $settings);
                $refMessage = $refLang === 'en'
                    ? "💰 Referral bonus!\nSomeone topped up via your link.\nYou received: {$bonusText}"
                    : "💰 Партнёрский бонус!\nПо вашей ссылке пополнили баланс.\nВам начислено: {$bonusText}";
                sendTelegramMessageByBotToken($referrerId, $refMessage);
            }
        }

        writeJson(USERS_FILE, $users);
        $lang = ((string) ($users[$id]['lang'] ?? 'ru')) === 'en' ? 'en' : 'ru';
        $amountText = number_format($amount, 2, '.', '');
        $balanceText = number_format((float) $users[$id]['balance'], 2, '.', '');
        $message = $lang === 'en'
            ? "✅ Your balance has been topped up by {$amountText} {$symbol}\n💰 Current balance: {$balanceText} {$symbol}"
            : "✅ Ваш баланс пополнен на {$amountText} {$symbol}\n💰 Текущий баланс: {$balanceText} {$symbol}";
        sendTelegramTopupNotification($id, $message);
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
        $settings['admin_username'] = $username;
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? ($settings['currency'] ?? 'RUB'))));
        $currency = preg_replace('/[^A-Z0-9]/', '', $currency) ?: 'RUB';
        $settings['currency'] = substr($currency, 0, 5);
        $currencySymbol = trim((string) ($_POST['currency_symbol'] ?? ($settings['currency_symbol'] ?? '₽')));
        $settings['currency_symbol'] = function_exists('mb_substr') ? mb_substr($currencySymbol, 0, 5) : substr($currencySymbol, 0, 5);
        $referralPercent = (float) ($_POST['referral_percent'] ?? ($settings['referral_percent'] ?? REFERRAL_PERCENT));
        $settings['referral_percent'] = round(max(0.0, min(100.0, $referralPercent)), 2);
        $settings['support_username'] = preg_replace('/[^a-zA-Z0-9_]/', '', ltrim((string) ($_POST['support_username'] ?? ($settings['support_username'] ?? '')), '@'));
        $settings['bot_username'] = preg_replace('/[^a-zA-Z0-9_]/', '', ltrim((string) ($_POST['bot_username'] ?? ($settings['bot_username'] ?? '')), '@'));
        $settings['help_text'] = [
            'ru' => trim((string) ($_POST['help_text_ru'] ?? '')),
            'en' => trim((string) ($_POST['help_text_en'] ?? '')),
        ];
        writeJson(SETTINGS_FILE, $settings);
        jsonResponse(['ok' => true, 'message' => 'Настройки сохранены']);
    }

    if ($api === 'upload_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['logo'])) {
            jsonResponse(['ok' => false, 'error' => 'logo_upload_failed'], 400);
        }
        $error = null;
        $logoPath = saveBotLogo((array) $_FILES['logo'], $error);
        if ($logoPath === null) {
            jsonResponse(['ok' => false, 'error' => $error ?? 'logo_upload_failed'], 400);
        }
        $settings = readJson(SETTINGS_FILE);
        $settings['logo_path'] = $logoPath;
        writeJson(SETTINGS_FILE, $settings);
        jsonResponse(['ok' => true, 'logo_path' => $logoPath, 'message' => 'Логотип загружен']);
    }

    if ($api === 'delete_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $settings = readJson(SETTINGS_FILE);
        $logoPath = resolveSaleFilePath((string) ($settings['logo_path'] ?? ''));
        if ($logoPath !== null) {
            @unlink($logoPath);
        }
        $settings['logo_path'] = '';
        writeJson(SETTINGS_FILE, $settings);
        jsonResponse(['ok' => true, 'message' => 'Логотип удалён']);
    }

    jsonResponse(['ok' => false, 'error' => 'unknown_api'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'set_webhook') {
    requireAuth();
    setWebhookEndpoint();
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
        body{margin:0;font-family:system-ui,Segoe UI,Roboto,Arial;background:#0f0f0f;color:#e6e6e6}
        .wrap{max-width:1180px;margin:24px auto;padding:0 16px}
        .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:16px}
        .row{display:flex;gap:12px;flex-wrap:wrap}
        input,select,button,textarea{background:#2a2a2a;border:1px solid #3a3a3a;color:#fff;padding:10px 14px;border-radius:8px}
        input:focus,select:focus,textarea:focus{border-color:#6366f1;outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
        button{cursor:pointer;background:#2a2a2a}
        button:hover{background:#303030}
        .btn-accent{border-color:#6366f1;background:#6366f1}
        .btn-accent:hover{background:#5558df}
        table{width:100%;border-collapse:collapse}
        th,td{border-bottom:1px solid #283041;padding:8px;text-align:left;font-size:14px}
        .tabs button{margin-right:8px}
        .hidden{display:none}
        .drop{border:1px dashed #3a465f;padding:14px;border-radius:10px;text-align:center}
        .muted{color:#9aa4b5;font-size:13px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
        .stat{background:#111;border:1px solid #2a2a2a;border-radius:10px;padding:12px}
        .bar{height:8px;background:#1c2230;border-radius:100px;overflow:hidden}
        .bar>span{display:block;height:100%;background:#6366f1;width:0}
        .settings-layout{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
        .settings-block{background:#1a1a1a;border-radius:12px;padding:24px;margin-bottom:0;border:1px solid #2a2a2a}
        .settings-block.full{grid-column:1/-1}
        .settings-block-title{font-size:13px;font-weight:600;color:#6366f1;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid #2a2a2a}
        .settings-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .settings-field{display:flex;flex-direction:column}
        .settings-field.full{grid-column:1/-1}
        .settings-label{font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
        .settings-input{width:100%;padding:10px 14px;background:#2a2a2a;border:1px solid #3a3a3a;border-radius:8px;color:#fff;font-size:15px;box-sizing:border-box}
        .settings-input:focus{border-color:#6366f1;outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
        .settings-textarea{min-height:100px;resize:vertical}
        .settings-save{width:100%;padding:12px 14px;border-radius:10px;border:1px solid #6366f1;background:#6366f1;color:#fff;font-size:15px;font-weight:600}
        .settings-save:hover{background:#5458db}
        .lang-switch{display:flex;gap:6px;align-items:center}
        .lang-btn{padding:8px 10px;font-size:13px}
        .lang-btn.active{background:#6366f1;border-color:#6366f1}
        .section-title{margin:0 0 16px}
        .grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .product-list,.category-list{display:grid;grid-template-columns:1fr;gap:12px}
        .item-card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px}
        .item-meta{display:flex;gap:14px;flex-wrap:wrap;color:#aaa;font-size:13px}
        .thumb{width:60px;height:60px;border-radius:10px;object-fit:cover;border:1px solid #2a2a2a;background:#111}
        .logo-preview{width:100px;height:100px;border-radius:18px;object-fit:cover;border:1px solid #2a2a2a;background:#111}
        .logo-drop{border:1px dashed #3a3a3a;padding:14px;border-radius:10px;text-align:center}
        .inline-form{margin-top:12px;padding-top:12px;border-top:1px solid #2a2a2a}
        @media (max-width: 900px){
            .settings-layout{grid-template-columns:1fr}
            .grid-2{grid-template-columns:1fr}
        }
        @media (max-width: 640px){
            .settings-fields{grid-template-columns:1fr}
        }
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
            <div class="row" style="justify-content:space-between;align-items:center">
                <h2 data-i18n="login_title">Вход в админку</h2>
                <div class="lang-switch" id="langSwitch">
                    <button type="button" class="lang-btn" data-lang-btn="ru">🇷🇺 RU</button>
                    <button type="button" class="lang-btn" data-lang-btn="en">🇬🇧 EN</button>
                </div>
            </div>
            <form id="loginForm">
                <input type="password" name="password" data-i18n-placeholder="password_placeholder" placeholder="Пароль" required style="width:100%;box-sizing:border-box">
                <button style="margin-top:10px;width:100%" class="btn-accent" data-i18n="login_btn">Войти</button>
            </form>
            <div id="loginError" class="muted"></div>
        </div>
        <script>
            const loginI18n = {
                ru: {login_title:'Вход в админку',password_placeholder:'Пароль',login_btn:'Войти',wrong_password:'Неверный пароль'},
                en: {login_title:'Admin login',password_placeholder:'Password',login_btn:'Sign in',wrong_password:'Wrong password'},
            };
            const loginStored = localStorage.getItem('admin_lang');
            const loginLang = loginStored === 'ru' || loginStored === 'en'
                ? loginStored
                : ((navigator.language || '').toLowerCase().startsWith('ru') ? 'ru' : 'en');
            let currentLoginLang = loginLang;
            function trLogin(key){ return loginI18n[currentLoginLang]?.[key] ?? loginI18n.ru[key] ?? key; }
            function applyLoginI18n(){
                document.querySelectorAll('[data-i18n]').forEach(el=>{
                    const key = el.getAttribute('data-i18n');
                    if(key){ el.textContent = trLogin(key); }
                });
                document.querySelectorAll('[data-i18n-placeholder]').forEach(el=>{
                    const key = el.getAttribute('data-i18n-placeholder');
                    if(key){ el.setAttribute('placeholder', trLogin(key)); }
                });
                document.querySelectorAll('[data-lang-btn]').forEach(btn=>btn.classList.toggle('active', btn.dataset.langBtn === currentLoginLang));
            }
            document.querySelectorAll('[data-lang-btn]').forEach(btn=>btn.onclick=()=>{
                currentLoginLang = btn.dataset.langBtn === 'en' ? 'en' : 'ru';
                localStorage.setItem('admin_lang', currentLoginLang);
                applyLoginI18n();
            });
            applyLoginI18n();
            loginForm.onsubmit = async (e) => {
                e.preventDefault();
                const form = new FormData(loginForm);
                const res = await fetch('?api=login',{method:'POST',body:form});
                const json = await res.json();
                if(json.ok){ location.reload(); return; }
                loginError.textContent = trLogin('wrong_password');
            };
        </script>
    <?php else: ?>
        <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:10px">
            <h2 style="margin:0" data-i18n="admin_title">Панель управления</h2>
            <div class="lang-switch">
                <button type="button" class="lang-btn" data-lang-btn="ru">🇷🇺 RU</button>
                <button type="button" class="lang-btn" data-lang-btn="en">🇬🇧 EN</button>
                <button id="logoutBtn" data-i18n="logout_btn">Выйти</button>
            </div>
        </div>

        <div class="card" id="stats"></div>

        <div class="card tabs">
            <button data-tab="categories" data-i18n="tab_categories">📦 Категории</button>
            <button data-tab="products" data-i18n="tab_products">🗂 Товары</button>
            <button data-tab="users" data-i18n="tab_users">👥 Пользователи</button>
            <button data-tab="settings" data-i18n="tab_settings">⚙️ Настройки</button>
        </div>

        <div id="categories" class="card"></div>
        <div id="products" class="card hidden"></div>
        <div id="users" class="card hidden"></div>
        <div id="settings" class="card hidden"></div>

        <script>
            const PAGE_SIZE = 10;
            const state = {categories:[],products:[],users:[],settings:{},stats:{}};
            const uiState = {usersQuery:'',usersPage:1,productsQuery:'',productsPage:1};
            const i18n = {
                ru: {
                    admin_title:'Панель управления', logout_btn:'Выйти', tab_categories:'📦 Категории', tab_products:'🗂 Товары', tab_users:'👥 Пользователи', tab_settings:'⚙️ Настройки',
                    stats_title:'📊 Статистика', stats_users:'Всего пользователей', stats_sales:'Всего продаж', stats_revenue:'Общая выручка', latest_purchases:'Последние покупки', no_data:'Нет данных',
                    categories_title:'📦 Категории', category_create:'Создать категорию', parent_optional:'Родительская категория (необязательно)', parent_category:'Родительская категория', root_category:'— Корневая категория —', name_ru:'Название RU', name_en:'Название EN', description_ru:'Описание RU', description_en:'Описание EN', add_category:'Добавить категорию', no_categories:'Нет категорий',
                    products_title:'🗂 Товары', search_products:'Поиск: название (RU/EN) или категория', found_products:'Найдено: {count} товаров', product_create:'Добавить товар', main_block:'Основное', description_block:'Описание', price_category:'Цена и категория', media_block:'Медиа', category:'Категория', price:'Цена', add_product:'Добавить товар',
                    preview_drop:'Перетащите изображение сюда или выберите вручную', files_drop:'Перетащите архивы сюда или выберите вручную', archives_count:'Архивов: {count}', edit:'✏️ Редактировать', archives:'📦 Архивы ({count})', delete:'🗑 Удалить', save:'Сохранить',
                    users_title:'👥 Пользователи', search_users:'Поиск: Telegram ID, имя, username', found_users:'Найдено: {count} пользователей', topup:'Пополнить', history:'История', history_empty:'История пуста', history_loaded:'История загружена',
                    settings_title:'⚙️ Настройки', bot_block:'Бот', currency_block:'Валюта', referral_block:'Партнёрская программа', support_block:'Саппорт', webhook_block:'Вебхук', webhook_url:'Webhook URL', set_webhook_btn:'🔗 Установить вебхук', webhook_set_success:'Вебхук установлен!', help_texts:'Тексты помощи', logo_block:'Логотип бота', upload_logo:'Загрузить логотип', delete_logo:'Удалить логотип',
                    bot_username:'Username бота', admin_username:'Username администратора', currency_code:'Код валюты', currency_symbol:'Символ валюты', referral_percent:'Процент реферального бонуса (%)', support_username:'Username саппорта', help_ru:'Текст помощи RU', help_en:'Текст помощи EN',
                    save_settings:'Сохранить настройки', settings_hint:'admin_username показывается пользователям в инструкции пополнения. support_username используется в разделе помощи.',
                    logo_uploaded:'Логотип загружен', logo_deleted:'Логотип удалён',
                    err_prefix:'Ошибка', unauthorized:'требуется авторизация', wrong_password:'неверный пароль', category_has_products:'у категории есть товары', category_has_subcategories:'у категории есть подкатегории', category_not_found:'категория не найдена', bad_category:'категория не найдена', bad_parent_category:'родительская категория не найдена', name_required:'Название RU обязательно', bad_amount:'некорректная сумма', user_not_found:'пользователь не найден', no_files:'выберите файлы', no_valid_files:'нет валидных файлов', zip_create_failed:'не удалось создать архив', preview_invalid_type:'поддерживаются только изображения (jpg/png/webp/gif)', preview_upload_failed:'не удалось загрузить изображение', product_not_found:'товар не найден', bundle_not_found:'архив не найден', username_invalid_format:'username администратора некорректный', unknown_api:'неизвестный API-метод', logo_invalid_type:'поддерживаются jpg/png/webp', logo_upload_failed:'не удалось загрузить логотип', bad_webhook_url:'webhook должен начинаться с https://', bot_token_missing:'BOT_TOKEN не задан', webhook_request_failed:'не удалось отправить запрос к Telegram', bad_telegram_response:'невалидный ответ Telegram', webhook_set_failed:'не удалось установить вебхук',
                    created_ok:'Создано ✅', updated_ok:'Обновлено ✅', deleted_ok:'Удалено', request_failed:'ошибка запроса', loading_failed:'ошибка загрузки данных', upload_failed:'ошибка загрузки', logout_failed:'не удалось выйти', topup_prompt:'Сумма пополнения:'
                },
                en: {
                    admin_title:'Admin panel', logout_btn:'Logout', tab_categories:'📦 Categories', tab_products:'🗂 Products', tab_users:'👥 Users', tab_settings:'⚙️ Settings',
                    stats_title:'📊 Statistics', stats_users:'Total users', stats_sales:'Total sales', stats_revenue:'Total revenue', latest_purchases:'Latest purchases', no_data:'No data',
                    categories_title:'📦 Categories', category_create:'Create category', parent_optional:'Parent category (optional)', parent_category:'Parent category', root_category:'— Root category —', name_ru:'Name RU', name_en:'Name EN', description_ru:'Description RU', description_en:'Description EN', add_category:'Add category', no_categories:'No categories',
                    products_title:'🗂 Products', search_products:'Search: name (RU/EN) or category', found_products:'Found: {count} products', product_create:'Add product', main_block:'Main', description_block:'Description', price_category:'Price & category', media_block:'Media', category:'Category', price:'Price', add_product:'Add product',
                    preview_drop:'Drag and drop preview image or choose manually', files_drop:'Drag and drop archives or choose manually', archives_count:'Archives: {count}', edit:'✏️ Edit', archives:'📦 Archives ({count})', delete:'🗑 Delete', save:'Save',
                    users_title:'👥 Users', search_users:'Search: Telegram ID, name, username', found_users:'Found: {count} users', topup:'Top up', history:'History', history_empty:'History is empty', history_loaded:'History loaded',
                    settings_title:'⚙️ Settings', bot_block:'Bot', currency_block:'Currency', referral_block:'Referral program', support_block:'Support', webhook_block:'Webhook', webhook_url:'Webhook URL', set_webhook_btn:'🔗 Set webhook', webhook_set_success:'Webhook installed!', help_texts:'Help texts', logo_block:'Bot logo', upload_logo:'Upload logo', delete_logo:'Delete logo',
                    bot_username:'Bot username', admin_username:'Admin username', currency_code:'Currency code', currency_symbol:'Currency symbol', referral_percent:'Referral bonus percent (%)', support_username:'Support username', help_ru:'Help text RU', help_en:'Help text EN',
                    save_settings:'Save settings', settings_hint:'admin_username is shown in top-up instructions. support_username is used in the help section.',
                    logo_uploaded:'Logo uploaded', logo_deleted:'Logo deleted',
                    err_prefix:'Error', unauthorized:'authorization required', wrong_password:'wrong password', category_has_products:'category has products', category_has_subcategories:'category has subcategories', category_not_found:'category not found', bad_category:'category not found', bad_parent_category:'parent category not found', name_required:'Name RU is required', bad_amount:'invalid amount', user_not_found:'user not found', no_files:'choose files', no_valid_files:'no valid files', zip_create_failed:'failed to create archive', preview_invalid_type:'only images are supported (jpg/png/webp/gif)', preview_upload_failed:'failed to upload image', product_not_found:'product not found', bundle_not_found:'archive not found', username_invalid_format:'invalid admin username', unknown_api:'unknown API method', logo_invalid_type:'only jpg/png/webp are supported', logo_upload_failed:'failed to upload logo', bad_webhook_url:'webhook must start with https://', bot_token_missing:'BOT_TOKEN is not set', webhook_request_failed:'failed to send request to Telegram', bad_telegram_response:'invalid Telegram response', webhook_set_failed:'failed to set webhook',
                    created_ok:'Created ✅', updated_ok:'Updated ✅', deleted_ok:'Deleted', request_failed:'request failed', loading_failed:'failed to load data', upload_failed:'upload failed', logout_failed:'failed to logout', topup_prompt:'Top-up amount:'
                },
            };
            const browserLang = (navigator.language || '').toLowerCase().startsWith('ru') ? 'ru' : 'en';
            let currentLang = (localStorage.getItem('admin_lang') === 'ru' || localStorage.getItem('admin_lang') === 'en')
                ? localStorage.getItem('admin_lang')
                : browserLang;
            function tr(key, vars = {}){
                let text = i18n[currentLang]?.[key] ?? i18n.ru[key] ?? key;
                Object.entries(vars).forEach(([k,v])=>{ text = text.replaceAll(`{${k}}`, String(v)); });
                return text;
            }
            function applyI18n(root = document){
                root.querySelectorAll('[data-i18n]').forEach(el=>{
                    const key = el.getAttribute('data-i18n');
                    if(key){ el.textContent = tr(key); }
                });
                root.querySelectorAll('[data-i18n-placeholder]').forEach(el=>{
                    const key = el.getAttribute('data-i18n-placeholder');
                    if(key){ el.setAttribute('placeholder', tr(key)); }
                });
                document.querySelectorAll('[data-lang-btn]').forEach(btn=>btn.classList.toggle('active', btn.dataset.langBtn === currentLang));
                document.documentElement.lang = currentLang;
            }
            document.querySelectorAll('[data-lang-btn]').forEach(btn=>btn.onclick=()=>{
                currentLang = btn.dataset.langBtn === 'en' ? 'en' : 'ru';
                localStorage.setItem('admin_lang', currentLang);
                renderStats(); renderCategories(); renderProducts(); renderUsers(); renderSettings();
                applyI18n();
            });

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

            function apiError(j, fallback = 'request_failed'){
                if(j?.message){ return `${tr('err_prefix')}: ${j.message}`; }
                if(j?.error){ return `${tr('err_prefix')}: ${tr(j.error)}`; }
                return `${tr('err_prefix')}: ${tr(fallback)}`;
            }

            logoutBtn.onclick = async()=>{
                try{
                    const res = await fetch('?api=logout');
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'logout_failed'), 'error'); return; }
                    location.reload();
                }catch(_){
                    showToast(apiError({}, 'logout_failed'), 'error');
                }
            };

            function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[m]));}
            function categoryName(c){return c?.name?.ru||c?.name?.en||'—'}
            function withParent(category){ return {...category, parent_id: category?.parent_id || null}; }
            function normalizedCategories(){ return state.categories.map(withParent); }
            function rootCategories(){ return normalizedCategories().filter(c => c.parent_id === null); }
            function flattenCategories(parentId = null, depth = 0, out = [], seen = new Set()){
                normalizedCategories()
                    .filter(c => c.parent_id === parentId)
                    .forEach(c => {
                        if(seen.has(c.id)){ return; }
                        seen.add(c.id);
                        out.push({category: c, depth});
                        flattenCategories(c.id, depth + 1, out, seen);
                    });
                return out;
            }
            function childrenByParent(){
                const map = {};
                normalizedCategories().forEach(c => {
                    const key = c.parent_id ?? '__root__';
                    if(!map[key]){ map[key] = []; }
                    map[key].push(c);
                });
                return map;
            }
            function productCategoryOptions(){
                const childrenMap = childrenByParent();
                return rootCategories().map(root => {
                    const children = childrenMap[root.id] || [];
                    if(children.length === 0){
                        return `<option value="${esc(root.id)}">${esc(categoryName(root))}</option>`;
                    }
                    const head = `<option value="" disabled>${esc(categoryName(root))}</option>`;
                    const childOptions = children
                        .map(child => `<option value="${esc(child.id)}">&nbsp;&nbsp;└─ ${esc(categoryName(child))}</option>`)
                        .join('');
                    return head + childOptions;
                }).join('');
            }

            function renderStats(){
                stats.innerHTML = `
                    <h3 class="section-title">${tr('stats_title')}</h3>
                    <div class="stats">
                        <div class="stat">${tr('stats_users')}<br><b>${state.stats.users||0}</b></div>
                        <div class="stat">${tr('stats_sales')}<br><b>${state.stats.sales||0}</b></div>
                        <div class="stat">${tr('stats_revenue')}<br><b>${(state.stats.revenue||0).toFixed(2)} ${esc(state.settings.currency_symbol||'₽')}</b></div>
                    </div>
                    <h4>${tr('latest_purchases')}</h4>
                    <table><tr><th>Дата</th><th>${tr('tab_users')}</th><th>${tr('tab_products')}</th><th>${tr('price')}</th></tr>
                    ${(state.stats.latest||[]).map(x=>`<tr><td>${esc(x.date)}</td><td>${esc(x.user_name)} (#${esc(x.user_id)})</td><td>${esc(x.product_name)}</td><td>${Number(x.price||0).toFixed(2)}</td></tr>`).join('') || `<tr><td colspan="4">${tr('no_data')}</td></tr>`}
                    </table>
                `;
            }

            function renderCategories(){
                const directProductCountByCategory = {};
                state.products.forEach(p=>directProductCountByCategory[p.category_id]=(directProductCountByCategory[p.category_id]||0)+1);
                const childrenMap = childrenByParent();
                const totalProductCountByCategory = {};
                const countProductsRecursive = (categoryId) => {
                    if(Object.prototype.hasOwnProperty.call(totalProductCountByCategory, categoryId)){
                        return totalProductCountByCategory[categoryId];
                    }
                    const own = directProductCountByCategory[categoryId] || 0;
                    const children = childrenMap[categoryId] || [];
                    const childrenTotal = children.reduce((sum, child)=>sum + countProductsRecursive(child.id), 0);
                    totalProductCountByCategory[categoryId] = own + childrenTotal;
                    return totalProductCountByCategory[categoryId];
                };
                normalizedCategories().forEach(c => countProductsRecursive(c.id));
                const rows = flattenCategories();
                const allCats = normalizedCategories();
                categories.innerHTML = `
                    <h3 class="section-title">${tr('categories_title')}</h3>
                    <div class="item-card">
                        <h4 style="margin-top:0">${tr('category_create')}</h4>
                        <form id="catForm" class="grid-2">
                            <select name="parent_id">
                                <option value="">${tr('root_category')}</option>
                                ${rows.map(({category, depth})=>`<option value="${esc(category.id)}">${esc(`${'— '.repeat(depth)}${categoryName(category)}`)}</option>`).join('')}
                            </select>
                            <div></div>
                            <input name="name_ru" data-i18n-placeholder="name_ru" placeholder="${tr('name_ru')}" required>
                            <input name="name_en" data-i18n-placeholder="name_en" placeholder="${tr('name_en')}">
                            <textarea name="description_ru" data-i18n-placeholder="description_ru" placeholder="${tr('description_ru')}"></textarea>
                            <textarea name="description_en" data-i18n-placeholder="description_en" placeholder="${tr('description_en')}"></textarea>
                            <button class="btn-accent" style="grid-column:1/-1">${tr('add_category')}</button>
                        </form>
                    </div>
                    <div class="category-list">
                        ${rows.map(({category, depth})=>{
                            const children = childrenMap[category.id] || [];
                            const parentName = category.parent_id ? categoryName(allCats.find(c=>c.id===category.parent_id)) : '—';
                            const disallowDelete = children.length > 0 || (directProductCountByCategory[category.id]||0) > 0;
                            return `<div class="item-card">
                                <div class="row" style="justify-content:space-between;align-items:center">
                                    <div>
                                        <b>${esc(`${'— '.repeat(depth)}${categoryName(category)}`)}</b>
                                        <div class="item-meta">
                                            <span>RU: ${esc(category.name?.ru||'')}</span>
                                            <span>EN: ${esc(category.name?.en||'')}</span>
                                            <span>${tr('parent_optional')}: ${esc(parentName||'—')}</span>
                                            <span>${tr('tab_products')}: ${totalProductCountByCategory[category.id]||0}</span>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <button data-toggle-edit-cat="${esc(category.id)}">${tr('edit')}</button>
                                        <button data-del-cat="${esc(category.id)}" ${disallowDelete ? 'disabled' : ''}>${tr('delete')}</button>
                                    </div>
                                </div>
                                <form id="edit-cat-${esc(category.id)}" class="grid-2 inline-form hidden" data-edit-category="${esc(category.id)}">
                                    <input type="hidden" name="id" value="${esc(category.id)}">
                                    <select name="parent_id">
                                        <option value="">${tr('root_category')}</option>
                                        ${rows.filter(x=>x.category.id!==category.id).map(({category:c, depth:d})=>`<option value="${esc(c.id)}" ${String(category.parent_id||'')===String(c.id)?'selected':''}>${esc(`${'— '.repeat(d)}${categoryName(c)}`)}</option>`).join('')}
                                    </select>
                                    <div></div>
                                    <input name="name_ru" value="${esc(category.name?.ru||'')}" placeholder="${tr('name_ru')}" required>
                                    <input name="name_en" value="${esc(category.name?.en||'')}" placeholder="${tr('name_en')}">
                                    <textarea name="description_ru" placeholder="${tr('description_ru')}">${esc(category.description?.ru||'')}</textarea>
                                    <textarea name="description_en" placeholder="${tr('description_en')}">${esc(category.description?.en||'')}</textarea>
                                    <button class="btn-accent" style="grid-column:1/-1">${tr('save')}</button>
                                </form>
                            </div>`;
                        }).join('') || `<div class="muted">${tr('no_categories')}</div>`}
                    </div>
                `;
                catForm.onsubmit = async (e)=>{
                    e.preventDefault();
                    const res = await fetch('?api=create_category',{method:'POST',body:new FormData(catForm)});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(tr('created_ok'));
                };
                document.querySelectorAll('[data-toggle-edit-cat]').forEach(btn=>btn.onclick=()=>{
                    const row = document.getElementById(`edit-cat-${btn.dataset.toggleEditCat}`);
                    if(row){ row.classList.toggle('hidden'); }
                });
                document.querySelectorAll('[data-edit-category]').forEach(form=>form.onsubmit=async(e)=>{
                    e.preventDefault();
                    const res = await fetch('?api=edit_category',{method:'POST',body:new FormData(form)});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(tr('updated_ok'));
                });
                document.querySelectorAll('[data-del-cat]').forEach(btn=>btn.onclick=async()=>{
                    const fd = new FormData(); fd.append('id', btn.dataset.delCat);
                    const r = await fetch('?api=delete_category',{method:'POST',body:fd});
                    const j = await r.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(j.message || tr('deleted_ok'));
                });
            }

            function renderProducts(){
                const categoryLabelById = {};
                flattenCategories().forEach(({category, depth}) => {
                    categoryLabelById[category.id] = `${'— '.repeat(depth)}${categoryName(category)}`;
                });
                const flatCategoryOptions = flattenCategories().map(({category, depth}) => ({
                    id: String(category.id || ''),
                    label: `${'— '.repeat(depth)}${categoryName(category)}`,
                }));
                const query = uiState.productsQuery.trim().toLowerCase();
                const filteredProducts = state.products.filter((p) => {
                    if(query === ''){ return true; }
                    const nameRu = String(p?.name?.ru || '').toLowerCase();
                    const nameEn = String(p?.name?.en || '').toLowerCase();
                    const category = String(categoryLabelById[p.category_id] || '').toLowerCase();
                    return nameRu.includes(query) || nameEn.includes(query) || category.includes(query);
                });
                const totalPages = Math.max(1, Math.ceil(filteredProducts.length / PAGE_SIZE));
                if(uiState.productsPage > totalPages){ uiState.productsPage = totalPages; }
                const start = (uiState.productsPage - 1) * PAGE_SIZE;
                const pageProducts = filteredProducts.slice(start, start + PAGE_SIZE);

                products.innerHTML = `
                    <h3 class="section-title">${tr('products_title')}</h3>
                    <div class="row" style="margin-bottom:10px">
                        <input id="productsSearch" data-i18n-placeholder="search_products" placeholder="${tr('search_products')}" value="${esc(uiState.productsQuery)}" oninput="window.__onProductsSearchInput(this.value)" style="min-width:320px;flex:1 1 320px">
                    </div>
                    <div class="muted" style="margin-bottom:10px">${tr('found_products', {count: filteredProducts.length})}</div>
                    <form id="productForm" class="item-card">
                        <div class="settings-block-title">${tr('product_create')}</div>
                        <div class="grid-2">
                            <input name="name_ru" placeholder="${tr('name_ru')}" required>
                            <input name="name_en" placeholder="${tr('name_en')}" required>
                            <textarea name="description_ru" placeholder="${tr('description_ru')}"></textarea>
                            <textarea name="description_en" placeholder="${tr('description_en')}"></textarea>
                            <input name="price" type="number" step="0.01" min="0" placeholder="${tr('price')}" required>
                            <select name="category_id" required>
                                <option value="">${tr('category')}</option>
                                ${productCategoryOptions()}
                            </select>
                        </div>
                        <label style="display:block;margin-top:10px">${tr('media_block')}</label>
                        <input type="file" name="preview_image" accept="image/jpeg,image/png,image/webp,image/gif">
                        <div id="dropZone" class="logo-drop" style="margin-top:10px">${tr('files_drop')}</div>
                        <input id="fileInput" type="file" name="files[]" multiple style="margin-top:10px">
                        <div id="fileNames" class="muted"></div>
                        <div class="bar" style="margin:10px 0"><span id="uploadBar"></span></div>
                        <button class="btn-accent" style="width:100%">${tr('add_product')}</button>
                    </form>
                    <div class="product-list" style="margin-top:16px">
                        ${pageProducts.map(p=>{
                            const bundles = Array.isArray(p.bundles) ? p.bundles : [];
                            const editCategoryOptions = flatCategoryOptions.map(opt => `<option value="${esc(opt.id)}" ${String(p.category_id || '') === opt.id ? 'selected' : ''}>${esc(opt.label)}</option>`).join('');
                            const preview = p.preview ? `<img src="../${esc(p.preview)}" alt="" class="thumb">` : '<div class="thumb"></div>';
                            return `<div class="item-card">
                                <div class="row" style="justify-content:space-between;align-items:flex-start">
                                    <div class="row" style="align-items:center">
                                        ${preview}
                                        <div>
                                            <b>${esc(p.name?.ru||p.name?.en||'—')}</b>
                                            <div class="item-meta">
                                                <span>${tr('category')}: ${esc(categoryLabelById[p.category_id]||'—')}</span>
                                                <span>${tr('price')}: ${Number(p.price||0).toFixed(2)}</span>
                                                <span>${tr('archives_count', {count: bundles.length})}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <button data-toggle-edit="${esc(p.id)}">${tr('edit')}</button>
                                        <button data-toggle-bundles="${esc(p.id)}">${tr('archives', {count: bundles.length})}</button>
                                        <button data-del-prod="${esc(p.id)}">${tr('delete')}</button>
                                    </div>
                                </div>
                                <form id="edit-row-${esc(p.id)}" class="grid-2 inline-form hidden" data-edit-product="${esc(p.id)}">
                                    <input type="hidden" name="product_id" value="${esc(p.id)}">
                                    <input name="name_ru" placeholder="${tr('name_ru')}" required value="${esc(p.name?.ru || '')}">
                                    <input name="name_en" placeholder="${tr('name_en')}" required value="${esc(p.name?.en || '')}">
                                    <textarea name="desc_ru" placeholder="${tr('description_ru')}">${esc(p.description?.ru || '')}</textarea>
                                    <textarea name="desc_en" placeholder="${tr('description_en')}">${esc(p.description?.en || '')}</textarea>
                                    <select name="category_id" required>${editCategoryOptions}</select>
                                    <input name="price" type="number" step="0.01" min="0" placeholder="${tr('price')}" required value="${Number(p.price || 0).toFixed(2)}">
                                    <input type="file" name="preview_image" accept="image/jpeg,image/png,image/webp,image/gif" style="grid-column:1/-1">
                                    <button class="btn-accent" style="grid-column:1/-1">${tr('save')}</button>
                                </form>
                                <div id="bundles-row-${esc(p.id)}" class="inline-form hidden">
                                    ${(bundles.map(bundle=>`<div class="row" style="margin:6px 0;align-items:center"><span>${esc(bundle.id)}.zip</span><span class="muted">${esc(bundle.created_at||'')}</span><button data-del-bundle="${esc(p.id)}:${esc(bundle.id)}">${tr('delete')}</button></div>`).join('')) || `<div class="muted">${tr('no_data')}</div>`}
                                    <form class="row" data-add-bundle="${esc(p.id)}" style="margin-top:8px;align-items:center">
                                        <input type="file" name="files[]" multiple required>
                                        <button>+ ${tr('archives', {count: ''}).replace(' ()','')}</button>
                                        <span class="muted">${tr('archives_count', {count: bundles.length})}</span>
                                    </form>
                                </div>
                            </div>`;
                        }).join('') || `<div class="muted">${tr('no_data')}</div>`}
                    </div>
                    <div class="row" style="margin-top:12px;align-items:center">
                        <button id="productsPrev" ${uiState.productsPage <= 1 ? 'disabled' : ''}>←</button>
                        <button id="productsNext" ${uiState.productsPage >= totalPages ? 'disabled' : ''}>→</button>
                    </div>
                `;

                window.__onProductsSearchInput = (value)=>{ uiState.productsQuery = value; uiState.productsPage = 1; renderProducts(); };
                const productsPrev = document.getElementById('productsPrev');
                const productsNext = document.getElementById('productsNext');
                if(productsPrev){ productsPrev.onclick = ()=>{ if(uiState.productsPage > 1){ uiState.productsPage--; renderProducts(); } }; }
                if(productsNext){ productsNext.onclick = ()=>{ if(uiState.productsPage < totalPages){ uiState.productsPage++; renderProducts(); } }; }

                const input = document.getElementById('fileInput');
                const names = document.getElementById('fileNames');
                const dz = document.getElementById('dropZone');
                const refreshNames = ()=>{ names.textContent = [...input.files].map(f=>f.name).join(', '); };
                input.onchange = refreshNames;
                dz.ondragover = (e)=>{e.preventDefault(); dz.style.borderColor='#6366f1';};
                dz.ondragleave = ()=>{dz.style.borderColor='';};
                dz.ondrop = (e)=>{ e.preventDefault(); dz.style.borderColor=''; input.files = e.dataTransfer.files; refreshNames(); };

                productForm.onsubmit = async (e)=>{
                    e.preventDefault();
                    const fd = new FormData(productForm);
                    const xhr = new XMLHttpRequest();
                    xhr.upload.onprogress = (ev)=>{ if(ev.lengthComputable){ uploadBar.style.width = ((ev.loaded/ev.total)*100).toFixed(1) + '%'; } };
                    xhr.onreadystatechange = async ()=>{
                        if(xhr.readyState === 4){
                            uploadBar.style.width = '0%';
                            try{
                                const j = JSON.parse(xhr.responseText || '{}');
                                if(!j.ok){ showToast(apiError(j, 'upload_failed'), 'error'); return; }
                                await load();
                                showToast(j.message || tr('created_ok'));
                            }catch(_){ showToast(apiError({}, 'request_failed'), 'error'); }
                        }
                    };
                    xhr.open('POST','?api=create_product');
                    xhr.send(fd);
                };

                document.querySelectorAll('[data-toggle-bundles]').forEach(btn=>btn.onclick=()=>{
                    const row = document.getElementById(`bundles-row-${btn.dataset.toggleBundles}`);
                    if(row){ row.classList.toggle('hidden'); }
                });
                document.querySelectorAll('[data-toggle-edit]').forEach(btn=>btn.onclick=()=>{
                    const row = document.getElementById(`edit-row-${btn.dataset.toggleEdit}`);
                    if(row){ row.classList.toggle('hidden'); }
                });
                document.querySelectorAll('[data-edit-product]').forEach(form=>form.onsubmit=async(e)=>{
                    e.preventDefault();
                    const res = await fetch('?api=edit_product',{method:'POST',body:new FormData(form)});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(tr('updated_ok'));
                });

                document.querySelectorAll('[data-add-bundle]').forEach(form=>form.onsubmit=async(e)=>{
                    e.preventDefault();
                    const productId = form.dataset.addBundle;
                    const file = form.querySelector('input[type="file"]');
                    if(!file || !file.files.length){ showToast(apiError({error:'no_files'}), 'error'); return; }
                    const fd = new FormData(form);
                    fd.append('product_id', productId);
                    const res = await fetch('?api=add_bundle',{method:'POST',body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(tr('updated_ok'));
                });

                document.querySelectorAll('[data-del-bundle]').forEach(btn=>btn.onclick=async()=>{
                    const [productId, bundleId] = String(btn.dataset.delBundle || '').split(':');
                    if(!productId || !bundleId){ return; }
                    const fd = new FormData();
                    fd.append('product_id', productId);
                    fd.append('bundle_id', bundleId);
                    const res = await fetch('?api=delete_bundle',{method:'POST',body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(tr('deleted_ok'));
                });

                document.querySelectorAll('[data-del-prod]').forEach(btn=>btn.onclick=async()=>{
                    const fd = new FormData(); fd.append('id', btn.dataset.delProd);
                    const res = await fetch('?api=delete_product',{method:'POST',body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(j.message || tr('deleted_ok'));
                });
            }

            function renderUsers(){
                const query = uiState.usersQuery.trim().toLowerCase();
                const filteredUsers = state.users.filter((u) => {
                    if(query === ''){ return true; }
                    const id = String(u?.id ?? '').toLowerCase();
                    const firstName = String(u?.first_name ?? '').toLowerCase();
                    const username = String(u?.username ?? '').toLowerCase();
                    return id.includes(query) || firstName.includes(query) || username.includes(query);
                });
                const totalPages = Math.max(1, Math.ceil(filteredUsers.length / PAGE_SIZE));
                if(uiState.usersPage > totalPages){ uiState.usersPage = totalPages; }
                const start = (uiState.usersPage - 1) * PAGE_SIZE;
                const pageUsers = filteredUsers.slice(start, start + PAGE_SIZE);

                users.innerHTML = `
                    <h3 class="section-title">${tr('users_title')}</h3>
                    <div class="row" style="margin-bottom:10px">
                        <input id="usersSearch" data-i18n-placeholder="search_users" placeholder="${tr('search_users')}" value="${esc(uiState.usersQuery)}" oninput="window.__onUsersSearchInput(this.value)" style="min-width:320px;flex:1 1 320px">
                    </div>
                    <div class="muted" style="margin-bottom:10px">${tr('found_users', {count: filteredUsers.length})}</div>
                    <table><tr><th>ID</th><th>Имя</th><th>Язык</th><th>Баланс</th><th>Покупок</th><th>Действия</th></tr>
                        ${pageUsers.map(u=>`<tr>
                            <td>${esc(u.id)}</td>
                            <td>${esc(u.first_name||'')}</td>
                            <td>${esc(u.lang||'—')}</td>
                            <td>${Number(u.balance||0).toFixed(2)}</td>
                            <td>${(u.purchases||[]).length}</td>
                            <td>
                                <button data-topup="${esc(u.id)}">${tr('topup')}</button>
                                <button data-history="${esc(u.id)}">${tr('history')}</button>
                            </td>
                        </tr>`).join('') || `<tr><td colspan="6">${tr('no_data')}</td></tr>`}
                    </table>
                    <div class="row" style="margin-top:12px;align-items:center">
                        <button id="usersPrev" ${uiState.usersPage <= 1 ? 'disabled' : ''}>←</button>
                        <button id="usersNext" ${uiState.usersPage >= totalPages ? 'disabled' : ''}>→</button>
                    </div>
                    <div id="historyBox" class="muted" style="margin-top:12px"></div>
                `;

                window.__onUsersSearchInput = (value)=>{
                    uiState.usersQuery = value;
                    uiState.usersPage = 1;
                    renderUsers();
                };
                const usersPrev = document.getElementById('usersPrev');
                const usersNext = document.getElementById('usersNext');
                if(usersPrev){
                    usersPrev.onclick = ()=>{
                        if(uiState.usersPage > 1){
                            uiState.usersPage--;
                            renderUsers();
                        }
                    };
                }
                if(usersNext){
                    usersNext.onclick = ()=>{
                        if(uiState.usersPage < totalPages){
                            uiState.usersPage++;
                            renderUsers();
                        }
                    };
                }
                document.querySelectorAll('[data-topup]').forEach(btn=>btn.onclick=async()=>{
                    const amount = prompt(tr('topup_prompt'));
                    if(!amount) return;
                    const fd = new FormData(); fd.append('id', btn.dataset.topup); fd.append('amount', amount);
                    const res = await fetch('?api=topup',{method:'POST',body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(j.message || tr('updated_ok'));
                });

                document.querySelectorAll('[data-history]').forEach(btn=>btn.onclick=async()=>{
                    const res = await fetch('?api=user_history&id='+encodeURIComponent(btn.dataset.history));
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    const list = (j.history||[]).map(x=>`${x.date} — ${x.product_name} — ${Number(x.price||0).toFixed(2)}`).join('<br>');
                    historyBox.innerHTML = list || tr('history_empty');
                    showToast(tr('history_loaded'));
                });
            }

            function renderSettings(){
                settings.innerHTML = `
                    <h3 class="section-title">${tr('settings_title')}</h3>
                    <form id="settingsForm" class="settings-layout">
                        <section class="settings-block">
                            <div class="settings-block-title">${tr('bot_block')}</div>
                            <div class="settings-fields">
                                <div class="settings-field">
                                    <label class="settings-label" for="botUsernameInput">${tr('bot_username')}</label>
                                    <input id="botUsernameInput" class="settings-input" type="text" name="bot_username" value="${esc(state.settings.bot_username||'')}" placeholder="mybot">
                                </div>
                                <div class="settings-field">
                                    <label class="settings-label" for="adminUsernameInput">${tr('admin_username')}</label>
                                    <input id="adminUsernameInput" class="settings-input" name="admin_username" value="${esc(state.settings.admin_username||'admin')}" placeholder="admin">
                                </div>
                            </div>
                        </section>
                        <section class="settings-block">
                            <div class="settings-block-title">${tr('currency_block')}</div>
                            <div class="settings-fields">
                                <div class="settings-field">
                                    <label class="settings-label" for="currencyInput">${tr('currency_code')}</label>
                                    <input id="currencyInput" class="settings-input" name="currency" maxlength="5" value="${esc(state.settings.currency||'RUB')}" placeholder="USD / RUB / EUR">
                                </div>
                                <div class="settings-field">
                                    <label class="settings-label" for="currencySymbolInput">${tr('currency_symbol')}</label>
                                    <input id="currencySymbolInput" class="settings-input" name="currency_symbol" maxlength="5" value="${esc(state.settings.currency_symbol||'₽')}" placeholder="$ / ₽ / €">
                                </div>
                            </div>
                        </section>
                        <section class="settings-block">
                            <div class="settings-block-title">${tr('referral_block')}</div>
                            <div class="settings-fields">
                                <div class="settings-field full">
                                    <label class="settings-label" for="referralPercentInput">${tr('referral_percent')}</label>
                                    <input id="referralPercentInput" class="settings-input" type="number" min="0" max="100" step="0.01" name="referral_percent" value="${esc(state.settings.referral_percent ?? 5)}" placeholder="5">
                                </div>
                            </div>
                        </section>
                        <section class="settings-block">
                            <div class="settings-block-title">${tr('support_block')}</div>
                            <div class="settings-fields">
                                <div class="settings-field full">
                                    <label class="settings-label" for="supportInput">${tr('support_username')}</label>
                                    <input id="supportInput" class="settings-input" name="support_username" value="${esc(state.settings.support_username||'')}" placeholder="support_username">
                                </div>
                            </div>
                        </section>
                        <section class="settings-block">
                            <div class="settings-block-title">${tr('webhook_block')}</div>
                            <div class="settings-fields">
                                <div class="settings-field full">
                                    <label class="settings-label" for="webhookUrlInput">${tr('webhook_url')}</label>
                                    <input id="webhookUrlInput" class="settings-input" name="webhook_url" readonly>
                                </div>
                                <div class="settings-field full">
                                    <button type="button" id="setWebhookBtn" class="btn-accent">${tr('set_webhook_btn')}</button>
                                </div>
                            </div>
                        </section>
                        <section class="settings-block">
                            <div class="settings-block-title">${tr('logo_block')}</div>
                            <div class="settings-fields">
                                <div class="settings-field full">
                                    <div id="logoDrop" class="logo-drop">${tr('upload_logo')}</div>
                                    <input id="logoInput" type="file" name="logo" accept="image/jpeg,image/png,image/webp" style="margin-top:10px">
                                </div>
                                <div class="settings-field full row" style="align-items:center;justify-content:space-between">
                                    <img id="logoPreview" class="logo-preview" src="${state.settings.logo_path ? ('../' + esc(state.settings.logo_path)) : ''}" style="${state.settings.logo_path ? '' : 'display:none'}">
                                    <button type="button" id="deleteLogoBtn">${tr('delete_logo')}</button>
                                </div>
                            </div>
                        </section>
                        <section class="settings-block full">
                            <div class="settings-block-title">${tr('help_texts')}</div>
                            <div class="settings-fields">
                                <div class="settings-field full">
                                    <label class="settings-label" for="helpTextRuInput">${tr('help_ru')}</label>
                                    <textarea id="helpTextRuInput" class="settings-input settings-textarea" name="help_text_ru" placeholder="Текст помощи (RU)">${esc(state.settings.help_text?.ru||'')}</textarea>
                                </div>
                                <div class="settings-field full">
                                    <label class="settings-label" for="helpTextEnInput">${tr('help_en')}</label>
                                    <textarea id="helpTextEnInput" class="settings-input settings-textarea" name="help_text_en" placeholder="Help text (EN)">${esc(state.settings.help_text?.en||'')}</textarea>
                                </div>
                                <div class="settings-field full">
                                    <button class="settings-save">${tr('save_settings')}</button>
                                </div>
                            </div>
                        </section>
                    </form>
                    <div class="muted">${tr('settings_hint')}</div>
                `;
                const currencyInput = document.getElementById('currencyInput');
                if(currencyInput){
                    currencyInput.oninput = ()=>{ currencyInput.value = currencyInput.value.toUpperCase(); };
                }
                const supportInput = document.getElementById('supportInput');
                if(supportInput){
                    supportInput.oninput = ()=>{ supportInput.value = supportInput.value.replace(/^@+/, '').replace(/[^a-zA-Z0-9_]/g, ''); };
                }
                const botUsernameInput = document.getElementById('botUsernameInput');
                if(botUsernameInput){
                    botUsernameInput.oninput = ()=>{ botUsernameInput.value = botUsernameInput.value.replace(/^@+/, '').replace(/[^a-zA-Z0-9_]/g, ''); };
                }
                const adminUsernameInput = document.getElementById('adminUsernameInput');
                if(adminUsernameInput){
                    adminUsernameInput.oninput = ()=>{ adminUsernameInput.value = adminUsernameInput.value.replace(/^@+/, '').replace(/[^a-zA-Z0-9_]/g, ''); };
                }
                const referralPercentInput = document.getElementById('referralPercentInput');
                if(referralPercentInput){
                    referralPercentInput.oninput = ()=>{
                        if(referralPercentInput.value === '') return;
                        let value = Number(referralPercentInput.value);
                        if(Number.isNaN(value)){ value = 0; }
                        if(value < 0){ value = 0; }
                        if(value > 100){ value = 100; }
                        referralPercentInput.value = String(value);
                    };
                }
                const webhookUrlInput = document.getElementById('webhookUrlInput');
                if(webhookUrlInput){
                    webhookUrlInput.value = `${window.location.origin}/webhook.php`;
                }
                const setWebhookBtn = document.getElementById('setWebhookBtn');
                if(setWebhookBtn){
                    setWebhookBtn.onclick = async()=>{
                        const webhookUrl = (webhookUrlInput?.value || '').trim();
                        const fd = new FormData();
                        fd.append('action', 'set_webhook');
                        fd.append('webhook_url', webhookUrl);
                        try{
                            const res = await fetch('index.php', {method:'POST', body:fd});
                            const j = await res.json();
                            if(!j.ok){
                                const description = String(j.description || j.telegram?.description || j.message || tr(j.error || 'request_failed'));
                                showToast(`❌ ${tr('err_prefix')}: ${description}`, 'error');
                                return;
                            }
                            showToast(`✅ ${tr('webhook_set_success')}`);
                        }catch(_){
                            showToast(`❌ ${tr('err_prefix')}: ${tr('request_failed')}`, 'error');
                        }
                    };
                }

                settingsForm.onsubmit = async (e)=>{
                    e.preventDefault();
                    const res = await fetch('?api=save_settings',{method:'POST',body:new FormData(settingsForm)});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(j.message || tr('updated_ok'));
                };
                const logoInput = document.getElementById('logoInput');
                const logoDrop = document.getElementById('logoDrop');
                const logoPreview = document.getElementById('logoPreview');
                const uploadLogo = async(file)=>{
                    if(!file){ return; }
                    const fd = new FormData();
                    fd.append('logo', file);
                    const res = await fetch('?api=upload_logo', {method:'POST', body:fd});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'logo_upload_failed'), 'error'); return; }
                    await load();
                    showToast(tr('logo_uploaded'));
                };
                logoInput.onchange = ()=>uploadLogo(logoInput.files?.[0] || null);
                logoDrop.ondragover = (e)=>{ e.preventDefault(); logoDrop.style.borderColor='#6366f1'; };
                logoDrop.ondragleave = ()=>{ logoDrop.style.borderColor=''; };
                logoDrop.ondrop = (e)=>{ e.preventDefault(); logoDrop.style.borderColor=''; uploadLogo(e.dataTransfer.files?.[0] || null); };
                document.getElementById('deleteLogoBtn').onclick = async()=>{
                    const res = await fetch('?api=delete_logo', {method:'POST'});
                    const j = await res.json();
                    if(!j.ok){ showToast(apiError(j, 'request_failed'), 'error'); return; }
                    await load();
                    showToast(tr('logo_deleted'));
                };
            }

            async function load(){
                const res = await fetch('?api=bootstrap');
                const j = await res.json();
                if(!j.ok){ showToast(apiError(j, 'loading_failed'), 'error'); return; }
                state.categories = (j.categories||[]).map(withParent);
                state.products = j.products||[];
                state.users = j.users_list||[];
                state.settings = j.settings||{};
                state.settings.currency = state.settings.currency || 'RUB';
                state.settings.currency_symbol = state.settings.currency_symbol || '₽';
                state.settings.support_username = state.settings.support_username || '';
                state.settings.bot_username = state.settings.bot_username || '';
                state.settings.logo_path = state.settings.logo_path || '';
                state.settings.referral_percent = Number(state.settings.referral_percent ?? 5);
                state.settings.help_text = state.settings.help_text || {ru:'',en:''};
                state.stats = j.stats||{};
                renderStats(); renderCategories(); renderProducts(); renderUsers(); renderSettings();
                applyI18n();
            }
            load();
        </script>
    <?php endif; ?>
</div>
<div id="toast-container" class="toast-container"></div>
</body>
</html>
