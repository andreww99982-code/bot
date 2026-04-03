<?php
/**
 * Storage.php — JSON-file-based data layer.
 */

class Storage
{
    // ---- generic helpers ------------------------------------------------

    public static function load(string $file): array
    {
        $path = DATA_DIR . '/' . $file;
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public static function save(string $file, array $data): void
    {
        $path = DATA_DIR . '/' . $file;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ---- users ----------------------------------------------------------

    public static function getUser(int $id): ?array
    {
        $users = self::load('users.json');
        return $users[$id] ?? null;
    }

    public static function saveUser(array $user): void
    {
        $users = self::load('users.json');
        $users[$user['id']] = $user;
        self::save('users.json', $users);
    }

    public static function allUsers(): array
    {
        return self::load('users.json');
    }

    public static function getOrCreateUser(int $id, array $defaults = []): array
    {
        $user = self::getUser($id);
        if ($user === null) {
            $user = array_merge([
                'id'         => $id,
                'username'   => '',
                'first_name' => '',
                'lang'       => DEFAULT_LANG,
                'balance'    => 0.0,
                'created_at' => time(),
                'state'      => null,
                'state_data' => [],
            ], $defaults);
            self::saveUser($user);
        }
        return $user;
    }

    public static function setUserState(int $id, ?string $state, array $stateData = []): void
    {
        $user = self::getOrCreateUser($id);
        $user['state']      = $state;
        $user['state_data'] = $stateData;
        self::saveUser($user);
    }

    public static function setUserLang(int $id, string $lang): void
    {
        $user = self::getOrCreateUser($id);
        $user['lang'] = $lang;
        self::saveUser($user);
    }

    public static function addBalance(int $id, float $amount): float
    {
        $user = self::getOrCreateUser($id);
        $user['balance'] = round(($user['balance'] ?? 0) + $amount, 2);
        self::saveUser($user);
        return $user['balance'];
    }

    public static function deductBalance(int $id, float $amount): bool
    {
        $user = self::getOrCreateUser($id);
        if (($user['balance'] ?? 0) < $amount) {
            return false;
        }
        $user['balance'] = round($user['balance'] - $amount, 2);
        self::saveUser($user);
        return true;
    }

    // ---- categories -----------------------------------------------------

    public static function allCategories(bool $enabledOnly = false): array
    {
        $cats = self::load('categories.json');
        if ($enabledOnly) {
            $cats = array_filter($cats, fn($c) => !empty($c['enabled']));
        }
        usort($cats, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        return array_values($cats);
    }

    public static function getCategory(string $id): ?array
    {
        foreach (self::load('categories.json') as $cat) {
            if ((string)$cat['id'] === (string)$id) return $cat;
        }
        return null;
    }

    public static function saveCategory(array $cat): void
    {
        $cats = self::load('categories.json');
        foreach ($cats as &$c) {
            if ((string)$c['id'] === (string)$cat['id']) {
                $c = $cat;
                self::save('categories.json', $cats);
                return;
            }
        }
        $cats[] = $cat;
        self::save('categories.json', $cats);
    }

    public static function nextCategoryId(): int
    {
        $cats = self::load('categories.json');
        return empty($cats) ? 1 : max(array_column($cats, 'id')) + 1;
    }

    // ---- products -------------------------------------------------------

    public static function allProducts(bool $enabledOnly = false): array
    {
        $prods = self::load('products.json');
        if ($enabledOnly) {
            $prods = array_filter($prods, fn($p) => !empty($p['enabled']));
        }
        return array_values($prods);
    }

    public static function productsByCategory(string $catId, bool $enabledOnly = true): array
    {
        return array_values(array_filter(
            self::allProducts($enabledOnly),
            fn($p) => (string)$p['category_id'] === (string)$catId
        ));
    }

    public static function getProduct(string $id): ?array
    {
        foreach (self::load('products.json') as $p) {
            if ((string)$p['id'] === (string)$id) return $p;
        }
        return null;
    }

    public static function saveProduct(array $product): void
    {
        $prods = self::load('products.json');
        foreach ($prods as &$p) {
            if ((string)$p['id'] === (string)$product['id']) {
                $p = $product;
                self::save('products.json', $prods);
                return;
            }
        }
        $prods[] = $product;
        self::save('products.json', $prods);
    }

    public static function nextProductId(): int
    {
        $prods = self::load('products.json');
        return empty($prods) ? 1 : max(array_column($prods, 'id')) + 1;
    }

    // ---- orders ---------------------------------------------------------

    public static function allOrders(): array
    {
        return self::load('orders.json');
    }

    public static function ordersByUser(int $userId): array
    {
        return array_values(array_filter(
            self::allOrders(),
            fn($o) => (int)$o['user_id'] === $userId
        ));
    }

    public static function saveOrder(array $order): void
    {
        $orders = self::load('orders.json');
        foreach ($orders as &$o) {
            if ((string)$o['id'] === (string)$order['id']) {
                $o = $order;
                self::save('orders.json', $orders);
                return;
            }
        }
        $orders[] = $order;
        self::save('orders.json', $orders);
    }

    public static function nextOrderId(): int
    {
        $orders = self::load('orders.json');
        return empty($orders) ? 1 : max(array_column($orders, 'id')) + 1;
    }

    // ---- settings -------------------------------------------------------

    public static function getSettings(): array
    {
        $defaults = [
            'shop_name'        => 'Digital Shop',
            'support_username' => '',
            'min_topup'        => 100,
            'welcome_text_ru'  => 'Добро пожаловать в магазин! Используйте меню ниже.',
            'welcome_text_en'  => 'Welcome to the shop! Use the menu below.',
        ];
        return array_merge($defaults, self::load('settings.json'));
    }

    public static function saveSettings(array $settings): void
    {
        self::save('settings.json', $settings);
    }

    // ---- top-up requests ------------------------------------------------

    public static function saveTopupRequest(array $req): void
    {
        $reqs = self::load('topup_requests.json');
        $reqs[] = $req;
        self::save('topup_requests.json', $reqs);
    }

    public static function allTopupRequests(): array
    {
        return self::load('topup_requests.json');
    }
}
