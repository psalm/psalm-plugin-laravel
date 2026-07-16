<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * A name-less `#[Table]` on a class that inherits `$table` rather than declaring its own — the shape that
 * reaches `initializeModelAttributes()`'s `$declaresTable` force-branch.
 *
 * The answer is VERSION-SPLIT inside the supported range, which is exactly why the replay must invoke the
 * real method rather than reproduce it: Laravel 13.3-13.5 have no force-branch and their `$this->table ??=`
 * keeps the inherited name, while 13.6+ assigns `$table->name ?? null` unconditionally — nulling it, so
 * `getTable()` re-derives from the class name. The consuming test therefore pins a runtime oracle and never a
 * literal.
 *
 * Laravel 13.0+ (`#[Table]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelInstancePreparerTest
 */
#[Table(key: 'uuid')]
final class TableKeyOnlyModel extends InheritedTableBase {}
