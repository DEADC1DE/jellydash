<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Controller;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Modules\Users\Invite\InviteManager;
use Mk\Modules\Users\Invite\RedeemError;

/**
 * Public, no-login page where an invitee redeems a code: picks username and
 * password, and the InviteManager provisions the Jellyfin account. Deliberate
 * minimal template (no sidebar, no stats) — this page is meant for strangers.
 *
 * Only InviteManager validation refusals (RedeemError) reach the visitor
 * verbatim; anything else renders a generic note so internals (paths, HTTP
 * codes) never leak on a public page.
 */
final class JoinController extends Controller
{
    public function handle(): void
    {
        // ?code= on the GET link, hidden field on the POST back.
        $code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
        $error = null;
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            $manager = new InviteManager();
            try {
                $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'))[0]);
                if (!$manager->withinRateLimit($ip)) {
                    throw new RedeemError('Too many attempts. Please try again later.');
                }
                $manager->redeem($code, $_POST);
                $success = true;
            } catch (RedeemError $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                Log::logException($e);
                $error = 'The invitation could not be redeemed right now. Please try again later.';
            }
        }

        $this->render('@users/join', [
            'code' => $code,
            'error' => $error,
            'success' => $success,
        ]);
    }
}
