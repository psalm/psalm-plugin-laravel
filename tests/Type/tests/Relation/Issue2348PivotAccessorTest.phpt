--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

interface Tagged {}

/**
 * A literal `as()` accessor renames the pivot key on every result of the relation (#2348): the model carries
 * `->details`, and `->pivot` is an unknown property as on any model. Traces, not check-type: Psalm compares object
 * shapes inside an intersection by name only, so check-type cannot pin the keys.
 */
function accessor_renames_every_result_surface(Mechanic $mechanic): void
{
    $relation = $mechanic->specializationsAccessorOnly();

    $_first = $relation->first();
    /** @psalm-trace $_first */

    $_first_or_fail = $relation->firstOrFail();
    /** @psalm-trace $_first_or_fail */

    $_first_or = $relation->firstOr(static fn (): int => 1);
    /** @psalm-trace $_first_or */

    $_first_where = $relation->firstWhere('name', 'x');
    /** @psalm-trace $_first_where */

    $_find = $relation->find(1);
    /** @psalm-trace $_find */

    $_find_list = $relation->find([1, 2]);
    /** @psalm-trace $_find_list */

    $_find_many = $relation->findMany([1, 2]);
    /** @psalm-trace $_find_many */

    $_find_or_fail = $relation->findOrFail(1);
    /** @psalm-trace $_find_or_fail */

    // The callback's `|1` branch is missing because the stub's findOr($id, Closure) form never binds TValue
    // (pre-existing stub gap); only the pivot key matters here.
    $_find_or = $relation->findOr(1, static fn (): int => 1);
    /** @psalm-trace $_find_or */

    $_paginate = $relation->paginate();
    /** @psalm-trace $_paginate */

    $_simple_paginate = $relation->simplePaginate();
    /** @psalm-trace $_simple_paginate */

    $_cursor_paginate = $relation->cursorPaginate();
    /** @psalm-trace $_cursor_paginate */

    $_cursor = $relation->cursor();
    /** @psalm-trace $_cursor */

    $_lazy = $relation->lazy();
    /** @psalm-trace $_lazy */

    $_lazy_by_id = $relation->lazyById();
    /** @psalm-trace $_lazy_by_id */

    $_results = $relation->getResults();
    /** @psalm-trace $_results */

    $_create = $relation->create(['name' => 'x']);
    /** @psalm-trace $_create */

    $_create_many = $relation->createMany([['name' => 'x']]);
    /** @psalm-trace $_create_many */

    $_save = $relation->save(new MechanicSpecialization());
    /** @psalm-trace $_save */

    $_first_or_create = $relation->firstOrCreate(['name' => 'x']);
    /** @psalm-trace $_first_or_create */

    $_update_or_create = $relation->updateOrCreate(['name' => 'x']);
    /** @psalm-trace $_update_or_create */

    $_where = $relation->where('name', 'x')->first();
    /** @psalm-trace $_where */

    $_where_pivot = $relation->wherePivot('active', 1)->first();
    /** @psalm-trace $_where_pivot */

    $_details = $relation->firstOrFail()->details;
    /** @psalm-trace $_details */

    $_pivot = $relation->firstOrFail()->pivot;
    /** @psalm-trace $_pivot */
}

/**
 * The accessor reaches the result whichever way the relation type got it.
 *
 * @param BelongsToMany<WorkOrder&Tagged, Mechanic, Pivot, 'details'> $intersected
 */
