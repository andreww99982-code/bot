<?php
/**
 * webhook.php — Telegram webhook entry point.
 *
 * Point your bot webhook here:
 *   https://your-domain.com/webhook.php
 *
 * Telegram will POST JSON updates to this URL.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// ---- 1. Validate webhook secret (when configured) ----------------------

$incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (WEBHOOK_SECRET !== '' && $incomingSecret !== WEBHOOK_SECRET) {
    http_response_code(403);
    exit('Forbidden');
}

// ---- 2. Read and parse the incoming update ------------------------------

$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(200);
    exit;
}

$update = json_decode($rawBody, true);
if (!is_array($update)) {
    http_response_code(200);
    exit;
}

// ---- 3. Log the raw update (capped at 5 MB) ----------------------------

$logFile = LOG_DIR . '/webhook.log';
if (!is_file($logFile) || filesize($logFile) < 5 * 1024 * 1024) {
    logEvent($rawBody, 'webhook.log');
}

// ---- 4. Respond 200 immediately so Telegram does not retry -------------

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);

// Flush output before processing (some servers support this)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ---- 5. Process the update ---------------------------------------------

try {
    handleUpdate($update);
} catch (Throwable $e) {
    logError('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

// ---- 6. Update handler --------------------------------------------------

function handleUpdate(array $update): void
{
    // Support message and edited_message updates
    $message = $update['message'] ?? $update['edited_message'] ?? null;

    if ($message === null) {
        // Unsupported update type — ignore gracefully
        return;
    }

    $chatId = (int) arrayGet($message, 'chat.id', 0);
    $userId = (int) arrayGet($message, 'from.id', 0);
    $text   = trim(arrayGet($message, 'text', ''));

    if ($chatId === 0) {
        return;
    }

    logEvent('Update from user ' . $userId . ' in chat ' . $chatId . ': ' . mb_substr($text, 0, 200));

    // Route commands
    if (str_starts_with($text, '/start')) {
        handleStart($chatId, $userId, $message);
        return;
    }

    if (str_starts_with($text, '/help')) {
        handleHelp($chatId);
        return;
    }

    if (str_starts_with($text, '/')) {
        // Unknown command
        handleUnknownCommand($chatId, $text);
        return;
    }

    // Plain text message
    handleTextMessage($chatId, $userId, $text, $message);
}

// ---- 7. Command and message handlers ------------------------------------

function handleStart(int $chatId, int $userId, array $message): void
{
    $firstName = htmlspecialchars(
        arrayGet($message, 'from.first_name', 'there'),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $text  = "👋 Hello, {$firstName}!\n\n";
    $text .= "I'm your Telegram bot. Send me a message and I'll echo it back.\n\n";
    $text .= "Available commands:\n";
    $text .= "/start — Show this message\n";
    $text .= "/help  — Get help\n";

    sendMessage($chatId, $text);
    logEvent('Handled /start for user ' . $userId);
}

function handleHelp(int $chatId): void
{
    $text  = "ℹ️ Help\n\n";
    $text .= "Available commands:\n";
    $text .= "/start — Show the welcome message\n";
    $text .= "/help  — Show this help text\n\n";
    $text .= "Send any text message and I'll echo it back.";

    sendMessage($chatId, $text);
}

function handleUnknownCommand(int $chatId, string $command): void
{
    // Only pass the command name, not any arguments, to the reply
    $cmd = strtok($command, ' ');
    $safe = htmlspecialchars($cmd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    sendMessage($chatId, "❓ Unknown command: {$safe}\n\nTry /start or /help.");
}

function handleTextMessage(int $chatId, int $userId, string $text, array $message): void
{
    if ($text === '') {
        return;
    }

    // Simple echo with a prefix
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    sendMessage($chatId, "You said: {$safe}");
}

