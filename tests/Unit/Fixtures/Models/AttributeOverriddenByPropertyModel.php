<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An explicit `$property` declaration wins over the matching `#[Attribute]` through Laravel's own
 * "replace only the default" / `$declaresTable` guards: `#[Guarded]` is ignored because `$guarded` is
 * no longer `['*']`, `#[Table]` does not override an own `$table` property, and `#[Table(timestamps: true)]`
 * is ignored because `$timestamps` is no longer `true`.
 *
 * The replay invokes those initializers rather than reproducing their guards, so what this pins is the
 * PRECONDITION every one of them reads: `newInstanceWithoutConstructor()` applies declared property
 * defaults, so the invoked initializers see `$guarded`/`$table`/`$timestamps` exactly as a constructed model
 * does. Were that not true, all three guards would silently open and the attributes would win.
 *
 * Laravel 13.0+ only; both consuming tests are gated on `class_exists()` (the guard/table one on
 * `#[Hidden]`, the timestamps one on the 13.2 `#[WithoutTimestamps]`).
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Guarded('attr_guard')]
#[Table('attr_table', timestamps: true)]
final class AttributeOverriddenByPropertyModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['own_guard'];

    /** @var string */
    protected $table = 'own_table';

    /** @var bool */
    public $timestamps = false;
}
