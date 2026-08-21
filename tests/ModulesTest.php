<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ModulesTest extends TestCase
{
    public function testGlobalAssetsUseEachAssetTimestampForCacheBusting(): void
    {
        $source = file_get_contents(ROOT_DIR . '/src/Modules.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("filemtime(self::dir(\$name) . '/assets/' . \$file)", $source);
        $this->assertStringNotContainsString("filemtime(self::dir(\$name) . '/module.php')", $source);
    }
}
