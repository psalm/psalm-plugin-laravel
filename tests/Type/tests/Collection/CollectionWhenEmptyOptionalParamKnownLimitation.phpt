--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Known limitation (see the `whenEmpty()` docblock in stubs/common/Support/Collection.phpstub): the
 * single-param callable arm also accepts a callable whose optional 2nd param is not bool-compatible,
 * although Laravel calls it with `$callback($this, $value)` and a bool, which throws a TypeError.
 *
 * @param Collection<int, Customer> $customers
 * @param callable(Collection<int, Customer>, array<array-key, mixed>=): int $callback
 */
function optional_non_bool_second_param(Collection $customers, callable $callback): void
{
    $customers->whenEmpty(fn (Collection $c, array $value = []): int => $c->count() + \count($value));
    $customers->whenEmpty($callback);
}
?>
--EXPECTF--
