--FILE--
<?php declare(strict_types=1);

use App\Builders\AppointmentBuilder;
use App\Models\Appointment;

/**
 * Issue #1140: a user Eloquent\Builder subclass that declares its own `@mixin <Model>`
 * (here AppointmentBuilder `@mixin Appointment`) must still resolve Query\Builder-forwarded
 * methods (whereNotNull, orWhereNull, orderBy, ...).
 *
 * Psalm replaces — rather than merges — a child's mixins with the parent's, so the
 * `@mixin Query\Builder` inherited from Eloquent\Builder is shadowed by the subclass's own
 * `@mixin Appointment`. BuilderSubclassQueryMixinHandler re-injects Query\Builder so resolution
 * (existence + return type) works as it does on a builder without an own `@mixin`.
 *
 * The receiver-typed-as-builder form below is the analysis equivalent of the `$this->...`
 * calls inside AppointmentBuilder's own methods — the receiver type is identical.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1140
 */

/** Forwarded Query\Builder methods resolve on the custom builder and preserve its type. */
function appt_forwarded_on_builder(AppointmentBuilder $b): void
{
    $_whereNotNull = $b->whereNotNull('completed_at');
    /** @psalm-check-type-exact $_whereNotNull = AppointmentBuilder */

    $_orWhereNull = $b->orWhereNull('completed_at');
    /** @psalm-check-type-exact $_orWhereNull = AppointmentBuilder */

    $_orderBy = $b->orderBy('scheduled_at');
    /** @psalm-check-type-exact $_orderBy = AppointmentBuilder */
}

/**
 * The re-injected Query\Builder mixin must NOT displace the user's `@mixin Appointment`:
 * model attributes must still resolve on builder instances (the reason the user added the
 * mixin). Guards against a fix that resolves query methods by dropping the model mixin.
 */
function appt_model_surface_preserved(AppointmentBuilder $b): void
{
    $_attr = $b->scheduled_at;
    /** @psalm-check-type-exact $_attr = string */
}

/** Chaining a forwarded method into a builder-only method keeps the custom builder type. */
function appt_chain_preserves_builder(AppointmentBuilder $b): void
{
    $_result = $b->whereNotNull('completed_at')->incomplete();
    /** @psalm-check-type-exact $_result = AppointmentBuilder */
}

/** Forwarded method resolves on the builder returned from Model::query(). */
function appt_forwarded_via_query(): void
{
    $_result = Appointment::query()->whereNotNull('completed_at');
    /** @psalm-check-type-exact $_result = AppointmentBuilder */
}

/** Negative: a genuinely undefined method on the builder must still be reported. */
function appt_undefined_method_still_reported(AppointmentBuilder $b): void
{
    $b->completelyFakeMethod();
}

/**
 * Negative on the model surface: resolution that descends through `@mixin Appointment` must
 * still reject a fake method on the model (the issue's symptom was reported on the model).
 */
function appt_undefined_method_on_model_still_reported(Appointment $m): void
{
    $m->completelyFakeMethod();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Builders\AppointmentBuilder::completelyfakemethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Models\Appointment::completelyfakemethod does not exist
