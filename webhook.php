<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$incomingSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (WEBHOOK_SECRET !== '' && !hash_equals(WEBHOOK_SECRET, $incomingSecret)) {
    http_response_code(403);
    exit('Forbidden');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    logError('Failed to read webhook input from php://input');
    exit;
}
$update = json_decode($rawBody, true);

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($update)) {
    exit;
}

logEvent(json_encode($update, JSON_UNESCAPED_UNICODE), 'webhook.log');

try {
    processUpdate($update);
} catch (Throwable $e) {
    logError('Unhandled: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

function processUpdate(array $update): void
{
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
        return;
    }

    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        return;
    }

    $chatId = (int) ($message['chat']['id'] ?? 0);
    $userRaw = $message['from'] ?? [];
    $userId = (string) ((int) ($userRaw['id'] ?? 0));
    if ($chatId === 0 || $userId === '0') {
        return;
    }

    $users = readJson(USERS_FILE);
    $user = $users[$userId] ?? [
        'id' => (int) $userId,
        'first_name' => (string) ($userRaw['first_name'] ?? 'User'),
        'username' => (string) ($userRaw['username'] ?? ''),
        'lang' => '',
        'balance' => 0.0,
        'purchases' => [],
    ];

    $user['first_name'] = (string) ($userRaw['first_name'] ?? $user['first_name']);
    $user['username'] = (string) ($userRaw['username'] ?? $user['username']);
    $users[$userId] = $user;
    writeJson(USERS_FILE, $users);

    $text = trim((string) ($message['text'] ?? ''));

    if (str_starts_with($text, '/start')) {
        if (($user['lang'] ?? '') === '') {
            sendMessage($chatId, t('choose_lang', 'ru'), [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru'],
                            ['text' => '🇬🇧 English', 'callback_data' => 'lang:en'],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }

        sendMainMenu($chatId, (string) $user['lang'], false, (string) $user['first_name']);
        return;
    }

    $lang = (string) ($user['lang'] ?: 'ru');
    sendMessage($chatId, t('unknown', $lang));
}

function handleCallback(array $callback): void
{
    $callbackId = (string) ($callback['id'] ?? '');
    $data = (string) ($callback['data'] ?? '');
    $message = $callback['message'] ?? [];
    $chatId = (int) ($message['chat']['id'] ?? 0);
    $messageId = (int) ($message['message_id'] ?? 0);
    $from = $callback['from'] ?? [];
    $userId = (string) ((int) ($from['id'] ?? 0));

    if ($callbackId !== '') {
        answerCallbackQuery($callbackId);
    }

    if ($chatId === 0 || $messageId === 0 || $userId === '0') {
        return;
    }

    $users = readJson(USERS_FILE);
    $user = $users[$userId] ?? [
        'id' => (int) $userId,
        'first_name' => (string) ($from['first_name'] ?? 'User'),
        'username' => (string) ($from['username'] ?? ''),
        'lang' => '',
        'balance' => 0.0,
        'purchases' => [],
    ];

    if (str_starts_with($data, 'lang:')) {
        $lang = substr($data, 5) === 'en' ? 'en' : 'ru';
        $user['lang'] = $lang;
        $users[$userId] = $user;
        writeJson(USERS_FILE, $users);
        sendMainMenu($chatId, $lang, true, (string) $user['first_name'], $messageId);
        return;
    }

    $lang = (string) ($user['lang'] ?: 'ru');
    $users[$userId] = $user;
    writeJson(USERS_FILE, $users);

    if ($data === 'main') {
        sendMainMenu($chatId, $lang, true, (string) $user['first_name'], $messageId);
        return;
    }

    if ($data === 'menu:catalog') {
        showCatalog($chatId, $messageId, $lang, null);
        return;
    }

    if (str_starts_with($data, 'cat:')) {
        showCategory($chatId, $messageId, $lang, substr($data, 4));
        return;
    }

    if (str_starts_with($data, 'product:')) {
        showProductCard($chatId, $messageId, $lang, substr($data, 8));
        return;
    }

    if (str_starts_with($data, 'buy:')) {
        handleBuyCallback($chatId, $messageId, $userId, $lang, substr($data, 4));
        return;
    }

    if ($data === 'menu:account') {
        showAccount($chatId, $messageId, $userId, $lang);
        return;
    }

    if ($data === 'menu:help') {
        $settings = readJson(SETTINGS_FILE);
        $help = trim((string) (($settings['help_text'][$lang] ?? $settings['help_text']['ru'] ?? '')));
        if ($help === '') {
            $help = t('help_text', $lang);
        }
        $supportUsername = ltrim(trim((string) ($settings['support_username'] ?? '')), '@');
        $buttons = [];
        if ($supportUsername !== '') {
            $buttons[] = [[
                'text' => $lang === 'en' ? '💬 Contact support' : '💬 Написать саппорту',
                'url' => 'https://t.me/' . $supportUsername,
            ]];
        }
        $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => 'main']];

        editMessageText($chatId, $messageId, $help, [
            'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    if ($data === 'account:topup') {
        showTopup($chatId, $messageId, $userId, $lang);
        return;
    }

    if ($data === 'account:history') {
        showHistory($chatId, $messageId, $userId, $lang);
        return;
    }

    if (str_starts_with($data, 'redownload:')) {
        redownload($chatId, $messageId, $userId, $lang, substr($data, 11));
    }
}

function sendMainMenu(int $chatId, string $lang, bool $edit = false, string $name = 'User', int $messageId = 0): void
{
    $text = t('welcome', $lang, ['name' => $name]) . "\n\n" . t('menu', $lang);
    $markup = [
        'inline_keyboard' => [
            [['text' => t('btn_catalog', $lang), 'callback_data' => 'menu:catalog']],
            [['text' => t('btn_account', $lang), 'callback_data' => 'menu:account']],
            [['text' => t('btn_help', $lang), 'callback_data' => 'menu:help']],
        ],
    ];

    if ($edit && $messageId > 0) {
        editMessageText($chatId, $messageId, $text, ['reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE)]);
        return;
    }

    sendMessage($chatId, $text, ['reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE)]);
}

function isRootCategory(array $category): bool
{
    return !isset($category['parent_id']) || $category['parent_id'] === null || $category['parent_id'] === '';
}

function findChildCategories(array $categories, string $parentId): array
{
    $children = [];
    foreach ($categories as $category) {
        if ((string) ($category['parent_id'] ?? '') === $parentId) {
            $children[] = $category;
        }
    }
    return $children;
}

function normalizeBundles(array $product): array
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
            'created_at' => (string) ($bundle['created_at'] ?? ''),
        ];
    }
    return array_values($bundles);
}

function getProductStockFromBundles(array $product): int
{
    return count(normalizeBundles($product));
}

function sendProductPhotoCard(int $chatId, int $messageId, string $photoPath, string $caption, string $replyMarkup): bool
{
    if (BOT_TOKEN === '') {
        return false;
    }

    $real = realpath($photoPath);
    if ($real === false || !is_file($real)) {
        return false;
    }

    $content = @file_get_contents($real);
    if ($content === false) {
        return false;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'tg_preview_');
    if ($tmp === false || @file_put_contents($tmp, $content, LOCK_EX) === false) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? (string) finfo_file($finfo, $tmp) : 'image/jpeg';
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    if (!str_starts_with($mime, 'image/')) {
        $mime = 'image/jpeg';
    }

    $ch = curl_init(TELEGRAM_API . '/sendPhoto');
    if ($ch === false) {
        @unlink($tmp);
        return false;
    }

    $payload = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'photo' => new CURLFile($tmp, $mime, basename($real)),
        'reply_markup' => $replyMarkup,
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);

    if ($error !== '') {
        logError('sendPhoto cURL error: ' . $error);
        return false;
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        logError('sendPhoto Telegram error: ' . (is_array($decoded) ? (string) ($decoded['description'] ?? 'unknown') : (string) $raw));
        return false;
    }

    apiRequest('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ]);

    return true;
}

function showCatalog(int $chatId, int $messageId, string $lang, ?string $parentId): void
{
    $categories = readJson(CATEGORIES_FILE);

    if (!$categories) {
        editMessageText($chatId, $messageId, t('no_categories', $lang), [
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => 'main']]]], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $text = t('catalog_title', $lang) . "\n\n";
    $buttons = [];
    foreach ($categories as $category) {
        if ($parentId === null) {
            if (!isRootCategory($category)) {
                continue;
            }
        } elseif ((string) ($category['parent_id'] ?? '') !== $parentId) {
            continue;
        }

        $name = (string) ($category['name'][$lang] ?? $category['name']['ru'] ?? 'Category');
        $buttons[] = [['text' => $name, 'callback_data' => 'cat:' . $category['id']]];
    }

    if (!$buttons) {
        editMessageText($chatId, $messageId, t('no_categories', $lang), [
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => $parentId === null ? 'main' : ('cat:' . $parentId)]]]], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => $parentId === null ? 'main' : ('cat:' . $parentId)]];

    editMessageText($chatId, $messageId, trim($text), [
        'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
    ]);
}

