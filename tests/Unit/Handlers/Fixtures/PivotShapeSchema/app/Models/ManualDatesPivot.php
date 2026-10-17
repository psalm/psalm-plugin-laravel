<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Converts no date: its timestamp columns stay the raw strings of `team_user`, whatever the parent's names say. */
final class ManualDatesPivot extends Pivot
{
    /** @return list<string> */
    #[\Override]
    public function getDates(): array
    {
        return [];
    }
}
