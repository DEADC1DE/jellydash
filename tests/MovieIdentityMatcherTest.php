<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\MovieIdentityMatcher;
use PHPUnit\Framework\TestCase;

final class MovieIdentityMatcherTest extends TestCase
{
    public function testExactProviderWithCompatibleEvidenceAllowsAutomaticRecovery(): void
    {
        $source = ['serverId' => 'server', 'providers' => ['tmdb' => '123'], 'year' => 2020, 'library' => 'Movies'];
        $target = ['serverId' => 'server', 'providers' => ['tmdb' => '123'], 'year' => 2020, 'library' => 'Movies'];
        self::assertSame('automatic', (new MovieIdentityMatcher())->match($source, $target));
    }

    public function testSharedProviderConflictOverridesAnExactOtherProvider(): void
    {
        self::assertNull((new MovieIdentityMatcher())->match(
            ['serverId' => 'server', 'providers' => ['tmdb' => '123', 'imdb' => 'tt1']],
            ['serverId' => 'server', 'providers' => ['tmdb' => '123', 'imdb' => 'tt2']],
        ));
    }

    public function testLegacyTitleIsOnlyASuggestionAndRemakesStayDistinct(): void
    {
        $matcher = new MovieIdentityMatcher();
        self::assertSame('suggestion', $matcher->match(
            ['serverId' => 'server', 'title' => 'Example Movie'],
            ['serverId' => 'server', 'title' => 'example movie'],
        ));
        self::assertNull($matcher->match(
            ['serverId' => 'server', 'title' => 'Example Movie', 'year' => 1990],
            ['serverId' => 'server', 'title' => 'Example Movie', 'year' => 2020],
        ));
    }

    public function testServerLibraryAndMetadataConflictsPreventAutomaticLinking(): void
    {
        $matcher = new MovieIdentityMatcher();
        $source = ['serverId' => 'server', 'providers' => ['tmdb' => '123'], 'library' => 'Movies'];
        self::assertNull($matcher->match($source, ['serverId' => 'other', 'providers' => ['tmdb' => '123']]));
        self::assertNull($matcher->match($source, ['serverId' => 'server', 'providers' => ['tmdb' => '123'], 'library' => 'Other']));
        self::assertSame('suggestion', $matcher->match($source + ['conflicted' => true],
            ['serverId' => 'server', 'providers' => ['tmdb' => '123']]));
    }

    public function testUnknownOrCollectionProviderDoesNotProveMovieIdentity(): void
    {
        self::assertNull((new MovieIdentityMatcher())->match(
            ['serverId' => 'server', 'providers' => ['collection' => 'same-group']],
            ['serverId' => 'server', 'providers' => ['collection' => 'same-group']],
        ));
        self::assertSame('suggestion', (new MovieIdentityMatcher())->match(
            ['serverId' => 'server', 'title' => 'Example Movie', 'providers' => ['plugin' => 'same-value']],
            ['serverId' => 'server', 'title' => 'Example Movie', 'providers' => ['plugin' => 'same-value']],
        ));
    }
}
