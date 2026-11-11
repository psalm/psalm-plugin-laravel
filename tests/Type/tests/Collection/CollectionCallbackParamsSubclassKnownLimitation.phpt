--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Collection;

/** @extends Collection<int, Customer> */
final class CallbackParamsCustomerList extends Collection {}

/**
 * Known limitation (see the `tap()` docblock in stubs/common/Support/Collection.phpstub): the callback
 * stubs on Collection do not reach its subclasses (vimeo/psalm#12158). An untyped tap()/pipe() param
 * resolves to the subclass since Psalm 7.3 (vimeo/psalm#13494), so that part is covered by
 * CollectionCallbackParamsTest.phpt. What remains: whenEmpty()/whenNotEmpty() keep Laravel's
 * `callable($this)` from the EnumeratesValues trait and leave the param `mixed`.
 * chunkWhile() is a Collection method, so its chunk is typed on subclasses too.
 */
// @todo-by https://github.com/vimeo/psalm/issues/12158 turn into positive coverage
function subclass_callbacks(CallbackParamsCustomerList $customers): void
{
    $customers->whenEmpty(fn ($_c) => null);
    $customers->whenNotEmpty(fn ($_c) => null);

    $customers->chunkWhile(function (Customer $_customer, int $_key, $chunk): bool {
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        return $chunk->isNotEmpty();
    });
}
?>
--EXPECTF--
MissingClosureParamType on line %d: Parameter $_c has no provided type
MissingClosureParamType on line %d: Parameter $_c has no provided type
