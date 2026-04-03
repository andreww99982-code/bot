<?php
class Payments
{
    // -------------------------------------------------------
    // Heleket.com Integration
    // -------------------------------------------------------
    public static function createHeleketInvoice(int $userId, float $amount, string $currency): ?array
    {
        if (!HELEKET_API_KEY || !HELEKET_SHOP_ID) {
            // Placeholder: return a demo invoice for testing
            $fakeId = 'heleket_' . uniqid();
            $payment = [
                'payment_id'  => 'PAY' . strtoupper(uniqid()),
                'user_id'     => $userId,
                'amount'      => $amount,
                'currency'    => $currency,
                'provider'    => 'heleket',
                'external_id' => $fakeId,
                'status'      => 'pending',
                'pay_url'     => 'https://heleket.com/pay/' . $fakeId,
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            Storage::addPayment($payment);
            return $payment;
        }

        $callbackUrl = APP_URL . '/webhook.php?route=heleket';
        $data = [
            'shop_id'      => HELEKET_SHOP_ID,
            'amount'       => number_format($amount, 2, '.', ''),
            'currency'     => $currency,
            'order_id'     => 'PAY' . strtoupper(uniqid()),
            'callback_url' => $callbackUrl,
            'success_url'  => APP_URL,
            'description'  => 'Balance top-up for user #' . $userId,
        ];

        $ch = curl_init('https://heleket.com/api/v1/invoice/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . HELEKET_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            TgBot::log('Heleket cURL error: ' . $error);
            return null;
        }

        $result = json_decode($response, true);
        if (!isset($result['data']['url'])) {
            TgBot::log('Heleket API error: ' . $response);
            return null;
        }

        $payment = [
            'payment_id'  => $data['order_id'],
            'user_id'     => $userId,
            'amount'      => $amount,
            'currency'    => $currency,
            'provider'    => 'heleket',
            'external_id' => $result['data']['invoice_id'] ?? $data['order_id'],
            'status'      => 'pending',
            'pay_url'     => $result['data']['url'],
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        Storage::addPayment($payment);
        return $payment;
    }

    public static function processHeleketWebhook(string $raw): void
    {
        $data = json_decode($raw, true);
        if (!$data) return;

        $externalId = $data['invoice_id'] ?? $data['order_id'] ?? '';
        $status     = $data['status'] ?? '';

        if ($status !== 'paid' && $status !== 'completed') return;

        $payment = Storage::getPaymentByExternalId('heleket', $externalId);
        if (!$payment || $payment['status'] === 'paid') return;

        // Mark as paid
        Storage::updatePaymentByExternalId('heleket', $externalId, ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);

        // Credit user
        self::creditUser($payment);
    }

    // -------------------------------------------------------
    // Crypto Bot Integration (Telegram @CryptoBot / @CryptoBotTest)
    // -------------------------------------------------------
    public static function createCryptoBotInvoice(int $userId, float $amount, string $currency): ?array
    {
        if (!CRYPTOBOT_TOKEN) {
            // Placeholder demo
            $fakeId = 'cb_' . uniqid();
            $payment = [
                'payment_id'  => 'PAY' . strtoupper(uniqid()),
                'user_id'     => $userId,
                'amount'      => $amount,
                'currency'    => $currency,
                'provider'    => 'cryptobot',
                'external_id' => $fakeId,
                'status'      => 'pending',
                'pay_url'     => 'https://t.me/CryptoBot?start=' . $fakeId,
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            Storage::addPayment($payment);
            return $payment;
        }

        $baseUrl  = CRYPTOBOT_TESTNET ? 'https://testnet-pay.crypt.bot/api' : 'https://pay.crypt.bot/api';
        $endpoint = $baseUrl . '/createInvoice';

        // TODO: Before going to production you MUST implement a proper fiat→crypto
        // exchange rate conversion. The current code passes the fiat amount directly
        // as a USDT amount, which will result in incorrect invoice totals.
        $cryptoCurrency = 'USDT';
        $cryptoAmount   = $amount; // FIXME: replace with real exchange-rate conversion

        $params = [
            'asset'           => $cryptoCurrency,
            'amount'          => number_format($cryptoAmount, 2, '.', ''),
            'description'     => 'Balance top-up #' . $userId,
            'payload'         => json_encode(['user_id' => $userId]),
            'paid_btn_name'   => 'callback',
            'paid_btn_url'    => APP_URL . '/webhook.php?route=cryptobot_check',
            'allow_comments'  => false,
            'allow_anonymous' => false,
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Crypto-Pay-API-Token: ' . CRYPTOBOT_TOKEN,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            TgBot::log('CryptoBot cURL error: ' . $error);
            return null;
        }

        $result = json_decode($response, true);
        if (!($result['ok'] ?? false) || !isset($result['result']['pay_url'])) {
            TgBot::log('CryptoBot API error: ' . $response);
            return null;
        }

        $invoice = $result['result'];
        $payment = [
            'payment_id'  => 'PAY' . strtoupper(uniqid()),
            'user_id'     => $userId,
            'amount'      => $amount,
            'currency'    => $currency,
            'provider'    => 'cryptobot',
            'external_id' => (string)$invoice['invoice_id'],
            'status'      => 'pending',
            'pay_url'     => $invoice['pay_url'],
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        Storage::addPayment($payment);
        return $payment;
    }

    public static function processCryptoBotWebhook(string $raw): void
    {
        $data = json_decode($raw, true);
        if (!$data) return;

        // CryptoBot sends update_type = 'invoice_paid'
        if (($data['update_type'] ?? '') !== 'invoice_paid') return;

        $invoice    = $data['payload'] ?? [];
        $externalId = (string)($invoice['invoice_id'] ?? '');
        $status     = $invoice['status'] ?? '';

        if ($status !== 'paid') return;

        $payment = Storage::getPaymentByExternalId('cryptobot', $externalId);
        if (!$payment || $payment['status'] === 'paid') return;

        Storage::updatePaymentByExternalId('cryptobot', $externalId, ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
        self::creditUser($payment);
    }

    // -------------------------------------------------------
    // Common: credit user after successful payment
    // -------------------------------------------------------
    public static function creditUser(array $payment): void
    {
        $userId = (int)$payment['user_id'];
        $amount = (float)$payment['amount'];

        $user = Storage::getUser($userId);
        if (!$user) return;

        $newBalance = Storage::updateBalance($userId, $amount);

        // Update stats
        $user = Storage::getUser($userId);
        $user['total_deposited'] = round(($user['total_deposited'] ?? 0) + $amount, 2);
        Storage::saveUser($userId, $user);

        $settings = Storage::getSettings();
        $currency = $settings['currency_symbol'] ?? '₽';
        $lang     = $user['language'] ?? 'ru';

        TgBot::sendMessage($userId, Lang::get('payment_success', $lang,
            number_format($amount, 2, '.', ' '),
            $currency,
            number_format($newBalance, 2, '.', ' '),
            $currency
        ));
    }
}
