<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Module classes live outside composer's PSR-4 roots; tests load them the
// same way InviteClientTest always has.
require_once __DIR__ . '/../modules/users/src/Invite/InviteRepository.php';
require_once __DIR__ . '/../modules/users/src/Invite/InviteManager.php';
require_once __DIR__ . '/../modules/users/src/Invite/RedeemError.php';

use Mk\Framework\Database;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Modules\Users\Invite\InviteManager;
use Mk\Modules\Users\Invite\InviteRepository;
use PHPUnit\Framework\TestCase;

final class InviteManagerTest extends TestCase
{
    private string $sqlitePath;
    /** @var list<array{path: string, method: string, payload: ?array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'invite-test-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->sqlitePath);
    }

    private function repository(): InviteRepository
    {
        return new InviteRepository(Database::sqlite($this->sqlitePath));
    }

    private function manager(): InviteManager
    {
        $requester = function (string $path, string $method, ?array $payload): mixed {
            $this->requests[] = ['path' => $path, 'method' => $method, 'payload' => $payload];
            if ($path === '/Users' && $method === 'GET') {
                return [['Id' => 'admin-1', 'Name' => 'admin', 'Policy' => []]];
            }
            if ($path === '/Users/New' && $method === 'POST') {
                return ['Id' => 'jf-new-1', 'Name' => (string) ($payload['Name'] ?? '')];
            }
            if ($path === '/Users/admin-1' || $path === '/Users/jf-new-1') {
                return ['Id' => 'jf-new-1', 'Name' => 'x', 'Policy' => ['EnableAllFolders' => true, 'IsAdministrator' => true]];
            }
            return [];
        };

        $client = new JellyfinClient('http://jellyfin.example', 'token', true, $requester);

        return new InviteManager($this->repository(), $client);
    }

    public function testCreateInvitationStoresCodeExpiryAndLibraries(): void
    {
        $manager = $this->manager();

        $invitation = $manager->createInvitation(7, 30, ['lib-1', 'lib-2'], true, false);

        $this->assertMatchesRegularExpression('/^[a-z2-9]{12}$/', (string) $invitation['code']);
        $this->assertSame(['lib-1', 'lib-2'], json_decode((string) $invitation['libraries'], true));
        $this->assertSame(30, (int) $invitation['duration_days']);
        $this->assertSame(1, (int) $invitation['allow_downloads']);
        $this->assertNotSame('1970', substr((string) $invitation['link_expires_at'], 0, 4));
    }

    public function testJoinUrlPrefixesBaseOnlyWhenKnown(): void
    {
        $manager = $this->manager();

        $this->assertSame('/?page=join&code=abc', $manager->joinUrl('abc', null));
        $this->assertSame('https://dash.example.com/?page=join&code=abc', $manager->joinUrl('abc', 'https://dash.example.com'));
    }

    public function testPublicBaseUrlPrefersConfigThenProxyHeaders(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        putenv('APP_PUBLIC_URL=');

        $this->assertNull(InviteManager::publicBaseUrl());

        $_SERVER['HTTP_HOST'] = 'dash.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertSame('https://dash.example.com', InviteManager::publicBaseUrl());

        putenv('APP_PUBLIC_URL=https://invite.example.org');
        $_ENV['APP_PUBLIC_URL'] = 'https://invite.example.org';
        $_SERVER['APP_PUBLIC_URL'] = 'https://invite.example.org';
        $_SERVER['HTTP_HOST'] = 'evil.example.com';
        $this->assertSame('https://invite.example.org', InviteManager::publicBaseUrl());

        putenv('APP_PUBLIC_URL=');
        unset($_ENV['APP_PUBLIC_URL'], $_SERVER['APP_PUBLIC_URL'], $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    public function testRedeemIsRateLimitedPerIp(): void
    {
        $manager = $this->manager();

        $allowed = 0;
        for ($i = 0; $i < 12; $i++) {
            if ($manager->withinRateLimit('203.0.113.7')) {
                $allowed++;
            }
        }
        $this->assertSame(10, $allowed);
        $this->assertFalse($manager->withinRateLimit('203.0.113.7'));
        $this->assertTrue($manager->withinRateLimit('203.0.113.8'));
    }

    public function testFailedProvisioningReleasesTheClaim(): void
    {
        $requester = function (string $path, string $method): mixed {
            if ($path === '/Users' && $method === 'GET') {
                return []; // no username collision
            }
            if ($path === '/Users/New') {
                throw new RuntimeException('Jellyfin request failed with HTTP 500.');
            }
            return [];
        };
        $manager = new InviteManager($this->repository(), new JellyfinClient('http://jellyfin.example', 'token', true, $requester));
        $code = (string) $manager->createInvitation(null, null, [], false, false)['code'];

        try {
            $manager->redeem($code, ['username' => 'user1', 'password' => 'longenough', 'password2' => 'longenough']);
            $this->fail('expected provisioning failure');
        } catch (RuntimeException) {
            // expected
        }

        $row = $this->repository()->invitationByCode($code);
        $this->assertNull($row['used_by'], 'claim must be released so the code stays redeemable');
    }

    public function testRedeemCreatesJellyfinUserWithPolicyAndStartsExpiry(): void
    {
        $manager = $this->manager();
        $manager->createInvitation(null, 90, ['lib-1'], false, true);
        $code = $manager->invitations()[0]['code'];

        $manager->redeem((string) $code, [
            'username' => 'Newbie',
            'password' => 'longenough',
            'password2' => 'longenough',
        ]);

        $policyWrite = null;
        foreach ($this->requests as $request) {
            if (str_ends_with($request['path'], '/Policy')) {
                $policyWrite = $request['payload'];
            }
        }
        $this->assertNotNull($policyWrite);
        $this->assertFalse($policyWrite['IsDisabled']);
        $this->assertFalse($policyWrite['EnableAllFolders']);
        $this->assertSame(['lib-1'], $policyWrite['EnabledFolders']);
        $this->assertTrue($policyWrite['EnableLiveTvAccess']);
        $this->assertFalse($policyWrite['EnableContentDownloading']);
        // The pre-existing admin policy must not be clobbered by the patch.
        // The pre-existing admin policy must not be clobbered by the patch.
        $this->assertTrue((bool) ($policyWrite['IsAdministrator'] ?? false));

        $row = $this->repository()->invitationByCode((string) $code);
        $this->assertSame('Newbie', $row['used_by']);

        $account = $this->repository()->accountByUserId('jf-new-1');
        $this->assertNotNull($account);
        $this->assertSame('Newbie', $account['username']);
        $this->assertNotNull($account['expires_at']);
    }

    public function testRedeemRejectsUsedUnknownAndMismatched(): void
    {
        $manager = $this->manager();
        $manager->createInvitation(null, null, [], false, false);
        $code = (string) $manager->invitations()[0]['code'];

        try {
            $manager->redeem('nope', ['username' => 'x', 'password' => 'longenough', 'password2' => 'longenough']);
            $this->fail('expected invalid code');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not valid', $e->getMessage());
        }

        $manager->redeem($code, ['username' => 'user1', 'password' => 'longenough', 'password2' => 'longenough']);

        try {
            $manager->redeem($code, ['username' => 'other', 'password' => 'longenough', 'password2' => 'longenough']);
            $this->fail('expected used code');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been used', $e->getMessage());
        }

        try {
            $manager->createInvitation(null, null, [], false, false);
            $manager->redeem((string) $manager->invitations()[0]['code'], [
                'username' => 'user2', 'password' => 'longenough', 'password2' => 'different',
            ]);
            $this->fail('expected password mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('do not match', $e->getMessage());
        }
    }

    public function testExtendReenablesAndEnforceDisablesExpired(): void
    {
        $manager = $this->manager();
        $repo = $this->repository();
        $repo->trackAccount('jf-expired', 'user1', date('Y-m-d H:i:s', time() - 86400));

        $this->assertSame(1, $manager->enforceExpiry());

        $policyWrite = null;
        foreach ($this->requests as $request) {
            if (str_ends_with($request['path'], '/Policy')) {
                $policyWrite = $request['payload'];
            }
        }
        $this->assertTrue((bool) $policyWrite['IsDisabled']);
        $this->assertNotNull($repo->accountByUserId('jf-expired')['disabled_at']);

        // Re-enabling directly and via an extension both clear the flag.
        $manager->setDisabled('jf-expired', false);
        $this->assertNull($repo->accountByUserId('jf-expired')['disabled_at']);

        $newExpiry = $manager->extend('jf-expired', 30);
        $this->assertNotNull($newExpiry);
        $this->assertGreaterThan(time(), strtotime((string) $newExpiry));
        $this->assertSame(0, $manager->enforceExpiry());
    }
}
