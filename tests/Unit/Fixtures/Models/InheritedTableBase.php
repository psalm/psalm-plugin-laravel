<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares `$table` so {@see TableKeyOnlyModel} can INHERIT it — the only shape in which
 * `initializeModelAttributes()`'s `$declaresTable` branch is observable.
 *
 * @internal fixture used by ModelInstancePreparerTest
 */
abstract class InheritedTableBase extends Model
{
    /** @var string */
    protected $table = 'inherited_table';
}
