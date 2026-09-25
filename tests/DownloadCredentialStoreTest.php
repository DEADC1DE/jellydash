<?php

declare(strict_types=1);

use Mk\Framework\Integrations\CredentialStore;
use PHPUnit\Framework\TestCase;

final class DownloadCredentialStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jellydash-key-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testDatabaseValueCanBeReadAfterContainerReplacementWithoutAKeyFile(): void
    {
        $firstPath = $this->directory . '/old-key';
        $saved = (new CredentialStore($firstPath))->encode('sample-secret', 'connection:one:sabnzbd');
        self::assertSame(2, json_decode($saved, true, flags: JSON_THROW_ON_ERROR)['v']);
        self::assertFileDoesNotExist($firstPath);
        self::assertSame('sample-secret', (new CredentialStore($this->directory . '/new-key'))
            ->decode($saved, 'connection:one:sabnzbd'));
        self::assertFileDoesNotExist($this->directory . '/new-key');
    }

    public function testValueIsBoundToItsConnectionAndInvalidValuesDoNotExposeSecrets(): void
    {
        $store = new CredentialStore($this->directory . '/key');
        $saved = $store->encode('sample-secret', 'connection:one:sabnzbd');
        foreach (['connection:two:sabnzbd' => $saved, 'connection:one:sabnzbd' => '{"v":2,"value":"sample-secret"}'] as $context => $value) {
            try {
                $store->decode($value, $context);
                self::fail('Invalid stored values must be rejected.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('sample-secret', $error->getMessage());
            }
        }
    }

    public function testMaximumSizedControlCharacterValueRoundTrips(): void
    {
        $store = new CredentialStore($this->directory . '/key');
        $value = str_repeat("\x01", 131072);
        $saved = $store->encode($value, 'session:one:sabnzbd:1');
        self::assertSame($value, (new CredentialStore($this->directory . '/other-key'))
            ->decode($saved, 'session:one:sabnzbd:1'));
        self::assertFileDoesNotExist($this->directory . '/key');
    }

    public function testExistingEncryptedValueCanBeReadOnlyWithItsOriginalKey(): void
    {
        $path = $this->directory . '/legacy-key';
        $context = 'connection:one:sabnzbd';
        $legacy = self::legacyEnvelope($path, 'sample-secret', $context);
        $store = new CredentialStore($path);
        self::assertTrue($store->isLegacy($legacy));
        self::assertSame('sample-secret', $store->decode($legacy, $context));
        unlink($path);
        try {
            $store->decode($legacy, $context);
            self::fail('A missing legacy key must not be replaced.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Enter the credential again', $error->getMessage());
        }
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.lock');
    }

    public static function legacyEnvelope(string $path, string $plaintext, string $context): string
    {
        $key = random_bytes(32);
        file_put_contents($path, $key);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        self::assertIsString($ciphertext);
        return json_encode([
            'v' => 1, 'key' => substr(hash('sha256', $key), 0, 16),
            'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR);
    }
}
