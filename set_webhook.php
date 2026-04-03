<?php
/**
 * set_webhook.php — Register the Telegram webhook with the Bot API.
 *
 * Usage:
 *   CLI:     php set_webhook.php
 *   Browser: https://your-domain.com/set_webhook.php  (requires WEBHOOK_SECRET)
 */

// Prevent direct browser access unless a matching secret is provided
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    $provided = $_GET['secret'] ?? $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '';
    if (WEBHOOK_SECRET === '' || !hash_equals(WEBHOOK_SECRET, $provided)) {
        http_response_code(403);
        exit('Access denied. Run via CLI: php set_webhook.php');
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Validate required configuration
if (BOT_TOKEN === '') {
    exitWithMessage('Error: BOT_TOKEN is not set. Export it as an environment variable.');
}
if (WEBHOOK_URL === '') {
    exitWithMessage('Error: WEBHOOK_URL is not set. Export it as an environment variable.');
}

// Build request parameters
$params = ['url' => WEBHOOK_URL];
if (WEBHOOK_SECRET !== '') {
    $params['secret_token'] = WEBHOOK_SECRET;
}

// Call setWebhook
$result = apiRequest('setWebhook', $params);

$output = json_encode($result ?? ['ok' => false, 'description' => 'No response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if (PHP_SAPI === 'cli') {
    echo $output . "\n";
} else {
    header('Content-Type: application/json');
    echo $output;
}

function exitWithMessage(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
    } else {
        http_response_code(500);
        echo $msg;
    }
    exit(1);
}

