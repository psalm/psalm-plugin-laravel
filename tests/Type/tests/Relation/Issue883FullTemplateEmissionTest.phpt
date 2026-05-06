--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/883
 *
 * `belongsToMany()` / `morphToMany()` relation bodies that don't include
 * `->using(...)` or `->as(...)` chain mutations must still produce a fully-
 * applied 4-template `BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel,
 * TAccessor>` (or `MorphToMany<...>`). A partial 2-of-4 emission overrides any
 * user-declared 4-template docblock and trips `MissingTemplateParam` on
 * consumers, because Psalm rejects the partial form once any template slot is
 * supplied — even when the stub declares `@template-default`.
 *
 * `@psalm-check-type-exact` silently normalises a 2-template type against a
 * 4-template assertion using stub defaults, so it cannot detect the regression.
 * `@psalm-trace` emits a Trace issue containing the literal inferred type,
 * which EXPECTF matches verbatim — that's the only way to lock in the
 * 4-template emission. Both `Shop::parts()` and `Shop::allWorkOrders()` are
 * declared without a `@psalm-return` docblock, so the handler-emitted Union
 * is what Psalm reports here (no docblock return type to interfere).
 *
 * The pivot default is per-relation: `BelongsToMany` => `Pivot`, `MorphToMany`
 * => `MorphPivot`. The accessor default is `'pivot'`.
 */

// belongsToMany without `->using()` / `->as()` and no `@psalm-return` docblock
// — emits the full 4-template form with the BelongsToMany default (`Pivot`).
function test_belongsToMany_without_chain_emits_4_templates(): BelongsToMany
{
    $r = (new Shop())->parts();
    /** @psalm-trace $r */
    return $r;
}

// morphToMany without chain mutations and no `@psalm-return` docblock: the
// per-relation pivot default is `MorphPivot`, not `Pivot`. A handler that
// hard-coded `Pivot` for the default fallback would silently emit the wrong
// type for accessor-only morph chains.
function test_morphToMany_without_chain_emits_4_templates_with_morphPivot_default(): MorphToMany
{
    $r = (new Shop())->allWorkOrders();
    /** @psalm-trace $r */
    return $r;
}

// belongsToMany with a 2-template `@psalm-return BelongsToMany<Part, $this>` docblock:
// this case verifies the precedence between the user docblock and the handler-emitted
// type. `WorkOrder::parts()` declares the 2-template form; the handler emits 4
// templates. The trace shows whichever wins. The 2-template docblock is itself a
// historical workaround for the partial-emission shape; once the handler emits the
// full 4-template form, those `@psalm-return` docblocks become redundant.
function test_belongsToMany_with_two_template_docblock_emits_full_4_templates(): BelongsToMany
{
    $r = (new WorkOrder())->parts();
    /** @psalm-trace $r */
    return $r;
}
?>
--EXPECTF--
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\Shop, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\MorphToMany<App\Models\WorkOrder, App\Models\Shop, Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
