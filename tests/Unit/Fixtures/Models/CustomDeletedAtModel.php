<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SoftDeletes model that overrides the `DELETED_AT` class constant. Exercises
 * `ModelMetadataRegistryBuilder::resolveDeletedAtColumn()` — the registry must
 * key the auto-added datetime cast off `archived_at`, not the default `deleted_at`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class CustomDeletedAtModel extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'archived_at';
}
