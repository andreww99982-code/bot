<?php
class Handlers
{
    public static function handleUpdate(array $update): void
    {
        if (isset($update['message'])) {
            self::handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            self::handleCallbackQuery($update['callback_query']);
        }
    }

    // -------------------------------------------------------
    // Message handler
    // -------------------------------------------------------
    private static function handleMessage(array $message): void
    {
        $chatId = (int)$message['chat']['id'];
        $text   = $message['text'] ?? '';

        $user = Storage::getOrCreateUser($message['from']);
        $lang = $user['language'] ?? 'ru';

        // Handle state-based input first
        if ($user['state']) {
            self::handleState($chatId, $text, $user);
            return;
        }

        // Commands
        if (strncmp($text, '/', 1) === 0) {
            self::handleCommand($chatId, $text, $user, $message);
            return;
        }

        // Reply keyboard buttons
        $catalog  = Lang::get('btn_catalog',   $lang);
        $balance  = Lang::get('btn_balance',   $lang);
        $topup    = Lang::get('btn_topup',     $lang);
        $history  = Lang::get('btn_history',   $lang);
        $profile  = Lang::get('btn_profile',   $lang);
        $language = Lang::get('btn_language',  $lang);

        switch ($text) {
            case $catalog:
                self::showCategories($chatId, $user);
                break;
            case $balance:
                self::showBalance($chatId, $user);
                break;
            case $topup:
                self::showTopupMenu($chatId, $user);
                break;
            case $history:
                self::showHistory($chatId, $user);
                break;
            case $profile:
                self::showProfile($chatId, $user);
                break;
            case $language:
                self::showLanguageMenu($chatId, $user);
                break;
            default:
                self::showMainMenu($chatId, $user);
        }
    }

    // -------------------------------------------------------
    // Command handler
    // -------------------------------------------------------
    private static function handleCommand(int $chatId, string $text, array $user, array $message): void
    {
        $cmd  = explode(' ', explode('@', $text)[0])[0];
        $lang = $user['language'] ?? 'ru';

        switch ($cmd) {
            case '/start':
                Storage::setUserState($chatId, null);
                $settings    = Storage::getSettings();
                $welcomeKey  = 'welcome_message_' . $lang;
                $welcomeText = $settings[$welcomeKey] ?? $settings['welcome_message_ru'] ?? '';
                $name = htmlspecialchars($user['first_name'] ?? 'друг');
                TgBot::sendMessage($chatId, Lang::get('start_hello', $lang, $name));
                if ($welcomeText) {
                    TgBot::sendMessage($chatId, $welcomeText);
                }
                self::showMainMenu($chatId, $user);
                break;

            case '/profile':
                self::showProfile($chatId, $user);
                break;

            case '/balance':
                self::showBalance($chatId, $user);
                break;

            case '/catalog':
                self::showCategories($chatId, $user);
                break;

            case '/history':
                self::showHistory($chatId, $user);
                break;

            default:
                self::showMainMenu($chatId, $user);
        }
    }

    // -------------------------------------------------------
    // State handler (for multi-step flows)
    // -------------------------------------------------------
    private static function handleState(int $chatId, string $text, array $user): void
    {
        $lang     = $user['language'] ?? 'ru';
        $state    = $user['state'];
        $data     = $user['state_data'] ?? [];
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';
        $minDeposit = (float)($settings['min_deposit'] ?? 50);

        if ($state === 'awaiting_topup_amount') {
            $amount = (float)str_replace(',', '.', trim($text));
            if ($amount < $minDeposit || !is_numeric(trim($text))) {
                TgBot::sendMessage($chatId, Lang::get('topup_invalid_amount', $lang, $minDeposit));
                return;
            }

            $provider = $data['provider'] ?? 'heleket';

            if ($provider === 'heleket') {
                $payment = Payments::createHeleketInvoice($chatId, $amount, $settings['currency'] ?? 'RUB');
            } else {
                $payment = Payments::createCryptoBotInvoice($chatId, $amount, $settings['currency'] ?? 'RUB');
            }

            Storage::setUserState($chatId, null);

            if (!$payment) {
                TgBot::sendMessage($chatId, Lang::get('topup_error', $lang));
                return;
            }

            $providerName = $provider === 'heleket' ? 'Heleket' : 'Crypto Bot';
            TgBot::sendMessage($chatId, Lang::get(
                'topup_invoice_created', $lang,
                number_format($amount, 2, '.', ' '), $currency,
                $providerName,
                $payment['pay_url']
            ), ['disable_web_page_preview' => true]);
        }
    }

