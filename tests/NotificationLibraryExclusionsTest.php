<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Push\NotificationLibraryExclusions;
use PHPUnit\Framework\TestCase;

final class NotificationLibraryExclusionsTest extends TestCase
{
    public function testSelectedAndManualLibraryNamesWithCommasRoundTrip(): void
    {
        $names = NotificationLibraryExclusions::fromForm(
            ['Music, Podcasts'],
            'Movies,"Radio, Podcasts"'
        );

        self::assertSame(['Music, Podcasts', 'Movies', 'Radio, Podcasts'], $names);
        self::assertSame(
            ['Music, Podcasts', 'Movies', 'Radio, Podcasts'],
            NotificationLibraryExclusions::fromForm([], NotificationLibraryExclusions::csvFieldValue($names))
        );
    }

    public function testEnvironmentFallbackAndSavedEmptySelectionStaySeparate(): void
    {
        $previous = getenv('PUSH_IGNORE_LIBRARIES');
        putenv('PUSH_IGNORE_LIBRARIES="Music, Podcasts"');
        try {
            AppSettings::set('push_ignore_libraries', null);
            self::assertSame(['Music, Podcasts'], NotificationLibraryExclusions::names(true));

            AppSettings::set('push_ignore_libraries', NotificationLibraryExclusions::storedValue([]));
            self::assertSame([], NotificationLibraryExclusions::names(true));
        } finally {
            AppSettings::set('push_ignore_libraries', null);
            putenv($previous === false ? 'PUSH_IGNORE_LIBRARIES' : 'PUSH_IGNORE_LIBRARIES=' . $previous);
        }
    }
}
