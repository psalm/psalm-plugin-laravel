<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Application wrapper whose extra initializer changes unique-ID runtime state. */
trait StatefulHasUuids
{
    use HasUuids;

    private bool $uniqueIdsInitialized = false;

    public function initializeStatefulHasUuids(): void
    {
        $this->uniqueIdsInitialized = true;
    }

    public function usesUniqueIds(): bool
    {
        return $this->uniqueIdsInitialized;
    }
}

/** @internal fixture used by ModelMetadataRegistryTest */
final class StatefulUniqueIdInitializerModel extends Model
{
    use StatefulHasUuids;
}
