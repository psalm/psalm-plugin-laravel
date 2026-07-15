<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class CustomUniqueIdInitializerModel extends Model
{
    use HasUuids;

    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = false;
    }
}
