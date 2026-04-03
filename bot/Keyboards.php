<?php
/**
 * Keyboards.php — Build Telegram inline / reply keyboards.
 */

class Keyboards
{
    // ---- reply keyboards ------------------------------------------------

    public static function mainMenu(string $lang = 'ru'): array
    {
        Lang::load($lang);
        return [
            'keyboard' => [
                [
                    ['text' => Lang::get('btn_catalog')],
                    ['text' => Lang::get('btn_profile')],
                ],
                [
                    ['text' => Lang::get('btn_topup')],
                    ['text' => Lang::get('btn_language')],
                ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];
    }

    // ---- inline keyboards -----------------------------------------------

    public static function languageSelect(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru'],
                    ['text' => '🇬🇧 English',  'callback_data' => 'lang:en'],
                ],
            ],
        ];
    }

    public static function categories(array $categories, string $lang): array
    {
        $rows = [];
        foreach ($categories as $cat) {
            $name = $cat['name_' . $lang] ?? $cat['name_ru'] ?? 'Category';
            $rows[] = [['text' => $name, 'callback_data' => 'cat:' . $cat['id']]];
        }
        $rows[] = [['text' => Lang::get('btn_back'), 'callback_data' => 'back:main']];
        return ['inline_keyboard' => $rows];
    }

    public static function products(array $products, string $catId, string $lang): array
    {
        $rows = [];
        foreach ($products as $p) {
            $name = $p['name_' . $lang] ?? $p['name_ru'] ?? 'Product';
            $rows[] = [['text' => $name, 'callback_data' => 'prod:' . $p['id']]];
        }
        $rows[] = [['text' => Lang::get('btn_back'), 'callback_data' => 'back:cat:' . $catId]];
        return ['inline_keyboard' => $rows];
    }

    public static function productActions(string $productId, string $lang): array
    {
        Lang::load($lang);
        return [
            'inline_keyboard' => [
                [['text' => Lang::get('btn_buy'), 'callback_data' => 'buy:' . $productId]],
                [['text' => Lang::get('btn_back'), 'callback_data' => 'back:prod_list:' . $productId]],
            ],
        ];
    }

    public static function topupProviders(string $lang): array
    {
        Lang::load($lang);
        $rows = [
            [['text' => '💳 Heleket',    'callback_data' => 'topup:heleket']],
            [['text' => '🤖 Crypto Bot', 'callback_data' => 'topup:cryptobot']],
            [['text' => Lang::get('btn_back'), 'callback_data' => 'back:main']],
        ];
        return ['inline_keyboard' => $rows];
    }

    public static function confirmPurchase(string $productId, string $lang): array
    {
        Lang::load($lang);
        return [
            'inline_keyboard' => [
                [
                    ['text' => Lang::get('btn_confirm'), 'callback_data' => 'confirm_buy:' . $productId],
                    ['text' => Lang::get('btn_cancel'),  'callback_data' => 'cancel_buy'],
                ],
            ],
        ];
    }

    public static function backToMain(string $lang): array
    {
        Lang::load($lang);
        return [
            'inline_keyboard' => [
                [['text' => Lang::get('btn_back_main'), 'callback_data' => 'back:main']],
            ],
        ];
    }
}
