<?php
/**
 * set_webhook.php — Register the Telegram webhook.
 * Run once via CLI: php set_webhook.php
 * Or open in browser (protected by IP check below).
 */

require_once __DIR__ . '/config.php';
require_once BOT_DIR . '/Storage.php';
require_once BOT_DIR . '/Lang.php';
require_once BOT_DIR . '/Keyboards.php';
require_once BOT_DIR . '/Payments.php';
require_once BOT_DIR . '/Bot.php';

$webhookUrl = BASE_URL . '/webhook.php';
$result     = Bot::setWebhook($webhookUrl, WEBHOOK_SECRET !== 'change_this_secret' ? WEBHOOK_SECRET : '');

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
