<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DockerSqliteSetupTest extends TestCase
{
    public function testImageAndEntrypointPrepareSQLiteSupport(): void
    {
        $dockerfile = file_get_contents(ROOT_DIR . '/Dockerfile');
        $entrypoint = file_get_contents(ROOT_DIR . '/docker/entrypoint.sh');

        $this->assertIsString($dockerfile);
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('sqlite3', $dockerfile);
        $this->assertStringContainsString('/var/www/html/var/data', $entrypoint);
        $this->assertStringContainsString('database:migrate-to-sqlite', $entrypoint);
    }

    public function testSQLiteComposeSetupIsSeparateAndPersistent(): void
    {
        $compose = file_get_contents(ROOT_DIR . '/docker-compose.sqlite.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString('DB_DRIVER: sqlite3', $compose);
        $this->assertStringContainsString('DB_NAME: /var/www/html/var/data/jellydash.sqlite', $compose);
        $this->assertStringContainsString('./sqlite-data:/var/www/html/var/data', $compose);
        $this->assertStringNotContainsString("\n  db:", $compose);
        $this->assertStringNotContainsString('depends_on:', $compose);
    }
}
