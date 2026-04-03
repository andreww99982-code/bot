<?php
/**
 * webhook.php — Telegram webhook entry point.
 * Point your bot webhook here: https://your-domain.com/webhook.php
 */

require_once __DIR__ . '/config.php';
require_once BOT_DIR . '/Storage.php';
require_once BOT_DIR . '/Lang.php';
require_once BOT_DIR . '/Keyboards.php';
require_once BOT_DIR . '/Payments.php';
require_once BOT_DIR . '/Bot.php';

// Verify webhook secret header (set when registering the webhook)
$incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (WEBHOOK_SECRET !== 'change_this_secret' && $incomingSecret !== WEBHOOK_SECRET) {
    http_response_code(403);
    exit('Forbidden');
}

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(200);
    exit;
}

$update = json_decode($rawBody, true);
if (!is_array($update)) {
    http_response_code(200);
    exit;
}

// Log incoming update (optional, disable on high-traffic bots)
$logFile = LOG_DIR . '/webhook.log';
if (!file_exists($logFile) || filesize($logFile) < 5 * 1024 * 1024) { // 5 MB cap
    @file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . ' ' . $rawBody . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$bot = new Bot($update);
$bot->handle();

http_response_code(200);
echo 'OK';
