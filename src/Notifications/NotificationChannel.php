<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

/**
 * One way of delivering a notification (Telegram, Pushover, Discord, ...).
 * A channel is "on" when its environment config is present; there is no UI.
 */
interface NotificationChannel
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Deliver one notification. Keys: title, body, url (relative),
     * absolute_url (present only when APP_URL is set). Returns true when the
     * service accepted the message; failures are logged, never thrown.
     *
     * @param array<string, mixed> $notification
     */
    public function send(array $notification): bool;
}
