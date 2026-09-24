<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Config;
use Mk\Framework\Integrations\ConnectionRepository;

/** Builds the public monitor from local records only. */
final class OverviewService
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly DownloadRepository $downloads,
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(?int $now = null, bool $summary = false): array
    {
        $now ??= time();
        if (!Feature::enabled()) {
            $result = ['feature_enabled' => false, 'configured' => false,
                'workers_enabled' => Config::bool('POLLER_ENABLED', true), 'monitoring_enabled' => false,
                'now' => $now, 'speed' => null, 'outside_speed' => null, 'speed_complete' => false,
                'partial' => false, 'connections' => [], 'active_count' => 0];
            if (!$summary) {
                $result['items'] = [];
                $result['history'] = [];
                $result['history_by_connection'] = [];
            }

            return $result;
        }

        $connections = $this->connections->all();
        $states = $this->downloads->states();
        $clients = $items = $history = $historyByConnection = $enabled = [];
        $speed = null;
        $outsideSpeed = null;
        $speedComplete = false;
        $speedUnknown = $outsideUnknown = false;
        $partial = false;
        $active = 0;
        $workersEnabled = Config::bool('POLLER_ENABLED', true);
        $monitoringEnabled = $workersEnabled;
        foreach ($connections as $connection) {
            $state = $states[$connection->id] ?? null;
            if (($state['revision'] ?? null) !== $connection->revision) {
                $state = null;
            }
            $last = $state['last_success_at'] ?? null;
            $age = $last === null ? PHP_INT_MAX : $now - $last;
            $failed = ($state['failure_code'] ?? null) !== null;
            $status = !$connection->enabled ? 'disabled' : ($last === null ? ($failed ? 'offline' : 'waiting')
                : ($age > 120 || $age < -60 ? 'offline' : ($failed || $age > 45 ? 'stale' : 'connected')));
            $snapshot = (array) ($state['snapshot'] ?? []);
            $fresh = $status === 'connected';
            $rawValue = array_key_exists('client_speed', $snapshot) ? $snapshot['client_speed'] : ($snapshot['speed'] ?? null);
            $rawSpeed = $fresh && is_numeric($rawValue) ? max(0.0, (float) $rawValue) : null;
            // Old snapshots stored only the client total. Never show it as selected-download speed.
            $scopedSpeed = $fresh && ($connection->filterMode === 'all' || array_key_exists('client_speed', $snapshot))
                && is_numeric($snapshot['speed'] ?? null) ? max(0.0, (float) $snapshot['speed']) : null;
            $clientOutside = $fresh && is_numeric($snapshot['outside_speed'] ?? null)
                ? max(0.0, (float) $snapshot['outside_speed']) : null;
            $clientSpeedComplete = $scopedSpeed !== null && ($connection->filterMode === 'all'
                || ($snapshot['speed_complete'] ?? false) === true);
            $clientPartial = $connection->enabled && (!$fresh || !($snapshot['complete'] ?? false));
            $partial = $partial || $clientPartial;
            $savedHistory = $snapshot['history'] ?? null;
            if (is_array($savedHistory) && $savedHistory !== []) {
                $historySuccess = is_int($savedHistory['last_success_at'] ?? null) ? $savedHistory['last_success_at'] : null;
                $historyDue = is_int($savedHistory['next_due_at'] ?? null) ? $savedHistory['next_due_at'] : 0;
                $historyError = is_string($savedHistory['error'] ?? null) ? $savedHistory['error'] : null;
                $historyStatus = !$connection->enabled ? 'disabled' : ($historyError !== null ? 'error'
                    : ($historySuccess === null ? 'waiting'
                        : ($now < $historySuccess - 60 || $now > $historyDue + 60 ? 'stale' : 'connected')));
                $historyHealth = [
                    'status' => $historyStatus,
                    'last_attempt_at' => $savedHistory['last_attempt_at'] ?? null,
                    'last_success_at' => $historySuccess,
                    'next_due_at' => $historyDue,
                    'failure_count' => $savedHistory['failure_count'] ?? 0,
                    'error' => $historyError,
                ];
            } else {
                // Older snapshots and inventory-based providers share the live collection health.
                $historyHealth = [
                    'status' => $status,
                    'last_attempt_at' => $state['last_attempt_at'] ?? null,
                    'last_success_at' => $last,
                    'next_due_at' => $state['next_due_at'] ?? 0,
                    'failure_count' => $state['failure_count'] ?? 0,
                    'error' => $state['failure_code'] ?? null,
                ];
            }
            $clients[] = [
                'id' => $connection->id, 'name' => $connection->name, 'provider' => $connection->provider,
                'enabled' => $connection->enabled, 'filter_mode' => $connection->filterMode,
                'status' => $status, 'last_success_at' => $last, 'speed' => $scopedSpeed,
                'client_speed' => $rawSpeed, 'outside_speed' => $clientOutside,
                'speed_complete' => $clientSpeedComplete,
                'error' => $failed ? (new ProviderException((string) $state['failure_code']))->getMessage() : null,
                'partial' => $clientPartial, 'history' => $historyHealth,
            ];
            if (!$connection->enabled) {
                continue;
            }
            $enabled[$connection->id] = $connection;
            // An external scheduler can collect while the built-in loop is disabled.
            $monitoringEnabled = $monitoringEnabled || ($state['last_attempt_at'] ?? null) !== null;
            $speedUnknown = $speedUnknown || !$clientSpeedComplete;
            $outsideUnknown = $outsideUnknown || $clientOutside === null;
            if ($scopedSpeed !== null) {
                $speed = ($speed ?? 0.0) + $scopedSpeed;
            }
            if ($clientOutside !== null) {
                $outsideSpeed = ($outsideSpeed ?? 0.0) + $clientOutside;
            }
            foreach ((array) ($snapshot['items'] ?? []) as $item) {
                if (!is_array($item) || !$connection->matches($item) || ($item['state'] ?? '') === 'completed') {
                    continue;
                }
                $item = array_intersect_key($item, array_flip([
                    'source_id', 'title', 'category', 'tags', 'state', 'progress', 'size', 'downloaded', 'speed', 'eta', 'position',
                ]));
                if ($fresh && ($item['state'] ?? '') === 'downloading') {
                    ++$active;
                }
                if (!$fresh) {
                    $item['speed'] = null;
                    $item['eta'] = null;
                }
                if (!$summary) {
                    $items[] = $item + ['connection_id' => $connection->id, 'connection_name' => $connection->name,
                        'provider' => $connection->provider, 'stale' => !$fresh];
                }
            }
        }
        if (!$summary && $enabled !== []) {
            // Read each bounded connection history before filtering and merging.
            foreach ($enabled as $connection) {
                $connectionHistory = [];
                foreach ($this->downloads->recent([$connection->id], 250) as $row) {
                    if ($connection->matches($row)) {
                        $item = array_intersect_key($row, array_flip([
                            'connection_id', 'source_id', 'title', 'category', 'tags', 'size', 'status', 'completed_at', 'observed_at',
                        ])) + ['connection_name' => $connection->name, 'provider' => $connection->provider];
                        $history[] = $item;
                        $connectionHistory[] = $item;
                    }
                }
                $historyByConnection[$connection->id] = array_slice($connectionHistory, 0, 20);
            }
            usort($history, static fn (array $a, array $b): int => [($b['completed_at'] ?? $b['observed_at']), $b['connection_id'], $b['source_id']]
                <=> [($a['completed_at'] ?? $a['observed_at']), $a['connection_id'], $a['source_id']]);
        }
        $speedComplete = $enabled !== [] && !$speedUnknown;
        if (!$speedComplete) {
            $speed = null;
        }
        if ($outsideUnknown) {
            $outsideSpeed = null;
        }
        $result = ['feature_enabled' => true, 'configured' => $connections !== [], 'workers_enabled' => $workersEnabled,
            'monitoring_enabled' => $monitoringEnabled,
            'now' => $now, 'speed' => $speed, 'outside_speed' => $outsideSpeed,
            'speed_complete' => $speedComplete, 'partial' => $partial, 'connections' => $clients, 'active_count' => $active];
        if (!$summary) {
            $result['items'] = $items;
            $result['history'] = array_slice($history, 0, 20);
            $result['history_by_connection'] = $historyByConnection;
        }

        return $result;
    }
}
