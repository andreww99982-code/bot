<?php

declare(strict_types=1);

define('BOT_TOKEN', (string) (getenv('BOT_TOKEN') ?: ''));
define('WEBHOOK_URL', (string) (getenv('WEBHOOK_URL') ?: ''));
define('WEBHOOK_SECRET', (string) (getenv('WEBHOOK_SECRET') ?: ''));
define('ADMIN_PASSWORD', (string) (getenv('ADMIN_PASSWORD') ?: 'changeme'));
define('REFERRAL_PERCENT', (float) (getenv('REFERRAL_PERCENT') ?: 5));

define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('FILES_DIR', BASE_DIR . '/files');
define('LOG_DIR', BASE_DIR . '/logs');

define('USERS_FILE', DATA_DIR . '/users.json');
define('CATEGORIES_FILE', DATA_DIR . '/categories.json');
define('PRODUCTS_FILE', DATA_DIR . '/products.json');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');

define('TELEGRAM_API', 'https://api.telegram.org/bot' . BOT_TOKEN);

$requiredDirs = [DATA_DIR, FILES_DIR, LOG_DIR, BASE_DIR . '/admin'];
foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

$defaults = [
    USERS_FILE => [],
    CATEGORIES_FILE => [],
    PRODUCTS_FILE => [],
    SETTINGS_FILE => [
        'admin_username' => 'admin',
        'currency' => 'RUB',
        'currency_symbol' => '₽',
        'support_username' => '',
        'bot_username' => '',
        'help_text' => [
            'ru' => '',
            'en' => '',
        ],
    ],
];

foreach ($defaults as $file => $data) {
    if (!file_exists($file)) {
        $result = file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        if ($result === false) {
            error_log('Failed to initialize data file: ' . $file);
        }
    }
}
