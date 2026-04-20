<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function apiRequest(string $method, array $params = []): ?array
{
    if (BOT_TOKEN === '') {
        logError('BOT_TOKEN is empty for ' . $method);
        return null;
    }

    $ch = curl_init(TELEGRAM_API . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        logError('Telegram API cURL error: ' . $error);
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        logError('Telegram API invalid response: ' . (string) $response);
        return null;
    }

    if (!($decoded['ok'] ?? false)) {
        logError('Telegram API error [' . $method . ']: ' . ($decoded['description'] ?? 'unknown'));
    }

    return $decoded;
}

function sendMessage($chatId, string $text, array $extra = []): ?array
{
    return apiRequest('sendMessage', array_merge([
        'chat_id' => $chatId,
        'text' => $text,
    ], $extra));
}

function sendDocument($chatId, string $filePath, string $caption = ''): ?array
{
    $real = realpath($filePath);
    if ($real === false || !is_file($real)) {
        logError('sendDocument file missing: ' . $filePath);
        return null;
    }

    $ch = curl_init(TELEGRAM_API . '/sendDocument');
    $data = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'document' => new CURLFile($real),
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        logError('sendDocument cURL error: ' . $error);
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        logError('sendDocument invalid response: ' . (string) $response);
        return null;
    }

    if (!($decoded['ok'] ?? false)) {
        logError('sendDocument Telegram error: ' . ($decoded['description'] ?? 'unknown'));
    }

    return $decoded;
}

function answerCallbackQuery(string $callbackId, string $text = ''): ?array
{
    $params = ['callback_query_id' => $callbackId];
    if ($text !== '') {
        $params['text'] = $text;
        $params['show_alert'] = false;
    }
    return apiRequest('answerCallbackQuery', $params);
}

function editMessageText($chatId, int $messageId, string $text, array $extra = []): ?array
{
    return apiRequest('editMessageText', array_merge([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
    ], $extra));
}

