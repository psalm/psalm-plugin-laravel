--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\Shop;
use App\Models\WorkOrder;
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

    $_trailing_newline = $mechanic->specializations()->as("details\n")->first();
    /** @psalm-trace $_trailing_newline */

    $relation = $flag ? $mechanic->specializationsAccessorOnly() : $mechanic->specializationProfiles();
    $_union_receiver = $relation->first();
    /** @psalm-trace $_union_receiver */
}

/**
 * Laravel reads an attribute, and returns null for a name `Model` declares a method for, before it reads the pivot
 * relation: such an accessor keeps `pivot`.
 */
function accessor_colliding_with_the_model_keeps_pivot(Mechanic $mechanic, WorkOrder $order): void
{
    $_property = $order->parts()->as('name')->firstOrFail();
    /** @psalm-trace $_property */

    $_model_method = $mechanic->specializations()->as('save')->firstOrFail();
    /** @psalm-trace $_model_method */
}

/**
 * Only the relation's own results are renamed: values a method passes through from its arguments or callbacks, and
 * other models, keep their `pivot`.
 *
 * @param BelongsToMany<Mechanic, MechanicSpecialization&object{pivot: Pivot}, Pivot, 'details'> $shaped_parent
 */
function values_the_relation_did_not_hydrate_keep_pivot(Mechanic $mechanic, Shop $shop, BelongsToMany $shaped_parent): void
{
    $_save_many = $mechanic->specializationsAccessorOnly()->saveMany([$mechanic->specializations()->firstOrFail()]);
    /** @psalm-trace $_save_many */

    $_chunk_map = $mechanic->specializationsAccessorOnly()->chunkMap(static fn () => $mechanic->specializations()->firstOrFail());
    /** @psalm-trace $_chunk_map */

    $_other_model = $mechanic->specializationsAccessorOnly()->firstOr(static fn () => $shop->parts()->firstOrFail());
    /** @psalm-trace $_other_model */

    $_parent = $shaped_parent->getParent();
    /** @psalm-trace $_parent */
}

/**
 * A relation body whose accessor the parser cannot prove (dynamic outermost `as()`, returns that differ in it, an
 * unknown link after the last `as()`) leaves TAccessor a plain `string`, never an inner literal or `'pivot'`; the
 * related model and pivot stay inferred.
 */
function body_accessor_not_provable(Mechanic $mechanic): void
{
    $_dynamic = $mechanic->specializationsDynamicAccessor();
    /** @psalm-trace $_dynamic */

    $_dynamic_first = $mechanic->specializationsDynamicAccessor()->firstOrFail();
    /** @psalm-trace $_dynamic_first */

    $_branch = $mechanic->specializationsBranchAccessor();
    /** @psalm-trace $_branch */

    $_delegated = $mechanic->specializationsDelegatedDynamic();
    /** @psalm-trace $_delegated */

    $_opaque_link = $mechanic->specializationsOpaqueLink();
    /** @psalm-trace $_opaque_link */
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
Trace on line %d: $_trailing_newline: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_union_receiver: App\Models\MechanicSpecialization&object{pivot:App\Models\SpecializationPivot}|App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_property: App\Models\Part&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}}
Trace on line %d: $_model_method: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_save_many: list{App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}}
Trace on line %d: $_chunk_map: Illuminate\Support\Collection<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_other_model: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|App\Models\Part&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_parent: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_dynamic_first: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_branch: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_delegated: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, App\Models\SpecializationPivot, string>
Trace on line %d: $_opaque_link: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_override: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_inherited: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_user_method: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
