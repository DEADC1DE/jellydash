<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Database;
use Mk\Framework\Downloads\DownloadAccess;
use Mk\Framework\Downloads\TestReceipts;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\TestCase;

final class DownloadsManagementGuardTest extends TestCase
{
    public function testOpenDashboardCanManageWithoutAnAccount(): void
    {
        $previous = getenv('AUTH_ENABLED');
        $previousSession = $_SESSION ?? [];
        putenv('AUTH_ENABLED=false');
        $db = Database::sqlite(':memory:');
        $db->ensureAuthSchema();
        $_SESSION = [];
        try {
            $auth = new Authorization($db);
            self::assertTrue(DownloadAccess::canManage($auth));
            self::assertNull($auth->verifiedUser());
        } finally {
            $_SESSION = $previousSession;
            putenv($previous === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previous);
            $db->getDibi()->disconnect();
        }
    }

    public function testProtectedDashboardUsesGlobalSettingsPermissionsAndHonorsDemotion(): void
    {
        $previous = getenv('AUTH_ENABLED');
        $previousSession = $_SESSION ?? [];
        putenv('AUTH_ENABLED=true');
        $db = Database::sqlite(':memory:');
        $db->ensureAuthSchema();
        $_SESSION = [];
        try {
            $auth = new Authorization($db);
            self::assertFalse(DownloadAccess::canManage($auth));
            $id = $db->addAuthUser('download-owner', 'test-password-123', 'Owner', Authorization::ROLE_OWNER);
            self::assertTrue($auth->userLogin('download-owner', 'test-password-123'));
            self::assertTrue(DownloadAccess::canManage($auth));
            $db->getDibi()->update('users', ['role' => Authorization::ROLE_ADMIN])->where('id = %i', $id)->execute();
            self::assertTrue(DownloadAccess::canManage($auth));
            $db->getDibi()->update('users', ['role' => Authorization::ROLE_USER])->where('id = %i', $id)->execute();
            self::assertFalse(DownloadAccess::canManage($auth));
            $db->getDibi()->update('users', ['role' => Authorization::ROLE_GUEST])->where('id = %i', $id)->execute();
            self::assertFalse(DownloadAccess::canManage($auth));
            $db->getDibi()->delete('users')->where('id = %i', $id)->execute();
            self::assertFalse(DownloadAccess::canManage($auth));
        } finally {
            $_SESSION = $previousSession;
            putenv($previous === false ? 'AUTH_ENABLED' : 'AUTH_ENABLED=' . $previous);
            $db->getDibi()->disconnect();
        }
    }

    public function testTestReceiptIsBoundToActorDestinationCredentialAndExpiry(): void
    {
        $session = [];
        $one = new Connection('one', 'sabnzbd', 'One', 'http://one.local');
        $two = new Connection('two', 'sabnzbd', 'Two', 'http://two.local');
        $credentials = ['username' => '', 'secret' => 'sample-secret'];
        $token = TestReceipts::issue($session, $one, $credentials, 7, 100);
        self::assertStringNotContainsString('sample-secret', json_encode($session));
        self::assertFalse(TestReceipts::consume($session, $token, $two, $credentials, 7, 110));
        self::assertFalse(TestReceipts::consume($session, $token, $one, $credentials, 8, 110));
        self::assertFalse(TestReceipts::consume($session, $token, $one, ['username' => '', 'secret' => 'other'], 7, 110));
        self::assertTrue(TestReceipts::consume($session, $token, $one, $credentials, 7, 110));
        self::assertFalse(TestReceipts::consume($session, $token, $one, $credentials, 7, 111));
        $token = TestReceipts::issue($session, $one, $credentials, 7, 100);
        self::assertFalse(TestReceipts::consume($session, $token, $one, $credentials, 7, 401));
    }

    public function testConnectionTestsAreLimitedWithinAMinute(): void
    {
        $session = [];
        for ($i = 0; $i < 5; ++$i) {
            TestReceipts::reserve($session, 100 + $i);
        }
        $this->expectException(OverflowException::class);
        TestReceipts::reserve($session, 120);
    }

    public function testAnonymousReceiptsAreBoundToTheirBrowserSession(): void
    {
        $session = [];
        $otherSession = [];
        $connection = new Connection('open', 'sabnzbd', 'Open dashboard', 'http://client.local');
        $credentials = ['username' => '', 'secret' => 'test-secret'];
        $token = TestReceipts::issue($session, $connection, $credentials, 0, 100);
        self::assertFalse(TestReceipts::consume($otherSession, $token, $connection, $credentials, 0, 110));
        self::assertFalse(TestReceipts::consume($session, $token, $connection, $credentials, 7, 110));
        self::assertTrue(TestReceipts::consume($session, $token, $connection, $credentials, 0, 110));
        self::assertFalse(TestReceipts::consume($session, $token, $connection, $credentials, 0, 111));
    }
}
