<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerConfigurationTest extends TestCase
{
    #[DataProvider('configurations')]
    public function testStartupConfigurationMatchesPhpSettings(
        string $enabled,
        string $history,
        string $libraries,
        string $seerr,
        string $expected,
    ): void {
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['POLLER_ENABLED'] = $enabled;
        $environment['POLL_INTERVAL'] = $history;
        $environment['LIBRARIES_CACHE_TTL'] = $libraries;
        $environment['SEERR_POLL_INTERVAL'] = $seerr;
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/bin/worker-config.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame($expected . PHP_EOL, $output);
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function configurations(): iterable
    {
        yield 'defaults' => ['', '', '', '', '1 30 300 120'];
        yield 'documented disabled' => ['false', '30', '300', '120', '0 30 300 120'];
        yield 'php true aliases' => ['TRUE', '1', 'yes', 'on', '1 1 300 120'];
        yield 'yes enabled' => ['yes', '30', '300', '120', '1 30 300 120'];
        yield 'on enabled' => ['ON', '30', '300', '120', '1 30 300 120'];
        yield 'numeric enabled' => ['1', '60', '180', '90', '1 60 180 90'];
        yield 'php false aliases' => ['OFF', '30', '300', '120', '0 30 300 120'];
        yield 'unrecognized disabled' => ['maybe', '30', '300', '120', '0 30 300 120'];
        yield 'invalid and zero intervals' => ['true', '0', 'abc', '-5', '1 30 300 120'];
        yield 'out of range intervals' => ['true', '86401', '030', '999999999999999999999', '1 30 300 120'];
        yield 'valid upper bound' => ['true', '86400', '86400', '86400', '1 86400 86400 86400'];
    }

    public function testEntrypointConsumesNormalizedSettingsBeforeStartingWorkers(): void
    {
        $entrypoint = file_get_contents(ROOT_DIR . '/docker/entrypoint.sh');
        self::assertIsString($entrypoint);
        self::assertStringContainsString('php /var/www/html/bin/worker-config.php', $entrypoint);
        self::assertStringContainsString('if [ "$workers_enabled" = "1" ]; then', $entrypoint);
        self::assertStringContainsString('sleep "${POLL_INTERVAL}"', $entrypoint);
    }
}
