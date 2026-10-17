<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class TeamUserPivot extends Pivot
{
    protected $table = 'team_user';
}
