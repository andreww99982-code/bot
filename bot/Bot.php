<?php
/**
 * Bot.php — Main Telegram bot handler.
 */

class Bot
{
    private array $update;
    private ?array $message;
    private ?array $callbackQuery;
    private int    $chatId;
    private int    $userId;
    private array  $user;
    private string $lang;

    public function __construct(array $update)
    {
        $this->update        = $update;
        $this->message       = $update['message'] ?? null;
        $this->callbackQuery = $update['callback_query'] ?? null;

        if ($this->callbackQuery) {
            $this->chatId = $this->callbackQuery['message']['chat']['id'];
            $this->userId = $this->callbackQuery['from']['id'];
        } elseif ($this->message) {
            $this->chatId = $this->message['chat']['id'];
            $this->userId = $this->message['from']['id'];
        } else {
            return;
        }

        // Bootstrap user
        $from     = $this->message['from'] ?? $this->callbackQuery['from'] ?? [];
        $this->user = Storage::getOrCreateUser($this->userId, [
            'username'   => $from['username']   ?? '',
            'first_name' => $from['first_name'] ?? '',
        ]);
        // Update name/username if changed
        if (!empty($from['username']) && $this->user['username'] !== $from['username']) {
            $this->user['username'] = $from['username'];
            Storage::saveUser($this->user);
        }

        $this->lang = $this->user['lang'] ?? DEFAULT_LANG;
        Lang::load($this->lang);
    }

    // ---- entry point ----------------------------------------------------

    public function handle(): void
    {
        if (!isset($this->chatId)) return;

        if ($this->callbackQuery) {
            $this->handleCallback($this->callbackQuery['data'] ?? '');
            return;
        }

        if ($this->message) {
            $text = trim($this->message['text'] ?? '');

            // Command routing
            if (str_starts_with($text, '/start')) {
                $this->cmdStart();
                return;
            }
            if ($text === '/help') {
                $this->cmdHelp();
                return;
            }

            // State-based input
            $state = $this->user['state'] ?? null;
            if ($state !== null) {
                $this->handleState($state, $text);
                return;
            }

            // Menu button routing
            $this->handleMenuButton($text);
        }
    }

    // ---- commands -------------------------------------------------------

    private function cmdStart(): void
    {
        if (empty($this->user['lang_set'])) {
            $this->sendMessage(
                Lang::get('choose_lang'),
                Keyboards::languageSelect()
            );
            return;
        }

        $settings = Storage::getSettings();
        $welcomeKey = 'welcome_text_' . $this->lang;
        $welcome = $settings[$welcomeKey] ?? Lang::get('welcome');

        $this->sendMessage($welcome, Keyboards::mainMenu($this->lang));
    }

    private function cmdHelp(): void
    {
        $settings = Storage::getSettings();
        $support  = $settings['support_username'] ?? '';
        $text = Lang::get('help_text');
        if ($support) {
            $text .= "\n\n" . Lang::get('support_contact', ['username' => '@' . ltrim($support, '@')]);
        }
        $this->sendMessage($text);
    }

    // ---- callback query handler -----------------------------------------

    private function handleCallback(string $data): void
    {
        $this->answerCallback();

        // Language selection
        if (str_starts_with($data, 'lang:')) {
            $lang = substr($data, 5);
            $this->user['lang']    = $lang;
            $this->user['lang_set'] = true;
            Storage::saveUser($this->user);
            Lang::load($lang);
            $this->lang = $lang;

            $settings = Storage::getSettings();
            $welcomeKey = 'welcome_text_' . $lang;
            $welcome = $settings[$welcomeKey] ?? Lang::get('welcome');
            $this->sendMessage($welcome, Keyboards::mainMenu($lang));
            return;
        }

        // Category selected
        if (str_starts_with($data, 'cat:')) {
            $catId = substr($data, 4);
            $this->showCategory($catId);
            return;
        }

        // Product selected
        if (str_starts_with($data, 'prod:')) {
            $productId = substr($data, 5);
            $this->showProduct($productId);
            return;
        }

        // Buy action
        if (str_starts_with($data, 'buy:')) {
            $productId = substr($data, 4);
            $this->showConfirmPurchase($productId);
            return;
        }

        // Confirm purchase
        if (str_starts_with($data, 'confirm_buy:')) {
            $productId = substr($data, 12);
            $this->processPurchase($productId);
            return;
        }

        // Cancel buy
        if ($data === 'cancel_buy') {
            $this->editMessageText(Lang::get('purchase_cancelled'));
            return;
        }

        // Top-up provider
        if (str_starts_with($data, 'topup:')) {
            $provider = substr($data, 6);
            $this->handleTopupProvider($provider);
            return;
        }

        // Back buttons
        if (str_starts_with($data, 'back:')) {
            $target = substr($data, 5);
            if ($target === 'main') {
                $this->editMessageText(Lang::get('main_menu'), Keyboards::mainMenu($this->lang));
                return;
            }
            if (str_starts_with($target, 'cat:')) {
                $catId = substr($target, 4);
                $this->showCategory($catId);
                return;
            }
            if (str_starts_with($target, 'prod_list:')) {
                // Back to the product list for this product's category
                $prod = Storage::getProduct(substr($target, 10));
                if ($prod) $this->showCategory($prod['category_id']);
                return;
            }
        }
    }

