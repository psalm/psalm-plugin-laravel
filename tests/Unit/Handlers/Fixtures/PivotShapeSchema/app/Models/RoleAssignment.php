<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A custom pivot class describes its own columns: `level` is a string here, whatever `team_user.level` says.
 *
 * @property string $level
 */
final class RoleAssignment extends Pivot {}
