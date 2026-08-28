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

            $this->render('@users/profile', [
                'layout' => $this->layout(['title' => $selected, 'page' => 'users']),
                'userName' => $selected,
                'summary' => $repository->summaryForUser($selected),
                'heatmap' => $repository->heatmap($selected),
                'recentPlays' => $repository->recentPlays($selected, self::PAGE_SIZE, ($playsPage - 1) * self::PAGE_SIZE),
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
}
