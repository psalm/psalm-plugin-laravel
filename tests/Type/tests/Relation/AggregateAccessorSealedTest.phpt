--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-sealed.xml
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Shop;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/503
 *
 * With sealAllProperties="true", aggregate-suffix properties that have NO matching
 * relation method should still trigger UndefinedMagicPropertyFetch. This guards
 * against the handler accidentally returning true from doesPropertyExist() for
 * property names that merely end in a known suffix.
 */
function test_count_undefined_relation(Shop $shop): void
{
    // 'nonexistent_count': ends with _count but Shop has no nonexistent() relation.
    $count = $shop->nonexistent_count;
}

function test_count_write_is_rejected(Shop $shop): void
{
    // Aggregate properties are read-only; writing should produce UndefinedMagicPropertyAssignment.
    $shop->work_orders_count = 5;
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $count is being assigned to
UnusedVariable on line %d: $count is never referenced or the value is not used
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Shop::$nonexistent_count is not defined
UndefinedMagicPropertyAssignment on line %d: Magic instance property App\Models\Shop::$work_orders_count is not defined
