<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Controller;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Modules\Users\Invite\InviteManager;

/**
 * Public, no-login page where an invitee redeems a code: picks username and
 * password, and the InviteManager provisions the Jellyfin account. Deliberate
 * minimal template (no sidebar, no stats) — this page is meant for strangers.
 */
final class JoinController extends Controller
{
    public function handle(): void
    {
        $code = trim((string) ($_REQUEST['code'] ?? ''));
        $error = null;
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            try {
                (new InviteManager())->redeem($code, $_POST);
                $success = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'The invitation could not be redeemed.';
                Log::logException($e);
            }
        }

        $this->render('@users/join', [
            'code' => $code,
            'error' => $error,
            'success' => $success,
        ]);
    }
}
