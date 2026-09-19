<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Log;

/** Bounded JSON delivery with sanitized failures for configurable servers. */
abstract class JsonNotificationChannel implements NotificationChannel
{
    /** @param \Closure(string, array<string, mixed>, array<string, string>): array{status: int, body: string}|null $sender */
    public function __construct(private ?\Closure $sender = null)
    {
    }

    /** @return list<string> */
    abstract protected function configurationKeys(): array;

    /** True even for partial configuration, so health can report it. */
    public function hasConfiguration(): bool
    {
        foreach ($this->configurationKeys() as $key) {
            if (NotificationEndpoint::setting($key) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @param \Closure(array<string, mixed>): bool $accepted
     */
    protected function deliver(string $url, array $payload, array $headers, \Closure $accepted): bool
    {
        try {
            $result = ($this->sender ?? HttpSender::postJson(...))($url, $payload, $headers);
            $data = json_decode($result['body'], true);
            if ($result['status'] === 200 && is_array($data) && $accepted($data)) {
                return true;
            }
            Log::logErrorMessage($this->name() . ' notification failed (HTTP ' . $result['status'] . '). Check the service and channel settings.', $this);
        } catch (\Throwable) {
            Log::logErrorMessage($this->name() . ' notification failed. Check the service and channel settings.', $this);
        }

        return false;
    }
}
