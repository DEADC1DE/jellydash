<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Notifications\DiscordChannel;
use Mk\Framework\Notifications\NotificationChannel;
use Mk\Framework\Notifications\PushoverChannel;
use Mk\Framework\Notifications\TelegramChannel;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostedNotificationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];
    private TestHandler $logs;

    protected function setUp(): void
    {
        foreach ([
            'TELEGRAM_BOT_TOKEN' => 'private-bot-token', 'TELEGRAM_CHAT_ID' => 'private-chat',
            'PUSHOVER_APP_TOKEN' => 'private-app-token', 'PUSHOVER_USER_KEY' => 'private-user-key',
            'DISCORD_WEBHOOK_URL' => 'https://discord.example.test/private-webhook',
        ] as $key => $value) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        $this->logs = new TestHandler(Level::Info);
        Container::set('logger', new Logger('production-test', [$this->logs]));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Container::reset();
    }

    #[DataProvider('channels')]
    public function testProviderFailureIsLoggedAtProductionLevelWithoutResponseSecrets(string $name, string $class): void
    {
        $channel = $this->channel($class, static fn (): array => [
            'status' => 429, 'body' => 'private-bot-token private-webhook private-message',
        ]);

        self::assertFalse($channel->send(['title' => 'Test', 'body' => 'private-message']));
        self::assertTrue($this->logs->hasErrorRecords(), $name);
        $records = json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('HTTP 429', $records);
        self::assertStringNotContainsString('private-', $records);
    }

    #[DataProvider('channels')]
    public function testLongUnicodeTextIsBoundedBeforeSending(string $name, string $class): void
    {
        $payload = null;
        $channel = $this->channel($class, static function (string $url, array $fields) use (&$payload, $name): array {
            $payload = $fields;

            return ['status' => $name === 'discord' ? 204 : 200, 'body' => ''];
        });

        self::assertTrue($channel->send([
            'title' => str_repeat('🎬', 400),
            'body' => str_repeat('字幕', 5000),
            'absolute_url' => 'https://example.test/history',
        ]));
        self::assertIsArray($payload);
        if ($name === 'telegram') {
            self::assertLessThanOrEqual(4096, mb_strlen($payload['text']));
            self::assertTrue(mb_check_encoding($payload['text'], 'UTF-8'));
            self::assertStringEndsWith('https://example.test/history', $payload['text']);
        } elseif ($name === 'pushover') {
            self::assertLessThanOrEqual(250, mb_strlen($payload['title']));
            self::assertLessThanOrEqual(1024, mb_strlen($payload['message']));
            self::assertTrue(mb_check_encoding($payload['message'], 'UTF-8'));
            self::assertSame('https://example.test/history', $payload['url']);
        } else {
            self::assertLessThanOrEqual(256, mb_strlen($payload['embeds'][0]['title']));
            self::assertLessThanOrEqual(4096, mb_strlen($payload['embeds'][0]['description']));
            self::assertTrue(mb_check_encoding($payload['embeds'][0]['description'], 'UTF-8'));
            self::assertSame('https://example.test/history', $payload['embeds'][0]['url']);
        }
    }

    public function testMalformedDiscordWebhookIsNotUsed(): void
    {
        putenv('DISCORD_WEBHOOK_URL=discord.example.test/private-webhook');
        $called = false;
        $channel = new DiscordChannel(static function () use (&$called): array {
            $called = true;

            return ['status' => 204, 'body' => ''];
        });

        self::assertFalse($channel->isConfigured());
        self::assertFalse($channel->send(['title' => 'Test']));
        self::assertFalse($called);
    }

    /** @return iterable<string, array{string, class-string<NotificationChannel>}> */
    public static function channels(): iterable
    {
        yield 'telegram' => ['telegram', TelegramChannel::class];
        yield 'pushover' => ['pushover', PushoverChannel::class];
        yield 'discord' => ['discord', DiscordChannel::class];
    }

    /** @param class-string<NotificationChannel> $class */
    private function channel(string $class, \Closure $sender): NotificationChannel
    {
        self::assertNotNull((new ReflectionClass($class))->getConstructor(), 'The sender must be injectable to prevent real requests in tests.');

        return new $class($sender);
    }
}
