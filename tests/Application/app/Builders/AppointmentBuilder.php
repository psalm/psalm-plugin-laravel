<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom builder that exposes its model's surface via `@mixin Appointment`.
 *
 * Archetype for issue #1140: a user `@mixin <Model>` on an Eloquent\Builder subclass shadows
 * the inherited `@mixin Query\Builder`. Psalm does not merge a child's own mixins with the
 * parent's (Populator replaces, not merges), so Query\Builder-forwarded methods
 * (whereNotNull, orWhereNull, ...) stop resolving on the builder without a compensating
 * re-injection of the Query\Builder mixin.
 *
 * The incomplete()/inThePast() bodies mirror the reported reproduction; the regression is
 * asserted in BuilderSubclassMixinForwardingTest (the type suite reflects these fixtures but
 * does not analyze their method bodies, so the assertions drive a builder-typed receiver).
 *
 * @extends Builder<Appointment>
 * @mixin Appointment
 */
final class AppointmentBuilder extends Builder
{
    public function incomplete(): self
    {
        $this->where(function (AppointmentBuilder $query): void {
            $query->where('scheduled_at', '>=', 'now')
                ->orWhereNull('completed_at');
        });

        return $this;
    }

    public function inThePast(): self
    {
        $this->where('scheduled_at', '<=', 'now')
            ->whereNotNull('completed_at');

        return $this;
    }
}
