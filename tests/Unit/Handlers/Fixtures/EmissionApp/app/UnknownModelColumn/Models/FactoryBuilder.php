<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Adds nothing; the model's `newEloquentBuilder()` hands it a joined query.
 *
 * @extends Builder<FactoryBuilderPost>
 */
final class FactoryBuilder extends Builder {}
