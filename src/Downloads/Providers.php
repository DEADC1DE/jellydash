<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Downloads\Providers\QbittorrentProvider;
use Mk\Framework\Downloads\Providers\SabnzbdProvider;
use Mk\Framework\Downloads\Providers\TransmissionProvider;
use Mk\Framework\Downloads\Providers\DelugeProvider;
use Mk\Framework\Downloads\Providers\NzbgetProvider;

final class Providers
{
    public const NAMES = ['sabnzbd' => 'SABnzbd', 'qbittorrent' => 'qBittorrent',
        'transmission' => 'Transmission', 'deluge' => 'Deluge', 'nzbget' => 'NZBGet'];

    public static function requiresUsername(string $provider): bool
    {
        return in_array($provider, ['qbittorrent', 'transmission', 'nzbget'], true);
    }

    public static function hasItemSpeed(string $provider): bool
    {
        return in_array($provider, ['qbittorrent', 'transmission', 'deluge'], true);
    }

    public static function get(string $provider): Provider
    {
        return match ($provider) {
            'sabnzbd' => new SabnzbdProvider(),
            'qbittorrent' => new QbittorrentProvider(),
            'transmission' => new TransmissionProvider(),
            'deluge' => new DelugeProvider(),
            'nzbget' => new NzbgetProvider(),
            default => throw new \InvalidArgumentException('Unsupported download client.'),
        };
    }
}
