<?php

declare(strict_types=1);

use Mk\Framework\Health\StatusService;
use PHPUnit\Framework\TestCase;

final class DownloadsIntegrationTest extends TestCase
{
    public function testLegacyModuleIsSkippedBeforeExecutionAndOtherModulesStillLoad(): void
    {
        $path = sys_get_temp_dir() . '/jellydash-modules-' . bin2hex(random_bytes(8));
        mkdir($path . '/downloads', 0700, true);
        mkdir($path . '/example', 0700, true);
        file_put_contents($path . '/downloads/module.php', '<?php throw new RuntimeException("Legacy manifest ran");');
        file_put_contents($path . '/example/module.php', '<?php return ["name" => "example", "nav" => ["label" => "Example", "route" => "example", "icon" => "", "order" => 10]];');
        try {
            $code = 'require ' . var_export(ROOT_DIR . '/vendor/autoload.php', true) . '; define("MODULES_DIR", ' . var_export($path, true)
                . '); echo json_encode(array_keys(Mk\\Framework\\Modules::all()));';
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $error);
            self::assertSame('["example"]', $output);
        } finally {
            foreach (['downloads', 'example'] as $folder) {
                unlink($path . '/' . $folder . '/module.php');
                rmdir($path . '/' . $folder);
            }
            rmdir($path);
        }
    }

    public function testDownloadHealthDistinguishesPartialSuccessAndNoConfiguredClients(): void
    {
        $snapshot = ['configured' => true, 'partial' => true, 'connections' => [
            ['enabled' => true, 'status' => 'connected'], ['enabled' => true, 'status' => 'offline'],
        ]];
        $service = new StatusService(readWorkers: static fn (): array => [], countSubscriptions: static fn (): int => 0,
            readQueue: static fn (): array => ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0],
            readDownloads: static fn (): array => $snapshot);
        $components = array_column($service->snapshot(1000)['components'], null, 'id');
        self::assertSame('delayed', $components['downloads']['state']);
        self::assertStringContainsString('Some download clients', $components['downloads']['message']);

        $service = new StatusService(readWorkers: static fn (): array => [], countSubscriptions: static fn (): int => 0,
            readQueue: static fn (): array => ['pending_retries' => 0, 'in_flight' => 0, 'stalled' => 0],
            readDownloads: static fn (): array => ['configured' => false]);
        self::assertNotContains('downloads', array_column($service->snapshot(1000)['components'], 'id'));
    }
}