function showCategory(int $chatId, int $messageId, string $lang, string $categoryId): void
{
    $categories = readJson(CATEGORIES_FILE);
    $products = readJson(PRODUCTS_FILE);
    $settings = readJson(SETTINGS_FILE);

    $category = $categories[$categoryId] ?? null;
    if (!$category) {
        showCatalog($chatId, $messageId, $lang, null);
        return;
    }

    $categoryName = (string) ($category['name'][$lang] ?? $category['name']['ru'] ?? 'Category');
    $categoryDesc = trim((string) ($category['description'][$lang] ?? $category['description']['ru'] ?? ''));
    $text = '📂 ' . $categoryName;
    if ($categoryDesc !== '') {
        $text .= "\n\n" . $categoryDesc;
    }

    $children = findChildCategories($categories, $categoryId);
    if ($children) {
        $buttons = [];
        foreach ($children as $child) {
            $name = (string) ($child['name'][$lang] ?? $child['name']['ru'] ?? 'Category');
            $buttons[] = [['text' => $name, 'callback_data' => 'cat:' . $child['id']]];
        }

        $backTarget = isRootCategory($category) ? 'menu:catalog' : ('cat:' . (string) $category['parent_id']);
        $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => $backTarget]];
        editMessageText($chatId, $messageId, trim($text), [
            'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $buttons = [];
    $found = 0;
    foreach ($products as $product) {
        if (($product['category_id'] ?? '') !== $categoryId || !($product['active'] ?? false)) {
            continue;
        }
        $stock = getProductStockFromBundles($product);
        if ($stock === 0) {
            continue;
        }

        $name = (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product');
        $price = formatPrice((float) ($product['price'] ?? 0), $settings);
        $buttons[] = [[
            'text' => $name . ' — ' . $price,
            'callback_data' => 'product:' . $product['id'],
        ]];
        $found++;
    }

    if ($found === 0) {
        $text .= "\n\n" . t('category_empty', $lang);
    }

    $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => isRootCategory($category) ? 'menu:catalog' : ('cat:' . (string) $category['parent_id'])]];

    editMessageText($chatId, $messageId, trim($text), [
        'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
    ]);
}

