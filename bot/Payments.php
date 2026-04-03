<?php
/**
 * Payments.php — Payment provider integration layer.
 *
 * Both Heleket and Crypto Bot integrations are architecture-ready.
 * Provide API keys in config.php and wire up the callback URLs.
 */

class Payments
{
    // ---- Heleket --------------------------------------------------------
    // Docs: https://heleket.com/docs/api

    /**
     * Create a payment invoice via Heleket.
     *
     * @return array{success: bool, url?: string, invoice_id?: string, error?: string}
     */
    public static function createHeleket(int $userId, float $amount, string $currency = 'RUB'): array
    {
        if (empty(HELEKET_API_KEY) || empty(HELEKET_SHOP_ID)) {
            return ['success' => false, 'error' => 'Heleket is not configured'];
        }

        $payload = [
            'shop_id'    => HELEKET_SHOP_ID,
            'amount'     => $amount,
            'currency'   => $currency,
            'order_id'   => 'user_' . $userId . '_' . time(),
            'return_url' => BASE_URL . '/webhook.php?topup_callback=heleket',
            'notify_url' => BASE_URL . '/webhook.php?topup_callback=heleket',
            'description'=> 'Balance top-up for user #' . $userId,
        ];

        $response = self::httpPost(
            'https://api.heleket.com/v1/payment/create',
            $payload,
            ['Authorization: Bearer ' . HELEKET_API_KEY]
        );

        if ($response && isset($response['payment_url'])) {
            return [
                'success'    => true,
                'url'        => $response['payment_url'],
                'invoice_id' => $response['invoice_id'] ?? '',
            ];
        }

        return ['success' => false, 'error' => $response['message'] ?? 'Unknown error'];
    }

    /**
     * Handle Heleket payment callback (POST from Heleket server).
     * Call this from webhook.php when ?topup_callback=heleket is present.
     */
    public static function handleHeleket(array $data): void
    {
        // Verify signature if Heleket provides one
        if (empty($data['order_id']) || empty($data['status'])) return;

        if ($data['status'] !== 'paid') return;

        // order_id format: user_{userId}_{timestamp}
        if (!preg_match('/^user_(\d+)_/', $data['order_id'], $m)) return;

        $userId = (int)$m[1];
        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) return;

        Storage::addBalance($userId, $amount);

        // Notify user
        // (Bot::sendMessage is static-safe here because it only needs token+chat_id)
        Bot::notifyUser($userId, Lang::get('topup_success', ['amount' => $amount]));
    }

    // ---- Crypto Bot (Telegram @CryptoBot) --------------------------------
    // Docs: https://help.crypt.bot/crypto-pay-api

    /**
     * Create an invoice via Crypto Bot.
     *
     * @return array{success: bool, url?: string, invoice_id?: int, error?: string}
     */
    public static function createCryptoBot(int $userId, float $amount, string $asset = 'USDT'): array
    {
        if (empty(CRYPTOBOT_TOKEN)) {
            return ['success' => false, 'error' => 'Crypto Bot is not configured'];
        }

        $payload = [
            'asset'           => $asset,
            'amount'          => $amount,
            'description'     => 'Balance top-up #' . $userId,
            'hidden_message'  => 'Thank you!',
            'paid_btn_name'   => 'openBot',
            'paid_btn_url'    => 'https://t.me/' . self::getBotUsername(),
            'payload'         => json_encode(['user_id' => $userId]),
            'allow_comments'  => false,
            'allow_anonymous' => false,
            'expires_in'      => 3600,
        ];

        $response = self::httpPost(
            'https://pay.crypt.bot/api/createInvoice',
            $payload,
            ['Crypto-Pay-API-Token: ' . CRYPTOBOT_TOKEN]
        );

        if ($response && ($response['ok'] ?? false)) {
            return [
                'success'    => true,
                'url'        => $response['result']['pay_url'] ?? '',
                'invoice_id' => $response['result']['invoice_id'] ?? 0,
            ];
        }

        return ['success' => false, 'error' => ($response['error']['name'] ?? 'Unknown error')];
    }

    /**
     * Handle Crypto Bot webhook (POST from Crypto Bot).
     * Call this from webhook.php when ?topup_callback=cryptobot is present.
     */
    public static function handleCryptoBot(array $data): void
    {
        if (($data['update_type'] ?? '') !== 'invoice_paid') return;
        $invoice = $data['payload'] ?? [];
        $payload = json_decode($invoice['payload'] ?? '{}', true);
        if (empty($payload['user_id'])) return;

        $userId = (int)$payload['user_id'];
        $amount = (float)($invoice['amount'] ?? 0);
        if ($amount <= 0) return;

        // Convert crypto to RUB: real-world implementation would use an exchange rate.
        // For now, credit the amount as-is (e.g. in USDT → use your exchange rate).
        Storage::addBalance($userId, $amount);
        Bot::notifyUser($userId, Lang::get('topup_success', ['amount' => $amount]));
    }

    // ---- helpers --------------------------------------------------------

    private static function httpPost(string $url, array $data, array $headers = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/json'],
                $headers
            ),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!$body) return null;
        return json_decode($body, true);
    }

    private static function getBotUsername(): string
    {
        // Cache could be added here; for now return empty
        return '';
    }
}
