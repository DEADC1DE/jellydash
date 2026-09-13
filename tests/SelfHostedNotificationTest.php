<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Notifications\GotifyChannel;
use Mk\Framework\Notifications\NotificationDispatcher;
use Mk\Framework\Notifications\NtfyChannel;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SelfHostedNotificationTest extends TestCase
{
    private array $environment = [];
    private TestHandler $logs;

    protected function setUp(): void
    {
        foreach (['NTFY_URL', 'NTFY_TOPIC', 'NTFY_TOKEN', 'GOTIFY_URL', 'GOTIFY_APP_TOKEN', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'APP_URL'] as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=');
        }
        $this->logs = new TestHandler();
        Container::set('logger', new Logger('test', [$this->logs]));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Container::reset();
    }

    public function testDefaultDispatcherReportsBothOptionalChannelsWithoutSending(): void
    {
        $report = (new NotificationDispatcher())->test(['title' => 'Test', 'body' => 'Test']);
        self::assertArrayHasKey('ntfy', $report);
        self::assertArrayHasKey('gotify', $report);
        self::assertSame(['configured' => false, 'sent' => false], $report['ntfy']);
        self::assertSame(['configured' => false, 'sent' => false], $report['gotify']);
    }

    public function testNtfyPostsUnicodeJsonToBaseUrlWithBearerAuthentication(): void
    {
        putenv('NTFY_URL=https://notify.example.test:8443/prefix///');
        putenv('NTFY_TOPIC=jellydash_test');
        putenv('NTFY_TOKEN=private-token');
        $channel = new NtfyChannel(function ($url, $payload, $headers): array {
            self::assertSame('https://notify.example.test:8443/prefix/', $url);
            self::assertSame(['Authorization' => 'Bearer private-token'], $headers);
            self::assertSame(['topic' => 'jellydash_test', 'title' => '▶ Žofie', 'message' => "Film \"A\"\n字幕", 'click' => 'https://dash.example.test/now-playing'], $payload);
            return ['status' => 200, 'body' => '{"id":"abc","event":"message"}'];
        });
        self::assertTrue($channel->isConfigured());
        self::assertTrue($channel->send(['title' => '▶ Žofie', 'body' => "Film \"A\"\n字幕", 'absolute_url' => 'https://dash.example.test/now-playing', 'tag' => 'private-play-id']));
    }

    public function testAnonymousNtfyOmitsAuthAndInvalidClickAndTruncatesByUtf8Bytes(): void
    {
        putenv('NTFY_URL=http://ntfy:80');
        putenv('NTFY_TOPIC=test');
        $channel = new NtfyChannel(function ($url, $payload, $headers): array {
            self::assertSame('http://ntfy:80/', $url);
            self::assertSame([], $headers);
            self::assertArrayNotHasKey('click', $payload);
            self::assertLessThanOrEqual(1024, strlen($payload['title']));
            self::assertLessThanOrEqual(4096, strlen($payload['message']));
            self::assertTrue(mb_check_encoding($payload['message'], 'UTF-8'));
            self::assertStringEndsWith('…', $payload['message']);
            return ['status' => 200, 'body' => '{"id":"abc","event":"message"}'];
        });
        self::assertTrue($channel->send(['title' => str_repeat('🎬', 300), 'body' => str_repeat('字幕', 1000), 'absolute_url' => 'javascript:alert(1)']));
    }

    public function testGotifyUsesApplicationHeaderAndPlainTextClickExtras(): void
    {
        putenv('GOTIFY_URL=http://gotify:8080/prefix/');
        putenv('GOTIFY_APP_TOKEN=app-token');
        $channel = new GotifyChannel(function ($url, $payload, $headers): array {
            self::assertSame('http://gotify:8080/prefix/message', $url);
            self::assertSame(['X-Gotify-Key' => 'app-token'], $headers);
            self::assertSame('Title', $payload['title']);
            self::assertSame('Message', $payload['message']);
            self::assertSame(5, $payload['priority']);
            self::assertSame('text/plain', $payload['extras']['client::display']['contentType']);
            self::assertSame('https://dash.example.test/history', $payload['extras']['client::notification']['click']['url']);
            return ['status' => 200, 'body' => '{"id":123}'];
        });
        self::assertTrue($channel->send(['title' => 'Title', 'body' => 'Message', 'absolute_url' => 'https://dash.example.test/history']));
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationNeverSends(string $key, string $value): void
    {
        putenv('NTFY_URL=https://notify.example.test');
        putenv('NTFY_TOPIC=test');
        putenv('GOTIFY_URL=https://notify.example.test');
        putenv('GOTIFY_APP_TOKEN=app-token');
        putenv($key . '=' . $value);
        $sender = static function (): never {
            self::fail('Invalid configuration must not send.');
        };
        $channel = str_starts_with($key, 'NTFY') ? new NtfyChannel($sender) : new GotifyChannel($sender);
        self::assertFalse($channel->isConfigured());
        self::assertTrue($channel->hasConfiguration());
        self::assertFalse($channel->send(['body' => 'Test']));
    }

    public static function invalidConfigurations(): iterable
    {
        foreach (['file:///tmp/test', 'https://user:pass@host.test', 'https://host.test/?token=x', 'https://host.test/#fragment', "https://host.test/\r\nx", 'https://host.test:99999', 'http://notify:0', 'https://host.test/../x', 'https://host.test/%0a'] as $url) {
            yield ['NTFY_URL', $url];
            yield ['GOTIFY_URL', $url];
        }
        foreach (['', 'with/slash', 'has space', 'file', str_repeat('a', 65)] as $topic) {
            yield ['NTFY_TOPIC', $topic];
        }
        yield ['NTFY_TOKEN', "token\r\nX-Injected: secret"];
        yield ['GOTIFY_APP_TOKEN', "token\nsecret"];
        yield ['GOTIFY_APP_TOKEN', ''];
    }

    #[DataProvider('failedResponses')]
    public function testFailuresAreSanitizedAndNotReportedAsAccepted(int $status, string $body): void
    {
        putenv('NTFY_URL=https://private.example.test');
        putenv('NTFY_TOPIC=private-topic');
        putenv('NTFY_TOKEN=private-token');
        $channel = new NtfyChannel(static fn (): array => ['status' => $status, 'body' => $body]);
        self::assertFalse($channel->send(['body' => 'private-message']));
        $records = json_encode($this->logs->getRecords());
        self::assertStringNotContainsString('private-', $records);
        self::assertTrue($this->logs->hasErrorRecords());
    }

    public static function failedResponses(): iterable
    {
        foreach ([0, 301, 401, 403, 429, 500] as $status) {
            yield [$status, 'private-token private-topic private-message'];
        }
        yield [200, '<html>Login page</html>'];
        yield [200, '{"error":"private-token"}'];
        yield [200, '{"id":"abc","event":"open"}'];
    }

    public function testThrowingProviderDoesNotLeakSecretsOrSuppressGotify(): void
    {
        putenv('NTFY_URL=https://ntfy.invalid');
        putenv('NTFY_TOPIC=test');
        putenv('GOTIFY_URL=https://gotify.invalid');
        putenv('GOTIFY_APP_TOKEN=token');
        putenv('APP_URL=https://dash.example.test');
        $ntfy = new NtfyChannel(static function (): never {
            throw new RuntimeException('private-token');
        });
        $gotify = new GotifyChannel(static function ($url, $payload): array {
            self::assertSame('https://dash.example.test/history', $payload['extras']['client::notification']['click']['url']);
            return ['status' => 200, 'body' => '{"id":1}'];
        });
        self::assertSame(1, (new NotificationDispatcher(channels: [$ntfy, $gotify]))->send(['body' => 'Test', 'url' => '/history']));
        self::assertStringNotContainsString('private-token', json_encode($this->logs->getRecords()));
    }
}
