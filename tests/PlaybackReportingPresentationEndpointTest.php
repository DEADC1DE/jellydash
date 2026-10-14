<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PlaybackReportingPresentationEndpointTest extends TestCase
{
    public function testPreviewReturnsSeparateImportableSkippedAndConflictCounts(): void
    {
        $payload = json_decode($this->probe(false), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['parsed' => 9, 'kind' => 'plugin', 'importable' => 3, 'skipped' => 4, 'conflicts' => 2], $payload);
    }

    public function testStreamPreservesConflictCountsThroughTheFinalResult(): void
    {
        $lines = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($this->probe(true))));

        self::assertCount(2, $lines);
        self::assertSame('importing', $lines[0]['phase']);
        self::assertSame(2, $lines[0]['conflicts']);
        self::assertSame('done', $lines[1]['phase']);
        self::assertSame(3, $lines[1]['inserted']);
        self::assertSame(4, $lines[1]['skipped']);
        self::assertSame(2, $lines[1]['conflicts']);
        self::assertSame(1, $lines[1]['unresolved']);
    }

    private function probe(bool $commit): string
    {
        $code = <<<'PHP'
            namespace Mk\Framework\Jellyfin {
                class PlaybackReportingImporter {
                    public function previewPlugin(): array { return ['parsed' => 9, 'kind' => 'plugin', 'importable' => 3, 'skipped' => 4, 'conflicts' => 2]; }
                    public function importFromPlugin(bool $dryRun = false, ?callable $progress = null): array {
                        $result = ['parsed' => 9, 'inserted' => 3, 'skipped' => 4, 'conflicts' => 2, 'unresolved' => 1];
                        if ($progress !== null) $progress(['phase' => 'importing', 'processed' => 9, 'total' => 9, 'inserted' => 3, 'skipped' => 4, 'conflicts' => 2]);
                        return $result;
                    }
                }
            }
            namespace {
                ini_set('session.serialize_handler', 'php_serialize');
                session_set_save_handler(new class implements \SessionHandlerInterface {
                    public function open(string $path, string $name): bool { return true; }
                    public function close(): bool { return true; }
                    public function read(string $id): string { return serialize(['csrf_token' => 'import-fixture-token']); }
                    public function write(string $id, string $data): bool { return true; }
                    public function destroy(string $id): bool { return true; }
                    public function gc(int $maxLifetime): int { return 0; }
                });
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
                $_SERVER['REQUEST_URI'] = '/api/playback-reporting.php';
                $_SERVER['SERVER_PORT'] = '80';
                $_SERVER['HTTP_X_CSRF_TOKEN'] = 'import-fixture-token';
                $_SERVER['HTTP_ACCEPT'] = $argv[2] === '1' ? 'application/x-ndjson' : 'application/json';
                $_POST = ['import_source' => 'plugin', 'commit' => $argv[2]];
                require $argv[1] . '/public/api/playback-reporting.php';
            }
            PHP;
        $environment = getenv();
        self::assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'AUTH_ENABLED' => 'false',
            'FORCE_HTTPS' => 'false', 'DB_DRIVER' => 'sqlite3', 'DB_NAME' => ':memory:',
            'JELLYFIN_URL' => '', 'JELLYFIN_API_TOKEN' => '', 'JELLYFIN_API_KEY' => '',
            'JELLYSEER_URL' => '', 'JELLYSEER_API_TOKEN' => '', 'TRUSTED_PROXIES' => '',
        ]);
        $process = proc_open([PHP_BINARY, '-r', $code, ROOT_DIR, $commit ? '1' : '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR, $environment);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        self::assertSame('', $errors);

        return $output;
    }
}
