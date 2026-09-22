<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/users/src/InviteClient.php';

use Mk\Modules\Users\InviteClient;
use PHPUnit\Framework\TestCase;

final class InviteClientTest extends TestCase
{
    public function testUsersByNameLowercasesAndUnwrapsTheUserList(): void
    {
        $client = new CapturingInviteClient();

        $client->nextResponse = ['users' => [
            ['id' => 1, 'username' => 'Alice', 'expires' => null],
            ['id' => 2, 'username' => 'bob', 'expires' => '2026-10-01T00:00:00'],
        ]];

        $users = $client->usersByName();

        $this->assertSame(['alice', 'bob'], array_keys($users));
        $this->assertSame('2026-10-01T00:00:00', $users['bob']['expires']);
    }

    public function testInvitationsSortNewestFirst(): void
    {
        $client = new CapturingInviteClient();
        $client->nextResponse = ['invitations' => [
            ['id' => 4, 'code' => 'old'],
            ['id' => 9, 'code' => 'new'],
        ]];

        $invitations = $client->invitations();

        $this->assertSame([9, 4], array_column($invitations, 'id'));
    }

    public function testCreateInvitationSeparatesLinkExpiryFromAccessDuration(): void
    {
        $client = new CapturingInviteClient();
        $client->nextResponse = ['invitation' => ['code' => 'JOIN-1']];

        $client->createInvitation(7, null, [2, 3], true, false);

        $this->assertSame('POST', $client->method);
        $this->assertSame('/api/invitations', $client->path);
        $this->assertSame([
            'server_ids' => [1],
            'duration' => 'unlimited',
            'unlimited' => true,
            'library_ids' => [2, 3],
            'allow_downloads' => true,
            'allow_live_tv' => false,
            'expires_in_days' => 7,
        ], $client->body);
    }

    public function testCreateInvitationOmitsLinkExpiryWithoutIt(): void
    {
        $client = new CapturingInviteClient();
        $client->nextResponse = ['invitation' => []];

        $client->createInvitation(null, 30, []);

        $this->assertSame('30', $client->body['duration']);
        $this->assertFalse($client->body['unlimited']);
        $this->assertArrayNotHasKey('expires_in_days', $client->body);
    }

    public function testAccountActionsHitTheRightEndpoints(): void
    {
        $client = new CapturingInviteClient();
        $client->nextResponse = [];

        $client->disableUser(5);
        $this->assertSame(['POST', '/api/users/5/disable', null], [$client->method, $client->path, $client->body]);

        $client->enableUser(6);
        $this->assertSame(['POST', '/api/users/6/enable', null], [$client->method, $client->path, $client->body]);

        $client->extendUser(7, 30);
        $this->assertSame(['POST', '/api/users/7/extend', ['days' => 30]], [$client->method, $client->path, $client->body]);

        $client->resetPassword(8);
        $this->assertSame(['POST', '/api/users/8/reset-password', null], [$client->method, $client->path, $client->body]);

        $client->deleteInvitation(9);
        $this->assertSame(['DELETE', '/api/invitations/9', null], [$client->method, $client->path, $client->body]);
    }
    public function testInvitationUrlPrefixesRelativePathsWithThePublicUrl(): void
    {
        $client = new CapturingInviteClient('https://invite.example.com');

        $this->assertSame('https://invite.example.com/j/ABC123', $client->invitationUrl('/j/ABC123'));
        $this->assertSame('https://invite.example.com/j/ABC123', $client->invitationUrl('j/ABC123'));
    }

    public function testInvitationUrlKeepsAbsoluteUrlsUntouched(): void
    {
        $client = new CapturingInviteClient('https://invite.example.com');

        $this->assertSame('https://other.example.org/i/XYZ', $client->invitationUrl('https://other.example.org/i/XYZ'));
        $this->assertSame('http://other.example.org/i/XYZ', $client->invitationUrl('http://other.example.org/i/XYZ'));
    }

    public function testInvitationUrlReturnsNullWithoutPublicUrlConfigured(): void
    {
        $client = new CapturingInviteClient(null);

        $this->assertNull($client->invitationUrl('/j/ABC123'));
        $this->assertNull($client->invitationUrl(null));
        $this->assertNull($client->invitationUrl('  '));
    }
}

final class CapturingInviteClient extends InviteClient
{
    public string $method = '';
    public string $path = '';
    /** @var array<string, mixed>|null */
    public ?array $body = null;
    /** @var array<string, mixed> */
    public array $nextResponse = [];

    public function __construct(?string $publicUrl = null)
    {
        parent::__construct('http://invite.test', 'token', null, $publicUrl);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $this->method = $method;
        $this->path = $path;
        $this->body = $body;

        return $this->nextResponse;
    }
}
