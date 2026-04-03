# Telegram Bot (PHP)

A clean, production-ready Telegram bot built in plain PHP.  
Handles webhook updates, supports text commands, logs events and errors.

---

## Requirements

| Requirement | Details |
|---|---|
| PHP | 8.0 or higher |
| cURL extension | `extension=curl` must be enabled |
| HTTPS | Required by Telegram for webhooks |
| Write permissions | `logs/` directory must be writable by the web server |

---

## Project Structure

```
/
├── config.php          — Configuration (reads from environment)
├── webhook.php         — Telegram webhook entry point
├── set_webhook.php     — Register the webhook with Telegram
├── helpers.php         — Shared utility functions
├── .htaccess           — Protect sensitive files from direct access
├── logs/
│   ├── webhook.log     — All incoming updates (auto-created, capped at 5 MB)
│   └── error.log       — Error events (auto-created)
└── README.md           — This file
```

---

## Setup

### 1. Clone / upload files

```bash
git clone https://github.com/youruser/bot.git /var/www/html/bot
```

Or upload the files to your web server's public root.

### 2. Set environment variables

**Never** put secrets in source files. Export them before running PHP, or set them in your hosting control panel / `.env` loader.

| Variable | Required | Description |
|---|---|---|
| `BOT_TOKEN` | ✅ | Bot token from [@BotFather](https://t.me/BotFather) |
| `WEBHOOK_URL` | ✅ | Full HTTPS URL to `webhook.php`, e.g. `https://example.com/webhook.php` |
| `WEBHOOK_SECRET` | recommended | Random string sent in the `X-Telegram-Bot-Api-Secret-Token` header |
| `ADMIN_IDS` | optional | Comma-separated Telegram user IDs for admin checks, e.g. `123456,789012` |

Example (Linux shell):
```bash
export BOT_TOKEN="1234567890:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
export WEBHOOK_URL="https://example.com/webhook.php"
export WEBHOOK_SECRET="$(openssl rand -hex 32)"
export ADMIN_IDS="123456789"
```

Example (Apache `VirtualHost`):
```apache
SetEnv BOT_TOKEN "1234567890:AAxxxxxxxx..."
SetEnv WEBHOOK_URL "https://example.com/webhook.php"
SetEnv WEBHOOK_SECRET "your-random-secret"
```

Example (Nginx + PHP-FPM, `fastcgi_params`):
```nginx
fastcgi_param BOT_TOKEN "1234567890:AAxxxxxxxx...";
fastcgi_param WEBHOOK_URL "https://example.com/webhook.php";
fastcgi_param WEBHOOK_SECRET "your-random-secret";
```

### 3. Set directory permissions

```bash
chmod 755 logs/
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

### 5. Test it

Send `/start` to your bot in Telegram. You should receive a greeting reply.

---

## Adding New Commands

Open `webhook.php` and add a new branch inside `handleUpdate()`:

```php
if (str_starts_with($text, '/mycommand')) {
    handleMyCommand($chatId, $userId, $message);
    return;
}
```

Then define the handler function at the bottom of the file:

```php
function handleMyCommand(int $chatId, int $userId, array $message): void
{
    sendMessage($chatId, '✅ My command works!');
}
```

---

## Utility Functions (`helpers.php`)

| Function | Description |
|---|---|
| `sendMessage($chatId, $text, $extra)` | Send a text message to a chat |
| `apiRequest($method, $params)` | Call any Telegram Bot API method |
| `logEvent($message, $file)` | Append a line to a log file in `logs/` |
| `logError($message)` | Shorthand to log to `error.log` |
| `isAdmin($userId)` | Check if a user ID is in `ADMIN_IDS` |
| `arrayGet($array, $key, $default)` | Safe dot-notation array accessor |

---

## Security Notes

- Secrets are **never** in source code — always use environment variables.
- `.htaccess` blocks direct browser access to `config.php`, `helpers.php`, and `set_webhook.php`.
- `WEBHOOK_SECRET` ensures only Telegram can post to `webhook.php`.
- The webhook responds with HTTP 200 immediately before processing to prevent Telegram retries.
- All user input is sanitised with `htmlspecialchars` before being echoed back.
- Errors are caught and logged without leaking internal details to the caller.
