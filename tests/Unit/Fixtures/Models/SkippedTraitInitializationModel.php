<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class SkippedTraitInitializationModel extends Model
{
    protected static function boot(): void
    {
        // Deliberately skip parent::boot(), so Laravel never populates its trait initializer list.
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['flag' => 'boolean'];
    }
}
