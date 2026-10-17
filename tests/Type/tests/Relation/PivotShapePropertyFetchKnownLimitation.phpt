--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\PivotShapeFetch;

use App\Models\Part;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * KNOWN LIMITATION, pinned: points 2 and 3 of https://github.com/psalm/psalm-plugin-laravel/issues/1944.
 *
 * Reading a column through an object shape on a class that has a `@mixin` reports a false positive:
 *
 *   - `Pivot&object{role: string}` -> `$p->role`
 *   - `Part&object{pivot: Pivot&object{...}}` (the `firstOrFail()` / `first()` / `get()` item type of
 *     BelongsToMany) -> `->pivot`
 *
 * Root cause is upstream Psalm, vimeo/psalm#12110: in `AtomicPropertyFetchAnalyzer` the recursion into
 * the intersection members is an `elseif` after the `@mixin` branch, so a class with any `@mixin` never
 * looks at its `object{...}` shape. Psalm only sees a mixin because this plugin's `Model` stub declares
 * `@mixin \Illuminate\Database\Eloquent\Builder<static>`, which `Pivot` and every model inherit.
 * The fix, vimeo/psalm#12111, is merged into 6.x only; Psalm 7 (master) still needs the merge-up.
 *
 * The plugin cannot fix this soundly: property provider events (`PropertyExistenceProviderEvent` and
 * friends) carry only the class name and the property name, never the receiver's intersection types,
 * so a provider could only whitelist `Pivot::$anything` or `Model::$pivot` for every receiver and
 * silence real typos. Do not "fix" it here.
 *
 * When the minimum supported Psalm contains the upstream fix this fixture's output turns empty (and
 * the property types become `string` / the shape): the test goes red, which is the signal to rename
 * it and replace it with positive coverage (see tests/Type/README.md gotcha 6).
 */

/** @param Pivot&object{role: string} $p */
function pivot_shape_read(Pivot $p): mixed
{
    return $p->role;
}

/** @param BelongsToMany<Part, WorkOrder, Pivot&object{role: string}, 'pivot'> $relation */
function pivot_property_through_first_or_fail(BelongsToMany $relation): mixed
{
    return $relation->firstOrFail()->pivot;
}

?>
--EXPECTF--
UndefinedMagicPropertyFetch on line %d: Magic instance property Illuminate\Database\Eloquent\Relations\Pivot::$role is not defined
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Part::$pivot is not defined
