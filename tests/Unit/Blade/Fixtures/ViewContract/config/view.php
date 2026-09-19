<?php

declare(strict_types=1);

return [
    // Two roots on purpose: 'dup' exists in both, and FileViewFinder renders the first root's file.
    'paths' => [
        __DIR__ . '/../resources/views',
        __DIR__ . '/../resources/views-theme',
    ],
    'compiled' => __DIR__ . '/../.cache/laravel-views',
];