    // ---- menu button handler --------------------------------------------

    private function handleMenuButton(string $text): void
    {
        $catalog  = Lang::get('btn_catalog');
        $profile  = Lang::get('btn_profile');
        $topup    = Lang::get('btn_topup');
        $language = Lang::get('btn_language');

        if ($text === $catalog) {
            $this->showCatalog();
        } elseif ($text === $profile) {
            $this->showProfile();
        } elseif ($text === $topup) {
            $this->showTopup();
        } elseif ($text === $language) {
            $this->sendMessage(Lang::get('choose_lang'), Keyboards::languageSelect());
        } else {
            $this->sendMessage(Lang::get('unknown_command'), Keyboards::mainMenu($this->lang));
        }
    }

    // ---- state handler --------------------------------------------------

    private function handleState(string $state, string $text): void
    {
        if ($state === 'topup_amount') {
            $provider = $this->user['state_data']['provider'] ?? 'heleket';
            $settings = Storage::getSettings();
            $minTopup = (float)($settings['min_topup'] ?? 100);

            $normalised = str_replace(',', '.', trim($text));
            if (!is_numeric($normalised) || (float)$normalised <= 0) {
                $this->sendMessage(Lang::get('topup_min', ['min' => $minTopup]));
                return;
            }
            $amount = (float)$normalised;

            if ($amount < $minTopup) {
                $this->sendMessage(Lang::get('topup_min', ['min' => $minTopup]));
                return;
            }

            Storage::setUserState($this->userId, null);
            $this->processTopupRequest($provider, $amount);
            return;
        }

        // Unknown state — clear it
        Storage::setUserState($this->userId, null);
        $this->sendMessage(Lang::get('unknown_command'), Keyboards::mainMenu($this->lang));
    }

    // ---- catalog --------------------------------------------------------

    private function showCatalog(): void
    {
        $categories = Storage::allCategories(true);
        if (empty($categories)) {
            $this->sendMessage(Lang::get('catalog_empty'), Keyboards::backToMain($this->lang));
            return;
        }
        $this->sendMessage(
            Lang::get('catalog_title'),
            Keyboards::categories($categories, $this->lang)
        );
    }

    private function showCategory(string $catId): void
    {
        $cat = Storage::getCategory($catId);
        if (!$cat || empty($cat['enabled'])) {
            $this->sendMessage(Lang::get('category_not_found'));
            return;
        }

        $products = Storage::productsByCategory($catId, true);
        if (empty($products)) {
            $this->editOrSend(
                Lang::get('category_empty', ['name' => $cat['name_' . $this->lang] ?? $cat['name_ru']]),
                ['inline_keyboard' => [[['text' => Lang::get('btn_back'), 'callback_data' => 'back:main']]]]
            );
            return;
        }

        $this->editOrSend(
            Lang::get('category_products', ['name' => $cat['name_' . $this->lang] ?? $cat['name_ru']]),
            Keyboards::products($products, $catId, $this->lang)
        );
    }

    private function showProduct(string $productId): void
    {
        $p = Storage::getProduct($productId);
        if (!$p || empty($p['enabled'])) {
            $this->sendMessage(Lang::get('product_not_found'));
            return;
        }

        $name = $p['name_' . $this->lang]        ?? $p['name_ru']        ?? '';
        $desc = $p['description_' . $this->lang] ?? $p['description_ru'] ?? '';
        $text = "<b>{$name}</b>\n\n{$desc}\n\n"
              . Lang::get('product_price', ['price' => $p['price'], 'currency' => CURRENCY_SIGN]);

        $this->editOrSend($text, Keyboards::productActions($productId, $this->lang), 'HTML');
    }

