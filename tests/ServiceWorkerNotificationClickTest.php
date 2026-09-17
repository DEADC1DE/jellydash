<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServiceWorkerNotificationClickTest extends TestCase
{
    public function testNotificationClickPreservesUnrelatedTabsAndFocusesDestination(): void
    {
        exec(sprintf('node %s 2>&1', escapeshellarg(ROOT_DIR . '/tests/frontend/notification-click.test.js')), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('Notification click tests passed.', $output);
    }
}
