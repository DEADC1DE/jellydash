<?php

declare(strict_types=1);

namespace Mk\Framework;

final class SessionStorage
{
    public static function usable(string $directory, ?bool $reportedWritable = null): bool
    {
        if (!is_dir($directory)) {
            return false;
        }
        if ($reportedWritable ?? is_writable($directory)) {
            return true;
        }

        // Windows may report a directory with the ReadOnly attribute as
        // unwritable even when session files can be created inside it.
        $probe = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.jellydash-session-probe-' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return @unlink($probe);
    }
}
