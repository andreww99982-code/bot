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
    Payments::processHeleketWebhook($raw);
    exit;
}

if (strpos($requestUri, '/webhook/cryptobot') !== false) {
    $raw = file_get_contents('php://input');
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
    exit;
}

try {
    Handlers::handleUpdate($update);
} catch (Exception $e) {
    TgBot::log('Exception in handleUpdate: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}
