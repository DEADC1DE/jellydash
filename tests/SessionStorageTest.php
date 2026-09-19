<?php

declare(strict_types=1);

use Mk\Framework\SessionStorage;
use PHPUnit\Framework\TestCase;

final class SessionStorageTest extends TestCase
{
    public function testAFalseWritableFlagDoesNotRejectAnActuallyWritableDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/jellydash-session-probe-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        try {
            $this->assertTrue(SessionStorage::usable($directory, false));
            $this->assertSame([], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])));
        } finally {
            rmdir($directory);
        }
    }

    public function testMissingDirectoryIsNotUsed(): void
    {
        $directory = sys_get_temp_dir() . '/jellydash-session-missing-' . bin2hex(random_bytes(8));
        $this->assertFalse(SessionStorage::usable($directory, true));
    }

    public function testBootstrapUsesTheVerifiedDirectory(): void
    {
        $bootstrap = (string) file_get_contents(ROOT_DIR . '/utils/@settings.php');
        $this->assertStringContainsString('SessionStorage::usable($sessionPath)', $bootstrap);
        $this->assertTrue(SessionStorage::usable(ROOT_DIR . '/var/sessions'));
    }
}
