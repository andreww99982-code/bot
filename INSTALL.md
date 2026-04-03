# Installation Guide

## Requirements
- PHP 8.0+
- cURL extension enabled
- File write permissions on `data/`, `uploads/`, `logs/`

## Quick Start

### 1. Upload files to your hosting
Upload all project files to your web server root (or a subdirectory).

### 2. Edit `config.php`
Open `config.php` and set at minimum:

```php
define('BOT_TOKEN',      'YOUR_BOT_TOKEN');   // from @BotFather
define('WEBHOOK_SECRET', 'any_random_secret'); // optional but recommended
define('ADMIN_PASSWORD', 'your_admin_pass');   // for /admin/ panel
define('BASE_URL',       'https://yourdomain.com'); // no trailing slash
```

You can also use environment variables (preferred on VPS):
```
BOT_TOKEN=...
WEBHOOK_SECRET=...
ADMIN_PASSWORD=...
BASE_URL=https://yourdomain.com
```

### 3. Set directory permissions
```bash
chmod 755 data/ uploads/ logs/
```

### 4. Register the webhook
Open in your browser:
```
https://yourdomain.com/set_webhook.php
```
Or run via CLI:
```bash
php set_webhook.php
```
You should see `{"ok":true, ...}`.

### 5. Open the admin panel
```
https://yourdomain.com/admin/
```
Log in with the password you set in `config.php`.

## Payment Providers

### Heleket
1. Register at https://heleket.com
2. Get your API key and Shop ID
3. Set `HELEKET_API_KEY` and `HELEKET_SHOP_ID` in `config.php`
4. Set your callback/notify URL to: `https://yourdomain.com/webhook.php?topup_callback=heleket`

### Crypto Bot (Telegram)
1. Open @CryptoBot in Telegram → API → Create application
2. Get your token
3. Set `CRYPTOBOT_TOKEN` in `config.php`
4. Crypto Bot will call your webhook automatically

## Project Structure

```
/
├── config.php              — Main configuration
├── webhook.php             — Telegram webhook endpoint
├── set_webhook.php         — Webhook registration helper
├── .htaccess               — Root security rules
├── bot/
│   ├── Bot.php             — Main bot handler
│   ├── Storage.php         — JSON data layer
│   ├── Lang.php            — Language helper
│   ├── Keyboards.php       — Telegram keyboards
│   └── Payments.php        — Payment integrations
├── admin/
│   ├── index.php           — Login
│   ├── dashboard.php       — Dashboard
│   ├── categories.php      — Category management
│   ├── products.php        — Product management
│   ├── users.php           — Users
│   ├── orders.php          — Orders + top-up approval
│   └── settings.php        — Shop settings
├── lang/
│   ├── ru.php              — Russian strings
│   └── en.php              — English strings
├── data/                   — JSON data files (protected)
├── uploads/                — Product files (protected)
└── logs/                   — Webhook logs (protected)
```

## Security Notes
- `data/`, `uploads/`, `logs/` are protected by `.htaccess` (Deny from all).
- `config.php` and bot class files are also protected from direct browser access.
- Use HTTPS on your server (required by Telegram for webhooks).
- Change `ADMIN_PASSWORD` before going live.