    private function showConfirmPurchase(string $productId): void
    {
        $p = Storage::getProduct($productId);
        if (!$p || empty($p['enabled'])) {
            $this->sendMessage(Lang::get('product_not_found'));
            return;
        }

        $name    = $p['name_' . $this->lang] ?? $p['name_ru'] ?? '';
        $balance = (float)($this->user['balance'] ?? 0);
        $price   = (float)$p['price'];

        if ($balance < $price) {
            $this->editMessageText(
                Lang::get('insufficient_balance', [
                    'balance' => $balance,
                    'price'   => $price,
                    'currency'=> CURRENCY_SIGN,
                ])
            );
            return;
        }

        $this->editMessageText(
            Lang::get('confirm_purchase', [
                'name'     => $name,
                'price'    => $price,
                'currency' => CURRENCY_SIGN,
                'balance'  => $balance,
            ]),
            Keyboards::confirmPurchase($productId, $this->lang)
        );
    }

    private function processPurchase(string $productId): void
    {
        $p = Storage::getProduct($productId);
        if (!$p || empty($p['enabled'])) {
            $this->editMessageText(Lang::get('product_not_found'));
            return;
        }

        $price = (float)$p['price'];
        if (!Storage::deductBalance($this->userId, $price)) {
            $this->editMessageText(Lang::get('insufficient_balance_simple'));
            return;
        }

        $orderId = Storage::nextOrderId();
        $order   = [
            'id'         => $orderId,
            'user_id'    => $this->userId,
            'product_id' => $productId,
            'amount'     => $price,
            'status'     => 'completed',
            'created_at' => time(),
        ];
        Storage::saveOrder($order);

        // Reload user to get updated balance
        $this->user = Storage::getUser($this->userId);
        $newBalance = (float)($this->user['balance'] ?? 0);

        $name = $p['name_' . $this->lang] ?? $p['name_ru'] ?? '';
        $successText = Lang::get('purchase_success', [
            'name'        => $name,
            'price'       => $price,
            'currency'    => CURRENCY_SIGN,
            'new_balance' => $newBalance,
        ]);

        $this->editMessageText($successText);

        // Deliver the file if product has one attached
        if (!empty($p['file_path'])) {
            $filePath = UPLOAD_DIR . '/' . $p['file_path'];
            if (file_exists($filePath)) {
                $this->sendDocument($filePath, Lang::get('your_file'));
            }
        } elseif (!empty($p['file_content'])) {
            // Text/key content delivery
            $this->sendMessage(
                Lang::get('your_content') . "\n\n<code>" . htmlspecialchars($p['file_content']) . "</code>",
                null,
                'HTML'
            );
        }
    }

    // ---- profile --------------------------------------------------------

    private function showProfile(): void
    {
        $this->user = Storage::getUser($this->userId) ?? $this->user;
        $orders  = Storage::ordersByUser($this->userId);
        $total   = array_sum(array_column($orders, 'amount'));
        $balance = (float)($this->user['balance'] ?? 0);

        $text = Lang::get('profile_text', [
            'name'         => $this->user['first_name'] ?? $this->userId,
            'id'           => $this->userId,
            'balance'      => $balance,
            'currency'     => CURRENCY_SIGN,
            'orders_count' => count($orders),
            'total_spent'  => round($total, 2),
        ]);

        // Build purchase history (last 5)
        if (!empty($orders)) {
            $recent = array_slice(array_reverse($orders), 0, 5);
            $text  .= "\n\n" . Lang::get('recent_orders') . "\n";
            foreach ($recent as $o) {
                $prod = Storage::getProduct($o['product_id']);
                $prodName = $prod ? ($prod['name_' . $this->lang] ?? $prod['name_ru'] ?? '#' . $o['product_id']) : '#' . $o['product_id'];
                $text .= '• ' . $prodName . ' — ' . $o['amount'] . CURRENCY_SIGN . ' (' . date('d.m.Y', $o['created_at']) . ")\n";
            }
        }

        $this->sendMessage($text, Keyboards::backToMain($this->lang), 'HTML');
    }

    // ---- top-up ---------------------------------------------------------

    private function showTopup(): void
    {
        $settings = Storage::getSettings();
        $minTopup = $settings['min_topup'] ?? 100;
        $this->sendMessage(
            Lang::get('topup_choose_provider', ['min' => $minTopup, 'currency' => CURRENCY_SIGN]),
            Keyboards::topupProviders($this->lang)
        );
    }

