<?php
/**
 * helpers.php — Shared utility functions for the Telegram bot.
 */

/**
 * Send a message to a Telegram chat.
 *
 * @param int|string $chatId  Target chat ID.
 * @param string     $text    Message text. Callers are responsible for sanitizing
 *                            user-supplied content before passing it here (e.g. htmlspecialchars).
 * @param array      $extra   Additional Telegram API parameters (parse_mode, reply_markup, etc.).
 * @return array|null         Decoded Telegram API response, or null on failure.
 */
function sendMessage($chatId, string $text, array $extra = []): ?array
{
    $params = array_merge([
        'chat_id' => $chatId,
        'text'    => $text,
    ], $extra);

    return apiRequest('sendMessage', $params);
}

/**
 * Make a Telegram Bot API request.
 *
 * @param string $method  Telegram API method name (e.g. "sendMessage").
 * @param array  $params  Parameters to send as JSON body.
 * @return array|null     Decoded response, or null on failure.
 */
function apiRequest(string $method, array $params = []): ?array
{
    $url = TELEGRAM_API . '/' . $method;

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

    if ($error !== '') {
        logError('cURL error calling ' . $method . ': ' . $error);
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        logError('Invalid JSON response from ' . $method . ': ' . $response);
        return null;
    }

    if (empty($decoded['ok'])) {
        logError('Telegram API error in ' . $method . ': ' . ($decoded['description'] ?? 'unknown'));
    }

    return $decoded;
}

/**
 * Append a timestamped line to a log file.
 *
 * @param string $message  Message to log.
 * @param string $file     Log file name (relative to LOG_DIR). Defaults to 'bot.log'.
 */
function logEvent(string $message, string $file = 'bot.log'): void
{
    $path = LOG_DIR . '/' . $file;
    $line = date('Y-m-d H:i:s') . ' ' . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Log an error message to error.log.
 */
function logError(string $message): void
{
    logEvent('[ERROR] ' . $message, 'error.log');
}

/**
 * Return whether a Telegram user ID is an admin.
 */
function isAdmin(int $userId): bool
{
    if (ADMIN_IDS === '') {
        return false;
    }
    $ids = array_map('intval', explode(',', ADMIN_IDS));
    return in_array($userId, $ids, true);
}

/**
 * Safely extract a nested value from an array using dot-notation.
 *
 * @param array  $array    Source array.
 * @param string $key      Dot-separated key path, e.g. "message.from.id".
 * @param mixed  $default  Returned when the key path does not exist.
 * @return mixed
 */
function arrayGet(array $array, string $key, $default = null)
{
    foreach (explode('.', $key) as $segment) {
        if (!is_array($array) || !array_key_exists($segment, $array)) {
            return $default;
        }
        $array = $array[$segment];
    }
    return $array;
}
