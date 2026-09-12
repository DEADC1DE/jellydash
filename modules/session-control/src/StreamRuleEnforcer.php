<?php

declare(strict_types=1);

namespace Mk\Modules\SessionControl;

use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\JellyfinSessionMapper;
use Mk\Framework\Jellyfin\MonitoringExclusions;
use Mk\Framework\Log;
use Mk\Framework\Notifications\NotificationDispatcher;

/**
 * Runs the enabled stream rules against the current Jellyfin sessions and
 * stops or kicks every session that matches — the Tautulli "kill stream by
 * condition" pattern, evaluated on every poll cycle. Kills are guarded per
 * session + rule so a session that lingers after its stop command is not
 * killed repeatedly, and every kill is announced through the configured
 * notification channels.
 */
final class StreamRuleEnforcer
{
    public function __construct(
        private ?StreamRuleRepository $repository = null,
        private ?StreamRuleEngine $engine = null,
        private ?\Closure $kill = null,
        private ?\Closure $announce = null,
        private ?\Closure $sessions = null,
        private ?\Closure $logException = null,
    ) {
    }

    /** @return int the number of sessions killed in this run */
    public function run(?int $now = null): int
    {
        $rules = ($this->repository ?? new StreamRuleRepository())->enabled();
        if ($rules === []) {
            return 0;
        }

        $streams = $this->currentStreams();
        if ($streams === []) {
            return 0;
        }

        $now ??= time();
        $engine = $this->engine ?? new StreamRuleEngine();
        $repository = $this->repository ?? new StreamRuleRepository();
        $announce = $this->announce ?? function (array $rule, array $stream, string $action): void {
            $this->announceViaDispatcher(new NotificationDispatcher(), $rule, $stream, $action);
        };
        $actions = $this->kill ?? new SessionActionsService();
        $kill = $actions instanceof SessionActionsService
            ? fn (string $action, string $sessionId): bool => $action === 'kick'
                ? $actions->kick($sessionId)
                : $actions->stop($sessionId)
            : $actions;

        $killed = 0;
        $perUser = $this->perUserAggregates($repository, $streams, $now);
        foreach ($rules as $rule) {
            foreach ($streams as $stream) {
                $sessionId = (string) ($stream['id'] ?? '');
                if ($sessionId === '' || $repository->wasKilledRecently($sessionId, (int) $rule['id'], $now)) {
                    continue;
                }

                if (!$engine->evaluate($rule['conditions'], (string) $rule['logic'], $this->parameters($stream, $perUser))) {
                    continue;
                }

                $killedAction = ($rule['action'] ?? 'stop') === 'kick' ? 'kick' : 'stop';
                $stopped = false;
                try {
                    $stopped = (bool) $kill($killedAction, $sessionId);
                } catch (\Throwable $error) {
                    ($this->logException ?? static function (\Throwable $error): void {
                        Log::logException($error);
                    })($error);
                }

                if (!$stopped) {
                    continue;
                }

                $repository->recordKill($sessionId, (int) $rule['id'], $now);
                $killed++;

                $announce($rule, $stream, $killedAction);
            }
        }

        return $killed;
    }

    /**
     * @return array<int, array<string, mixed>> mapped streams with monitoring exclusions applied
     */
    private function currentStreams(): array
    {
        if ($this->sessions !== null) {
            return ($this->sessions)();
        }

        try {
            $client = new JellyfinClient();
            $mapper = new JellyfinSessionMapper($client->baseUrl());
            $mapped = $mapper->map((new MonitoringExclusions())->filterSessions($client->sessions()));

            return $mapped['streams'];
        } catch (\Throwable $error) {
            Log::logException($error);

            return [];
        }
    }

    /**
     * Per-user aggregates over the current session snapshot (active stream
     * count, distinct IPs, and each IP's 1-based slot by first-seen order)
     * turn cross-session questions like "3rd IP for this user" into
     * per-session rule parameters.
     *
     * @param array<int, array<string, mixed>> $streams
     * @return array<string, array<string, mixed>> keyed by "user\0sessionId"
     */
    private function perUserAggregates(StreamRuleRepository $repository, array $streams, int $now): array
    {
        $ipRanks = $repository->refreshIpRanks(array_map(static fn (array $stream): array => [
            'user' => (string) ($stream['user'] ?? ''),
            'ip' => (string) ($stream['ip'] ?? ''),
        ], $streams), $now);

        $stats = [];
        $aggregate = [];
        foreach ($streams as $stream) {
            $user = (string) ($stream['user'] ?? '');
            $ip = (string) ($stream['ip'] ?? '');
            $stats[$user] ??= ['streams' => 0, 'ips' => []];
            $stats[$user]['streams']++;
            if ($ip !== '') {
                $stats[$user]['ips'][$ip] = true;
            }
        }

        foreach ($streams as $stream) {
            $user = (string) ($stream['user'] ?? '');
            $ip = (string) ($stream['ip'] ?? '');
            $aggregate[$user . "\0" . (string) ($stream['id'] ?? '')] = [
                'userStreams' => $stats[$user]['streams'],
                'userIpCount' => count($stats[$user]['ips']),
                'ipIndex' => $ip !== '' ? ($ipRanks[$user . "\0" . $ip] ?? 0) : 0,
            ];
        }

        return $aggregate;
    }

    /**
     * Rule parameters are the mapped stream fields plus the per-user
     * aggregates; watched minutes is a friendlier unit for "kill anything
     * playing longer than X" rules.
     *
     * @param array<string, mixed> $stream
     * @param array<string, array<string, mixed>> $perUser
     * @return array<string, mixed>
     */
    private function parameters(array $stream, array $perUser = []): array
    {
        $parameters = $stream;
        $parameters['watchedMin'] = (int) floor(((int) ($stream['watchedSec'] ?? 0)) / 60);
        $parameters += $perUser[$stream['user'] . "\0" . $stream['id']] ?? [];

        return $parameters;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $stream
     */
    private function announceViaDispatcher(NotificationDispatcher $dispatcher, array $rule, array $stream, string $action): void
    {
        if (!$dispatcher->hasAnyChannel()) {
            return;
        }

        $user = trim((string) ($stream['user'] ?? '')) ?: 'Someone';
        $title = trim((string) ($stream['title'] ?? '')) ?: 'a stream';
        $device = trim((string) ($stream['device'] ?? ''));
        $name = trim((string) ($rule['name'] ?? '')) ?: '#' . $rule['id'];

        try {
            $dispatcher->send([
                'title' => ($action === 'kick' ? '⛔ Kicked: ' : '⛔ Auto-stopped: ') . $user,
                'body' => '"' . $title . '"' . ($device !== '' ? ' on ' . $device : '')
                    . ' — rule "' . $name . '" matched.',
                'tag' => 'jellydash-stream-rule-' . (string) $rule['id'],
                'url' => '/now-playing',
            ]);
        } catch (\Throwable $error) {
            Log::logException($error);
        }
    }
}