function showProductCard(int $chatId, int $messageId, string $lang, string $productId): void
{
    $products = readJson(PRODUCTS_FILE);
    $settings = readJson(SETTINGS_FILE);

    $product = $products[$productId] ?? null;
    if (!$product) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $name = (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product');
    $description = trim((string) ($product['description'][$lang] ?? $product['description']['ru'] ?? ''));
    $price = formatPrice((float) ($product['price'] ?? 0), $settings);
    $stock = getProductStockFromBundles($product);
    $stockText = $stock > 0
        ? ($lang === 'en' ? ('📦 Available: ' . $stock . ' pcs.') : ('📦 Доступно: ' . $stock . ' шт.'))
        : ($lang === 'en' ? '❌ Out of stock' : '❌ Нет в наличии');

    $text = $description !== ''
        ? t('product_card', $lang, ['name' => $name, 'description' => $description, 'price' => $price, 'stock' => $stockText])
        : t('product_card_nodesc', $lang, ['name' => $name, 'price' => $price, 'stock' => $stockText]);

    $backTarget = isset($product['category_id']) && $product['category_id'] !== '' ? 'cat:' . $product['category_id'] : 'menu:catalog';
    $buttons = [];
    if ($stock > 0 && ($product['active'] ?? false)) {
        $buttons[] = [[
            'text' => t('btn_buy', $lang, ['price' => $price]),
            'callback_data' => 'buy:' . $product['id'],
        ]];
    }
    $buttons[] = [[
        'text' => t('btn_back', $lang),
        'callback_data' => $backTarget,
    ]];

    $replyMarkup = json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE);
    $previewPath = resolveSaleFilePath((string) ($product['preview'] ?? ''));
    if ($previewPath !== null && sendProductPhotoCard($chatId, $messageId, $previewPath, $text, (string) $replyMarkup)) {
        return;
    }

    editMessageText($chatId, $messageId, $text, [
        'reply_markup' => $replyMarkup,
    ]);
}

