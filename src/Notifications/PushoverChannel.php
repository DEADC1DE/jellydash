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
    /** @param (\Closure(string, array<string, string>): array{status: int, body: string})|null $sender */
    public function __construct(private ?\Closure $sender = null)
    {
    }

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
            'title' => NotificationEndpoint::characters(trim((string) ($notification['title'] ?? 'Jellydash')), 250),
            'message' => NotificationEndpoint::characters(trim((string) ($notification['body'] ?? '')) ?: '...', 1024),
        ];

        $absolute = trim((string) ($notification['absolute_url'] ?? ''));
        if (NotificationEndpoint::validUrl($absolute) && mb_strlen($absolute) <= 512) {
            $fields['url'] = $absolute;
            $fields['url_title'] = 'Open Jellydash';
        }

        $result = ($this->sender ?? HttpSender::postForm(...))('https://api.pushover.net/1/messages.json', $fields);

        if ($result['status'] !== 200) {
            Log::logErrorMessage('Pushover notification failed (HTTP ' . $result['status'] . '). Check the channel settings.', $this);

            return false;
        }

        return true;
    }
}
