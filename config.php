<?php
// ============================================================
//  config.php — Bot configuration
//  All secrets must be set via environment variables.
//  Never hardcode credentials here.
// ============================================================

// Telegram bot token from @BotFather (required)
define('BOT_TOKEN',      getenv('BOT_TOKEN')      ?: '');

// Full HTTPS URL where webhook.php is reachable by Telegram (required)
define('WEBHOOK_URL',    getenv('WEBHOOK_URL')     ?: '');

// Optional secret token sent by Telegram in X-Telegram-Bot-Api-Secret-Token header
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET')  ?: '');

// Comma-separated Telegram user IDs that are treated as admins, e.g. "123456,789012"
define('ADMIN_IDS',      getenv('ADMIN_IDS')       ?: '');

// Paths
define('LOG_DIR', __DIR__ . '/logs');

// Telegram Bot API base URL
define('TELEGRAM_API', 'https://api.telegram.org/bot' . BOT_TOKEN);
