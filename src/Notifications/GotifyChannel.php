<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

final class GotifyChannel extends JsonNotificationChannel
{
    public function name(): string
    {
        return 'gotify';
    }

    protected function configurationKeys(): array
    {
        return ['GOTIFY_URL', 'GOTIFY_APP_TOKEN'];
    }

    public function isConfigured(): bool
    {
        $token = NotificationEndpoint::setting('GOTIFY_APP_TOKEN');

        return NotificationEndpoint::baseUrl(NotificationEndpoint::setting('GOTIFY_URL')) !== null
            && $token !== '' && NotificationEndpoint::validToken($token);
    }

    public function send(array $notification): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $payload = [
            'title' => NotificationEndpoint::text((string) ($notification['title'] ?? ''), 1024),
            'message' => NotificationEndpoint::text((string) ($notification['body'] ?? '') ?: 'Jellydash notification', 4096),
            'priority' => 5,
            'extras' => ['client::display' => ['contentType' => 'text/plain']],
        ];
        $link = (string) ($notification['absolute_url'] ?? '');
        if (NotificationEndpoint::validUrl($link)) {
            $payload['extras']['client::notification'] = ['click' => ['url' => $link]];
        }

        return $this->deliver(
            NotificationEndpoint::baseUrl(NotificationEndpoint::setting('GOTIFY_URL')) . 'message',
            $payload,
            ['X-Gotify-Key' => NotificationEndpoint::setting('GOTIFY_APP_TOKEN')],
            static fn (array $data): bool => is_int($data['id'] ?? null) && $data['id'] > 0,
        );
    }
}
