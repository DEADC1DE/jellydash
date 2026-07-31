<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Jellyseerr\JellyseerrClient;
use Mk\Framework\Jellyseerr\RequestMapper;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Log;

/**
 * Latest Jellyseerr requests. Reads the local mirror the poller keeps in sync,
 * so the page never waits on the Jellyseerr API, and still renders the last
 * known state if Jellyseerr is down.
 */
final class JellyseerrController extends Controller
{
    private const CARD_LIMIT = 24;

    public function handle(): void
    {
        $configured = (new JellyseerrClient())->isConfigured();
        $requests = [];
        $error = null;

        if ($configured) {
            try {
                $requests = $this->cards((new SeerrRequestRepository())->latest(self::CARD_LIMIT));
            } catch (\Throwable $e) {
                Log::logException($e);
                $error = 'Could not load requests.';
            }
        }

        $this->render('jellyseerr/index', [
            'layout' => $this->layout([
                'title' => 'Jellyseerr',
                'page' => 'jellyseerr',
            ]),
            'configured' => $configured,
            'error' => $error,
            'requests' => $requests,
            'summary' => $this->summary($requests),
        ]);
    }

    /**
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            $status = RequestMapper::status(
                (int) $row['request_status'],
                (int) $row['media_status']
            );

            $isTv = (string) $row['media_type'] === 'tv';
            $requester = trim((string) ($row['requested_by'] ?? '')) ?: 'Unknown';
            $seasons = $row['season_count'] !== null ? (int) $row['season_count'] : 0;

            $cards[] = [
                'title' => (string) $row['title'],
                'year' => $row['year'] !== null ? (string) $row['year'] : null,
                'poster' => RequestMapper::posterUrl($row['poster_path'] !== null ? (string) $row['poster_path'] : null),
                'type_label' => $isTv ? 'Series' : 'Movie',
                'is_tv' => $isTv,
                'is_4k' => (int) $row['is_4k'] === 1,
                'seasons' => $isTv && $seasons > 0 ? $seasons : null,
                'requester' => $requester,
                'initials' => RequestMapper::initials($requester),
                'status_label' => $status['label'],
                'status_kind' => $status['kind'],
                'requested_ago' => $this->timeAgo((string) $row['requested_at']),
            ];
        }

        return $cards;
    }

    /**
     * Footer counters.
     *
     * @param array<int, array<string, mixed>> $cards
     * @return array<string, int|string>
     */
    private function summary(array $cards): array
    {
        $available = 0;
        $requested = 0;
        $pending = 0;

        foreach ($cards as $card) {
            match ($card['status_kind']) {
                'available', 'partial' => $available++,
                'requested' => $requested++,
                'pending' => $pending++,
                default => null,
            };
        }

        return [
            'total' => count($cards),
            'available' => $available,
            'requested' => $requested,
            'pending' => $pending,
        ];
    }

    private function timeAgo(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }

        try {
            $seconds = time() - (new \DateTimeImmutable($datetime))->getTimestamp();
        } catch (\Exception) {
            return '';
        }

        if ($seconds < 0) {
            return 'just now';
        }
        if ($seconds < 60) {
            return $seconds . 's ago';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ago';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ago';
        }

        return intdiv($seconds, 86400) . 'd ago';
    }
}
