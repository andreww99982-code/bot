# Digital Goods Telegram Bot (PHP)

A production-ready Telegram bot for selling digital goods, built in plain PHP.  
Features a product catalog, user balance/top-up, purchase history, RU/EN localization,  
payment integrations (Heleket, CryptoBot), and a web-based admin panel.

---

## Requirements

| Requirement | Details |
|---|---|
| PHP | 8.0 or higher |
| cURL extension | `extension=curl` must be enabled |
| HTTPS | Required by Telegram for webhooks |
| Write permissions | `data/`, `logs/`, `storage/` directories must be writable |

---

## Project Structure

```
/
├── config.php              — Configuration (reads from environment variables)
├── webhook.php             — Telegram webhook entry point + payment callbacks
├── set_webhook.php         — Register the webhook with Telegram
├── helpers.php             — Standalone helper functions (legacy / utility)
├── .htaccess               — Protect sensitive files and directories
├── bot/
│   ├── handlers.php        — Message and callback_query routing
│   ├── storage.php         — JSON-based data layer
│   ├── utils.php           — TgBot class (Telegram API wrapper + logging)
│   ├── lang.php            — RU/EN localization strings
│   └── payments.php        — Heleket and CryptoBot payment integrations
├── admin/
│   └── index.php           — Single-file admin panel (categories, products, orders, users, settings)
├── data/
│   ├── categories.json     — Product categories
│   ├── products.json       — Products
│   ├── orders.json         — Purchase orders
│   ├── payments.json       — Payment transactions
│   ├── settings.json       — Bot settings (editable via admin panel)
│   └── users.json          — User records
├── storage/
│   └── files/              — Uploaded digital product files (protected from web access)
├── logs/
│   └── bot.log             — Application log (auto-created, capped at 5 MB)
└── README.md               — This file
```

---

## Setup

### 1. Clone / upload files

```bash
git clone https://github.com/youruser/bot.git /var/www/html/bot
```

### 2. Set environment variables

**Never** put secrets in source files. Export them before running PHP, or set them in your hosting control panel.

| Variable | Required | Description |
|---|---|---|
| `BOT_TOKEN` | ✅ | Bot token from [@BotFather](https://t.me/BotFather) |
| `WEBHOOK_URL` | ✅ | Full HTTPS URL to `webhook.php`, e.g. `https://example.com/webhook.php` |
| `WEBHOOK_SECRET` | recommended | Random string for `X-Telegram-Bot-Api-Secret-Token` header |
| `APP_URL` | ✅ | Base URL of your site, e.g. `https://example.com` (used for payment callbacks) |
| `BOT_USERNAME` | optional | Bot username without `@`, e.g. `myshopbot` |
| `ADMIN_CHAT_ID` | optional | Your Telegram user ID for admin notifications |
| `ADMIN_LOGIN` | optional | Admin panel login (default: `admin`) |
| `ADMIN_PASSWORD` | optional | Admin panel password or bcrypt hash (default: `changeme123`) |
| `ADMIN_SESSION_NAME` | optional | PHP session name for admin panel |
| `HELEKET_API_KEY` | optional | Heleket.com API key |
| `HELEKET_SHOP_ID` | optional | Heleket.com Shop ID |
| `CRYPTOBOT_TOKEN` | optional | CryptoBot API token from [@CryptoBot](https://t.me/CryptoBot) |
| `CRYPTOBOT_TESTNET` | optional | Set to `true` to use CryptoBot testnet |

Example (Linux shell):
```bash
export BOT_TOKEN="1234567890:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
export WEBHOOK_URL="https://example.com/webhook.php"
export WEBHOOK_SECRET="$(openssl rand -hex 32)"
export APP_URL="https://example.com"
export ADMIN_PASSWORD="$(php -r "echo password_hash('your_password', PASSWORD_DEFAULT);")"
```

Example (Apache `VirtualHost`):
```apache
SetEnv BOT_TOKEN "1234567890:AAxxxxxxxx..."
SetEnv WEBHOOK_URL "https://example.com/webhook.php"
SetEnv WEBHOOK_SECRET "your-random-secret"
SetEnv APP_URL "https://example.com"
SetEnv ADMIN_PASSWORD "$2y$12$..."
```

### 3. Set directory permissions

```bash
chmod 755 data/ logs/ storage/ storage/files/
```

### 4. Register the webhook

**Via CLI** (recommended):
```bash
BOT_TOKEN=... WEBHOOK_URL=... WEBHOOK_SECRET=... php set_webhook.php
```

**Via browser** (pass `WEBHOOK_SECRET` as a query parameter):
```
https://example.com/set_webhook.php?secret=your-random-secret
```

A successful response looks like:
```json
{
    "ok": true,
    "result": true,
    "description": "Webhook was set"
}
```

### 5. Access the admin panel

Navigate to:
```
https://example.com/admin/index.php
```

Log in with `ADMIN_LOGIN` / `ADMIN_PASSWORD`. From the admin panel you can:
- Add/edit/delete product categories
- Add/edit/delete products (with file upload)
- View orders and users
- Edit bot settings (welcome message, currency, minimum deposit)

### 6. Test the bot

Send `/start` to your bot in Telegram. You should see a welcome message and the main menu.

---

## Bot Commands

| Command | Description |
|---|---|
| `/start` | Show welcome message and main menu |
| `/catalog` | Browse product categories |
| `/balance` | Check your current balance |
| `/history` | View your purchase history |
| `/profile` | Show your profile info |

The main menu also provides reply-keyboard buttons for all of the above.

---

## Payment Integrations

### Heleket
Set `HELEKET_API_KEY` and `HELEKET_SHOP_ID`. The payment callback URL is:
```
https://example.com/webhook.php?route=heleket
```

### CryptoBot
Set `CRYPTOBOT_TOKEN`. The callback is handled automatically by CryptoBot's webhook system.

If neither payment gateway is configured, the bot will generate demo invoice links for testing.

---

## Adding Products

1. Log into the admin panel at `/admin/index.php`.
2. Create a category under **Categories**.
3. Add a product under **Products** — attach a file from `storage/files/` or enter a Telegram file ID.
4. Set the product as active.

Users can then browse the catalog, top up their balance, and purchase products.

---

## Security Notes

- All secrets use environment variables — never hardcoded.
- `.htaccess` and per-directory `.htaccess` files block direct access to `data/`, `storage/`, `config.php`, `helpers.php`, and `set_webhook.php`.
- `WEBHOOK_SECRET` ensures only Telegram can post to `webhook.php`.
- Payment provider webhooks verify HMAC signatures (Heleket) or API tokens (CryptoBot).
- Admin panel supports bcrypt-hashed passwords.
- All user input is HTML-escaped before being displayed.
- File delivery validates paths against `storage/` to prevent path traversal.
- JSON data files use exclusive file locking to prevent race conditions.
