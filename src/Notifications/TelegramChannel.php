<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Config;
use Mk\Framework\Log;

/**
 * Telegram bot channel. Config: TELEGRAM_BOT_TOKEN (from @BotFather) and
 * TELEGRAM_CHAT_ID (the chat/group the bot posts to).
 */
final class TelegramChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'telegram';
    }

    public function isConfigured(): bool
    {
        return Config::get('TELEGRAM_BOT_TOKEN') !== null && Config::get('TELEGRAM_CHAT_ID') !== null;
    }

    public function send(array $notification): bool
    {
        $token = (string) Config::get('TELEGRAM_BOT_TOKEN');

        $text = trim((string) ($notification['title'] ?? ''));
        $body = trim((string) ($notification['body'] ?? ''));
        if ($body !== '') {
            $text .= "\n" . $body;
        }
        $absolute = trim((string) ($notification['absolute_url'] ?? ''));
        if ($absolute !== '') {
            $text .= "\n" . $absolute;
        }

        // Plain text on purpose: titles can contain characters that Markdown
        // parse modes would reject.
        $result = HttpSender::postForm('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'chat_id' => (string) Config::get('TELEGRAM_CHAT_ID'),
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ]);

        if ($result['status'] !== 200) {
            Log::logDebugMessage('Telegram notification failed (HTTP ' . $result['status'] . '): ' . mb_substr($result['body'], 0, 300), $this);

            return false;
        }

        return true;
    }
}
