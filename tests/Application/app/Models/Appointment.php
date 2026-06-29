<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\AppointmentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Garage service appointment.
 *
 * Archetype for issue #1140: a model whose dedicated custom builder declares
 * `@mixin Appointment` to surface model attributes on builder instances. The builder is
 * registered via newEloquentBuilder(), mirroring the common custom-query-builder pattern.
 *
 * @see AppointmentBuilder
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1140
 *
 * @property string      $scheduled_at Appointment time
 * @property string|null $completed_at Completion time, null while pending
 *
 * @method static AppointmentBuilder incomplete()
 * @method static AppointmentBuilder inThePast()
 */
final class Appointment extends Model
{
    protected $table = 'appointments';

    #[\Override]
    public function newEloquentBuilder($query): AppointmentBuilder
    {
        return new AppointmentBuilder($query);
    }
}
