<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * A name-less `#[Table]` on a class that inherits `$table` rather than declaring its own — the shape that
 * reaches `initializeModelAttributes()`'s `$declaresTable` force-branch.
 *
 * Laravel assigns `$table->name ?? null` unconditionally on this shape, nulling the inherited name so
 * `getTable()` re-derives from the class name, where a `??=` would keep it. That is why the replay must
 * invoke the real method rather than reproduce the branch. The consuming test therefore pins a runtime
 * oracle and never a literal.
 *
 * Laravel 13.0+ (`#[Table]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelInstancePreparerTest
 */
#[Table(key: 'uuid')]
final class TableKeyOnlyModel extends InheritedTableBase {}