    // -------------------------------------------------------
    // Callback query handler
    // -------------------------------------------------------
    private static function handleCallbackQuery(array $cbq): void
    {
        $cbId   = $cbq['id'];
        $chatId = (int)$cbq['message']['chat']['id'];
        $msgId  = (int)$cbq['message']['message_id'];
        $data   = $cbq['data'] ?? '';

        $user = Storage::getUser($chatId);
        if (!$user) {
            TgBot::answerCallback($cbId, 'Error: user not found');
            return;
        }

        // Parse callback data: action[:param1[:param2...]]
        $parts  = explode(':', $data);
        $action = $parts[0] ?? '';
        $param1 = $parts[1] ?? null;
        $param2 = $parts[2] ?? null;

        TgBot::answerCallback($cbId);

        switch ($action) {
            case 'catalog':
                self::showCategories($chatId, $user, $msgId);
                break;

            case 'cat':
                self::showProducts($chatId, $user, (int)$param1, $msgId);
                break;

            case 'product':
                self::showProduct($chatId, $user, (int)$param1, $msgId);
                break;

            case 'buy':
                self::buyProduct($chatId, $user, (int)$param1, $msgId);
                break;

            case 'topup':
                self::showTopupMenu($chatId, $user, $msgId);
                break;

            case 'topup_provider':
                self::startTopupFlow($chatId, $user, $param1, $msgId);
                break;

            case 'lang':
                self::setLanguage($chatId, $user, $param1, $msgId);
                break;

            case 'main_menu':
                self::showMainMenuEdit($chatId, $user, $msgId);
                break;
        }
    }

    // -------------------------------------------------------
    // UI: Main Menu
    // -------------------------------------------------------
    private static function showMainMenu(int $chatId, array $user): void
    {
        $lang = $user['language'] ?? 'ru';
        TgBot::sendMessage($chatId, Lang::get('main_menu', $lang), [
            'reply_markup' => TgBot::replyKeyboard([
                [Lang::get('btn_catalog', $lang), Lang::get('btn_balance', $lang)],
                [Lang::get('btn_topup', $lang),   Lang::get('btn_history', $lang)],
                [Lang::get('btn_profile', $lang),  Lang::get('btn_language', $lang)],
            ]),
        ]);
    }

    private static function showMainMenuEdit(int $chatId, array $user, int $msgId): void
    {
        $lang = $user['language'] ?? 'ru';
        TgBot::editMessage($chatId, $msgId, Lang::get('main_menu', $lang));
    }

    // -------------------------------------------------------
    // UI: Profile
    // -------------------------------------------------------
    private static function showProfile(int $chatId, array $user): void
    {
        $lang     = $user['language'] ?? 'ru';
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';

        $username = $user['username'] ? '@' . htmlspecialchars($user['username']) : '—';
        $name     = trim(htmlspecialchars($user['first_name'] . ' ' . ($user['last_name'] ?? '')));

        $text = Lang::get('profile_text', $lang,
            $user['id'],
            $name,
            $username,
            number_format($user['balance'] ?? 0, 2, '.', ' '), $currency,
            (int)($user['purchases_count'] ?? 0),
            number_format($user['total_spent'] ?? 0, 2, '.', ' '), $currency,
            number_format($user['total_deposited'] ?? 0, 2, '.', ' '), $currency,
            $user['created_at'] ?? '—'
        );

        TgBot::sendMessage($chatId, $text);
    }

