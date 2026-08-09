<?php

declare(strict_types=1);

use Mk\Framework\Jellyseerr\SeerrRequestRepository;

require dirname(__DIR__) . '/bootstrap.php';

echo count((new SeerrRequestRepository())->claimUnnotified());