function accessor_follows_the_relation_type(Mechanic $mechanic, ?Mechanic $maybe, BelongsToMany $intersected): void
{
    $_delegated = $mechanic->specializationProfiles()->firstOrFail();
    /** @psalm-trace $_delegated */

    $_untyped = $mechanic->specializationNotes()->firstOrFail();
    /** @psalm-trace $_untyped */

    $_morph_to_many = $mechanic->workOrderTagsAccessorOnly()->firstOrFail();
    /** @psalm-trace $_morph_to_many */

    $_as_then_using = $mechanic->specializationsAsThenUsing()->firstOrFail();
    /** @psalm-trace $_as_then_using */

    // `as()` returns `static<...>`, so the next receiver is `BelongsToMany<...>&static`.
    $_call_site = $mechanic->specializations()->as('subscription')->firstOrFail();
    /** @psalm-trace $_call_site */

    $_as_then_with_pivot = $mechanic->specializations()->as('subscription')->withPivot('active')->firstOrFail();
    /** @psalm-trace $_as_then_with_pivot */

    $_with_pivot_then_as = $mechanic->specializations()->withPivot('active')->as('subscription')->firstOrFail();
    /** @psalm-trace $_with_pivot_then_as */

    $_last_as_wins = $mechanic->specializationsAccessorOnly()->as('subscription')->firstOrFail();
    /** @psalm-trace $_last_as_wins */

    $in_place = $mechanic->specializations();
    $in_place->as('subscription');
    $_in_place = $in_place->firstOrFail();
    /** @psalm-trace $_in_place */

    $_nullsafe = $maybe?->specializationsAccessorOnly()->firstOrFail();
    /** @psalm-trace $_nullsafe */

    $_class_constant = $mechanic->specializationsConstAccessor()->firstOrFail();
    /** @psalm-trace $_class_constant */

    $_declared = $mechanic->specializationsDeclaredAccessor()->firstOrFail();
    /** @psalm-trace $_declared */

    // A scope and a Builder macro (`withTrashed()`) run on the query, so they keep the accessor.
    $_scoped = $mechanic->workOrderTagsScoped()->firstOrFail();
    /** @psalm-trace $_scoped */

    $_explicit_pivot_branch = $mechanic->specializationsBranchExplicitPivot();
    /** @psalm-trace $_explicit_pivot_branch */

    // Other members of the related model's intersection stay on it.
    $_intersected = $intersected->firstOrFail();
    /** @psalm-trace $_intersected */

    // A relation method of the related model does not shadow the loaded pivot relation.
    $_relation_name = $mechanic->specializations()->as('mechanics')->firstOrFail();
    /** @psalm-trace $_relation_name */
}
?>
--EXPECTF--
Trace on line %d: $_first: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_first_or_fail: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_first_or: 1|App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_first_where: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_find: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_find_list: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>|null
Trace on line %d: $_find_many: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_find_or_fail: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_find_or: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_paginate: Illuminate\Pagination\LengthAwarePaginator<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_simple_paginate: Illuminate\Pagination\Paginator<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_cursor_paginate: Illuminate\Pagination\CursorPaginator<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_cursor: Illuminate\Support\LazyCollection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_lazy: Illuminate\Support\LazyCollection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_lazy_by_id: Illuminate\Support\LazyCollection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_results: Traversable<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_create: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_create_many: list<App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_save: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_first_or_create: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_update_or_create: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_where: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_where_pivot: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_details: Illuminate\Database\Eloquent\Relations\Pivot
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\MechanicSpecialization::$pivot is not defined
Trace on line %d: $_pivot: mixed
Trace on line %d: $_delegated: App\Models\MechanicSpecialization&object{profile:App\Models\SpecializationPivot}
Trace on line %d: $_untyped: App\Models\MechanicSpecialization&object{note:Illuminate\Database\Eloquent\Relations\Pivot&object{notes:mixed}}
Trace on line %d: $_morph_to_many: App\Models\WorkOrder&object{meta:Illuminate\Database\Eloquent\Relations\MorphPivot}
Trace on line %d: $_as_then_using: App\Models\MechanicSpecialization&object{details:App\Models\SpecializationPivot}
Trace on line %d: $_call_site: App\Models\MechanicSpecialization&object{subscription:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_as_then_with_pivot: App\Models\MechanicSpecialization&object{subscription:Illuminate\Database\Eloquent\Relations\Pivot&object{active:mixed}}
Trace on line %d: $_with_pivot_then_as: App\Models\MechanicSpecialization&object{subscription:Illuminate\Database\Eloquent\Relations\Pivot&object{active:mixed}}
Trace on line %d: $_last_as_wins: App\Models\MechanicSpecialization&object{subscription:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_in_place: App\Models\MechanicSpecialization&object{subscription:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_nullsafe: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}|null
Trace on line %d: $_class_constant: App\Models\MechanicSpecialization&object{certification:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_declared: App\Models\MechanicSpecialization&object{declared:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_scoped: App\Models\WorkOrder&object{meta:Illuminate\Database\Eloquent\Relations\MorphPivot}
Trace on line %d: $_explicit_pivot_branch: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
Trace on line %d: $_intersected: App\Models\WorkOrder&object{details:Illuminate\Database\Eloquent\Relations\Pivot}&Tagged
Trace on line %d: $_relation_name: App\Models\MechanicSpecialization&object{mechanics:Illuminate\Database\Eloquent\Relations\Pivot}
