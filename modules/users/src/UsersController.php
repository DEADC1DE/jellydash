<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\StatisticsPeriod;
use Mk\Framework\Log;
use Mk\Framework\Main;
use Mk\Modules\Devices\DeviceService;
use Mk\Modules\Users\Invite\InviteManager;

final class UsersController extends Controller
{
    private const PAGE_SIZE = 10;

    public function handle(): void
    {
        $canManageUsers = (new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL);
        $selected = trim((string) ($_GET['user'] ?? ''));
        $repository = new UserStatsRepository();

        // Post/Redirect/Get, same pattern as the settings operations: a
        // successful action redirects (so a browser refresh cannot replay the
        // POST), and errors come back as a short-lived query flag rendered by
        // the page below. Csrf::check() exits with 419 on a bad token.
        $inviteError = Main::captureGetString('invite_error');
        $inviteNotice = Main::captureSessionNotice();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$canManageUsers) {
                http_response_code(403);
                exit('Forbidden');
            }
            Csrf::check();
            try {
                $error = $this->handleInviteAction($inviteNotice);
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'Invite action failed.';
                Log::logException($e);
            }

            // Success notices (freshly reset passwords) travel in the session,
            // never in the URL — query strings end up in browser history and
            // access logs.
            if ($inviteNotice !== null) {
                Main::putSessionNotice($inviteNotice);
            }
            $action = (string) ($_POST['do'] ?? '');
            $postId = (string) ($_POST['id'] ?? '');
            $keepUserDialogOpen = $error === null
                && $postId !== ''
                && in_array($action, ['set-expiry', 'set-permissions', 'disable-user', 'enable-user', 'extend-user'], true);
            $anchor = $keepUserDialogOpen ? '#user-manager-' . rawurlencode($postId) : '#invitations';
            $location = '/users';
            if ($error !== null) {
                $location .= '?' . http_build_query(['invite_error' => $error]);
            }
            header('Location: ' . $location . $anchor);
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

        $jellyfinUsers = (new JellyfinClient())->users($canManageUsers);
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

        $invite = $canManageUsers
            ? $this->inviteState($inviteError, $inviteNotice)
            : ['usersByName' => []];

        // Expiry data keyed by lowercase username; Jellyfin account names are
        // the join key for the list shown on this page.
        foreach ($rows as &$row) {
            $row['invite'] = $invite['usersByName'][mb_strtolower((string) $row['name'])] ?? null;
        }
        unset($row);

        $this->render('@users/index', [
            'layout' => $this->layout(['title' => 'Users', 'page' => 'users']),
            'rows' => $rows,
            'overview' => $overview,
            'invite' => $invite,
            'can_manage_users' => $canManageUsers,
        ]);
    }

    /**
     * Native invite data (local tables + Jellyfin libraries) plus the state
     * the template needs. A Jellyfin failure must never break the Users page
     * itself, so it degrades to an error note.
     *
     * @return array{error: string|null, notice: string|null, usersByName: array<string, array<string, mixed>>, invitations: array<int, array<string, mixed>>, libraries: array<int, array<string, mixed>>, libraries_available: bool}
     */
    private function inviteState(?string $error, ?string $notice): array
    {
        $state = [
            'error' => $error,
            'notice' => $notice,
            'usersByName' => [],
            'invitations' => [],
            'libraries' => [],
            'libraries_available' => false,
        ];

        try {
            $manager = new InviteManager();
            $state['usersByName'] = $manager->repository()->accountsByName();
            $state['invitations'] = $manager->invitations(InviteManager::publicBaseUrl());
            $folders = (new JellyfinClient())->mediaFolders();
            $state['libraries_available'] = true;
            $state['libraries'] = array_map(
                static fn (array $folder): array => [
                    'id' => (string) ($folder['Id'] ?? ''),
                    'name' => (string) ($folder['Name'] ?? $folder['Id']),
                ],
                array_values(array_filter(
                    $folders,
                    static fn (array $folder): bool => (string) ($folder['Id'] ?? '') !== ''
                ))
            );
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Invites are unavailable.';
            Log::logException($e);
        }

        return $state;
    }

    /**
     * Dispatch the invite/account form actions POSTed from the Users page.
     * Returns null on success; a message string becomes the page error note.
     * Account ids are Jellyfin user ids; expiry bookkeeping lives locally.
     */
    private function handleInviteAction(?string &$notice): ?string
    {
        $manager = new InviteManager();
        $id = (string) ($_POST['id'] ?? '');

        switch ((string) ($_POST['do'] ?? '')) {
            case 'create-invite':
                $linkExpires = (string) ($_POST['linkExpires'] ?? '');
                $access = (string) ($_POST['access'] ?? 'unlimited');
                $manager->createInvitation(
                    $linkExpires !== '' ? (int) $linkExpires : null,
                    $access === 'unlimited' ? null : max(1, (int) $access),
                    array_map('strval', (array) ($_POST['libraries'] ?? [])),
                    ($_POST['downloads'] ?? '') === '1',
                    ($_POST['livetv'] ?? '') === '1',
                );
                return null;
            case 'delete-invite':
                if ($id !== '') {
                    $manager->deleteInvitation((int) $id);
                }
                return null;
            case 'disable-user':
                if ($id !== '') {
                    $manager->setDisabled($id, true);
                }
                return null;
            case 'enable-user':
                if ($id !== '') {
                    $manager->setDisabled($id, false);
                }
                return null;
            case 'extend-user':
                if ($id !== '') {
                    $manager->extend($id, (int) ($_POST['days'] ?? 30), (string) ($_POST['username'] ?? ''));
                }
                return null;
            case 'set-expiry':
                if ($id !== '') {
                    $username = (string) ($_POST['username'] ?? '');
                    $date = trim((string) ($_POST['expires_on'] ?? ''));
                    $manager->setExpiryDate($id, $username, $date !== '' ? $date : null);
                }
                return null;
            case 'set-permissions':
                if ($id !== '') {
                    $postedLibraries = $_POST['libraries'] ?? [];
                    if (!is_array($postedLibraries)) {
                        return 'Choose valid media libraries.';
                    }
                    foreach ($postedLibraries as $libraryId) {
                        if (!is_string($libraryId)) {
                            return 'Choose valid media libraries.';
                        }
                    }
                    $manager->setAccountPermissions(
                        $id,
                        $postedLibraries,
                        ($_POST['all_libraries'] ?? '') === '1',
                        ($_POST['downloads'] ?? '') === '1',
                        ($_POST['livetv'] ?? '') === '1',
                    );
                }
                return null;
            case 'reset-password':
                if ($id !== '') {
                    // A reset the admin never sees is useless, so the fresh
                    // password travels back through the notice flash.
                    $password = bin2hex(random_bytes(6));
                    (new JellyfinClient())->setPassword($id, $password);
                    $notice = 'New password: ' . $password;
                }
                return null;
            default:
                return 'Unknown invite action.';
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