function handleBuyCallback(int $chatId, int $messageId, string $userId, string $lang, string $payload): void
{
    $parts = explode(':', $payload);
    if (count($parts) === 1) {
        showQuantitySelector($chatId, $messageId, $lang, $parts[0]);
        return;
    }

    if (count($parts) !== 2) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $qty = (int) $parts[1];
    if ($qty <= 0) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    processBuy($chatId, $messageId, $userId, $lang, $parts[0], $qty);
}

function showQuantitySelector(int $chatId, int $messageId, string $lang, string $productId): void
{
    $products = readJson(PRODUCTS_FILE);
    $settings = readJson(SETTINGS_FILE);

    $product = $products[$productId] ?? null;
    if (!$product || !($product['active'] ?? false)) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $stock = getProductStockFromBundles($product);
    if ($stock === 0) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $name = (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product');
    $price = formatPrice((float) ($product['price'] ?? 0), $settings);
    $text = t('choose_qty', $lang, ['name' => $name, 'price' => $price]);

    $availableQty = [1, 2, 3, 5, 10];
    $availableQty = array_values(array_filter($availableQty, static fn (int $optionQty): bool => $optionQty <= $stock));
    if (!$availableQty) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $buttons = [];
    $firstRow = [];
    $secondRow = [];
    foreach ($availableQty as $index => $qty) {
        $button = ['text' => (string) $qty, 'callback_data' => 'buy:' . $productId . ':' . $qty];
        if ($index < 3) {
            $firstRow[] = $button;
        } else {
            $secondRow[] = $button;
        }
    }
    if ($firstRow) {
        $buttons[] = $firstRow;
    }
    if ($secondRow) {
        $buttons[] = $secondRow;
    }
    $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => 'product:' . $productId]];

    editMessageText($chatId, $messageId, $text, [
        'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
    ]);
}

