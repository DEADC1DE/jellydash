<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Log;

/**
 * Discord webhook channel. Config: DISCORD_WEBHOOK_URL (Server Settings >
 * Integrations > Webhooks > Copy Webhook URL). No bot registration needed.
 */
final class DiscordChannel implements NotificationChannel
{
    /** @param (\Closure(string, array<string, mixed>): array{status: int, body: string})|null $sender */
    public function __construct(private ?\Closure $sender = null)
    {
    }

    public function name(): string
    {
        return 'discord';
    }

    public function isConfigured(): bool
    {
        return NotificationEndpoint::validUrl(NotificationEndpoint::setting('DISCORD_WEBHOOK_URL'));
    }

    public function send(array $notification): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $embed = [
            'title' => NotificationEndpoint::characters(trim((string) ($notification['title'] ?? 'Jellydash')), 256),
            'description' => NotificationEndpoint::characters(trim((string) ($notification['body'] ?? '')), 4096),
            'color' => 0x7C5CFF,
        ];

        $absolute = trim((string) ($notification['absolute_url'] ?? ''));
        if (NotificationEndpoint::validUrl($absolute)) {
            $embed['url'] = $absolute;
        }

        $result = ($this->sender ?? HttpSender::postJson(...))(NotificationEndpoint::setting('DISCORD_WEBHOOK_URL'), [
            'username' => 'Jellydash',
            'embeds' => [$embed],
        ]);

        // Discord answers 204 No Content on success.
        if ($result['status'] < 200 || $result['status'] >= 300) {
            Log::logErrorMessage('Discord notification failed (HTTP ' . $result['status'] . '). Check the channel settings.', $this);

            return false;
        }

        return true;
    }
}
