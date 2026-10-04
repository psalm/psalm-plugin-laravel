<?php

declare(strict_types=1);

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
        ],

        // Nested group, no `driver` of its own: Laravel reaches `tenant.assets` through its dotted
        // config lookup, but `disk('tenant')` throws.
        'tenant' => [
            'assets' => [
                'driver' => 'local',
                'root' => __DIR__ . '/../storage/app/tenant-assets',
            ],
        ],
    ],
];
