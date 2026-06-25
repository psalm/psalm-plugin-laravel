--FILE--
<?php declare(strict_types=1);

use App\Models\WorkOrder;

/**
 * Reproducer for https://github.com/psalm/psalm-plugin-laravel/issues/805
 *
 * A custom query builder method (WorkOrderBuilder::withFavoriteStatus) attaches a computed
 * column to fetched rows via addSelect(['favorite' => ...]). Consumers then read
 * $workOrder->favorite, but the plugin does not track attribute additions made by
 * scope/builder methods, so every such read is a false-positive UndefinedMagicPropertyFetch
 * (psalm/218).
 *
 * This test currently documents the buggy behavior (the false positive in --EXPECTF--).
 * When the underlying feature lands, the UndefinedMagicPropertyFetch line for $favorite must
 * be removed and the property read asserted clean (and ideally typed).
 *
 * Sibling closed issues #510 / #503 already handle the withCount() aggregate-suffix case;
 * arbitrary addSelect()-based scopes are the open generalisation.
 */
function test_scope_added_attribute_is_undefined(): void
{
    $workOrder = WorkOrder::query()->withFavoriteStatus(1)->firstOrFail();

    // FALSE POSITIVE (the bug): the plugin does not know withFavoriteStatus() added
    // the `favorite` column, so this read is reported as an undefined magic property.
    $_favorite = $workOrder->favorite;
}
?>
--EXPECTF--
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\WorkOrder::$favorite is not defined
