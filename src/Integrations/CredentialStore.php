<?php

declare(strict_types=1);

namespace Mk\Framework\Integrations;

/** Encrypts integration credentials with a separately persisted application key. */
final class CredentialStore
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . '/var/data/integration-key';
    }

    public function encrypt(string $plaintext, string $context, bool $allowCreate = false): string
    {
        if (strlen($plaintext) > 8192) {
            throw new \InvalidArgumentException('The credential is too long.');
        }
        $key = $this->key($allowCreate);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Could not encrypt the integration credential.');
        }
        return json_encode([
            'v' => 1, 'key' => substr(hash('sha256', $key), 0, 16),
            'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR);
    }

    public function decrypt(string $envelope, string $context): string
    {
        $failure = 'Could not unlock the integration credential. Restore the original integration key or enter the credential again.';
        if (strlen($envelope) > 16384) {
            throw new \RuntimeException($failure);
        }
        try {
            $data = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException($failure);
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1) {
            throw new \RuntimeException($failure);
        }
        foreach (['key', 'nonce', 'tag', 'ciphertext'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new \RuntimeException($failure);
            }
        }
        $key = $this->key(false);
        $nonce = base64_decode($data['nonce'], true);
        $tag = base64_decode($data['tag'], true);
        $ciphertext = base64_decode($data['ciphertext'], true);
        if (!hash_equals(substr(hash('sha256', $key), 0, 16), $data['key'])
            || $nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new \RuntimeException($failure);
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if ($plaintext === false) {
            throw new \RuntimeException($failure);
        }
        return $plaintext;
    }

    private function key(bool $allowCreate): string
    {
        if (is_file($this->path)) {
            return $this->readKey();
        }
        if (!$allowCreate) {
            throw new \RuntimeException('The integration key is missing. Restore it before using saved credentials.');
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create persistent integration storage.');
        }
        $oldMask = umask(0077);
        try {
            $lock = @fopen($this->path . '.lock', 'c');
        } finally {
            umask($oldMask);
        }
        if ($lock === false) {
            throw new \RuntimeException('Could not lock persistent integration storage.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock persistent integration storage.');
            }
            clearstatcache(true, $this->path);
            if (!is_file($this->path)) {
                $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $oldMask = umask(0077);
                try {
                    $handle = @fopen($temporary, 'x+b');
                } finally {
                    umask($oldMask);
                }
                if ($handle === false) {
                    throw new \RuntimeException('Could not write the integration key.');
                }
                try {
                    $key = random_bytes(32);
                    if (fwrite($handle, $key) !== 32 || !fflush($handle)) {
                        throw new \RuntimeException('Could not write the integration key.');
                    }
                    if (function_exists('fsync')) {
                        fsync($handle);
                    }
                } finally {
                    fclose($handle);
                }
                @chmod($temporary, 0600);
                if (!@rename($temporary, $this->path)) {
                    @unlink($temporary);
                    throw new \RuntimeException('Could not persist the integration key.');
                }
            }
            return $this->readKey();
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readKey(): string
    {
        $key = @file_get_contents($this->path, false, null, 0, 33);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('The integration key is unreadable or invalid. Restore the original key.');
        }
        return $key;
    }
}
