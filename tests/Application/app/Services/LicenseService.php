<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Test fixture for {@see \App\Facades\License}. Methods exercise the paths
 * resolved by {@see \Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler}:
 * a public method not listed in the facade's `@method` catalogue (getStatus),
 * a public method whose signature conflicts with `@method` (isPlus), and a
 * protected method that must NOT be surfaced on the facade (internalCheck).
 */
class LicenseService
{
    public function getStatus(bool $checkCache = true): string
    {
        return $checkCache ? 'cached' : 'fresh';
    }

    /** Concrete signature conflicts with the facade's `@method static bool isPlus()` on purpose. */
    public function isPlus(): string
    {
        return 'plus';
    }

    protected function internalCheck(): bool
    {
        return true;
    }
}
