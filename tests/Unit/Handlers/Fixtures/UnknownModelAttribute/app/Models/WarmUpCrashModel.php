<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately crashes {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder::compute()}
 * (via `computeSchema()`'s `getTable()` call) to exercise `warmUp()`'s outer safety-net catch, for
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\WarmUpFailureVisibilityTest}. Warm-up failing for
 * this model must never affect {@see KnownThing}'s own warm-up in the same run (per-model try/catch).
 */
class WarmUpCrashModel extends Model
{
    public function getTable(): string
    {
        throw new \RuntimeException('deliberate warm-up crash fixture');
    }
}
