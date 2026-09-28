<?php

declare(strict_types=1);

namespace Mk\Modules\Users\Invite;

/**
 * A redeem refusal that is safe (and useful) to show on the public join
 * page: bad code, taken username, password mismatch. Any other failure —
 * Jellyfin unreachable, HTTP errors — must NOT carry its message to
 * strangers; JoinController renders a generic note for those instead.
 */
final class RedeemError extends \RuntimeException
{
}
