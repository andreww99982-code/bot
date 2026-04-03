# Telegram Digital Shop Bot

A complete Telegram bot for selling digital goods (archives, photos, files) with a PHP web admin panel, JSON storage, and balance top-up via Heleket and Crypto Bot.

## Features

- 🛍 Product catalog with categories (defined in admin panel)
- 💰 Internal user balance system
- 💳 Balance top-up via Heleket.com and Crypto Bot (@CryptoBot)
- 📋 Purchase history & statistics
- 👤 User profile / personal account
- 🌐 Russian / English language switcher
- 🔒 Protected file storage (not browser-accessible)
- 🖥 Web admin panel with Bootstrap 5 UI
- 📦 Easy shared hosting installation

## Installation

### 1. Upload Files
Upload all project files to your server's `public_html` (or web root) directory.

### 2. Set Permissions
```bash
chmod 755 data/ storage/ logs/
chmod 644 data/*.json
```

### 3. Edit config.php
```php
define('BOT_TOKEN',     'YOUR_TELEGRAM_BOT_TOKEN');
define('BOT_USERNAME',  'your_bot_username');
define('ADMIN_CHAT_ID', '123456789');      // Your Telegram user ID
define('WEBHOOK_SECRET', 'random_secret_string');
define('APP_URL', 'https://your-domain.com');

// Payment gateways (leave empty to use demo mode)
define('HELEKET_API_KEY',  'your_heleket_api_key');
define('HELEKET_SHOP_ID',  'your_heleket_shop_id');
define('CRYPTOBOT_TOKEN',   'your_cryptobot_token');

// Admin panel credentials
define('ADMIN_LOGIN',    'admin');
define('ADMIN_PASSWORD', 'your_secure_password');
```

### 4. Set Telegram Webhook
Open in browser:
```
https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=https://your-domain.com/webhook.php&secret_token=YOUR_WEBHOOK_SECRET
```

### 5. Access Admin Panel
Go to: `https://your-domain.com/admin/`

Log in with the credentials from `config.php`.

### 6. Add Content
1. Go to **Categories** → Add Category
2. Go to **Products** → Add Product (upload file, set price)
3. Your catalog is live!

## Directory Structure

```
/
├── config.php          # Main configuration
├── webhook.php         # Telegram webhook entry point
├── .htaccess           # Security (disable directory listing)
├── bot/
│   ├── storage.php     # JSON data layer
│   ├── utils.php       # Telegram API helpers
│   ├── lang.php        # RU/EN translations
│   ├── handlers.php    # Bot command/message handlers
│   └── payments.php    # Heleket + CryptoBot integration
├── admin/
│   └── index.php       # Web admin panel
├── data/               # JSON data files (protected)
│   ├── .htaccess       # Deny direct access
│   ├── users.json
│   ├── categories.json
│   ├── products.json
│   ├── orders.json
│   ├── payments.json
│   └── settings.json
├── storage/            # Uploaded product files (protected)
│   ├── .htaccess       # Deny direct access
│   └── files/
└── logs/               # Error logs (protected)
    └── .htaccess       # Deny direct access
```

## Payment Integration

### Heleket.com
1. Register at heleket.com
2. Get your API Key and Shop ID
3. Set the webhook URL in your Heleket dashboard:
   `https://your-domain.com/webhook.php?route=heleket`

### Crypto Bot (@CryptoBot)
1. Open @CryptoBot on Telegram
2. Send `/pay` to get your API token
3. Enable webhook in @CryptoBot dashboard:
   `https://your-domain.com/webhook.php?route=cryptobot`

> **Note:** If payment credentials are not set, the bot uses demo mode (fake invoice links for testing).

## Bot Commands

- `/start` — Welcome message + main menu
- `/catalog` — Browse products
- `/balance` — View current balance
- `/history` — Purchase history
- `/profile` — User profile

## Requirements

- PHP 7.4+
- cURL extension enabled
- Write permissions on `data/`, `storage/`, `logs/`
- HTTPS (required for Telegram webhooks)
