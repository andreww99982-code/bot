<?php
/**
 * set_webhook.php — Register the Telegram webhook.
 * For security, this script only runs from CLI or when the correct secret token is provided.
 * Run via CLI: php set_webhook.php
 */

// Only allow CLI execution or requests with the correct secret header
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    $provided = $_GET['secret'] ?? $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '';
    if (!hash_equals(WEBHOOK_SECRET, $provided) || WEBHOOK_SECRET === 'change_this_secret') {
        http_response_code(403);
        exit('Access denied. Run from CLI: php set_webhook.php');
    }
}

require_once __DIR__ . '/config.php';
require_once BOT_DIR . '/Storage.php';
require_once BOT_DIR . '/Lang.php';
require_once BOT_DIR . '/Keyboards.php';
require_once BOT_DIR . '/Payments.php';
require_once BOT_DIR . '/Bot.php';

$webhookUrl = BASE_URL . '/webhook.php';
$result     = Bot::setWebhook($webhookUrl, WEBHOOK_SECRET !== 'change_this_secret' ? WEBHOOK_SECRET : '');

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
