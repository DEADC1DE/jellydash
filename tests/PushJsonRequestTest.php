<?php

declare(strict_types=1);

use Mk\Framework\Push\PushJsonRequest;
use PHPUnit\Framework\TestCase;

final class PushJsonRequestTest extends TestCase
{
    public function testSmallJsonObjectCanBeRead(): void
    {
        $stream = $this->stream('{"scope":"current"}');
        try {
            $this->assertSame(['scope' => 'current'], PushJsonRequest::decodeStream($stream, '19'));
        } finally {
            fclose($stream);
        }
    }

    public function testBodyLimitRejectsDeclaredAndStreamedOversize(): void
    {
        $stream = $this->stream('{}');
        try {
            $this->expectException(\LengthException::class);
            PushJsonRequest::decodeStream($stream, '99999999');
        } finally {
            fclose($stream);
        }
    }

    public function testStreamCapWorksWithoutContentLength(): void
    {
        $stream = $this->stream('{"value":"' . str_repeat('a', 20_000) . '"}');
        try {
            $this->expectException(\LengthException::class);
            PushJsonRequest::decodeStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testInvalidJsonIsRejected(): void
    {
        $stream = $this->stream('{broken');
        try {
            $this->expectException(\JsonException::class);
            PushJsonRequest::decodeStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testPushEndpointsShareTheBoundedJsonAndCsrfContract(): void
    {
        foreach (['subscribe', 'unsubscribe', 'test'] as $name) {
            $source = (string) file_get_contents(ROOT_DIR . '/public/api/push/' . $name . '.php');
            $this->assertStringContainsString('PushJsonRequest::decode()', $source, $name);
            $this->assertStringContainsString('Csrf::validateHeader()', $source, $name);
            $this->assertStringContainsString("header('Allow: POST')", $source, $name);
            $this->assertStringContainsString('http_response_code(413)', $source, $name);
            $this->assertStringContainsString('Refresh the page and try again.', $source, $name);
        }
    }

    public function testPushEndpointsReturnJsonForMethodAndCsrfErrors(): void
    {
        foreach (['subscribe', 'unsubscribe', 'test'] as $name) {
            foreach (['GET' => 405, 'POST' => 419] as $method => $status) {
                $code = <<<'PHP'
                    $_SERVER['REQUEST_METHOD'] = $argv[2];
                    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
                    $_SERVER['REQUEST_URI'] = '/api/push/' . basename($argv[1]);
                    $_SERVER['SERVER_PORT'] = '80';
                    register_shutdown_function(static function (): void {
                        echo "\nPROBE:", http_response_code();
                    });
                    require $argv[1];
                    PHP;
                $process = proc_open(
                    [PHP_BINARY, '-r', $code, ROOT_DIR . '/public/api/push/' . $name . '.php', $method],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    ROOT_DIR,
                    array_replace(getenv(), [
                        'APP_ENV' => 'testing', 'AUTH_ENABLED' => 'false', 'FORCE_HTTPS' => 'false',
                        'DB_DRIVER' => 'sqlite3', 'DB_NAME' => ':memory:',
                    ]),
                );
                self::assertIsResource($process);
                $output = (string) stream_get_contents($pipes[1]);
                $errors = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                [$body, $actualStatus] = explode("\nPROBE:", $output, 2);
                self::assertSame($status, (int) $actualStatus, $name . '/' . $method);
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                self::assertIsString($decoded['error'] ?? null);
                if ($status === 419) {
                    self::assertStringContainsString('Refresh the page and try again.', $decoded['error']);
                }
            }
        }
    }

    public function testDockerCapsPushRequestBodiesBeforePhp(): void
    {
        $config = (string) file_get_contents(ROOT_DIR . '/docker/apache/000-default.conf');
        $this->assertStringContainsString('<Directory /var/www/html/public/api/push>', $config);
        $this->assertStringContainsString('LimitRequestBody 16384', $config);
    }

    /** @return resource */
    private function stream(string $body)
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $body);
        rewind($stream);

        return $stream;
    }
}
