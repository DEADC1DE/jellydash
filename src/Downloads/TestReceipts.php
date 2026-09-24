<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Integrations\Connection;

/** Session-bound proof of a recent test of the exact destination and credential. */
final class TestReceipts
{
    /** @param array<string,mixed> $session */
    public static function reserve(array &$session, int $now): void
    {
        $times = is_array($session['downloads_test_times'] ?? null) ? $session['downloads_test_times'] : [];
        $times = array_values(array_filter($times, static fn (mixed $time): bool => is_int($time) && $time > $now - 60));
        if (count($times) >= 5) {
            throw new \OverflowException('Wait a minute before testing another connection.');
        }
        $times[] = $now;
        $session['downloads_test_times'] = $times;
    }

    /** @param array<string,mixed> $session @param array{username:string,secret:string} $credentials */
    public static function issue(array &$session, Connection $connection, array $credentials, int $actor, int $now): string
    {
        if (!is_string($session['downloads_receipt_key'] ?? null)) {
            $session['downloads_receipt_key'] = bin2hex(random_bytes(32));
        }
        $receipts = is_array($session['downloads_receipts'] ?? null) ? $session['downloads_receipts'] : [];
        $receipts = array_filter($receipts, static fn (mixed $row): bool => is_array($row) && (int) ($row['expires'] ?? 0) >= $now);
        $token = bin2hex(random_bytes(24));
        $receipts[$token] = [
            'fingerprint' => self::fingerprint($connection, $credentials, $actor, $session['downloads_receipt_key']),
            'expires' => $now + 300,
        ];
        $session['downloads_receipts'] = array_slice($receipts, -5, null, true);
        return $token;
    }

    /** @param array<string,mixed> $session @param array{username:string,secret:string} $credentials */
    public static function consume(array &$session, string $token, Connection $connection, array $credentials, int $actor, int $now): bool
    {
        $row = $session['downloads_receipts'][$token] ?? null;
        if (!is_array($row) || !is_string($session['downloads_receipt_key'] ?? null)
            || !is_string($row['fingerprint'] ?? null) || (int) ($row['expires'] ?? 0) < $now) {
            return false;
        }
        $valid = hash_equals($row['fingerprint'], self::fingerprint($connection, $credentials, $actor, $session['downloads_receipt_key']));
        if ($valid) {
            unset($session['downloads_receipts'][$token]);
        }
        return $valid;
    }

    /** @param array{username:string,secret:string} $credentials */
    private static function fingerprint(Connection $connection, array $credentials, int $actor, string $key): string
    {
        // New connections receive their persistent ID when saved. Bind their
        // tests to the destination, actor and inputs instead of a temporary ID.
        return hash_hmac('sha256', json_encode([
            $actor, $connection->revision > 0 ? $connection->id : 'new', $connection->revision,
            $connection->provider, $connection->url, $connection->verifyTls, $credentials,
        ], JSON_THROW_ON_ERROR), $key);
    }
}
