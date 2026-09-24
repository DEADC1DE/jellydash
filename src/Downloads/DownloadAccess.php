<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Authorization;

final class DownloadAccess
{
    public static function canManage(?Authorization $authorization = null): bool
    {
        return ($authorization ?? new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL);
    }
}