function readJson(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $content = @file_get_contents($file);
    if ($content === false || $content === '') {
        return [];
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeJson(string $file, array $data): bool
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

/**
 * Generate RFC 4122 UUID v4.
 */
function generateId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function resolveSaleFilePath(string $relativePath): ?string
{
    $base = realpath(FILES_DIR);
    if ($base === false) {
        return null;
    }

    $prefix = trim(str_replace('\\', '/', basename(FILES_DIR)), '/') . '/';
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!str_starts_with($normalized, $prefix)) {
        return null;
    }

    $remainder = substr($normalized, strlen($prefix));
    if ($remainder === '') {
        return null;
    }

    $target = realpath(FILES_DIR . '/' . $remainder);
    if ($target === false || !is_file($target)) {
        return null;
    }

    if (!str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $target;
}

function logEvent(string $msg, string $file = 'events.log'): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(LOG_DIR . '/' . $file, $line, FILE_APPEND | LOCK_EX);
}

function logError(string $msg): void
{
    logEvent('[ERROR] ' . $msg, 'error.log');
}

function formatPrice(float $amount, array $settings): string
{
    return number_format($amount, 2, '.', '') . ' ' . ($settings['currency_symbol'] ?? '₽');
}

function t(string $key, string $lang, array $vars = []): string
{
    $lang = $lang === 'en' ? 'en' : 'ru';

    $map = [
        'ru' => [
            'choose_lang' => 'Выберите язык / Choose language',
            'welcome' => "Добро пожаловать, {name}!",
            'menu' => 'Главное меню:',
            'btn_catalog' => '🛍 Каталог',
            'btn_account' => '👤 Личный кабинет',
            'btn_help' => 'ℹ️ Помощь',
            'btn_back' => '⬅️ Назад',
            'btn_menu' => '🏠 Главное меню',
            'btn_topup' => '💳 Пополнить баланс',
            'btn_history' => '🧾 История покупок',
            'catalog_title' => 'Категории:',
            'no_categories' => 'Категории пока не добавлены.',
            'category_empty' => 'В этой категории пока нет доступных товаров.',
            'products_title' => 'Товары в категории: {name}',
            'buy' => 'Купить',
            'btn_buy' => '🛒 Купить — {price}',
            'account_title' => "👤 Личный кабинет\nИмя: {name}\nБаланс: {balance}\nКуплено товаров: {count}",
            'help_text' => "ℹ️ Помощь\n\nВыберите раздел в меню и следуйте подсказкам.",
            'topup_text' => "Для пополнения баланса напишите администратору: @{admin_username}\nВаш ID: {user_id}",
            'insufficient' => 'Недостаточно средств. Не хватает: {need}',
            'insufficient_balance' => 'Недостаточно средств.\nСтоимость: {total}\nНе хватает: {need}',
            'purchase_ok' => '✅ Покупка успешна! Файл отправлен.',
            'purchase_success' => "✅ Покупка успешна!\nТовар: {name}\nКоличество: {qty}\nСумма: {total}",
            'purchase_missing_file' => 'Покупка проведена, но файл не найден. Свяжитесь с администратором.',
            'purchase_busy' => 'Сервис временно занят. Попробуйте ещё раз через несколько секунд.',
            'product_not_found' => 'Товар не найден или недоступен.',
            'product_card' => "🛍 {name}\n\n📝 {description}\n\n💰 Цена: {price}\n📦 Остаток: {stock}",
            'product_card_nodesc' => "🛍 {name}\n\n💰 Цена: {price}\n📦 Остаток: {stock}",
            'choose_qty' => "🛒 {name}\n💰 Цена за 1 шт: {price}\n\nВыберите количество:",
            'history_title' => 'История покупок:',
            'history_empty' => 'Покупок пока нет.',
            'download_again' => 'Скачать снова',
            'unknown' => 'Команда не распознана. Откройте /start',
        ],
        'en' => [
            'choose_lang' => 'Choose language / Выберите язык',
            'welcome' => 'Welcome, {name}!',
            'menu' => 'Main menu:',
            'btn_catalog' => '🛍 Catalog',
            'btn_account' => '👤 My Account',
            'btn_help' => 'ℹ️ Help',
            'btn_back' => '⬅️ Back',
            'btn_menu' => '🏠 Main menu',
            'btn_topup' => '💳 Top up balance',
            'btn_history' => '🧾 Purchase history',
            'catalog_title' => 'Categories:',
            'no_categories' => 'No categories yet.',
            'category_empty' => 'No products available in this category yet.',
            'products_title' => 'Products in category: {name}',
            'buy' => 'Buy',
            'btn_buy' => '🛒 Buy — {price}',
            'account_title' => "👤 My Account\nName: {name}\nBalance: {balance}\nPurchased items: {count}",
            'help_text' => "ℹ️ Help\n\nChoose a section in the menu and follow the instructions.",
            'topup_text' => "To top up your balance, contact the administrator: @{admin_username}\nYour ID: {user_id}",
            'insufficient' => 'Insufficient balance. Missing: {need}',
            'insufficient_balance' => "Insufficient balance.\nCost: {total}\nMissing: {need}",
            'purchase_ok' => '✅ Purchase completed! File sent.',
            'purchase_success' => "✅ Purchase completed!\nProduct: {name}\nQuantity: {qty}\nTotal: {total}",
            'purchase_missing_file' => 'Purchase completed, but file is missing. Contact admin.',
            'purchase_busy' => 'Service is busy now. Please try again in a few seconds.',
            'product_not_found' => 'Product not found or unavailable.',
            'product_card' => "🛍 {name}\n\n📝 {description}\n\n💰 Price: {price}\n📦 Stock: {stock}",
            'product_card_nodesc' => "🛍 {name}\n\n💰 Price: {price}\n📦 Stock: {stock}",
            'choose_qty' => "🛒 {name}\n💰 Price per item: {price}\n\nChoose quantity:",
            'history_title' => 'Purchase history:',
            'history_empty' => 'No purchases yet.',
            'download_again' => 'Download again',
            'unknown' => 'Unknown command. Open /start',
        ],
    ];

    $text = $map[$lang][$key] ?? $map['ru'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string) $v, $text);
    }

    return $text;
}
