<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately fails the registry's schema section (via `computeSchema()`'s `getTable()` call) for
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\WarmUpFailureVisibilityTest}. The section warning
 * must stay visible while the model retains all unrelated metadata.
 */
class WarmUpCrashModel extends Model
{
    public function getTable(): string
    {
        throw new \RuntimeException('deliberate warm-up crash fixture');
    }
}
