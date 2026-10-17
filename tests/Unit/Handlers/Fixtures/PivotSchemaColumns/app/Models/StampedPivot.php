<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class StampedPivot extends Pivot
{
    protected $table = 'stamped_team_user';
}
