<?php
// ============================================================
//  CONFIG — edit before deploying
// ============================================================

define('BOT_TOKEN',         getenv('BOT_TOKEN')         ?: 'YOUR_BOT_TOKEN_HERE');
define('WEBHOOK_SECRET',    getenv('WEBHOOK_SECRET')    ?: 'change_this_secret');
define('ADMIN_PASSWORD',    getenv('ADMIN_PASSWORD')    ?: 'admin123');
define('ADMIN_SESSION_KEY', 'tg_shop_admin_session');

// Comma-separated Telegram user IDs that receive admin notifications
define('ADMIN_IDS',         getenv('ADMIN_IDS')         ?: '');

// Payment providers (set to empty string to disable)
define('HELEKET_API_KEY',   getenv('HELEKET_API_KEY')   ?: '');
define('HELEKET_SHOP_ID',   getenv('HELEKET_SHOP_ID')   ?: '');
define('CRYPTOBOT_TOKEN',   getenv('CRYPTOBOT_TOKEN')   ?: '');

// Base URL of the shop (no trailing slash)
define('BASE_URL',          getenv('BASE_URL')          ?: 'https://example.com');

// Paths (relative to project root; customise if needed)
define('DATA_DIR',    __DIR__ . '/data');
define('UPLOAD_DIR',  __DIR__ . '/uploads');
define('LOG_DIR',     __DIR__ . '/logs');
define('BOT_DIR',     __DIR__ . '/bot');
define('LANG_DIR',    __DIR__ . '/lang');

// Misc
define('DEFAULT_LANG', 'ru');
define('CURRENCY',     'RUB');
define('CURRENCY_SIGN','₽');
