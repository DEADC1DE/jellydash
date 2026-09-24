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

    public function testPersistedKeyDecryptsAfterRestartAndCiphertextIsRandomized(): void
    {
        $path = $this->directory . '/key';
        $store = new CredentialStore($path);
        $first = $store->encrypt('sample-secret', 'connection:one:sabnzbd', true);
        $second = $store->encrypt('sample-secret', 'connection:one:sabnzbd');
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('sample-secret', $first);
        self::assertSame(32, filesize($path));
        self::assertSame('sample-secret', (new CredentialStore($path))->decrypt($first, 'connection:one:sabnzbd'));
    }

    public function testCiphertextCannotMoveToAnotherConnection(): void
    {
        $store = new CredentialStore($this->directory . '/key');
        $ciphertext = $store->encrypt('sample-secret', 'connection:one:sabnzbd', true);
        $this->expectException(RuntimeException::class);
        $store->decrypt($ciphertext, 'connection:two:sabnzbd');
    }

    public function testMissingKeyIsNotReplacedWhenDecryptingExistingData(): void
    {
        $path = $this->directory . '/key';
        $store = new CredentialStore($path);
        $ciphertext = $store->encrypt('sample-secret', 'connection:one:sabnzbd', true);
        unlink($path);
        try {
            $store->decrypt($ciphertext, 'connection:one:sabnzbd');
            self::fail('A missing key must not be regenerated.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('sample-secret', $error->getMessage());
            self::assertFileDoesNotExist($path);
        }
    }

    public function testCreationNeedsAnExplicitEmptyStoreDecision(): void
    {
        $store = new CredentialStore($this->directory . '/key');
        $this->expectException(RuntimeException::class);
        $store->encrypt('sample-secret', 'context');
    }

    public function testCorruptedEnvelopeAndWrongKeyFailWithoutExposingContent(): void
    {
        $path = $this->directory . '/key';
        $store = new CredentialStore($path);
        $ciphertext = $store->encrypt('sample-secret', 'context', true);
        file_put_contents($path, random_bytes(32));
        foreach ([$ciphertext, '{"v":1,"ciphertext":"sample-secret"}', 'invalid'] as $value) {
            try {
                $store->decrypt($value, 'context');
                self::fail('Invalid encryption data must be rejected.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('sample-secret', $error->getMessage());
            }
        }
    }
}
