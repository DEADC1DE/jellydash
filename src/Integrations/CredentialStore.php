<?php

declare(strict_types=1);

namespace Mk\Framework\Integrations;

/** Encodes server-only integration values in the existing application database. */
final class CredentialStore
{
    private const MAX_VALUE_BYTES = 131072;
    private const MAX_ENVELOPE_BYTES = 1048576;

    private readonly string $legacyKeyPath;

    public function __construct(?string $legacyKeyPath = null)
    {
        $this->legacyKeyPath = $legacyKeyPath ?? dirname(__DIR__, 2) . '/var/data/integration-key';
    }

    public function encode(string $value, string $context): string
    {
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException('The stored integration value is too large.');
        }

        $envelope = json_encode([
            'v' => 2,
            'context' => hash('sha256', $context),
            'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            throw new \InvalidArgumentException('The stored integration value is too large.');
        }

        return $envelope;
    }

    public function decode(string $envelope, string $context): string
    {
        $failure = 'The saved integration value is invalid. Enter the credential again if needed.';
        if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            throw new \RuntimeException($failure);
        }
        try {
            $data = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException($failure);
        }
        if (!is_array($data)) {
            throw new \RuntimeException($failure);
        }
        if (($data['v'] ?? null) === 2) {
            if (!is_string($data['context'] ?? null) || !hash_equals(hash('sha256', $context), $data['context'])
                || !is_string($data['value'] ?? null) || strlen($data['value']) > self::MAX_VALUE_BYTES) {
                throw new \RuntimeException($failure);
            }

            return $data['value'];
        }
        if (($data['v'] ?? null) !== 1) {
            throw new \RuntimeException($failure);
        }

        return $this->decodeLegacy($data, $context);
    }

    public function isLegacy(string $envelope): bool
    {
        try {
            $data = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($data) && ($data['v'] ?? null) === 1;
    }

    /** @param array<string,mixed> $data */
    private function decodeLegacy(array $data, string $context): string
    {
        $failure = 'Could not read the old encrypted credential. Restore its original integration key. Enter the credential again if that key is unavailable.';
        foreach (['key', 'nonce', 'tag', 'ciphertext'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new \InvalidArgumentException($failure);
            }
        }
        $key = @file_get_contents($this->legacyKeyPath, false, null, 0, 33);
        if ($key === false || strlen($key) !== 32) {
            throw new \InvalidArgumentException($failure);
        }
        $nonce = base64_decode($data['nonce'], true);
        $tag = base64_decode($data['tag'], true);
        $ciphertext = base64_decode($data['ciphertext'], true);
        if (!hash_equals(substr(hash('sha256', $key), 0, 16), $data['key'])
            || $nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new \InvalidArgumentException($failure);
        }
        $value = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if ($value === false) {
            throw new \InvalidArgumentException($failure);
        }

        return $value;
    }
}
