--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/883.
 *
 * BelongsToMany / MorphToMany relation method bodies that don't include
 * `->using(...)` / `->as(...)` chain mutations must still produce a fully-
 * applied 4-template type. The handler-emitted Union overrides any user-
 * declared docblock at the call site, and Psalm rejects partial 2-of-4 forms
 * (consumers trip MissingTemplateParam) even when the stub declares a default
 * for the missing slots. The pivot default is per-relation: BelongsToMany =>
 * Pivot, MorphToMany => MorphPivot. The accessor default is 'pivot'.
 *
 * The chain-consumer tests below are the regression oracle: empty `--EXPECTF--`
 * asserts that `wherePivot()->count()` (the user's reported shape) does not
 * trip MissingTemplateParam. The typed-return tests pin the expected emission
 * shape; they don't catch a 2-of-4 regression on their own (Psalm normalises
 * 2-template against 4-template via the stub default declared by
 * `@template T of … = Default`), but reading them clarifies which slots the
 * handler should fill.
 */

// Typed-return assertions pin the expected emission. `Shop::parts()` and
// `Shop::allWorkOrders()` carry no `@psalm-return` docblock, so the handler-
// emitted Union is what Psalm reports here. `WorkOrder::parts()` declares a
// 2-template `@psalm-return` docblock, demonstrating that handler emission
// wins over the user docblock at the call site.

/**
 * @psalm-return BelongsToMany<Part, Shop, Pivot, 'pivot'>
 */
function test_belongsToMany_no_chain_emits_4_templates(): BelongsToMany
{
    return (new Shop())->parts();
}

/**
 * @psalm-return MorphToMany<WorkOrder, Shop, MorphPivot, 'pivot'>
 */
function test_morphToMany_no_chain_emits_4_templates(): MorphToMany
{
    return (new Shop())->allWorkOrders();
}

/**
 * @psalm-return BelongsToMany<Part, WorkOrder, Pivot, 'pivot'>
 */
function test_belongsToMany_handler_overrides_two_template_docblock(): BelongsToMany
{
    return (new WorkOrder())->parts();
}

// Chain-consumer scenarios from the issue's reproducer. The chain
// `wherePivot()->count()` trips MissingTemplateParam under a 2-of-4
// emission, so the empty `--EXPECTF--` block below is the regression oracle.

function test_belongsToMany_no_chain_consumer_chain_resolves(): int
{
    return (new Shop())->parts()
        ->wherePivot('quantity', 1)
        ->count();
}

function test_morphToMany_no_chain_consumer_chain_resolves(): int
{
    return (new Shop())->allWorkOrders()
        ->wherePivot('priority', 'high')
        ->count();
}
?>
--EXPECTF--
