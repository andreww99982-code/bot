<?php
// ============================================================
//  config.php — Bot configuration
//  All secrets must be set via environment variables.
//  Never hardcode credentials here.
// ============================================================

// ---- Telegram ---------------------------------------------------

// Bot token from @BotFather (required)
define('BOT_TOKEN',     getenv('BOT_TOKEN')     ?: '');

// Bot username without the @ sign (optional, used for deep-links)
define('BOT_USERNAME',  getenv('BOT_USERNAME')  ?: '');

// Full HTTPS URL to webhook.php reachable by Telegram (required for set_webhook.php)
define('WEBHOOK_URL',   getenv('WEBHOOK_URL')   ?: '');

// Random string sent by Telegram in X-Telegram-Bot-Api-Secret-Token header
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: '');

// ---- Admin panel ------------------------------------------------

// Telegram user ID of the bot owner (used for admin notifications)
define('ADMIN_CHAT_ID',  getenv('ADMIN_CHAT_ID') ?: '');

// Admin panel login username
define('ADMIN_LOGIN',    getenv('ADMIN_LOGIN')    ?: 'admin');

// Admin panel password — store a bcrypt hash for production:
//   php -r "echo password_hash('your_password', PASSWORD_DEFAULT);"
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '');

// PHP session name for admin panel
define('ADMIN_SESSION_NAME', getenv('ADMIN_SESSION_NAME') ?: 'digital_shop_admin');

// ---- Payment gateways -------------------------------------------

// Heleket.com API key and Shop ID
define('HELEKET_API_KEY', getenv('HELEKET_API_KEY') ?: '');
define('HELEKET_SHOP_ID', getenv('HELEKET_SHOP_ID') ?: '');

// @CryptoBot token (obtain from @CryptoBot on Telegram)
define('CRYPTOBOT_TOKEN',   getenv('CRYPTOBOT_TOKEN')   ?: '');
define('CRYPTOBOT_TESTNET', filter_var(getenv('CRYPTOBOT_TESTNET') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// ---- Site URL ---------------------------------------------------

// Public HTTPS URL of this installation (used in payment callback URLs)
define('APP_URL', getenv('APP_URL') ?: '');

// ---- Paths ------------------------------------------------------

define('BASE_PATH',    __DIR__);
define('DATA_PATH',    __DIR__ . '/data');
define('STORAGE_PATH', __DIR__ . '/storage');
define('LOGS_PATH',    __DIR__ . '/logs');

// Keep LOG_DIR as an alias used by helpers.php
define('LOG_DIR', __DIR__ . '/logs');

// Telegram Bot API base URL (used by helpers.php)
define('TELEGRAM_API', 'https://api.telegram.org/bot' . BOT_TOKEN);
