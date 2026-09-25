<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderHttpClient;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\JsonRpcHistoryStream;
use Mk\Framework\Downloads\ProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DownloaderHttpClientTest extends TestCase
{
    public function testHistoryStreamExceedsOrdinaryCapWithoutBufferingBody(): void
    {
        $count = 0;
        $stream = new JsonRpcHistoryStream(static function (array $row) use (&$count): void { ++$count; });
        $response = (new DownloaderHttpClient())->request(new HttpRequest('POST', $this->start('history-stream') . '/jsonrpc',
            body: '{"method":"history","params":[false],"id":1}', historyStream: $stream));
        self::assertSame(6000, $count);
        self::assertGreaterThan(2097152, $stream->bytesReceived());
        self::assertLessThan(100, strlen($response->body));
        self::assertSame(['id' => 1, 'result' => [], 'error' => null], json_decode($response->body, true));
    }

    public function testHistoryStreamPreservesAuthenticationResponse(): void
    {
        $stream = new JsonRpcHistoryStream(static function (array $row): void { self::fail('Must not consume rejected response'); });
        $response = (new DownloaderHttpClient())->request(new HttpRequest('POST', $this->start('history-auth') . '/jsonrpc',
            body: '{"method":"history","params":[false],"id":1}', historyStream: $stream));
        self::assertSame(401, $response->status);
        self::assertSame(0, $stream->bytesReceived());
    }

    public function testHistoryStreamCannotRaiseOtherEndpointsResponseLimits(): void
    {
        $this->expectException(ProviderException::class);
        (new DownloaderHttpClient())->request(new HttpRequest('POST', 'http://127.0.0.1:1/jsonrpc',
            body: '{"method":"status","params":[],"id":1}',
            historyStream: new JsonRpcHistoryStream(static function (array $row): void {})));
    }

    private string $directory;
    private mixed $process = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jellydash-downloader-http-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAllowedReadReturnsBoundedBodyAndHeaders(): void
    {
        $url = $this->start('success') . '/api?output=json&apikey=test-key&mode=queue&start=0&limit=1';
        $response = (new DownloaderHttpClient())->request(new HttpRequest('GET', $url));

        self::assertSame(200, $response->status);
        self::assertSame('{"queue":{"slots":[]}}', $response->body);
        self::assertSame(['safe'], $response->header('X-Test'));
        self::assertStringContainsString('GET /api?', (string) file_get_contents($this->directory . '/request'));
    }

    public function testRedirectIsReturnedWithoutFollowingIt(): void
    {
        $url = $this->start('redirect') . '/api?output=json&apikey=test-key&mode=queue&start=0&limit=1';
        $response = (new DownloaderHttpClient())->request(new HttpRequest('GET', $url));

        self::assertSame(302, $response->status);
        self::assertSame(['/api?mode=queue'], $response->header('location'));
        self::assertSame(0, proc_close($this->process));
        $this->process = null;
    }

    public function testOversizeResponseFailsWithSafeReason(): void
    {
        $url = $this->start('large') . '/api?output=json&apikey=test-key&mode=queue&start=0&limit=1';
        try {
            (new DownloaderHttpClient())->request(new HttpRequest('GET', $url));
            self::fail('Expected an oversize response failure.');
        } catch (ProviderException $exception) {
            self::assertSame('response_too_large', $exception->reason);
            self::assertStringNotContainsString('test-key', $exception->getMessage());
        }
    }

    public function testIndependentReadsUseTheMultiRequestPath(): void
    {
        $base = $this->start('multi');
        $responses = (new DownloaderHttpClient())->requestMany([
            new HttpRequest('GET', $base . '/api?output=json&apikey=test-key&mode=queue&start=0&limit=1'),
            new HttpRequest('GET', $base . '/api?output=json&apikey=test-key&mode=history&archive=0&start=0&limit=1'),
        ]);

        self::assertSame(['{"kind":"queue"}', '{"kind":"history"}'], array_column($responses, 'body'));
        self::assertSame(0, proc_close($this->process));
        $this->process = null;
        self::assertStringContainsString('mode=queue', (string) file_get_contents($this->directory . '/requests'));
        self::assertStringContainsString('mode=history', (string) file_get_contents($this->directory . '/requests'));
    }

    public function testTimeoutFailsWithSafeReason(): void
    {
        $url = $this->start('timeout') . '/api?output=json&apikey=test-key&mode=queue&start=0&limit=1';
        $started = microtime(true);
        try {
            (new DownloaderHttpClient())->request(new HttpRequest('GET', $url));
            self::fail('Expected a timeout.');
        } catch (ProviderException $exception) {
            self::assertSame('timeout', $exception->reason);
            self::assertLessThan(5.75, microtime(true) - $started);
            self::assertStringNotContainsString('test-key', $exception->getMessage());
        }
    }

    #[DataProvider('unsafeRequests')]
    public function testRejectsUnsafeOrMutatingOperations(HttpRequest $request): void
    {
        try {
            (new DownloaderHttpClient())->request($request);
            self::fail('Expected request validation to fail.');
        } catch (ProviderException $exception) {
            self::assertSame('invalid_response', $exception->reason);
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public static function unsafeRequests(): iterable
    {
        foreach (['/transmission/rpc' => 'torrent-remove', '/json' => 'core.remove_torrent', '/jsonrpc' => 'editqueue'] as $path => $method) {
            yield $method => [new HttpRequest('POST', 'http://127.0.0.1:1' . $path, body: json_encode(['method' => $method, 'params' => []], JSON_THROW_ON_ERROR))];
        }
        yield 'batch cannot smuggle writes' => [new HttpRequest('POST', 'http://127.0.0.1:1/jsonrpc', body: '[{"method":"status"},{"method":"shutdown"}]')];
        yield 'query cannot override RPC' => [new HttpRequest('POST', 'http://127.0.0.1:1/jsonrpc?method=shutdown', body: '{"method":"status","params":[]}')];
        yield 'SAB mutation' => [new HttpRequest('GET', 'http://127.0.0.1:1/api?mode=pause&apikey=secret')];
        yield 'qB mutation' => [new HttpRequest('POST', 'http://127.0.0.1:1/api/v2/torrents/delete', body: 'hashes=all')];
        yield 'credentials in URL' => [new HttpRequest('GET', 'http://user:secret@127.0.0.1/api?mode=queue')];
        yield 'unsupported scheme' => [new HttpRequest('GET', 'file:///tmp/secret')];
        yield 'header injection' => [new HttpRequest('GET', 'http://127.0.0.1:1/api?mode=queue', ["X-Test: ok\r\nX-Secret: secret"])];
    }

    #[DataProvider('allowedRpcReads')]
    public function testRpcReadMethodsPassThroughTransport(string $path, string $body): void
    {
        $response = (new DownloaderHttpClient())->request(new HttpRequest('POST', $this->start('success') . $path,
            ['Content-Type: application/json'], $body));
        self::assertSame(200, $response->status);
    }

    public static function allowedRpcReads(): iterable
    {
        yield ['/' . 'transmission/rpc', '{"method":"torrent-get","arguments":{"fields":["hashString"]},"tag":1}'];
        yield ['/json', '{"method":"core.get_torrents_status","params":[{},["state"]],"id":1}'];
        yield ['/jsonrpc', '{"method":"history","params":[false],"id":1}'];
    }

    private function start(string $mode): string
    {
        $this->process = proc_open([PHP_BINARY, ROOT_DIR . '/tests/fixtures/downloader-http-server.php', $this->directory, $mode], [
            0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout', 'w'], 2 => ['file', $this->directory . '/stderr', 'w'],
        ], $pipes);
        self::assertIsResource($this->process);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        while (!is_file($this->directory . '/ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($this->directory . '/ready');
        return 'http://' . trim((string) file_get_contents($this->directory . '/ready'));
    }
}
