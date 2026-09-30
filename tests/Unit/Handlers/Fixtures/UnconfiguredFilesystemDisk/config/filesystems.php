<?php

declare(strict_types=1);

// Deliberately minimal: only the disks the fixture references as "known".
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

        'archive' => [
            'driver' => 'local',
            'root' => __DIR__ . '/../storage/archive',
        ],

        // Nested group: Laravel resolves disk('tenant.assets') through its dotted
        // config lookup (FilesystemManager::getConfig() reads "filesystems.disks.{$name}").
        'tenant' => [
            'assets' => [
                'driver' => 'local',
                'root' => __DIR__ . '/../storage/app/tenant-assets',
            ],
        ],
    ],
];
