<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SoftDeletes model that overrides the `DELETED_AT` class constant, so the auto-added datetime cast must be
 * keyed off `archived_at` rather than the default `deleted_at`.
 *
 * The warm-up replay invokes the real `SoftDeletes::initializeSoftDeletes()`, which reads
 * `getDeletedAtColumn()` and honours the constant on its own — so this fixture pins that the replay INVOKES
 * that initializer rather than standing in for it: a mirror would have to re-derive the constant by hand,
 * which is exactly the copy this class exists to make unnecessary.
 *
 * @internal fixture used by ModelMetadataRegistryTest and ModelInstancePreparerTest
 */
final class CustomDeletedAtModel extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'archived_at';
}
