<?php
/**
 * webhook.php — Telegram webhook entry point.
 *
 * Point your bot webhook here:
 *   https://your-domain.com/webhook.php
 *
 * Telegram will POST JSON updates to this URL.
 * Payment provider callbacks also arrive here at:
 *   /webhook.php?route=heleket
 *   /webhook.php?route=cryptobot
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bot/storage.php';
require_once __DIR__ . '/bot/utils.php';
require_once __DIR__ . '/bot/lang.php';
require_once __DIR__ . '/bot/payments.php';
require_once __DIR__ . '/bot/handlers.php';

// ---- 1. Validate webhook secret (when configured) ----------------------

$headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (WEBHOOK_SECRET !== '' && $headerSecret !== WEBHOOK_SECRET) {
    TgBot::log('Webhook: invalid secret token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(403);
    exit('Forbidden');
}

// ---- 2. Route payment provider webhooks --------------------------------

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$route      = $_GET['route'] ?? '';

if ($route === 'heleket' || strpos($requestUri, '/webhook/heleket') !== false) {
    $raw = file_get_contents('php://input');
    if (HELEKET_API_KEY !== '') {
        $signature = $_SERVER['HTTP_X_HELEKET_SIGNATURE'] ?? '';
        $expected  = hash_hmac('sha256', $raw, HELEKET_API_KEY);
        if (!hash_equals($expected, $signature)) {
            TgBot::log('Heleket webhook: invalid signature');
            http_response_code(403);
            exit('Forbidden');
        }
    }
    Payments::processHeleketWebhook($raw);
    http_response_code(200);
    exit;
}

if ($route === 'cryptobot' || strpos($requestUri, '/webhook/cryptobot') !== false) {
    $raw = file_get_contents('php://input');
    if (CRYPTOBOT_TOKEN !== '') {
        $cbHeader = $_SERVER['HTTP_CRYPTO_PAY_API_TOKEN'] ?? '';
        if (!hash_equals($cbHeader, CRYPTOBOT_TOKEN)) {
            TgBot::log('CryptoBot webhook: invalid token');
            http_response_code(403);
            exit('Forbidden');
        }
    }
    Payments::processCryptoBotWebhook($raw);
    http_response_code(200);
    exit;
}

// ---- 3. Read and parse the Telegram update -----------------------------

$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(200);
    exit;
}

$update = json_decode($input, true);
if (!is_array($update)) {
    TgBot::log('Webhook: invalid JSON — ' . mb_substr($input, 0, 500));
    http_response_code(200);
    exit;
}

// ---- 4. Respond 200 immediately so Telegram does not retry -------------

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ---- 5. Process the update ---------------------------------------------

try {
    Handlers::handleUpdate($update);
} catch (Throwable $e) {
    TgBot::log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

