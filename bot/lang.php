<?php
class Lang
{
    private static array $strings = [
        'ru' => [
            'start_hello'       => "👋 Привет, <b>%s</b>!\n\nДобро пожаловать в магазин цифровых товаров.",
            'main_menu'         => "🏠 Главное меню",
            'btn_catalog'       => "🛍 Каталог",
            'btn_balance'       => "💰 Баланс",
            'btn_topup'         => "➕ Пополнить",
            'btn_history'       => "📋 История",
            'btn_profile'       => "👤 Профиль",
            'btn_language'      => "🌐 Язык",
            'btn_back'          => "⬅️ Назад",
            'btn_buy'           => "💳 Купить за %s %s",
            'btn_already_bought'=> "✅ Уже куплено — скачать снова",
            'btn_cancel'        => "❌ Отмена",

            'profile_text'      => "👤 <b>Профиль</b>\n\n🆔 ID: <code>%d</code>\n👤 Имя: %s\n🔖 Username: %s\n\n💰 Баланс: <b>%s %s</b>\n🛒 Покупок: <b>%d</b>\n💸 Потрачено: <b>%s %s</b>\n📥 Пополнено: <b>%s %s</b>\n📅 Регистрация: %s",
            'balance_text'      => "💰 <b>Ваш баланс</b>\n\nТекущий баланс: <b>%s %s</b>",
            'topup_choose'      => "➕ <b>Пополнение баланса</b>\n\nВыберите способ оплаты:",
            'topup_enter_amount'=> "Введите сумму пополнения (минимум %s %s):",
            'topup_invalid_amount'=> "❌ Неверная сумма. Введите число не менее %s.",
            'topup_invoice_created'=> "✅ Счёт создан!\n\n💰 Сумма: <b>%s %s</b>\nПровайдер: <b>%s</b>\n\n<a href=\"%s\">👉 Оплатить</a>\n\nПосле оплаты баланс пополнится автоматически.",
            'topup_error'       => "❌ Ошибка при создании счёта. Попробуйте позже.",
            'payment_success'   => "✅ Баланс пополнен на <b>%s %s</b>!\n\nТекущий баланс: <b>%s %s</b>",

            'catalog_empty'     => "😔 Каталог пока пуст.",
            'categories_list'   => "🛍 <b>Каталог</b>\n\nВыберите категорию:",
            'products_list'     => "📦 <b>%s</b>\n\nВыберите товар:",
            'products_empty'    => "😔 В этой категории пока нет товаров.",
            'product_info'      => "📦 <b>%s</b>\n\n%s\n\n💰 Цена: <b>%s %s</b>",
            'buy_no_balance'    => "❌ Недостаточно средств!\n\nЦена: <b>%s %s</b>\nВаш баланс: <b>%s %s</b>\n\nПополните баланс для совершения покупки.",
            'buy_success'       => "✅ Покупка успешна!\n\n<b>%s</b>\n\nТовар отправлен ниже 👇",
            'buy_file_error'    => "✅ Покупка оформлена, но файл не найден. Обратитесь в поддержку.",
            'already_bought_resend'=> "✅ Вы уже приобрели этот товар. Файл отправляется повторно.",

            'history_empty'     => "📋 История покупок пуста.",
            'history_header'    => "📋 <b>История покупок</b>\n\n",
            'history_item'      => "🔹 <b>%s</b>\n   💰 %s %s · 📅 %s\n",

            'language_choose'   => "🌐 Выберите язык / Choose language:",
            'language_set_ru'   => "✅ Язык установлен: Русский",
            'language_set_en'   => "✅ Language set: English",

            'btn_heleket'       => "💳 Heleket",
            'btn_cryptobot'     => "🤖 Crypto Bot",

            'error_generic'     => "❌ Произошла ошибка. Попробуйте позже.",
        ],
        'en' => [
            'start_hello'       => "👋 Hello, <b>%s</b>!\n\nWelcome to the digital goods shop.",
            'main_menu'         => "🏠 Main Menu",
            'btn_catalog'       => "🛍 Catalog",
            'btn_balance'       => "💰 Balance",
            'btn_topup'         => "➕ Top Up",
            'btn_history'       => "📋 History",
            'btn_profile'       => "👤 Profile",
            'btn_language'      => "🌐 Language",
            'btn_back'          => "⬅️ Back",
            'btn_buy'           => "💳 Buy for %s %s",
            'btn_already_bought'=> "✅ Already bought — download again",
            'btn_cancel'        => "❌ Cancel",

            'profile_text'      => "👤 <b>Profile</b>\n\n🆔 ID: <code>%d</code>\n👤 Name: %s\n🔖 Username: %s\n\n💰 Balance: <b>%s %s</b>\n🛒 Purchases: <b>%d</b>\n💸 Spent: <b>%s %s</b>\n📥 Deposited: <b>%s %s</b>\n📅 Joined: %s",
            'balance_text'      => "💰 <b>Your Balance</b>\n\nCurrent balance: <b>%s %s</b>",
            'topup_choose'      => "➕ <b>Top Up Balance</b>\n\nChoose a payment method:",
            'topup_enter_amount'=> "Enter the amount (minimum %s %s):",
            'topup_invalid_amount'=> "❌ Invalid amount. Enter a number of at least %s.",
            'topup_invoice_created'=> "✅ Invoice created!\n\n💰 Amount: <b>%s %s</b>\nProvider: <b>%s</b>\n\n<a href=\"%s\">👉 Pay now</a>\n\nBalance will be credited automatically after payment.",
            'topup_error'       => "❌ Error creating invoice. Please try again later.",
            'payment_success'   => "✅ Balance topped up by <b>%s %s</b>!\n\nCurrent balance: <b>%s %s</b>",

            'catalog_empty'     => "😔 Catalog is empty.",
            'categories_list'   => "🛍 <b>Catalog</b>\n\nChoose a category:",
            'products_list'     => "📦 <b>%s</b>\n\nChoose a product:",
            'products_empty'    => "😔 No products in this category yet.",
            'product_info'      => "📦 <b>%s</b>\n\n%s\n\n💰 Price: <b>%s %s</b>",
            'buy_no_balance'    => "❌ Insufficient funds!\n\nPrice: <b>%s %s</b>\nYour balance: <b>%s %s</b>\n\nTop up your balance to proceed.",
            'buy_success'       => "✅ Purchase successful!\n\n<b>%s</b>\n\nYour file is below 👇",
            'buy_file_error'    => "✅ Purchase recorded, but file not found. Please contact support.",
            'already_bought_resend'=> "✅ You already own this product. Sending file again.",

            'history_empty'     => "📋 No purchase history yet.",
            'history_header'    => "📋 <b>Purchase History</b>\n\n",
            'history_item'      => "🔹 <b>%s</b>\n   💰 %s %s · 📅 %s\n",

            'language_choose'   => "🌐 Choose language / Выберите язык:",
            'language_set_ru'   => "✅ Язык установлен: Русский",
            'language_set_en'   => "✅ Language set: English",

            'btn_heleket'       => "💳 Heleket",
            'btn_cryptobot'     => "🤖 Crypto Bot",

            'error_generic'     => "❌ An error occurred. Please try again later.",
        ],
    ];

    public static function get(string $key, string $lang = 'ru', ...$args): string
    {
        $str = self::$strings[$lang][$key] ?? self::$strings['ru'][$key] ?? $key;
        if (!empty($args)) {
            return sprintf($str, ...$args);
        }
        return $str;
    }

    public static function forUser(array $user, string $key, ...$args): string
    {
        $lang = $user['language'] ?? 'ru';
        if (!isset(self::$strings[$lang])) $lang = 'ru';
        return self::get($key, $lang, ...$args);
    }
}
