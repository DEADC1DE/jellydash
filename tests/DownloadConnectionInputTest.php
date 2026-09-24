<?php

declare(strict_types=1);

use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DownloadConnectionInputTest extends TestCase
{
    public function testAdditionalClientAuthAndApiSuffixes(): void
    {
        foreach (['transmission' => '/transmission/rpc', 'deluge' => '/json', 'nzbget' => '/jsonrpc'] as $provider => $suffix) {
            $connection = ConnectionInput::parse([
                'provider' => $provider, 'name' => 'Staging', 'url' => 'https://client.test/base' . $suffix,
                'username' => 'admin', 'filter_mode' => 'selected', 'categories' => ['tv'],
            ]);
            self::assertSame('https://client.test/base', $connection->url);
            self::assertSame($provider === 'deluge' ? '' : 'admin', $connection->username);
        }
    }

    public function testTransmissionMatchesAnyLabelAndUnlabelledExactly(): void
    {
        $connection = new Connection('tr', 'transmission', 'Transmission', 'http://client.test', filterMode: 'selected', categories: ['movies']);
        self::assertTrue($connection->matches(['category' => 'other', 'tags' => ['other', 'movies']]));
        self::assertFalse($connection->matches(['category' => 'movies', 'tags' => ['other']]));
        self::assertFalse($connection->matches(['category' => '', 'tags' => []]));
        $unlabelled = new Connection('tr', 'transmission', 'Transmission', 'http://client.test', filterMode: 'selected', categories: ['']);
        self::assertTrue($unlabelled->matches(['category' => '', 'tags' => []]));
        self::assertFalse($unlabelled->matches(['category' => '', 'tags' => ['movies']]));
        $deluge = new Connection('dl', 'deluge', 'Deluge', 'http://client.test', filterMode: 'selected', categories: ['movies']);
        self::assertFalse($deluge->matches(['category' => '', 'tags' => []]));
    }

    public function testNzbgetSupportsPasswordOnlyAuthentication(): void
    {
        $connection = ConnectionInput::parse(['provider' => 'nzbget', 'name' => 'NZBGet', 'url' => 'http://nzbget.test']);
        self::assertSame('', $connection->username);
        $this->expectException(InvalidArgumentException::class);
        ConnectionInput::parse(['provider' => 'nzbget', 'name' => 'NZBGet', 'url' => 'http://nzbget.test', 'username' => 'invalid:username']);
    }

    public function testPrivateHostsBasePathsAndProviderSuffixAreNormalized(): void
    {
        self::assertSame('http://sab_client:8080/base', ConnectionInput::normalizeUrl('http://SAB_CLIENT:8080/base/'));
        self::assertSame('https://[::1]/base', ConnectionInput::normalizeUrl('https://[::1]:443/base/'));
        $connection = ConnectionInput::parse(['provider' => 'sabnzbd', 'name' => 'Usenet', 'url' => 'http://localhost:8080/sabnzbd/api']);
        self::assertSame('http://localhost:8080/sabnzbd', $connection->url);
        self::assertSame('all', $connection->filterMode);
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsUnsafeOrAmbiguousUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionInput::normalizeUrl($url);
    }

    public static function invalidUrls(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'file:///etc/passwd', 'http://user:pass@localhost', 'http://localhost/?apikey=sample',
            'http://localhost/#fragment', "http://localhost/\npath", 'http://localhost/%0afoo',
            'http://localhost/a/../b', 'http://localhost/%2e%2e/b', 'http://localhost\\other',
            'http://localhost:0', 'http://localhost:70000',
        ]);
    }

    public function testFiltersAreExactCategoryOrTagAndCanIncludeUncategorized(): void
    {
        $c = new Connection('id', 'qbittorrent', 'Torrents', 'http://localhost', filterMode: 'selected', categories: ['radarr', ''], tags: ['jellyfin']);
        self::assertTrue($c->matches(['category' => 'radarr', 'tags' => []]));
        self::assertTrue($c->matches(['category' => 'other', 'tags' => ['jellyfin']]));
        self::assertTrue($c->matches(['category' => '', 'tags' => []]));
        self::assertFalse($c->matches(['category' => 'Radarr', 'tags' => ['Jellyfin']]));
        self::assertFalse($c->matches(['category' => 'radarr-extra', 'tags' => []]));
    }

    public function testLongUnicodeFilterNamesKeepTheirExactIdentity(): void
    {
        $category = str_repeat('ä', 129);
        $tag = str_repeat('t', 129);
        $connection = ConnectionInput::parse([
            'provider' => 'qbittorrent', 'name' => 'Torrents', 'url' => 'http://localhost',
            'username' => 'admin', 'filter_mode' => 'selected',
            'categories' => [$category], 'tags' => [$tag],
        ]);

        self::assertSame([$category], $connection->categories);
        self::assertSame([$tag], $connection->tags);
        self::assertTrue($connection->matches(['category' => $category, 'tags' => []]));
        self::assertTrue($connection->matches(['category' => '', 'tags' => [$tag]]));
        self::assertFalse($connection->matches(['category' => substr($category, 0, -2), 'tags' => []]));
    }

    public function testFilterNamesKeepSignificantSpacesAndRejectOversizedIdentity(): void
    {
        $connection = ConnectionInput::parse([
            'provider' => 'qbittorrent', 'name' => 'Torrents', 'url' => 'http://localhost',
            'username' => 'admin', 'filter_mode' => 'selected', 'categories' => [' spaced '],
        ]);
        self::assertSame([' spaced '], $connection->categories);
        self::assertFalse($connection->matches(['category' => 'spaced', 'tags' => []]));

        $this->expectException(InvalidArgumentException::class);
        ConnectionInput::parse([
            'provider' => 'qbittorrent', 'name' => 'Torrents', 'url' => 'http://localhost',
            'username' => 'admin', 'filter_mode' => 'selected',
            'categories' => [str_repeat('x', 257)],
        ]);
    }

    public function testEmptySelectedFiltersDoNotTurnIntoAllDownloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionInput::parse(['provider' => 'sabnzbd', 'name' => 'Usenet', 'url' => 'http://localhost', 'filter_mode' => 'selected']);
    }

    public function testSabRejectsTorrentTags(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionInput::parse(['provider' => 'sabnzbd', 'name' => 'Usenet', 'url' => 'http://localhost', 'tags' => ['jellyfin']]);
    }
}
