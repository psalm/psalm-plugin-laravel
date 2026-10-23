<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** A custom pivot with no `@property` and no casts. */
class Rota extends Pivot {}
