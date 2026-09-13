<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

final class NtfyChannel extends JsonNotificationChannel
{
    public function name(): string
    {
        return 'ntfy';
    }

    protected function configurationKeys(): array
    {
        return ['NTFY_URL', 'NTFY_TOPIC', 'NTFY_TOKEN'];
    }

    public function isConfigured(): bool
    {
        $topic = NotificationEndpoint::setting('NTFY_TOPIC');

        return NotificationEndpoint::baseUrl(NotificationEndpoint::setting('NTFY_URL')) !== null
            && preg_match('/\A[-_A-Za-z0-9]{1,64}\z/', $topic) === 1
            && !in_array($topic, ['docs', 'static', 'file', 'app', 'metrics', 'account', 'settings', 'signup', 'login', 'v1'], true)
            && NotificationEndpoint::validToken(NotificationEndpoint::setting('NTFY_TOKEN'));
    }

    public function send(array $notification): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $payload = [
            'topic' => NotificationEndpoint::setting('NTFY_TOPIC'),
            'title' => NotificationEndpoint::text((string) ($notification['title'] ?? ''), 1024),
            'message' => NotificationEndpoint::text((string) ($notification['body'] ?? '') ?: 'Jellydash notification', 4096),
        ];
        $link = (string) ($notification['absolute_url'] ?? '');
        if (NotificationEndpoint::validUrl($link)) {
            $payload['click'] = $link;
        }
        $token = NotificationEndpoint::setting('NTFY_TOKEN');

        return $this->deliver(
            (string) NotificationEndpoint::baseUrl(NotificationEndpoint::setting('NTFY_URL')),
            $payload,
            $token === '' ? [] : ['Authorization' => 'Bearer ' . $token],
            static fn (array $data): bool => is_string($data['id'] ?? null) && $data['id'] !== '' && ($data['event'] ?? null) === 'message',
        );
    }
}
