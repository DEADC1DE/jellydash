<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Controller;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\StatisticsPeriod;
use Mk\Framework\Log;
use Mk\Framework\Main;
use Mk\Modules\Devices\DeviceService;

final class UsersController extends Controller
{
    private const PAGE_SIZE = 10;

    public function handle(): void
    {
        $selected = trim((string) ($_GET['user'] ?? ''));
        $repository = new UserStatsRepository();

        // Post/Redirect/Get, same pattern as the settings operations: a
        // successful action redirects (so a browser refresh cannot replay the
        // POST), and errors come back as a short-lived query flag rendered by
        // the page below. Csrf::check() exits with 419 on a bad token.
        $wizarrError = Main::captureGetString('wizarr_error');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            try {
                $error = $this->handleWizarrAction();
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'Wizarr action failed.';
                Log::logException($e);
            }

            header('Location: /users' . ($error !== null ? '?' . http_build_query(['wizarr_error' => $error]) . '#invitations' : '#invitations'));
            exit;
        }

        if ($selected !== '') {
            $allDevices = array_values(array_filter(
                (new DeviceService())->list(),
                static fn (array $device): bool => $device['lastUserName'] === $selected
            ));

            $playsPage = max(1, (int) ($_GET['playsPage'] ?? 1));
            $devicesPage = max(1, (int) ($_GET['devicesPage'] ?? 1));

            $playsTotal = $repository->recentPlaysCount($selected);
            $devicesTotal = count($allDevices);

            $recentPlays = array_map(
                function (array $play): array {
                    $play['poster'] = $this->poster(
                        (string) ($play['itemId'] ?? ''),
                        (string) ($play['itemType'] ?? ''),
                        (string) ($play['itemName'] ?? '')
                    );
                    return $play;
                },
                $repository->recentPlays($selected, self::PAGE_SIZE, ($playsPage - 1) * self::PAGE_SIZE)
            );

            $this->render('@users/profile', [
                'layout' => $this->layout(['title' => $selected, 'page' => 'users']),
                'userName' => $selected,
                'summary' => $repository->summaryForUser($selected),
                'heatmap' => $repository->heatmap($selected),
                'recentPlays' => $recentPlays,
                'playsPage' => $playsPage,
                'playsTotalPages' => max(1, (int) ceil($playsTotal / self::PAGE_SIZE)),
                'devices' => array_slice($allDevices, ($devicesPage - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
                'devicesPage' => $devicesPage,
                'devicesTotalPages' => max(1, (int) ceil($devicesTotal / self::PAGE_SIZE)),
            ]);
            return;
        }

        $jellyfinUsers = (new JellyfinClient())->users();
        $rows = array_map(
            fn (array $user): array => array_merge($user, $repository->summaryForUser($user['name'])),
            $jellyfinUsers
        );

        $overview = (new UserRangeOverview())->build(
            StatisticsPeriod::normalizeRange(Main::captureGetString('range')),
            $jellyfinUsers
        );

        // Cover art for the Top Titles table, same recipe as the profile's
        // recent plays: real Jellyfin art over a gradient fallback, series
        // artwork for episodes.
        $overview['titles'] = array_map(
            fn (array $title): array => array_merge($title, [
                'poster' => $this->poster($title['itemId'], $title['isEpisode'] ? 'Episode' : 'Movie', $title['name']),
            ]),
            $overview['titles']
        );

        $wizarr = $this->wizarrState($wizarrError);

        // Wizarr expiry data keyed by lowercase username; Wizarr accounts and
        // Jellyfin accounts are the same accounts, so names are the join key.
        foreach ($rows as &$row) {
            $row['wizarr'] = $wizarr['usersByName'][mb_strtolower((string) $row['name'])] ?? null;
        }
        unset($row);

        $this->render('@users/index', [
            'layout' => $this->layout(['title' => 'Users', 'page' => 'users']),
            'rows' => $rows,
            'overview' => $overview,
            'wizarr' => $wizarr,
        ]);
    }

    /**
     * Wizarr user/invitation data plus the state the template needs to decide
     * between full UI, quiet hint (not configured), or an error note. Any
     * Wizarr failure must never break the Users page itself.
     *
     * @return array{configured: bool, error: string|null, usersByName: array<string, array<string, mixed>>, invitations: array<int, array<string, mixed>>, libraries: array<int, array<string, mixed>>}
     */
    private function wizarrState(?string $error): array
    {
        $state = [
            'configured' => false,
            'error' => $error,
            'usersByName' => [],
            'invitations' => [],
            'libraries' => [],
        ];

        $client = new WizarrClient();
        if (!$client->isConfigured()) {
            return $state;
        }

        $state['configured'] = true;
        if ($error !== null) {
            return $state;
        }

        try {
            $state['usersByName'] = $client->usersByName();
            $state['invitations'] = $client->invitations();
            $state['libraries'] = $client->libraries();
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Wizarr is unreachable.';
            Log::logException($e);
        }

        return $state;
    }

    /**
     * Dispatch the Wizarr form actions POSTed from the Users page. Returns
     * null on success; a message string becomes the page error note.
     */
    private function handleWizarrAction(): ?string
    {
        $client = new WizarrClient();
        if (!$client->isConfigured()) {
            return 'Wizarr is not configured (WIZARR_URL / WIZARR_API_TOKEN missing).';
        }

        $id = (int) ($_POST['id'] ?? 0);

        switch ((string) ($_POST['do'] ?? '')) {
            case 'create-invite':
                $linkExpires = (string) ($_POST['linkExpires'] ?? '');
                $access = (string) ($_POST['access'] ?? 'unlimited');
                $client->createInvitation(
                    $linkExpires !== '' ? (int) $linkExpires : null,
                    $access === 'unlimited' ? null : max(1, (int) $access),
                    array_map('intval', (array) ($_POST['libraries'] ?? [])),
                    ($_POST['downloads'] ?? '') === '1',
                    ($_POST['livetv'] ?? '') === '1',
                );
                return null;
            case 'delete-invite':
                if ($id > 0) {
                    $client->deleteInvitation($id);
                }
                return null;
            case 'disable-user':
                if ($id > 0) {
                    $client->disableUser($id);
                }
                return null;
            case 'enable-user':
                if ($id > 0) {
                    $client->enableUser($id);
                }
                return null;
            case 'extend-user':
                if ($id > 0) {
                    $client->extendUser($id, (int) ($_POST['days'] ?? 30));
                }
                return null;
            case 'reset-password':
                if ($id > 0) {
                    $client->resetPassword($id);
                }
                return null;
            default:
                return 'Unknown Wizarr action.';
        }
    }

    /**
     * Real Jellyfin poster art layered over a colored gradient (shows through
     * while the image loads, or if the item has no artwork) — same recipe as
     * the core History page's poster() so covers look consistent app-wide.
     * The title enables image.php's search fallback for legacy item ids.
     */
    private function poster(string $itemId, string $itemType, string $title = ''): string
    {
        $gradient = $this->posterGradient($itemId !== '' ? $itemId : $itemType);

        if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
            return $gradient;
        }

        $url = '/api/image.php?item=' . rawurlencode($itemId) . '&type=Primary&maxWidth=240';
        if ($itemType === 'Episode') {
            $url .= '&kind=series';
        }
        if ($title !== '') {
            $url .= '&title=' . rawurlencode($title);
        }

        return 'url("' . $url . '"), ' . $gradient;
    }

    private function posterGradient(string $seed): string
    {
        $gradients = [
            'linear-gradient(145deg,#7a4a1e,#160d07)',
            'linear-gradient(145deg,#1f4a5c,#0a141c)',
            'linear-gradient(145deg,#233d5d,#090d18)',
            'linear-gradient(145deg,#69411f,#100b0c)',
            'linear-gradient(145deg,#375449,#091411)',
        ];

        return $gradients[abs(crc32($seed)) % count($gradients)];
    }
}
