<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Log;

final class NowPlayingService
{
    public function __construct(
        private ?JellyfinClient $client = null,
        private ?JellyfinSessionMapper $mapper = null,
        private ?PlayHistoryRepository $history = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $client = $this->client ?? new JellyfinClient();
        $mapper = $this->mapper ?? new JellyfinSessionMapper($client->baseUrl());
        $mapped = $mapper->map($client->sessions());
        /** @var array<int, array<string, mixed>> $streams */
        $streams = $mapped['streams'];
        $watchToday = 0;

        try {
            $history = $this->history ?? new PlayHistoryRepository();
            $history->logActiveStreams($streams);
            $watchToday = $history->watchTimeToday();
        } catch (\Throwable $e) {
            Log::logException($e);
        }

        return [
            'streams' => $streams,
            'hidden_count' => $mapped['hidden_count'],
            'hidden_sources' => $mapped['hidden_sources'],
            'stats' => $this->stats($streams, $watchToday),
            'refreshed_at' => gmdate('c'),
        ];
    }

    /**
     * Fetch the current sessions and persist any active plays, without building
     * the full display payload. Used by the background poller (bin/console.php
     * history:poll) so history is recorded even when no one has the dashboard
     * open. Returns the number of active streams seen.
     */
    public function recordActivePlays(): int
    {
        $client = $this->client ?? new JellyfinClient();
        $mapper = $this->mapper ?? new JellyfinSessionMapper($client->baseUrl());
        /** @var array<int, array<string, mixed>> $streams */
        $streams = $mapper->map($client->sessions())['streams'];

        ($this->history ?? new PlayHistoryRepository())->logActiveStreams($streams);

        return count($streams);
    }

    /**
     * @param array<int, array<string, mixed>> $streams
     * @return array<string, mixed>
     */
    private function stats(array $streams, int $watchToday): array
    {
        $users = [];
        $bitrate = 0;
        $transcodes = 0;

        foreach ($streams as $stream) {
            $users[(string) ($stream['user'] ?? '')] = true;
            $bitrate += (int) ($stream['bitrate'] ?? 0);
            if (($stream['isTranscode'] ?? false) === true) {
                $transcodes++;
            }
        }

        return [
            'watch_today' => $this->durationLabel($watchToday),
            'active_streams' => count($streams),
            'active_users' => count(array_filter(array_keys($users))),
            'bandwidth_mbps' => number_format($bitrate / 1000000, 1, '.', ''),
            'transcodes' => $transcodes,
        ];
    }

    private function durationLabel(int $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $remainingMinutes . 'm';
    }
}
