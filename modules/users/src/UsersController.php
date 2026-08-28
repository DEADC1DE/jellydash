<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Modules\Devices\DeviceService;

final class UsersController extends Controller
{
    private const PAGE_SIZE = 10;

    public function handle(): void
    {
        $selected = trim((string) ($_GET['user'] ?? ''));
        $repository = new UserStatsRepository();

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
                    $play['poster'] = $this->poster((string) ($play['itemId'] ?? ''), (string) ($play['itemType'] ?? ''));
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

        $this->render('@users/index', [
            'layout' => $this->layout(['title' => 'Users', 'page' => 'users']),
            'rows' => $rows,
        ]);
    }

    /**
     * Real Jellyfin poster art layered over a colored gradient (shows through
     * while the image loads, or if the item has no artwork) — same recipe as
     * the core History page's poster() so covers look consistent app-wide.
     */
    private function poster(string $itemId, string $itemType): string
    {
        $gradient = $this->posterGradient($itemId !== '' ? $itemId : $itemType);

        if ($itemId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $itemId)) {
            return $gradient;
        }

        $url = '/api/image.php?item=' . rawurlencode($itemId) . '&type=Primary&maxWidth=240';
        if ($itemType === 'Episode') {
            $url .= '&kind=series';
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
