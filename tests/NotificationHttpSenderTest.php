<?php

declare(strict_types=1);

use Mk\Framework\Notifications\HttpSender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationHttpSenderTest extends TestCase
{
    private string $directory;
    private mixed $process = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jellydash-http-' . bin2hex(random_bytes(8));
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

    public function testRealJsonRequestPreservesHeadersAndPayload(): void
    {
        $url = $this->start('success');
        $payload = ['title' => '🎬 Žofie', 'message' => "A\n\"B\"", 'topic' => 'test'];
        $result = HttpSender::postJson($url . '/prefix/', $payload, ['Authorization' => 'Bearer test-token']);
        self::assertSame(200, $result['status']);
        self::assertSame('test-id', json_decode($result['body'], true)['id']);
        $request = $this->request();
        self::assertSame('POST /prefix/ HTTP/1.1', $request['line']);
        self::assertSame('Bearer test-token', $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame($payload, json_decode($request['body'], true));
    }

    public function testExistingFormTransportStillEncodesAndReturnsResponse(): void
    {
        $result = HttpSender::postForm($this->start('success') . '/form', ['text' => 'A & B', 'chat_id' => '123']);
        self::assertSame(200, $result['status']);
        $request = $this->request();
        self::assertSame('application/x-www-form-urlencoded', $request['headers']['content-type']);
        self::assertSame('text=A+%26+B&chat_id=123', $request['body']);
    }

    public function testRedirectDoesNotForwardTokenToAnotherRequest(): void
    {
        $result = HttpSender::postJson($this->start('redirect'), ['message' => 'Test'], ['X-Gotify-Key' => 'test-token']);
        self::assertSame(302, $result['status']);
        self::assertSame(0, proc_close($this->process));
        $this->process = null;
        self::assertFileDoesNotExist($this->directory . '/followed');
    }

    #[DataProvider('responses')]
    public function testFailuresAndLimits(string $mode, int $expected): void
    {
        $started = microtime(true);
        $result = HttpSender::postJson($this->start($mode), ['message' => 'Test']);
        self::assertSame($expected, $result['status']);
        self::assertLessThan(11, microtime(true) - $started);
        self::assertLessThanOrEqual(65536, strlen($result['body']));
    }

    public static function responses(): iterable
    {
        foreach ([401, 403, 429, 500] as $status) {
            yield [(string) $status, $status];
        }
        yield ['large', 0];
        yield ['disconnect', 0];
        yield ['timeout', 0];
    }

    public function testInvalidEncodingHeadersAndProtocolsFailLocally(): void
    {
        self::assertSame(0, HttpSender::postJson('http://127.0.0.1:1', ['bad' => "\xff"])['status']);
        self::assertSame(0, HttpSender::postJson('http://127.0.0.1:1', [], ['Authorization' => "token\r\nOther: x"])['status']);
        self::assertSame(0, HttpSender::postJson('file:///nonexistent', [])['status']);
        self::assertSame(0, HttpSender::postJson('http://127.0.0.1:1', [])['status']);
    }

    public function testUntrustedTlsCertificateRejectsDelivery(): void
    {
        $url = str_replace('http://', 'https://', $this->start('tls'));
        $result = HttpSender::postJson($url, ['message' => 'Test'], ['Authorization' => 'Bearer test-token']);
        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression('/certificate|issuer/i', $result['body']);
        self::assertSame(0, proc_close($this->process));
        $this->process = null;
        self::assertFileDoesNotExist($this->directory . '/request.json');
    }

    private function start(string $mode): string
    {
        $this->process = proc_open([PHP_BINARY, ROOT_DIR . '/tests/fixtures/notification-http-server.php', $this->directory, $mode], [
            0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout', 'w'], 2 => ['file', $this->directory . '/stderr', 'w'],
        ], $pipes);
        self::assertIsResource($this->process);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        while (!is_file($this->directory . '/ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($this->directory . '/ready');

        return 'http://' . trim(file_get_contents($this->directory . '/ready'));
    }

    private function request(): array
    {
        return json_decode(file_get_contents($this->directory . '/request.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