function processBuy(int $chatId, int $messageId, string $userId, string $lang, string $productId, int $qty): void
{
    if ($qty <= 0) {
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $settings = readJson(SETTINGS_FILE);
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    $lockPath = DATA_DIR . '/purchase.lock';
    $lock = fopen($lockPath, 'c+');

    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        editMessageText($chatId, $messageId, t('purchase_busy', $lang));
        return;
    }

    $users = readJson(USERS_FILE);
    $products = readJson(PRODUCTS_FILE);

    $user = $users[$userId] ?? null;
    $product = $products[$productId] ?? null;

    if (!$user || !$product || !($product['active'] ?? false)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }
    $bundles = normalizeBundles($product);
    $stock = count($bundles);
    if ($stock === 0) {
        flock($lock, LOCK_UN);
        fclose($lock);
        editMessageText($chatId, $messageId, 'Нет в наличии / Out of stock');
        return;
    }

    $price = (float) ($product['price'] ?? 0.0);
    $balance = (float) ($user['balance'] ?? 0.0);
    if ($qty > $stock) {
        flock($lock, LOCK_UN);
        fclose($lock);
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }
    $selectedKeys = $qty === 1 ? [array_rand($bundles)] : (array) array_rand($bundles, $qty);
    $selectedBundles = [];
    foreach ($selectedKeys as $key) {
        $selectedBundles[] = $bundles[(int) $key];
    }
    $selectedBundleIds = [];
    $selectedFiles = [];
    foreach ($selectedBundles as $bundle) {
        $bundleId = (string) ($bundle['id'] ?? '');
        $bundlePath = resolveSaleFilePath((string) ($bundle['file'] ?? ''));
        if ($bundleId === '' || $bundlePath === null) {
            flock($lock, LOCK_UN);
            fclose($lock);
            editMessageText($chatId, $messageId, t('product_not_found', $lang));
            return;
        }
        $selectedBundleIds[] = $bundleId;
        $selectedFiles[] = ['path' => $bundlePath, 'caption' => (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product')];
    }
    $total = round($price * $qty, 2);

    if ($balance < $total) {
        $need = formatPrice($total - $balance, $settings);
        $totalText = formatPrice($total, $settings);
        flock($lock, LOCK_UN);
        fclose($lock);

        editMessageText($chatId, $messageId, t('insufficient_balance', $lang, ['need' => $need, 'total' => $totalText]), [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => t('btn_topup', $lang), 'callback_data' => 'account:topup']],
                    [['text' => t('btn_back', $lang), 'callback_data' => 'product:' . $productId]],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $user['balance'] = round($balance - $total, 2);
    $product['sold'] = (int) ($product['sold'] ?? 0) + $qty;
    $nextBundles = [];
    foreach ($bundles as $bundle) {
        if (in_array((string) ($bundle['id'] ?? ''), $selectedBundleIds, true)) {
            continue;
        }
        $nextBundles[] = $bundle;
    }
    $product['bundles'] = array_values($nextBundles);
    $product['stock'] = count($product['bundles']);
    if ($product['stock'] === 0) {
        $product['active'] = false;
    }

    $purchaseId = generateId();
    $name = (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product');
    $bundleFiles = array_values(array_map(static fn (array $bundle): string => (string) ($bundle['file'] ?? ''), $selectedBundles));
    $purchase = [
        'id' => $purchaseId,
        'product_id' => $product['id'],
        'product_name' => $name,
        'price' => $total,
        'date' => date('Y-m-d H:i:s'),
        'file' => (string) ($bundleFiles[0] ?? ''),
        'files' => $bundleFiles,
        'qty' => $qty,
        'bundle_ids' => $selectedBundleIds,
    ];

    $user['purchases'][] = $purchase;
    $users[$userId] = $user;
    $products[$productId] = $product;

    $orders = readJson(DATA_DIR . '/orders.json');
    $orders[$purchaseId] = array_merge($purchase, [
        'user_id' => $userId,
    ]);

    writeJson(PRODUCTS_FILE, $products);
    writeJson(USERS_FILE, $users);
    writeJson(DATA_DIR . '/orders.json', $orders);

    flock($lock, LOCK_UN);
    fclose($lock);

    editMessageText($chatId, $messageId, t('purchase_success', $lang, [
        'name' => $name,
        'qty' => (string) $qty,
        'total' => formatPrice($total, $settings),
    ]), [
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_menu', $lang), 'callback_data' => 'main']]]], JSON_UNESCAPED_UNICODE),
    ]);

    foreach ($selectedFiles as $item) {
        $sent = sendDocument($chatId, $item['path'], $item['caption']);
        if ($sent === null || !($sent['ok'] ?? false)) {
            sendMessage($chatId, t('purchase_missing_file', $lang));
            continue;
        }
        @unlink($item['path']);
    }
}

function showAccount(int $chatId, int $messageId, string $userId, string $lang): void
{
    $users = readJson(USERS_FILE);
    $settings = readJson(SETTINGS_FILE);
    $user = $users[$userId] ?? null;
    if (!$user) {
        return;
    }

    $text = t('account_title', $lang, [
        'name' => (string) ($user['first_name'] ?? 'User'),
        'balance' => formatPrice((float) ($user['balance'] ?? 0.0), $settings),
        'count' => (string) count((array) ($user['purchases'] ?? [])),
    ]);

    $markup = [
        'inline_keyboard' => [
            [['text' => t('btn_topup', $lang), 'callback_data' => 'account:topup']],
            [['text' => t('btn_history', $lang), 'callback_data' => 'account:history']],
            [['text' => t('btn_back', $lang), 'callback_data' => 'main']],
        ],
    ];

    editMessageText($chatId, $messageId, $text, ['reply_markup' => json_encode($markup, JSON_UNESCAPED_UNICODE)]);
}

function showTopup(int $chatId, int $messageId, string $userId, string $lang): void
{
    $settings = readJson(SETTINGS_FILE);
    $admin = ltrim((string) ($settings['admin_username'] ?? 'admin'), '@');
    if ($admin === '') {
        $admin = 'admin';
    }

    editMessageText($chatId, $messageId, t('topup_text', $lang, ['admin_username' => $admin, 'user_id' => $userId]), [
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => 'menu:account']]]], JSON_UNESCAPED_UNICODE),
    ]);
}

