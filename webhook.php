<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bot/storage.php';
require_once __DIR__ . '/bot/utils.php';
require_once __DIR__ . '/bot/lang.php';
require_once __DIR__ . '/bot/payments.php';
require_once __DIR__ . '/bot/handlers.php';

// Verify Telegram secret token if set
$headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (WEBHOOK_SECRET && $headerSecret !== WEBHOOK_SECRET) {
    http_response_code(403);
    exit('Forbidden');
}

// Handle payment provider webhooks
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

if (strpos($requestUri, '/webhook/heleket') !== false) {
    $raw = file_get_contents('php://input');
    // Verify Heleket HMAC signature if API key is configured
    if (HELEKET_API_KEY) {
        $signature = $_SERVER['HTTP_X_HELEKET_SIGNATURE'] ?? '';
        $expected  = hash_hmac('sha256', $raw, HELEKET_API_KEY);
        if (!hash_equals($expected, strtolower($signature))) {
            TgBot::log('Heleket webhook: invalid signature');
            http_response_code(403);
            exit('Forbidden');
        }
    }
    Payments::processHeleketWebhook($raw);
    exit;
}

if (strpos($requestUri, '/webhook/cryptobot') !== false) {
    $raw = file_get_contents('php://input');
    // CryptoBot sends token in Crypto-Pay-API-Token header for verification
    if (CRYPTOBOT_TOKEN) {
        $cbHeader = $_SERVER['HTTP_CRYPTO_PAY_API_TOKEN'] ?? '';
        if (!hash_equals(CRYPTOBOT_TOKEN, $cbHeader)) {
            TgBot::log('CryptoBot webhook: invalid token');
            http_response_code(403);
            exit('Forbidden');
        }
    }
    Payments::processCryptoBotWebhook($raw);
    exit;
}

// Handle Telegram update
$input = file_get_contents('php://input');
if (!$input) {
    exit;
}

$update = json_decode($input, true);
if (!$update) {
    TgBot::log('Invalid JSON in webhook: ' . substr($input, 0, 500));
    exit;
}

try {
    Handlers::handleUpdate($update);
} catch (Exception $e) {
    TgBot::log('Exception in handleUpdate: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}
