<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Integrations\ConnectionRepository;

final class Collector
{
    public const INTERVAL = 15;

    /** @param \Closure(string): Provider|null $provider */
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly DownloadRepository $downloads,
        private readonly ?\Closure $provider = null,
    ) {
    }

    /** @return array{collected:int,failed:int} */
    public function run(?int $now = null): array
    {
        if (!Feature::enabled(true)) {
            return ['collected' => 0, 'failed' => 0];
        }

        $now ??= time();
        $started = hrtime(true);
        $states = $this->downloads->states();
        $connections = $this->connections->all();
        usort($connections, static fn ($a, $b): int => ($states[$a->id]['next_due_at'] ?? 0) <=> ($states[$b->id]['next_due_at'] ?? 0));
        $result = ['collected' => 0, 'failed' => 0];
        foreach ($connections as $connection) {
            if (!Feature::enabled(true)) {
                break;
            }
            if ((hrtime(true) - $started) / 1e9 >= 20) {
                break;
            }
            $token = $this->downloads->claim($connection, $now);
            if ($token === null) {
                continue;
            }
            if (!Feature::enabled(true)) {
                $this->downloads->abandon($connection, $token);
                break;
            }
            try {
                $state = $this->downloads->state($connection->id);
                $provider = ($this->provider ?? Providers::get(...))($connection->provider);
                $batch = $provider->collect(
                    $connection,
                    $this->connections->credentials($connection),
                    (array) ($state['cursor'] ?? []),
                    $this->downloads->session($connection),
                );
                if (!Feature::enabled(true)) {
                    $this->downloads->abandon($connection, $token);
                    break;
                }
                $finished = $now + (int) ((hrtime(true) - $started) / 1e9);
                if ($this->downloads->succeed($connection, $token, $batch, $finished)) {
                    ++$result['collected'];
                }
            } catch (\Throwable $error) {
                if (!Feature::enabled(true)) {
                    $this->downloads->abandon($connection, $token);
                    break;
                }
                $code = $error instanceof ProviderException ? $error->reason : 'request_failed';
                // Provider bodies, addresses and credentials never enter the log.
                $this->downloads->fail($connection, $token, $code, $now + (int) ((hrtime(true) - $started) / 1e9));
                ++$result['failed'];
            }
        }

        return $result;
    }
}
