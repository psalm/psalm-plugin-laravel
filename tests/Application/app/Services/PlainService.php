<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Test fixture for {@see \App\Facades\PlainFacade}: a root without `__call`, so an unknown
 * facade method really fails at runtime.
 */
class PlainService
{
    public function real(): int
    {
        return 1;
    }
}
