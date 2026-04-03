<?php
class Storage
{
    // -------------------------------------------------------
    // Core JSON helpers
    // -------------------------------------------------------
    private static function readJson(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $fh = fopen($file, 'r');
        if (!$fh) return [];
        flock($fh, LOCK_SH);
        $content = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private static function writeJson(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("[Storage] Failed to create directory: $dir");
                return;
            }
        }
        $fh = fopen($file, 'c');
        if (!$fh) {
            error_log("[Storage] Failed to open file for writing: $file");
            return;
        }
        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    // -------------------------------------------------------
    // Users
    // -------------------------------------------------------
    public static function getUsers(): array
    {
        return self::readJson(DATA_PATH . '/users.json');
    }

    public static function getUser(int $userId): ?array
    {
        $users = self::getUsers();
        return $users[$userId] ?? null;
    }

    public static function saveUser(int $userId, array $data): void
    {
        $users = self::getUsers();
        $users[$userId] = $data;
        self::writeJson(DATA_PATH . '/users.json', $users);
    }

    public static function getOrCreateUser(array $tgUser): array
    {
        $userId = (int)$tgUser['id'];
        $existing = self::getUser($userId);
        if ($existing) {
            // Update name/username if changed
            $existing['first_name'] = $tgUser['first_name'] ?? $existing['first_name'];
            $existing['last_name']  = $tgUser['last_name']  ?? $existing['last_name'] ?? '';
            $existing['username']   = $tgUser['username']   ?? $existing['username']  ?? '';
            self::saveUser($userId, $existing);
            return $existing;
        }
        $user = [
            'id'               => $userId,
            'first_name'       => $tgUser['first_name'] ?? '',
            'last_name'        => $tgUser['last_name']  ?? '',
            'username'         => $tgUser['username']   ?? '',
            'language'         => 'ru',
            'balance'          => 0.0,
            'total_spent'      => 0.0,
            'total_deposited'  => 0.0,
            'purchases_count'  => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'state'            => null,
            'state_data'       => [],
        ];
        self::saveUser($userId, $user);
        return $user;
    }

    public static function updateBalance(int $userId, float $delta): float
    {
        $user = self::getUser($userId);
        if (!$user) return 0.0;
        $user['balance'] = round(($user['balance'] ?? 0) + $delta, 2);
        if ($user['balance'] < 0) $user['balance'] = 0.0;
        self::saveUser($userId, $user);
        return $user['balance'];
    }

    public static function setUserState(int $userId, ?string $state, array $data = []): void
    {
        $user = self::getUser($userId);
        if (!$user) return;
        $user['state']      = $state;
        $user['state_data'] = $data;
        self::saveUser($userId, $user);
    }

    public static function setUserLanguage(int $userId, string $lang): void
    {
        $user = self::getUser($userId);
        if (!$user) return;
        $user['language'] = $lang;
        self::saveUser($userId, $user);
    }

    // -------------------------------------------------------
    // Categories
    // -------------------------------------------------------
    public static function getCategories(): array
    {
        return self::readJson(DATA_PATH . '/categories.json');
    }

    public static function getCategory(int $id): ?array
    {
        return self::getCategories()[$id] ?? null;
    }

    public static function saveCategory(int $id, array $data): void
    {
        $cats = self::getCategories();
        $cats[$id] = $data;
        self::writeJson(DATA_PATH . '/categories.json', $cats);
    }

    public static function deleteCategory(int $id): void
    {
        $cats = self::getCategories();
        unset($cats[$id]);
        self::writeJson(DATA_PATH . '/categories.json', $cats);
    }

    public static function nextCategoryId(): int
    {
        $cats = self::getCategories();
        if (empty($cats)) return 1;
        return max(array_map('intval', array_keys($cats))) + 1;
    }

    // -------------------------------------------------------
    // Products
    // -------------------------------------------------------
    public static function getProducts(): array
    {
        return self::readJson(DATA_PATH . '/products.json');
    }

    public static function getProduct(int $id): ?array
    {
        return self::getProducts()[$id] ?? null;
    }

    public static function saveProduct(int $id, array $data): void
    {
        $prods = self::getProducts();
        $prods[$id] = $data;
        self::writeJson(DATA_PATH . '/products.json', $prods);
    }

    public static function deleteProduct(int $id): void
    {
        $prods = self::getProducts();
        unset($prods[$id]);
        self::writeJson(DATA_PATH . '/products.json', $prods);
    }

    public static function nextProductId(): int
    {
        $prods = self::getProducts();
        if (empty($prods)) return 1;
        return max(array_map('intval', array_keys($prods))) + 1;
    }

    public static function getProductsByCategory(int $catId): array
    {
        return array_filter(self::getProducts(), fn($p) => (int)$p['category_id'] === $catId && ($p['active'] ?? false));
    }

    // -------------------------------------------------------
    // Orders
    // -------------------------------------------------------
    public static function getOrders(): array
    {
        return self::readJson(DATA_PATH . '/orders.json');
    }

    public static function addOrder(array $order): void
    {
        $orders = self::getOrders();
        $orders[] = $order;
        self::writeJson(DATA_PATH . '/orders.json', $orders);
    }

    public static function getUserOrders(int $userId): array
    {
        return array_values(array_filter(self::getOrders(), fn($o) => (int)$o['user_id'] === $userId));
    }

    public static function hasUserBoughtProduct(int $userId, int $productId): bool
    {
        foreach (self::getOrders() as $order) {
            if ((int)$order['user_id'] === $userId && (int)$order['product_id'] === $productId && $order['status'] === 'paid') {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------
    // Payments
    // -------------------------------------------------------
    public static function getPayments(): array
    {
        return self::readJson(DATA_PATH . '/payments.json');
    }

    public static function addPayment(array $payment): void
    {
        $payments = self::getPayments();
        $payments[] = $payment;
        self::writeJson(DATA_PATH . '/payments.json', $payments);
    }

    public static function updatePaymentByExternalId(string $provider, string $externalId, array $update): bool
    {
        $payments = self::getPayments();
        $found = false;
        foreach ($payments as &$p) {
            if ($p['provider'] === $provider && $p['external_id'] === $externalId) {
                foreach ($update as $k => $v) {
                    $p[$k] = $v;
                }
                $found = true;
                break;
            }
        }
        unset($p);
        if ($found) {
            self::writeJson(DATA_PATH . '/payments.json', $payments);
        }
        return $found;
    }

    public static function getPaymentByExternalId(string $provider, string $externalId): ?array
    {
        foreach (self::getPayments() as $p) {
            if ($p['provider'] === $provider && $p['external_id'] === $externalId) {
                return $p;
            }
        }
        return null;
    }

    // -------------------------------------------------------
    // Settings
    // -------------------------------------------------------
    public static function getSettings(): array
    {
        $defaults = [
            'bot_name'          => 'Digital Shop',
            'currency'          => 'RUB',
            'currency_symbol'   => '₽',
            'min_deposit'       => 50,
            'welcome_message_ru'=> 'Добро пожаловать в наш магазин цифровых товаров!',
            'welcome_message_en'=> 'Welcome to our digital goods shop!',
        ];
        $saved = self::readJson(DATA_PATH . '/settings.json');
        return array_merge($defaults, $saved);
    }

    public static function saveSettings(array $settings): void
    {
        self::writeJson(DATA_PATH . '/settings.json', $settings);
    }
}