    // -------------------------------------------------------
    // UI: Balance
    // -------------------------------------------------------
    private static function showBalance(int $chatId, array $user): void
    {
        $lang     = $user['language'] ?? 'ru';
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';

        TgBot::sendMessage($chatId, Lang::get('balance_text', $lang,
            number_format($user['balance'] ?? 0, 2, '.', ' '),
            $currency
        ), [
            'reply_markup' => TgBot::inlineKeyboard([[
                ['text' => Lang::get('btn_topup', $lang), 'callback_data' => 'topup'],
            ]]),
        ]);
    }

    // -------------------------------------------------------
    // UI: Top-up Menu
    // -------------------------------------------------------
    private static function showTopupMenu(int $chatId, array $user, ?int $msgId = null): void
    {
        $lang = $user['language'] ?? 'ru';
        $kb   = TgBot::inlineKeyboard([
            [
                ['text' => Lang::get('btn_heleket', $lang),   'callback_data' => 'topup_provider:heleket'],
                ['text' => Lang::get('btn_cryptobot', $lang), 'callback_data' => 'topup_provider:cryptobot'],
            ],
        ]);
        $text = Lang::get('topup_choose', $lang);
        if ($msgId) {
            TgBot::editMessage($chatId, $msgId, $text, ['reply_markup' => $kb]);
        } else {
            TgBot::sendMessage($chatId, $text, ['reply_markup' => $kb]);
        }
    }

    private static function startTopupFlow(int $chatId, array $user, string $provider, ?int $msgId): void
    {
        $lang     = $user['language'] ?? 'ru';
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';
        $min      = $settings['min_deposit'] ?? 50;

        Storage::setUserState($chatId, 'awaiting_topup_amount', ['provider' => $provider]);

        if ($msgId) {
            TgBot::editMessage($chatId, $msgId, Lang::get('topup_enter_amount', $lang, $min, $currency));
        } else {
            TgBot::sendMessage($chatId, Lang::get('topup_enter_amount', $lang, $min, $currency), [
                'reply_markup' => TgBot::removeKeyboard(),
            ]);
        }
    }

    // -------------------------------------------------------
    // UI: Catalog — Categories
    // -------------------------------------------------------
    private static function showCategories(int $chatId, array $user, ?int $msgId = null): void
    {
        $lang = $user['language'] ?? 'ru';
        $cats = array_filter(Storage::getCategories(), fn($c) => $c['active'] ?? false);

        if (empty($cats)) {
            $text = Lang::get('catalog_empty', $lang);
            if ($msgId) TgBot::editMessage($chatId, $msgId, $text);
            else TgBot::sendMessage($chatId, $text);
            return;
        }

        $rows = [];
        foreach ($cats as $cat) {
            $name   = $lang === 'en' && !empty($cat['name_en']) ? $cat['name_en'] : $cat['name'];
            $rows[] = [['text' => $name, 'callback_data' => 'cat:' . $cat['id']]];
        }

        $kb   = TgBot::inlineKeyboard($rows);
        $text = Lang::get('categories_list', $lang);
        if ($msgId) TgBot::editMessage($chatId, $msgId, $text, ['reply_markup' => $kb]);
        else TgBot::sendMessage($chatId, $text, ['reply_markup' => $kb]);
    }

