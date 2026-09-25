<?php

declare(strict_types=1);

// Deliberately minimal — only the two disks the fixture actually references as "known".
return [
    'default' => 'local',

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => __DIR__ . '/../storage/app',
        ],

        'public' => [
            'driver' => 'local',
            'root' => __DIR__ . '/../storage/app/public',
            'url' => '/storage',
            'visibility' => 'public',
        ],
    ],
];
