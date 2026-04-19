<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (PHP_SAPI !== 'cli') {
    $provided = (string) ($_GET['secret'] ?? $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '');
    if (WEBHOOK_SECRET === '' || !hash_equals(WEBHOOK_SECRET, $provided)) {
        http_response_code(403);
        exit('Access denied');
    }
}

if (BOT_TOKEN === '' || WEBHOOK_URL === '') {
    http_response_code(500);
    exit('BOT_TOKEN and WEBHOOK_URL must be set via environment variables');
}

$params = [
    'url' => WEBHOOK_URL,
    'allowed_updates' => ['message', 'callback_query'],
];

if (WEBHOOK_SECRET !== '') {
    $params['secret_token'] = WEBHOOK_SECRET;
}

$result = apiRequest('setWebhook', $params) ?? ['ok' => false, 'description' => 'No response from Telegram API'];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
