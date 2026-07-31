<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mk\Framework\Config;
use Mk\Framework\Log;

/**
 * Thin wrapper over minishlink/web-push: signs each message with our VAPID
 * keypair, encrypts the payload for each subscription, and reports which
 * endpoints are dead so the caller can prune them.
 */
final class WebPushSender
{
    private ?string $publicKey;
    private ?string $privateKey;
    private string $subject;

    public function __construct()
    {
        $this->publicKey = Config::get('VAPID_PUBLIC_KEY');
        $this->privateKey = Config::get('VAPID_PRIVATE_KEY');
        $this->subject = Config::get('VAPID_SUBJECT', 'mailto:admin@example.com') ?? 'mailto:admin@example.com';
    }

    /**
     * True once a VAPID keypair is configured; otherwise sending is impossible
     * and callers should no-op quietly.
     */
    public function isConfigured(): bool
    {
        return $this->publicKey !== null && $this->privateKey !== null;
    }

    /**
     * Send one payload to every given subscription.
     *
     * @param array<int, array{endpoint: string, p256dh: string, auth: string}> $subscriptions
     * @param array<string, mixed> $payload
     * @return array{sent: int, failed: int, expired: array<int, string>}
     *   `expired` holds endpoints the push service rejected as gone (404/410),
     *   which the caller should delete.
     */
    public function send(array $subscriptions, array $payload): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'expired' => []];

        if (!$this->isConfigured() || $subscriptions === []) {
            return $result;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->subject,
                    'publicKey' => $this->publicKey,
                    'privateKey' => $this->privateKey,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::logException($e);

            return $result;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'keys' => [
                            'p256dh' => $sub['p256dh'],
                            'auth' => $sub['auth'],
                        ],
                    ]),
                    $json !== false ? $json : null
                );
            } catch (\Throwable $e) {
                Log::logException($e);
            }
        }

        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $result['sent']++;
                    continue;
                }

                $result['failed']++;
                if ($report->isSubscriptionExpired()) {
                    $result['expired'][] = $report->getEndpoint();
                }
            }
        } catch (\Throwable $e) {
            Log::logException($e);
        }

        return $result;
    }
}
