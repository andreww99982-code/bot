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
        showCatalog($chatId, $messageId, $lang);
        return;
    }

    if (str_starts_with($data, 'cat:')) {
        showCategory($chatId, $messageId, $lang, substr($data, 4));
        return;
    }

    if (str_starts_with($data, 'buy:')) {
        processBuy($chatId, $messageId, $userId, $lang, substr($data, 4));
        return;
    }

    if ($data === 'menu:account') {
        showAccount($chatId, $messageId, $userId, $lang);
        return;
    }

    if ($data === 'menu:help') {
        editMessageText($chatId, $messageId, t('help_text', $lang), [
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => 'main']]]], JSON_UNESCAPED_UNICODE),
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

function showCatalog(int $chatId, int $messageId, string $lang): void
{
    $categories = readJson(CATEGORIES_FILE);
    $products = readJson(PRODUCTS_FILE);

    if (!$categories) {
        editMessageText($chatId, $messageId, t('no_categories', $lang), [
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => 'main']]]], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $text = t('catalog_title', $lang) . "\n\n";
    $buttons = [];
    foreach ($categories as $category) {
        $count = 0;
        foreach ($products as $product) {
            if (($product['category_id'] ?? '') !== ($category['id'] ?? '')) {
                continue;
            }
            if (!($product['active'] ?? false)) {
                continue;
            }
            $stock = (int) ($product['stock'] ?? 0);
            if ($stock !== 0) {
                $count++;
            }
        }
        $name = (string) ($category['name'][$lang] ?? $category['name']['ru'] ?? 'Category');
        $text .= '• ' . $name . ' (' . $count . ")\n";
        $buttons[] = [['text' => $name . ' (' . $count . ')', 'callback_data' => 'cat:' . $category['id']]];
    }
    $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => 'main']];

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
        showCatalog($chatId, $messageId, $lang);
        return;
    }

    $categoryName = (string) ($category['name'][$lang] ?? $category['name']['ru'] ?? 'Category');
    $text = t('products_title', $lang, ['name' => $categoryName]) . "\n\n";

    $buttons = [];
    $found = 0;
    foreach ($products as $product) {
        if (($product['category_id'] ?? '') !== $categoryId || !($product['active'] ?? false)) {
            continue;
        }
        $stock = (int) ($product['stock'] ?? 0);
        if ($stock === 0) {
            continue;
        }

        $name = (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product');
        $price = formatPrice((float) ($product['price'] ?? 0), $settings);
        $qty = $stock < 0 ? '∞' : (string) $stock;
        $text .= '• ' . $name . ' — ' . $price . ' | x' . $qty . "\n";

        $buttons[] = [[
            'text' => t('buy', $lang) . ': ' . $name,
            'callback_data' => 'buy:' . $product['id'],
        ]];
        $found++;
    }

    if ($found === 0) {
        $text .= t('category_empty', $lang);
    }

    $buttons[] = [['text' => t('btn_back', $lang), 'callback_data' => 'menu:catalog']];

    editMessageText($chatId, $messageId, trim($text), [
        'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
    ]);
}

function processBuy(int $chatId, int $messageId, string $userId, string $lang, string $productId): void
{
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

    if (!$user || !$product || !($product['active'] ?? false) || (int) ($product['stock'] ?? 0) === 0) {
        flock($lock, LOCK_UN);
        fclose($lock);
        editMessageText($chatId, $messageId, t('product_not_found', $lang));
        return;
    }

    $price = (float) ($product['price'] ?? 0.0);
    $balance = (float) ($user['balance'] ?? 0.0);

    if ($balance < $price) {
        $need = formatPrice($price - $balance, $settings);
        flock($lock, LOCK_UN);
        fclose($lock);

        editMessageText($chatId, $messageId, t('insufficient', $lang, ['need' => $need]), [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => t('btn_topup', $lang), 'callback_data' => 'account:topup']],
                    [['text' => t('btn_back', $lang), 'callback_data' => 'menu:catalog']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    $user['balance'] = round($balance - $price, 2);
    $product['sold'] = (int) ($product['sold'] ?? 0) + 1;
    if ((int) ($product['stock'] ?? -1) > 0) {
        $product['stock'] = (int) $product['stock'] - 1;
    }

    $purchaseId = generateId();
    $name = (string) ($product['name'][$lang] ?? $product['name']['ru'] ?? 'Product');
    $purchase = [
        'id' => $purchaseId,
        'product_id' => $product['id'],
        'product_name' => $name,
        'price' => $price,
        'date' => date('Y-m-d H:i:s'),
        'file' => $product['file'],
    ];

    $user['purchases'][] = $purchase;
    $users[$userId] = $user;
    $products[$productId] = $product;

    writeJson(USERS_FILE, $users);
    writeJson(PRODUCTS_FILE, $products);

    flock($lock, LOCK_UN);
    fclose($lock);

    editMessageText($chatId, $messageId, t('purchase_ok', $lang), [
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => t('btn_back', $lang), 'callback_data' => 'main']]]], JSON_UNESCAPED_UNICODE),
    ]);

    $path = resolveSaleFilePath((string) $product['file']);
    if ($path === null) {
        sendMessage($chatId, t('purchase_missing_file', $lang));
        return;
    }

    sendDocument($chatId, $path, $name);
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
    $admin = (string) ($settings['admin_username'] ?? 'admin');

    editMessageText($chatId, $messageId, t('topup_text', $lang, ['admin' => $admin, 'id' => $userId]), [
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
