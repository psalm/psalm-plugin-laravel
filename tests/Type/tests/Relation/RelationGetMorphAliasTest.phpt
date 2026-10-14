--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Laravel declares `@return int|string`, but the body is `array_search(...) ?: $className`:
 * the `?:` discards the falsy keys 0 and '', falling back to the (non-empty) class name.
 * A morph map may carry integer keys, so the int arm stays (non-zero, which Psalm cannot express;
 * positive-int would be wrong for negative keys).
 */
function test_get_morph_alias(): void
{
    $_alias = Relation::getMorphAlias(Customer::class);
    /** @psalm-check-type-exact $_alias = int|non-empty-string */
}
?>
--EXPECTF--
