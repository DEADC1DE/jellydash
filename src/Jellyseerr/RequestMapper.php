<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

/**
 * Translates Jellyseerr's raw API shapes into what the UI shows.
 *
 * A request carries two independent state fields (the approval state and the
 * media's availability state), which together decide the single status pill the
 * card displays.
 */
final class RequestMapper
{
    // MediaRequestStatus
    public const REQUEST_PENDING = 1;
    public const REQUEST_APPROVED = 2;
    public const REQUEST_DECLINED = 3;
    public const REQUEST_FAILED = 4;
    public const REQUEST_COMPLETED = 5;

    // MediaStatus
    public const MEDIA_UNKNOWN = 1;
    public const MEDIA_PENDING = 2;
    public const MEDIA_PROCESSING = 3;
    public const MEDIA_PARTIALLY_AVAILABLE = 4;
    public const MEDIA_AVAILABLE = 5;

    /**
     * Collapse the approval + availability states into one label, using
     * Jellyseerr's own vocabulary. Approval problems (declined/failed/awaiting)
     * win because they need attention; otherwise anything not yet on the server
     * is simply "Requested"; how far along the grab is isn't something we can
     * report accurately, and Jellyseerr doesn't either.
     *
     * @return array{label: string, kind: string}
     */
    public static function status(int $requestStatus, int $mediaStatus): array
    {
        return match ($requestStatus) {
            self::REQUEST_DECLINED => ['label' => 'Declined', 'kind' => 'declined'],
            self::REQUEST_FAILED => ['label' => 'Failed', 'kind' => 'declined'],
            self::REQUEST_PENDING => ['label' => 'Pending approval', 'kind' => 'pending'],
            default => match ($mediaStatus) {
                self::MEDIA_AVAILABLE => ['label' => 'Available', 'kind' => 'available'],
                self::MEDIA_PARTIALLY_AVAILABLE => ['label' => 'Partially available', 'kind' => 'partial'],
                default => ['label' => 'Requested', 'kind' => 'requested'],
            },
        };
    }

    /**
     * Pull the display fields out of a TMDB detail payload. Movies use
     * title/releaseDate; series use name/firstAirDate.
     *
     * @param array<string, mixed> $detail
     * @return array{title: string, year: ?string, posterPath: ?string}
     */
    public static function details(array $detail, string $mediaType): array
    {
        $isTv = $mediaType === 'tv';

        $title = trim((string) ($isTv ? ($detail['name'] ?? '') : ($detail['title'] ?? '')));
        if ($title === '') {
            // Fall back to the other field in case a payload surprises us.
            $title = trim((string) ($detail['title'] ?? $detail['name'] ?? ''));
        }

        $date = trim((string) ($isTv ? ($detail['firstAirDate'] ?? '') : ($detail['releaseDate'] ?? '')));
        $year = preg_match('/^(\d{4})/', $date, $m) === 1 ? $m[1] : null;

        $poster = trim((string) ($detail['posterPath'] ?? ''));

        return [
            'title' => $title !== '' ? $title : 'Unknown title',
            'year' => $year,
            'posterPath' => $poster !== '' ? $poster : null,
        ];
    }

    /**
     * Poster URL on the TMDB CDN. `w342` is the smallest size that still looks
     * sharp on a retina card.
     */
    public static function posterUrl(?string $posterPath): ?string
    {
        if ($posterPath === null || $posterPath === '') {
            return null;
        }

        return 'https://image.tmdb.org/t/p/w342' . $posterPath;
    }

    /**
     * Up to two initials for the requester avatar bubble.
     */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) === 1) {
            return $first;
        }

        return $first . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
