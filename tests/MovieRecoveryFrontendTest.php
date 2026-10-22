<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MovieRecoveryFrontendTest extends TestCase
{
    public function testMovieRecoveryActionsPollingPagingAndErrors(): void
    {
        $script = ROOT_DIR . '/tests/frontend/movie-recovery.test.js';
        exec(sprintf('node %s 2>&1', escapeshellarg($script)), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertContains('Movie recovery frontend tests passed.', $output);
    }
}
