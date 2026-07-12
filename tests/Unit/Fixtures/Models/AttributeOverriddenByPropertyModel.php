<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An explicit `$property` declaration wins over the matching `#[Attribute]`, mirroring Laravel's
 * "replace only the default" / `$declaresTable` guards: `#[Guarded]` is ignored because `$guarded` is
 * no longer `['*']`, and `#[Table]` does not override an own `$table` property. Guards the
 * `applyGuardedAttribute()` early-return and `applyTableAttribute()` directness branch. Laravel 13.0+
 * only; the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Guarded('attr_guard')]
#[Table('attr_table')]
final class AttributeOverriddenByPropertyModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['own_guard'];

    /** @var string */
    protected $table = 'own_table';
}
