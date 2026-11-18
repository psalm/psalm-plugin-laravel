--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The pivot key is renamed only when the receiver proves the runtime accessor (#2348). Anything else keeps
 * Laravel's `pivot` typing, the same as before the rename existed.
 */
function accessor_not_provable_keeps_pivot(Mechanic $mechanic, string $accessor, bool $flag): void
{
    $_string = $mechanic->specializations()->as($accessor)->first();
    /** @psalm-trace $_string */

    // Psalm's isSingle() accepts a union of literals; a rename would pick one of them.
    $_literal_union = $mechanic->specializations()->as($flag ? 'a' : 'b')->first();
    /** @psalm-trace $_literal_union */

    $_explicit_pivot = $mechanic->specializations()->as('pivot')->first();
    /** @psalm-trace $_explicit_pivot */

    $_not_a_property_name = $mechanic->specializations()->as('pivot-data')->first();
    /** @psalm-trace $_not_a_property_name */

    $relation = $flag ? $mechanic->specializationsAccessorOnly() : $mechanic->specializationProfiles();
    $_union_receiver = $relation->first();
    /** @psalm-trace $_union_receiver */
}

/**
 * A relation body whose outermost `as()` the parser cannot read (dynamic or a constant) leaves TAccessor a plain
 * `string`, never an inner literal or `'pivot'`.
 */
function body_accessor_not_provable(Mechanic $mechanic): void
{
    $_dynamic = $mechanic->specializationsDynamicAccessor();
    /** @psalm-trace $_dynamic */

    $_dynamic_first = $mechanic->specializationsDynamicAccessor()->firstOrFail();
    /** @psalm-trace $_dynamic_first */

    $_const = $mechanic->specializationsConstAccessor();
    /** @psalm-trace $_const */

    $_const_first = $mechanic->specializationsConstAccessor()->firstOrFail();
    /** @psalm-trace $_const_first */
}

interface PeeksPivot
{
    /** @return MechanicSpecialization&object{pivot: Pivot} */
    public function peek(): MechanicSpecialization;
}

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of Pivot = Pivot
 * @template TAccessor of string = 'pivot'
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
final class AuditedBelongsToMany extends BelongsToMany
{
    /** @return (TRelatedModel&object{pivot: TPivotModel})|null */
    #[\Override]
    public function first($columns = ['*'])
    {
        return parent::first($columns);
    }
}

/**
 * A user subclass may override a method or hydrate the pivot differently, and a method of a userland type in the
 * receiver is not Laravel's: both keep their declared type.
 *
 * @param AuditedBelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'details'> $subclass
 * @param BelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'details'>&PeeksPivot $intersection
 */
function user_methods_keep_their_type(AuditedBelongsToMany $subclass, BelongsToMany $intersection): void
{
    $_override = $subclass->first();
    /** @psalm-trace $_override */

    $_inherited = $subclass->firstOrFail();
    /** @psalm-trace $_inherited */

    $_user_method = $intersection->peek();
    /** @psalm-trace $_user_method */
}
?>
--EXPECTF--
Trace on line %d: $_string: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_literal_union: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_explicit_pivot: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_not_a_property_name: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_union_receiver: App\Models\MechanicSpecialization&object{pivot:App\Models\SpecializationPivot}|App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_dynamic_first: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_const: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_const_first: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_override: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_inherited: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_user_method: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
