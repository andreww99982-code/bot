<?php
class TgBot
{
    private static function request(string $method, array $params = []): ?array
    {
        $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            self::log("cURL error in $method: $error");
            return null;
        }
        $data = json_decode($response, true);
        if (!($data['ok'] ?? false)) {
            self::log("Telegram API error in $method: " . ($data['description'] ?? 'unknown') . ' | params: ' . json_encode($params));
        }
        return $data;
    }

    public static function sendMessage(int $chatId, string $text, array $extra = []): ?array
    {
        return self::request('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public static function editMessage(int $chatId, int $messageId, string $text, array $extra = []): ?array
    {
        return self::request('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public static function answerCallback(string $callbackId, string $text = '', bool $alert = false): void
    {
        self::request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $alert,
        ]);
    }

    public static function sendDocument(int $chatId, string $filePath, string $caption = ''): ?array
    {
        $url  = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendDocument';
        $ch   = curl_init($url);
        $post = ['chat_id' => $chatId];
        if ($caption) {
            $post['caption'] = $caption;
        }

        if (file_exists($filePath)) {
            $post['document'] = new CURLFile($filePath);
        } else {
            // filePath is a Telegram file_id
            $post['document'] = $filePath;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) {
            self::log("cURL error in sendDocument: $error");
            return null;
        }
        return json_decode($response, true);
    }

    public static function deleteMessage(int $chatId, int $messageId): void
    {
        self::request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public static function setWebhook(string $url): ?array
    {
        $params = ['url' => $url];
        if (WEBHOOK_SECRET) {
            $params['secret_token'] = WEBHOOK_SECRET;
        }
        return self::request('setWebhook', $params);
    }

    public static function log(string $message): void
    {
        $logFile = LOGS_PATH . '/bot.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("[TgBot] Failed to create log directory: $dir");
                return;
            }
        }
        // Cap log at 5 MB
        if (file_exists($logFile) && filesize($logFile) >= 5 * 1024 * 1024) {
            return;
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Build an inline keyboard from rows of buttons.
     * Each button: ['text' => '...', 'callback_data' => '...'] or ['text'=>'...','url'=>'...']
     */
    public static function inlineKeyboard(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * Build a reply keyboard from rows of button text strings.
     */
    public static function replyKeyboard(array $rows, bool $resize = true): array
    {
        $keyboard = [];
        foreach ($rows as $row) {
            $keyboard[] = array_map(fn($t) => ['text' => $t], $row);
        }
        return ['keyboard' => $keyboard, 'resize_keyboard' => $resize];
    }

    public static function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }
}