    // -------------------------------------------------------
    // UI: Products in Category
    // -------------------------------------------------------
    private static function showProducts(int $chatId, array $user, int $catId, ?int $msgId = null): void
    {
        $lang     = $user['language'] ?? 'ru';
        $cat      = Storage::getCategory($catId);
        $catName  = $cat ? ($lang === 'en' && !empty($cat['name_en']) ? $cat['name_en'] : $cat['name']) : '—';
        $products = Storage::getProductsByCategory($catId);
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';

        if (empty($products)) {
            $rows = [[['text' => Lang::get('btn_back', $lang), 'callback_data' => 'catalog']]];
            $text = Lang::get('products_empty', $lang);
            if ($msgId) TgBot::editMessage($chatId, $msgId, $text, ['reply_markup' => TgBot::inlineKeyboard($rows)]);
            else TgBot::sendMessage($chatId, $text, ['reply_markup' => TgBot::inlineKeyboard($rows)]);
            return;
        }

        $rows = [];
        foreach ($products as $p) {
            $title  = $lang === 'en' && !empty($p['title_en']) ? $p['title_en'] : $p['title'];
            $price  = number_format((float)$p['price'], 2, '.', ' ');
            $rows[] = [['text' => "$title — $price $currency", 'callback_data' => 'product:' . $p['id']]];
        }
        $rows[] = [['text' => Lang::get('btn_back', $lang), 'callback_data' => 'catalog']];

        $text = Lang::get('products_list', $lang, $catName);
        $kb   = TgBot::inlineKeyboard($rows);
        if ($msgId) TgBot::editMessage($chatId, $msgId, $text, ['reply_markup' => $kb]);
        else TgBot::sendMessage($chatId, $text, ['reply_markup' => $kb]);
    }

    // -------------------------------------------------------
    // UI: Single Product Detail
    // -------------------------------------------------------
    private static function showProduct(int $chatId, array $user, int $productId, ?int $msgId = null): void
    {
        $lang     = $user['language'] ?? 'ru';
        $product  = Storage::getProduct($productId);
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';

        if (!$product || !($product['active'] ?? false)) {
            if ($msgId) TgBot::editMessage($chatId, $msgId, '❌ Product not found.');
            else TgBot::sendMessage($chatId, '❌ Product not found.');
            return;
        }

        $title    = $lang === 'en' && !empty($product['title_en']) ? $product['title_en'] : $product['title'];
        $desc     = $lang === 'en' && !empty($product['description_en']) ? $product['description_en'] : ($product['description'] ?? '');
        $price    = number_format((float)$product['price'], 2, '.', ' ');
        $catId    = (int)($product['category_id'] ?? 0);
        $alreadyBought = Storage::hasUserBoughtProduct($chatId, $productId);

        $text = Lang::get('product_info', $lang, htmlspecialchars($title), htmlspecialchars($desc), $price, $currency);

        if ($alreadyBought) {
            $buyBtn = ['text' => Lang::get('btn_already_bought', $lang), 'callback_data' => 'buy:' . $productId];
        } else {
            $buyBtn = ['text' => Lang::get('btn_buy', $lang, $price, $currency), 'callback_data' => 'buy:' . $productId];
        }

        $kb = TgBot::inlineKeyboard([
            [$buyBtn],
            [['text' => Lang::get('btn_back', $lang), 'callback_data' => 'cat:' . $catId]],
        ]);

        if ($msgId) TgBot::editMessage($chatId, $msgId, $text, ['reply_markup' => $kb]);
        else TgBot::sendMessage($chatId, $text, ['reply_markup' => $kb]);
    }

