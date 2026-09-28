<?php

declare(strict_types=1);

return [
    'default' => 'local',

    'disks' => [
        'local' => ['driver' => 'local', 'root' => __DIR__ . '/../storage/app'],
        'public' => ['driver' => 'local', 'root' => __DIR__ . '/../storage/app/public'],
        'archive' => ['driver' => 'local', 'root' => __DIR__ . '/../storage/archive'],
    ],
];
