--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\ObjectShapeFetch;

use App\Models\Customer;
use App\Models\Mechanic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * vimeo/psalm#12110 workaround (#1944): a property fetch on `Model&object{p: T}` resolves `p` from the shape.
 * Psalm skips the shape on a Model because the stub's `@mixin Builder` takes the other branch, and never
 * reaches it on a final class.
 */
class Plain
{
}

/** @mixin Plain */
class MixinHost
{
}

final class Declaring extends Model
{
    /** @var int */
    public $declared = 1;
}

// Non-final Model: the `@mixin Builder` branch shadows the shape.
/** @param Customer&object{extra: int} $m */
function shape_on_non_final_model(Customer $m): int
{
    return $m->extra;
}

// Final Model: Psalm skips the intersection fallback altogether.
/** @param Mechanic&object{extra: int} $m */
function shape_on_final_model(Mechanic $m): int
{
    return $m->extra;
}

/** @param Pivot&object{role: string} $p */
function shape_on_pivot(Pivot $p): string
{
    return $p->role;
}

// A property the class declares keeps the class type, whatever the shape says.
/** @param Declaring&object{declared: string} $m */
function declared_property_wins(Declaring $m): int
{
    return $m->declared;
}

// A non-model intersection is Psalm's own business, the handler leaves it alone.
/** @param Plain&object{p: string} $m */
function non_model_untouched(Plain $m): string
{
    return $m->p;
}

// Outside Eloquent models the vimeo/psalm#12110 shadowing is Psalm's own and stays: the mixin hides the shape.
/** @param MixinHost&object{p: string} $m */
function non_model_mixin_untouched(MixinHost $m): string
{
    return $m->p;
}

// The null of a nullable receiver is still a null fetch.
/** @param (Mechanic&object{extra: int})|null $m */
function nullable_receiver_still_reports(?Mechanic $m): ?int
{
    return $m->extra;
}

/** @param (Mechanic&object{extra: int})|null $m */
function nullsafe_receiver(?Mechanic $m): ?int
{
    $extra = $m?->extra;
    /** @psalm-check-type-exact $extra = int|null */

    return $extra;
}

// The receiver is restored after the fetch: method calls and later reads keep resolving on the model.
/** @param Mechanic&object{extra: int} $m */
function receiver_restored_after_fetch(Mechanic $m): string
{
    $extra = $m->extra;
    /** @psalm-check-type-exact $extra = int */

    $key = $m->getKey();
    /** @psalm-check-type-exact $key = int */

    $again = $m->extra;
    /** @psalm-check-type-exact $again = int */

    return $m->getTable() . $extra . $again . $key;
}

// A receiver that is no scoped variable: the handler rewrites the node type instead.
/** @param \Closure(): (Mechanic&object{extra: int}) $make */
function receiver_is_a_call(\Closure $make): int
{
    return $make()->extra + \strlen($make()->getTable());
}

// Nested fetches: the inner fetch is itself the receiver of the outer one.
/** @param Mechanic&object{inner: Pivot&object{role: string}} $m */
function nested_fetches(Mechanic $m): string
{
    return $m->inner->role;
}

// A shape that lacks the property declares nothing to resolve.
/** @param Mechanic&object{extra: int} $m */
function missing_shape_property(Mechanic $m): mixed
{
    return $m->absent;
}
// The shape sets the result type; Psalm's own `@property` read still runs (taint and purity: see the sibling
// ObjectShapePropertyFetchPurity and TaintedModelPseudoPropertyThroughObjectShape tests).
/** @param Customer&object{id: int} $m */
function shape_wins_over_pseudo_property(Customer $m): int
{
    $id = $m->id;
    /** @psalm-check-type-exact $id = int */

    return $id;
}

// Chained `?->`: the short-circuit null stays in the result and is not reported on the later link.
/** @param (Mechanic&object{inner: Mechanic&object{extra: int}})|null $m */
function nullsafe_chain(?Mechanic $m): ?int
{
    $extra = $m?->inner->extra;
    /** @psalm-check-type-exact $extra = int|null */

    return $extra;
}

/** @param (Mechanic&object{extra: int})|null $m */
function isset_receiver(?Mechanic $m): int
{
    if (isset($m->extra)) {
        return $m->extra;
    }

    return 0;
}

/** @param (Mechanic&object{extra: int})|null $m */
function coalesce_receiver(?Mechanic $m): int
{
    return $m->extra ?? 0;
}

// A second read of the same fetch takes Psalm's scoped path and keeps the shape type; an assignment wins.
/** @param Mechanic&object{extra: int} $m */
function repeated_and_assigned_reads(Mechanic $m): int
{
    $first = $m->extra;
    $second = $m->extra;
    /** @psalm-check-type-exact $second = int */

    $m->extra = 5;
    $assigned = $m->extra;
    /** @psalm-check-type-exact $assigned = 5 */

    return $first + $second + $assigned;
}

// A union of qualifying Models combines the shape types.
/** @param (Mechanic&object{extra: int})|(Customer&object{extra: string}) $m */
function union_of_models(Mechanic|Customer $m): int|string
{
    $extra = $m->extra;
    /** @psalm-check-type-exact $extra = int|string */

    return $extra;
}

// Any atomic the shape does not cover: the handler declines and Psalm reports both as without it.
/** @param (Mechanic&object{extra: int})|Plain $m */
function union_with_non_model(Mechanic|Plain $m): mixed
{
    return $m->extra;
}

// Writes keep Psalm's diagnostics; only the false read is gone.
/** @param Mechanic&object{extra: int} $m */
function compound_assignment_and_unset(Mechanic $m): void
{
    $m->extra .= 'x';
    unset($m->extra);
}
?>
--EXPECTF--
MixedReturnStatement on line %d: Could not infer a return type
UndefinedPropertyFetch on line %d: Instance property Tests\Psalm\LaravelPlugin\Sandbox\ObjectShapeFetch\MixinHost::$p is not defined
PossiblyNullPropertyFetch on line %d: Cannot get property on possibly null variable $m of type %s
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Mechanic::$absent is not defined
UndefinedMagicPropertyAssignment on line %d: Magic instance property App\Models\Mechanic::$extra is not defined
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Mechanic::$extra is not defined
UndefinedPropertyFetch on line %d: Instance property Tests\Psalm\LaravelPlugin\Sandbox\ObjectShapeFetch\Plain::$extra is not defined
UndefinedMagicPropertyAssignment on line %d: Magic instance property App\Models\Mechanic::$extra is not defined
