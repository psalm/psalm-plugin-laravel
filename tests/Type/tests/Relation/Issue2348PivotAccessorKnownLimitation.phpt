--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;

/**
 * Accepted gaps of the accessor rename, see the PivotAccessorHandler docblock (#2348).
 *
 * - Callback params keep `pivot`: Psalm types a closure argument before the call resolves. So does a callback value the
 *   method only passes through, a `chunkMap(fn ($s) => $s)` identity included (pinned in
 *   Issue2348PivotAccessorLaravel1341Test.phpt: before Laravel 13.41 that param carries no pivot shape at all).
 * - A first-class callable skips the after-call hook, so its return keeps `pivot`.
 * - A `firstOr()` / `findOr()` callback value of the relation's own model with the same pivot type, bare or nested
 *   (`['cached' => $model]`), is renamed with the relation's result: Psalm returns one type for both branches, so the
 *   callback's model is indistinguishable, although it really carries `pivot`. Other models keep `pivot`
 *   (Issue2348PivotAccessorDeclinesTest.phpt).
 * - Psalm does not track the relation object: an `as()` on an alias, or after `lazy()` / `cursor()` built a deferred
 *   result, changes the runtime key but not the inferred one.
 * - A registered relation macro (`BelongsToMany::macro()`, run by `Relation::__call` on the relation itself) may call
 *   `as()`: the parser keeps the accessor it read before the macro. Scopes and Builder macros are forwarded to the
 *   query and cannot change it.
 * - An accessor named like a relation method of the related model types the result's shape, but reading it still
 *   resolves to that relation's property: Psalm prefers the ordinary property over the object shape, while Laravel
 *   returns the loaded pivot.
 */
function accessor_known_limitations(Mechanic $mechanic): void
{
    $mechanic->specializationsAccessorOnly()->each(static function ($specialization): void {
        /** @psalm-trace $specialization */
    });

    $mechanic->specializationsAccessorOnly()->chunk(10, static function ($chunk): void {
        /** @psalm-trace $chunk */
    });

    $_first_class_callable = $mechanic->specializationsAccessorOnly()->firstOrFail(...);
    /** @psalm-trace $_first_class_callable */

    $_first_or_same_shape = $mechanic->specializationsAccessorOnly()
        ->firstOr(static fn () => $mechanic->specializations()->firstOrFail());
    /** @psalm-trace $_first_or_same_shape */

    $_first_or_nested = $mechanic->specializationsAccessorOnly()
        ->firstOr(static fn () => ['cached' => $mechanic->specializations()->firstOrFail()]);
    /** @psalm-trace $_first_or_nested */

    $relation = $mechanic->specializationsAccessorOnly();
    $alias = $relation;
    $lazy = $relation->lazy();
    $alias->as('later');
    $_through_alias = $relation->firstOrFail();
    /** @psalm-trace $_through_alias */

    $_deferred = $lazy->first();
    /** @psalm-trace $_deferred */

    $_relation_macro = $mechanic->specializationsAccessorMacro()->firstOrFail();
    /** @psalm-trace $_relation_macro */

    $_relation_named = $mechanic->specializations()->as('mechanics')->firstOrFail()->mechanics;
    /** @psalm-trace $_relation_named */
}
?>
--EXPECTF--
UnusedClosureParam on line %d: Param specialization is never referenced in this method
Trace on line %d: $specialization: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
UnusedClosureParam on line %d: Param chunk is never referenced in this method
Trace on line %d: $chunk: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_first_class_callable: Closure[impure](%s):App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_first_or_same_shape: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_first_or_nested: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|array{cached: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}}
Trace on line %d: $_through_alias: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_deferred: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_relation_macro: App\Models\MechanicSpecialization&object{first:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_relation_named: Illuminate\Database\Eloquent\Collection<int, App\Models\Mechanic&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
