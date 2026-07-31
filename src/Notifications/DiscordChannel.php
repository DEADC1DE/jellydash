<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Config;
use Mk\Framework\Log;

/**
 * Discord webhook channel. Config: DISCORD_WEBHOOK_URL (Server Settings >
 * Integrations > Webhooks > Copy Webhook URL). No bot registration needed.
 */
final class DiscordChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'discord';
    }

    public function isConfigured(): bool
    {
        return Config::get('DISCORD_WEBHOOK_URL') !== null;
    }

    public function send(array $notification): bool
    {
        $embed = [
            'title' => trim((string) ($notification['title'] ?? 'Jellydash')),
            'description' => trim((string) ($notification['body'] ?? '')),
            'color' => 0x7C5CFF,
        ];

        $absolute = trim((string) ($notification['absolute_url'] ?? ''));
        if ($absolute !== '') {
            $embed['url'] = $absolute;
        }

        $result = HttpSender::postJson((string) Config::get('DISCORD_WEBHOOK_URL'), [
            'username' => 'Jellydash',
            'embeds' => [$embed],
        ]);

        // Discord answers 204 No Content on success.
        if ($result['status'] < 200 || $result['status'] >= 300) {
            Log::logDebugMessage('Discord notification failed (HTTP ' . $result['status'] . '): ' . mb_substr($result['body'], 0, 300), $this);

            return false;
        }

        return true;
    }
}
