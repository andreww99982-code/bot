<?php
// ============================================================
// TELEGRAM BOT CONFIGURATION
// ============================================================
define('BOT_TOKEN',     'YOUR_BOT_TOKEN_HERE');
define('BOT_USERNAME',  'your_bot_username');
define('ADMIN_CHAT_ID', '');          // Telegram user_id of the admin

// Secret token for Telegram webhook security (random string)
define('WEBHOOK_SECRET', 'change_this_to_random_string');

// ============================================================
// PAYMENT GATEWAYS
// ============================================================
define('HELEKET_API_KEY',  '');        // heleket.com API key
define('HELEKET_SHOP_ID',  '');        // heleket.com Shop ID

define('CRYPTOBOT_TOKEN',   '');       // @CryptoBot token from @CryptoBot
define('CRYPTOBOT_TESTNET', false);    // true = use testnet.send.tg

// ============================================================
// PATHS
// ============================================================
define('BASE_PATH',    dirname(__FILE__));
define('DATA_PATH',    BASE_PATH . '/data');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('LOGS_PATH',    BASE_PATH . '/logs');

// Your site URL (used in payment callback URLs)
define('APP_URL', 'https://your-domain.com');

// ============================================================
// ADMIN PANEL
// ============================================================
define('ADMIN_LOGIN',        'admin');
// Store a bcrypt hash here for security:
//   php -r "echo password_hash('your_password', PASSWORD_DEFAULT);"
// Or leave as plain text for initial setup (change before production!)
define('ADMIN_PASSWORD',     'changeme123');
define('ADMIN_SESSION_NAME', 'digital_shop_admin');
