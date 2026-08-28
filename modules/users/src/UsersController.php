<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\JellyfinClient;

final class UsersController extends Controller
{
    public function handle(): void
    {
        $selected = trim((string) ($_GET['user'] ?? ''));
        $repository = new UserStatsRepository();

        if ($selected !== '') {
            $this->render('@users/profile', [
                'layout' => $this->layout(['title' => $selected, 'page' => 'users']),
                'userName' => $selected,
                'summary' => $repository->summaryForUser($selected),
                'heatmap' => $repository->heatmap($selected),
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
