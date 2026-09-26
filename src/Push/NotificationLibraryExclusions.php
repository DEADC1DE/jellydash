<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Mk\Framework\AppSettings;
use Mk\Framework\Config;

/**
 * Library names for playback alerts. Settings use JSON so a library name can
 * contain commas; the environment fallback and manual field accept CSV.
 */
final class NotificationLibraryExclusions
{
    /** @return array<int, string> */
    public static function names(bool $requireAvailable = false): array
    {
        $stored = AppSettings::get('push_ignore_libraries', null, $requireAvailable);
        if ($stored === null) {
            return self::parseCsv((string) Config::get('PUSH_IGNORE_LIBRARIES', ''));
        }
        if ($stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \UnexpectedValueException('Invalid notification library exclusions setting.');
        }
        foreach ($decoded as $name) {
            if (!is_string($name)) {
                throw new \UnexpectedValueException('Invalid notification library exclusions setting.');
            }
        }

        return self::normalize($decoded);
    }

    /**
     * @param array<int, mixed> $checked
     * @return array<int, string>
     */
    public static function fromForm(array $checked, string $additional): array
    {
        return self::normalize([...$checked, ...self::parseCsv($additional)]);
    }

    /** @param array<int, string> $names */
    public static function storedValue(array $names): string
    {
        return json_encode(self::normalize($names), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<int, string> $names */
    public static function csvFieldValue(array $names): string
    {
        return implode(',', array_map(static function (string $name): string {
            if (!str_contains($name, ',') && !str_contains($name, '"')) {
                return $name;
            }

            return '"' . str_replace('"', '""', $name) . '"';
        }, $names));
    }

    /** @return array<int, string> */
    private static function parseCsv(string $raw): array
    {
        return trim($raw) === '' ? [] : self::normalize(str_getcsv($raw, ',', '"', ''));
    }

    /**
     * @param array<int, mixed> $names
     * @return array<int, string>
     */
    private static function normalize(array $names): array
    {
        $unique = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 128) {
                continue;
            }
            $unique[mb_strtolower($name)] = $unique[mb_strtolower($name)] ?? $name;
        }

        return array_values($unique);
    }
}
