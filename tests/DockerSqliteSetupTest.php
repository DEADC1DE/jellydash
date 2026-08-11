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
        $this->assertStringContainsString("extension_loaded('sqlite3')", $dockerfile);
        $this->assertStringNotContainsString('libsqlite3-dev', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^\s+sqlite3\s+\\\\$/m', $dockerfile);
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

    public function testReadmeMakesTheSelectedComposeSetupTheDefault(): void
    {
        $readme = file_get_contents(ROOT_DIR . '/README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString(
            'curl -L -o docker-compose.yml https://raw.githubusercontent.com/themartz90/jellydash/main/docker-compose.sqlite.yml',
            $readme,
        );
        $this->assertStringContainsString('For both MariaDB and SQLite:', $readme);
        $this->assertStringContainsString('docker compose pull && docker compose up -d', $readme);
        $this->assertStringContainsString('cp -n docker-compose.yml docker-compose.mariadb.yml', $readme);
        $this->assertStringContainsString('MariaDB Compose backup verified', $readme);
        $this->assertStringContainsString('cp docker-compose.sqlite.yml docker-compose.yml', $readme);
        $this->assertStringContainsString('cp docker-compose.mariadb.yml docker-compose.yml', $readme);
        $this->assertStringNotContainsString('docker compose -f docker-compose.sqlite.yml pull', $readme);
        $this->assertStringNotContainsString('docker compose -f docker-compose.sqlite.yml up -d', $readme);
    }
}