    // -------------------------------------------------------
    // Buy Product
    // -------------------------------------------------------
    private static function buyProduct(int $chatId, array $user, int $productId, ?int $msgId): void
    {
        $lang     = $user['language'] ?? 'ru';
        $product  = Storage::getProduct($productId);
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';

        if (!$product || !($product['active'] ?? false)) {
            TgBot::sendMessage($chatId, Lang::get('error_generic', $lang));
            return;
        }

        $price   = (float)$product['price'];
        $balance = (float)($user['balance'] ?? 0);
        $alreadyBought = Storage::hasUserBoughtProduct($chatId, $productId);

        if (!$alreadyBought && $balance < $price) {
            TgBot::sendMessage($chatId, Lang::get(
                'buy_no_balance', $lang,
                number_format($price, 2, '.', ' '), $currency,
                number_format($balance, 2, '.', ' '), $currency
            ), [
                'reply_markup' => TgBot::inlineKeyboard([[
                    ['text' => Lang::get('btn_topup', $lang), 'callback_data' => 'topup'],
                ]]),
            ]);
            return;
        }

        if ($alreadyBought) {
            TgBot::sendMessage($chatId, Lang::get('already_bought_resend', $lang));
        } else {
            // Deduct balance
            Storage::updateBalance($chatId, -$price);

            // Update user stats
            $freshUser = Storage::getUser($chatId);
            if ($freshUser) {
                $freshUser['purchases_count'] = ($freshUser['purchases_count'] ?? 0) + 1;
                $freshUser['total_spent']     = round(($freshUser['total_spent'] ?? 0) + $price, 2);
                Storage::saveUser($chatId, $freshUser);
            }

            // Record order
            $orderId = 'ORD' . strtoupper(uniqid());
            Storage::addOrder([
                'order_id'   => $orderId,
                'user_id'    => $chatId,
                'product_id' => $productId,
                'price'      => $price,
                'currency'   => $settings['currency'] ?? 'RUB',
                'status'     => 'paid',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $title = $lang === 'en' && !empty($product['title_en']) ? $product['title_en'] : $product['title'];
            TgBot::sendMessage($chatId, Lang::get('buy_success', $lang, htmlspecialchars($title)));
        }

        // Deliver file
        $filePath = $product['file_path'] ?? '';
        if ($filePath) {
            $absPath = BASE_PATH . '/' . ltrim($filePath, '/');
            if (file_exists($absPath)) {
                $result = TgBot::sendDocument($chatId, $absPath, htmlspecialchars($product['title'] ?? ''));
                if ($result && isset($result['result']['document']['file_id'])) {
                    // Cache file_id for future deliveries
                    $product['tg_file_id'] = $result['result']['document']['file_id'];
                    Storage::saveProduct($productId, $product);
                }
            } elseif (!empty($product['tg_file_id'])) {
                TgBot::sendDocument($chatId, $product['tg_file_id']);
            } else {
                TgBot::sendMessage($chatId, Lang::get('buy_file_error', $lang));
            }
        } else {
            TgBot::sendMessage($chatId, Lang::get('buy_file_error', $lang));
        }
    }

    // -------------------------------------------------------
    // UI: History
    // -------------------------------------------------------
    private static function showHistory(int $chatId, array $user): void
    {
        $lang     = $user['language'] ?? 'ru';
        $orders   = Storage::getUserOrders($chatId);
        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';

        if (empty($orders)) {
            TgBot::sendMessage($chatId, Lang::get('history_empty', $lang));
            return;
        }

        $text = Lang::get('history_header', $lang);
        foreach (array_reverse($orders) as $order) {
            $product = Storage::getProduct((int)$order['product_id']);
            $title   = $product ? ($lang === 'en' && !empty($product['title_en']) ? $product['title_en'] : $product['title']) : '#' . $order['product_id'];
            $text   .= Lang::get('history_item', $lang,
                htmlspecialchars($title),
                number_format((float)$order['price'], 2, '.', ' '),
                $currency,
                $order['created_at'] ?? '—'
            );
        }

        TgBot::sendMessage($chatId, $text);
    }

    // -------------------------------------------------------
    // UI: Language
    // -------------------------------------------------------
    private static function showLanguageMenu(int $chatId, array $user): void
    {
        $lang = $user['language'] ?? 'ru';
        TgBot::sendMessage($chatId, Lang::get('language_choose', $lang), [
            'reply_markup' => TgBot::inlineKeyboard([[
                ['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru'],
                ['text' => '🇬🇧 English',  'callback_data' => 'lang:en'],
            ]]),
        ]);
    }

    private static function setLanguage(int $chatId, array $user, string $lang, ?int $msgId): void
    {
        if (!in_array($lang, ['ru', 'en'])) $lang = 'ru';
        Storage::setUserLanguage($chatId, $lang);
        $user['language'] = $lang;

        $confirmKey = $lang === 'ru' ? 'language_set_ru' : 'language_set_en';
        if ($msgId) {
            TgBot::editMessage($chatId, $msgId, Lang::get($confirmKey, $lang));
        } else {
            TgBot::sendMessage($chatId, Lang::get($confirmKey, $lang));
        }
        self::showMainMenu($chatId, $user);
    }
}
