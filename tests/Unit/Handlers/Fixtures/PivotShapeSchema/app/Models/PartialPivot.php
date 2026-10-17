<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Its casts need constructor state, which the registry's warm-up does not run: the casts stay unproven, so the
 * `team_user.role` string of the relation table must not be claimed (the runtime cast is an integer).
 */
final class PartialPivot extends Pivot
{
    private bool $configured = false;

    public function __construct(array $attributes = [])
    {
        $this->configured = true;
        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    #[\Override]
    public function getCasts(): array
    {
        if (!$this->configured) {
            throw new \LogicException('getCasts requires constructor configuration');
        }

        return ['role' => 'integer'];
    }
}
