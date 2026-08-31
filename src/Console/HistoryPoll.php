<?php

declare(strict_types=1);

namespace Mk\Framework\Console;

final class HistoryPoll
{
    /**
     * @param \Closure(): int                 $recordActivePlays
     * @param \Closure(): int                 $dispatchNotifications
     * @param \Closure(\Throwable): void      $logException
     * @param \Closure(string): void           $write
     */
    public function __construct(
        private \Closure $recordActivePlays,
        private \Closure $dispatchNotifications,
        private \Closure $logException,
        private \Closure $write,
    ) {
    }

    public function run(): void
    {
        try {
            $logged = ($this->recordActivePlays)();
            if ($logged > 0) {
                ($this->write)(" history:poll - recorded {$logged} active stream(s)");
            }
        } catch (\Throwable $error) {
            ($this->logException)($error);
        }

        $alerts = ($this->dispatchNotifications)();
        if ($alerts > 0) {
            ($this->write)(" history:poll - sent {$alerts} playback alert(s)");
        }
    }
}