    private function handleTopupProvider(string $provider): void
    {
        $allowed = ['heleket', 'cryptobot'];
        if (!in_array($provider, $allowed)) return;

        $settings = Storage::getSettings();
        $minTopup = (float)($settings['min_topup'] ?? 100);

        $this->editMessageText(
            Lang::get('topup_enter_amount', ['min' => $minTopup, 'currency' => CURRENCY_SIGN])
        );
        Storage::setUserState($this->userId, 'topup_amount', ['provider' => $provider]);
    }

    private function processTopupRequest(string $provider, float $amount): void
    {
        if ($provider === 'heleket') {
            $result = Payments::createHeleket($this->userId, $amount);
        } else {
            $result = Payments::createCryptoBot($this->userId, $amount);
        }

        if (!$result['success']) {
            // Provider not configured or error — show manual top-up instruction
            $settings = Storage::getSettings();
            $support  = $settings['support_username'] ?? '';
            $this->sendMessage(
                Lang::get('topup_manual', [
                    'amount'   => $amount,
                    'currency' => CURRENCY_SIGN,
                    'support'  => $support ? '@' . ltrim($support, '@') : Lang::get('support_generic'),
                ])
            );

            // Save request for admin to process manually
            Storage::saveTopupRequest([
                'user_id'    => $this->userId,
                'amount'     => $amount,
                'provider'   => $provider,
                'status'     => 'pending',
                'created_at' => time(),
            ]);
            return;
        }

        $this->sendMessage(
            Lang::get('topup_link', ['amount' => $amount, 'currency' => CURRENCY_SIGN, 'url' => $result['url']]),
            ['inline_keyboard' => [[['text' => Lang::get('btn_pay'), 'url' => $result['url']]]]]
        );
    }

    // ---- Telegram API wrappers ------------------------------------------

    private function sendMessage(
        string  $text,
        ?array  $replyMarkup = null,
        string  $parseMode   = '',
        bool    $preview     = false
    ): ?array {
        $params = [
            'chat_id'                  => $this->chatId,
            'text'                     => $text,
            'disable_web_page_preview' => !$preview,
        ];
        if ($parseMode) $params['parse_mode'] = $parseMode;
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);

        return self::apiCall('sendMessage', $params);
    }

    private function editMessageText(
        string $text,
        ?array $replyMarkup = null,
        string $parseMode   = ''
    ): ?array {
        if (!$this->callbackQuery) return null;

        $params = [
            'chat_id'    => $this->chatId,
            'message_id' => $this->callbackQuery['message']['message_id'],
            'text'       => $text,
        ];
        if ($parseMode) $params['parse_mode'] = $parseMode;
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);

        return self::apiCall('editMessageText', $params);
    }

    private function editOrSend(string $text, ?array $replyMarkup = null, string $parseMode = ''): void
    {
        if ($this->callbackQuery) {
            $this->editMessageText($text, $replyMarkup, $parseMode);
        } else {
            $this->sendMessage($text, $replyMarkup, $parseMode);
        }
    }

    private function answerCallback(string $text = ''): void
    {
        if (!$this->callbackQuery) return;
        self::apiCall('answerCallbackQuery', [
            'callback_query_id' => $this->callbackQuery['id'],
            'text'              => $text,
        ]);
    }

    private function sendDocument(string $filePath, string $caption = ''): ?array
    {
        // Restrict path to UPLOAD_DIR to prevent traversal
        $realPath   = realpath($filePath);
        $realUpload = realpath(UPLOAD_DIR);
        if ($realPath === false || $realUpload === false || !str_starts_with($realPath, $realUpload . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $params = [
            'chat_id'  => $this->chatId,
            'document' => new CURLFile($realPath),
        ];
        if ($caption) $params['caption'] = $caption;
        return self::apiCall('sendDocument', $params, false);
    }

    // ---- static helpers -------------------------------------------------

    /**
     * Send a message to any user (used by Payments callback handlers).
     */
    public static function notifyUser(int $userId, string $text): void
    {
        self::apiCall('sendMessage', [
            'chat_id' => $userId,
            'text'    => $text,
        ]);
    }

    public static function apiCall(string $method, array $params, bool $jsonEncode = true): ?array
    {
        $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
        $ch  = curl_init($url);

        if ($jsonEncode) {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $params,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
        }

        $result = curl_exec($ch);
        curl_close($ch);
        return $result ? json_decode($result, true) : null;
    }

    /**
     * Register the webhook URL with Telegram.
     * Call once via CLI: php set_webhook.php
     */
    public static function setWebhook(string $url, string $secret = ''): ?array
    {
        $params = ['url' => $url];
        if ($secret) $params['secret_token'] = $secret;
        return self::apiCall('setWebhook', $params);
    }
}
