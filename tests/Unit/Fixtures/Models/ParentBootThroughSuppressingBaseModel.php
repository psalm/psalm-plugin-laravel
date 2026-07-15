<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

abstract class SuppressingParentBootModel extends Model
{
    protected static function boot(): void
    {
        // Deliberately suppress Model::boot().
    }
}

/** @internal fixture used by ModelMetadataRegistryTest */
final class ParentBootThroughSuppressingBaseModel extends SuppressingParentBootModel
{
    protected static function boot(): void
    {
        parent::boot();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['flag' => 'boolean'];
    }
}