function showHistory(int $chatId, int $messageId, string $userId, string $lang): void
{
    $users = readJson(USERS_FILE);
    $settings = readJson(SETTINGS_FILE);
    $user = $users[$userId] ?? null;
    if (!$user) {
        return;
    }

    $purchases = (array) ($user['purchases'] ?? []);
    if (!$purchases) {
        editMessageText($chatId, $messageId, t('history_empty', $lang), [
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => 'menu:account']]]], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $text = t('history_title', $lang) . "\n\n";
    $buttons = [];

    foreach (array_reverse($purchases) as $purchase) {
        $text .= '• ' . ($purchase['date'] ?? '-') . ' | ' . ($purchase['product_name'] ?? '-') . ' | ' . formatPrice((float) ($purchase['price'] ?? 0), $settings) . "\n";
        $buttons[] = [[
            'text' => t('download_again', $lang) . ': ' . ($purchase['product_name'] ?? '#'),
            'callback_data' => 'redownload:' . ($purchase['id'] ?? ''),
        ]];
    }

    $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => 'menu:account']];

    editMessageText($chatId, $messageId, trim($text), [
        'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
    ]);
}

function redownload(int $chatId, int $messageId, string $userId, string $lang, string $purchaseId): void
{
    $users = readJson(USERS_FILE);
    $user = $users[$userId] ?? null;
    if (!$user) {
        return;
    }

    foreach ((array) ($user['purchases'] ?? []) as $purchase) {
        if (($purchase['id'] ?? '') !== $purchaseId) {
            continue;
        }

        $path = resolveSaleFilePath((string) ($purchase['file'] ?? ''));
        if ($path === null) {
            sendMessage($chatId, t('purchase_missing_file', $lang));
            return;
        }

        sendDocument($chatId, $path, (string) ($purchase['product_name'] ?? 'file'));
        return;
    }

    sendMessage($chatId, t('product_not_found', $lang));
}
