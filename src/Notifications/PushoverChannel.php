<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Config;
use Mk\Framework\Log;

/**
 * Pushover channel. Config: PUSHOVER_APP_TOKEN (an application/API token from
 * pushover.net) and PUSHOVER_USER_KEY (your user key).
 */
final class PushoverChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'pushover';
    }

    public function isConfigured(): bool
    {
        return Config::get('PUSHOVER_APP_TOKEN') !== null && Config::get('PUSHOVER_USER_KEY') !== null;
    }

    public function send(array $notification): bool
    {
        $fields = [
            'token' => (string) Config::get('PUSHOVER_APP_TOKEN'),
            'user' => (string) Config::get('PUSHOVER_USER_KEY'),
            'title' => trim((string) ($notification['title'] ?? 'Jellydash')),
            'message' => trim((string) ($notification['body'] ?? '')) ?: '...',
        ];

        $absolute = trim((string) ($notification['absolute_url'] ?? ''));
        if ($absolute !== '') {
            $fields['url'] = $absolute;
            $fields['url_title'] = 'Open Jellydash';
        }

        $result = HttpSender::postForm('https://api.pushover.net/1/messages.json', $fields);

        if ($result['status'] !== 200) {
            Log::logDebugMessage('Pushover notification failed (HTTP ' . $result['status'] . '): ' . mb_substr($result['body'], 0, 300), $this);

            return false;
        }

        return true;
    }
}
