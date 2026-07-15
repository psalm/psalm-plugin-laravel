<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
    use HasUuids;

    protected static function boot(): void
    {
        parent::boot();
    }
}
