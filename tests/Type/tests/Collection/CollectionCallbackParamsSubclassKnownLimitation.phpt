--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Collection;

/** @extends Collection<int, Customer> */
final class CallbackParamsCustomerList extends Collection {}

/**
 * Known limitation (see the `tap()` docblock in stubs/common/Support/Collection.phpstub): the callback
 * stubs on Collection do not reach its subclasses, which keep Laravel's `callable($this)` from the
 * EnumeratesValues trait (vimeo/psalm#12158). An untyped closure param is still rejected; a typed one
 * passes. chunkWhile() is a Collection method, so its chunk is typed on subclasses too.
 */
function subclass_callbacks(CallbackParamsCustomerList $customers): void
{
    $customers->tap(function ($_c): void {});
    $customers->tap(fn (CallbackParamsCustomerList $c): int => $c->count());

    $customers->chunkWhile(function (Customer $_customer, int $_key, $chunk): bool {
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        return $chunk->isNotEmpty();
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of CallbackParamsCustomerList::tap expects callable[impure](CallbackParamsCustomerList):mixed, but Closure[pure](static):void provided
