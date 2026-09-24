<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\AppSettings;

final class Feature
{
    /** @phpstan-impure The fresh check can change after another request saves Settings. */
    public static function enabled(bool $fresh = false): bool
    {
        try {
            $value = $fresh
                ? AppSettings::getFresh('downloads_enabled', null, true)
                : AppSettings::get('downloads_enabled', null, true);
        } catch (\RuntimeException) {
            // An unreadable preference must not start collection or expose saved activity.
            return false;
        }

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
