# Telegram магазин файлов (PHP + vanilla JS)

Минималистичный проект бота для продажи файлов:
- бот на `webhook.php`
- админка в одном файле `admin/index.php` (SPA без внешних библиотек)
- база данных в JSON
- архивы формируются сервером через `ZipArchive`

## Структура

```text
/
├── webhook.php
├── admin/
│   ├── index.php
│   └── .htaccess
├── data/
│   ├── users.json
│   ├── categories.json
│   ├── products.json
│   └── settings.json
├── files/
│   └── .htaccess
├── logs/
│   └── .htaccess
├── config.php
├── helpers.php
├── .htaccess
└── set_webhook.php
```

## Требования

- PHP 8.1+
- расширения: `curl`, `zip`
- HTTPS домен
- права записи для папок `data/`, `files/`, `logs/`

## Переменные окружения

Все секреты только через `getenv()`:

- `BOT_TOKEN`
- `WEBHOOK_URL` (полный URL до `webhook.php`)
- `WEBHOOK_SECRET`
- `ADMIN_PASSWORD`

Пример для Apache (`.htaccess`):

```apache
SetEnv BOT_TOKEN "<BOT_TOKEN>"
SetEnv WEBHOOK_URL "https://example.com/webhook.php"
SetEnv WEBHOOK_SECRET "very_secret_value"
SetEnv ADMIN_PASSWORD "strong_admin_password"
```

## Установка

1. Загрузите проект на хостинг.
2. Укажите переменные окружения.
3. Убедитесь, что папки `data`, `files`, `logs` доступны на запись.
4. Установите webhook:
   - CLI: `php set_webhook.php`
   - браузер: `https://example.com/set_webhook.php?secret=YOUR_WEBHOOK_SECRET`
5. Откройте админку: `https://example.com/admin/`

## Как работает бот

### `/start`
- если язык не выбран: показывает кнопки 🇷🇺/🇬🇧
- если уже выбран: сразу главное меню

### Главное меню
- 🛍 Каталог
- 👤 Личный кабинет
- ℹ️ Помощь

### Каталог
- показывает категории и количество доступных товаров
- внутри категории: товар, цена, остаток
- покупка:
  - проверка баланса
  - защита от гонок через lock-файл
  - списание баланса
  - уменьшение остатка
  - запись истории
  - отправка ZIP через `sendDocument`

### Личный кабинет
- имя, баланс, количество покупок
- пополнение (инструкция с `@admin_username`)
- история покупок
- повторная отправка файла

## Админка

### Авторизация
- пароль из `ADMIN_PASSWORD`
- сессия через PHP session

### Разделы
- 📦 Категории: создание (RU/EN), удаление (если нет товаров)
- 🗂 Товары: создание, загрузка файлов (drag & drop + input), ZIP-архив, цена, остаток
- 👥 Пользователи: список, пополнение баланса, просмотр истории
- ⚙️ Настройки: username администратора для инструкции пополнения
- 📊 Статистика: пользователи, продажи, выручка, последние 10 покупок

## JSON структуры

### `data/users.json`

```json
{
  "123456789": {
    "id": 123456789,
    "first_name": "Ivan",
    "username": "ivan",
    "lang": "ru",
    "balance": 100,
    "purchases": [
      {
        "id": "uuid",
        "product_id": "product_uuid",
        "product_name": "Название",
        "price": 50,
        "date": "2026-04-19 12:00:00",
        "file": "files/archive_uuid.zip"
      }
    ]
  }
}
```

### `data/categories.json`

```json
{
  "cat_uuid": {
    "id": "cat_uuid",
    "name": {"ru": "Документы", "en": "Documents"},
    "description": {"ru": "...", "en": "..."}
  }
}
```

### `data/products.json`

```json
{
  "prod_uuid": {
    "id": "prod_uuid",
    "category_id": "cat_uuid",
    "name": {"ru": "Пакет", "en": "Pack"},
    "price": 99,
    "file": "files/archive_prod_uuid.zip",
    "stock": -1,
    "sold": 0,
    "active": true,
    "created_at": "2026-04-19 12:00:00"
  }
}
```

### `data/settings.json`

```json
{
  "admin_username": "admin_tg_username",
  "currency": "RUB",
  "currency_symbol": "₽"
}
```
