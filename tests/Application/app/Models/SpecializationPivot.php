<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom pivot model for the Mechanic <-> MechanicSpecialization relationship.
 *
 * Used in type tests to verify that BelongsToMany's TPivotModel template
 * param is properly propagated through find/first/create methods.
 *
 * @property int $mechanic_id
 * @property int $mechanic_specialization_id
 * @property string|null $certified_at
 */
final class SpecializationPivot extends Pivot
{
    protected $table = 'mechanic_mechanic_specialization';
}
