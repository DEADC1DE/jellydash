<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers\QbittorrentProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QbittorrentSessionTest extends TestCase
{
    #[DataProvider('acceptedCookies')]
    public function testAcceptedLoginSendsTheCompleteCookie(string $name, string $sid, int $status): void
    {
        $transport = $this->transport(new HttpResponse($status, $status === 200 ? 'Ok.' : '', [
            'set-cookie' => [$name . '=' . $sid . '; Path=/; HttpOnly'],
        ]), $name . '=' . $sid);

        $result = (new QbittorrentProvider($transport))->testConnection($this->connection(), $this->credentials());

        self::assertSame('5.1.2 (Web API 2.11.4)', $result['version']);
        self::assertCount(5, $transport->requests);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function acceptedCookies(): iterable
    {
        yield 'long ASP.NET SID' => ['SID', str_repeat('Ab+c/def', 78), 200];
        yield 'maximum SID' => ['SID', str_repeat('a', 4096), 200];
        yield 'QBT SID' => ['QBT_SID', 'session_12345678', 200];
        yield 'port SID and padding' => ['QBT_SID_8080', 'ab+c/defgh==', 204];
    }

    #[DataProvider('sessionCookies')]
    public function testCollectionReusesTheCompleteLongSessionWithoutAnotherLogin(string $sid): void
    {
        $transport = $this->transport(new HttpResponse(200, 'Ok.', [
            'set-cookie' => ['SID=' . $sid . '; Path=/'],
        ]), 'SID=' . $sid);
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);

        $first = $provider->collect($this->connection(), $this->credentials());
        $second = $provider->collect($this->connection(), $this->credentials(), $first->cursor, $first->session);

        self::assertSame($sid, $first->session['sid']);
        self::assertSame($first->session, $second->session);
        self::assertCount(1, array_filter($transport->requests, static fn (HttpRequest $request): bool => $request->method === 'POST'));
        self::assertCount(6, $transport->requests);
    }

    /** @return iterable<string,array{string}> */
    public static function sessionCookies(): iterable
    {
        yield 'long ASP.NET SID' => [str_repeat('Ab+c/def', 78)];
        yield 'maximum SID' => [str_repeat('a', 4096)];
    }

    #[DataProvider('unusableCookies')]
    public function testAcceptedLoginWithNoUsableCookieReportsSessionFailure(?string $cookie): void
    {
        $transport = $this->transport(new HttpResponse(200, 'Ok.', $cookie === null ? [] : [
            'set-cookie' => [$cookie],
        ]));

        try {
            (new QbittorrentProvider($transport))->testConnection($this->connection(), $this->credentials());
            self::fail('An unusable login session must be rejected.');
        } catch (ProviderException $exception) {
            self::assertSame('session_unavailable', $exception->reason);
            self::assertSame('The client accepted the login but did not return a usable session cookie.', $exception->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }

    /** @return iterable<string,array{?string}> */
    public static function unusableCookies(): iterable
    {
        yield 'missing cookie' => [null];
        yield 'over limit' => ['SID=' . str_repeat('a', 4097) . '; Path=/'];
        yield 'too short' => ['SID=short; Path=/'];
        yield 'space' => ['SID=unsafe value; Path=/'];
        yield 'line break' => ["SID=unsafe\r\nvalue; Path=/"];
        yield 'quote' => ['SID=unsafe"value; Path=/'];
        yield 'unrecognized name' => ['OTHER_SID=session_12345678; Path=/'];
        yield 'oversized port' => ['QBT_SID_123456=session_12345678; Path=/'];
    }

    #[DataProvider('rejectedLogins')]
    public function testRejectedLoginStillReportsAuthenticationFailure(int $status, string $body): void
    {
        $transport = $this->transport(new HttpResponse($status, $body));

        try {
            (new QbittorrentProvider($transport))->testConnection($this->connection(), $this->credentials());
            self::fail('A rejected login must fail authentication.');
        } catch (ProviderException $exception) {
            self::assertSame('authentication_failed', $exception->reason);
            self::assertSame('The client rejected the credentials.', $exception->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }

    /** @return iterable<string,array{int,string}> */
    public static function rejectedLogins(): iterable
    {
        yield 'failed body' => [200, 'Fails.'];
        yield 'unauthorized' => [401, ''];
        yield 'forbidden' => [403, ''];
    }

    public function testNoContentLoginWithoutCookieStillSupportsTrustedIpAndSessionReuse(): void
    {
        $transport = $this->transport(new HttpResponse(204, ''));
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);

        $first = $provider->collect($this->connection(), $this->credentials());
        $second = $provider->collect($this->connection(), $this->credentials(), $first->cursor, $first->session);

        self::assertSame('', $first->session['sid']);
        self::assertSame('', $first->session['cookie_name']);
        self::assertSame($first->session, $second->session);
        self::assertCount(1, array_filter($transport->requests, static fn (HttpRequest $request): bool => $request->method === 'POST'));
    }

    #[DataProvider('unsafeCachedSessions')]
    public function testUnusableCachedCookieIsReplacedBeforeSendingReads(string $name, string $sid): void
    {
        $transport = $this->transport(new HttpResponse(200, 'Ok.', [
            'set-cookie' => ['SID=replacement_12345; Path=/'],
        ]), 'SID=replacement_12345');

        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect(
            $this->connection(), $this->credentials(), [], [
                'sid' => $sid, 'cookie_name' => $name, 'expires_at' => 1500,
                'endpoint_hash' => hash('sha256', 'http://qb.test'),
            ],
        );

        self::assertSame('POST', $transport->requests[0]->method);
        self::assertSame('replacement_12345', $batch->session['sid']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function unsafeCachedSessions(): iterable
    {
        yield 'over limit' => ['SID', str_repeat('a', 4097)];
        yield 'unsafe value' => ['SID', "unsafe\r\nvalue"];
        yield 'unsafe name' => ["SID\r\nOther", 'session_12345678'];
    }

    private function connection(): Connection
    {
        return new Connection('test-id', 'qbittorrent', 'Test', 'http://qb.test', revision: 1, hasSecret: true);
    }

    /** @return array{username:string,secret:string} */
    private function credentials(): array
    {
        return ['username' => 'admin', 'secret' => 'test-password'];
    }

    private function transport(HttpResponse $login, ?string $cookie = null): QbittorrentSessionTransport
    {
        return new QbittorrentSessionTransport(static function (HttpRequest $request) use ($login, $cookie): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return $login;
            }
            $cookies = array_values(array_filter($request->headers, static fn (string $header): bool => str_starts_with($header, 'Cookie:')));
            self::assertSame($cookie === null ? [] : ['Cookie: ' . $cookie], $cookies);
            return match ($path) {
                '/api/v2/app/version' => new HttpResponse(200, 'v5.1.2'),
                '/api/v2/app/webapiVersion' => new HttpResponse(200, '2.11.4'),
                '/api/v2/torrents/categories', '/api/v2/torrents/tags',
                '/api/v2/transfer/info', '/api/v2/torrents/info' => new HttpResponse(200, '[]'),
                default => self::fail('Unexpected qBittorrent request.'),
            };
        });
    }
}

final class QbittorrentSessionTransport implements DownloaderTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    private Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        return ($this->handler)($request);
    }

    public function requestMany(array $requests): array
    {
        return array_map(fn (HttpRequest $request): HttpResponse => $this->request($request), $requests);
    }
}
