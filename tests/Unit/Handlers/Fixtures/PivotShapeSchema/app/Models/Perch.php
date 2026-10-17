<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** Overrides the joining table segment as the RELATED model of Team::overriddenRelated(). */
final class Perch extends Model
{
    #[\Override]
    public function joiningTableSegment(): string
    {
        return 'roost';
    }
}
