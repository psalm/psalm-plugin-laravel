--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * A keyed read is `mixed`: flashed input holds JSON scalars and anything `withInput(array)` was
 * given, and a `Model` default resolves to an attribute of any cast type. `$default` is not
 * constrained, so an int, bool or object fallback is accepted.
 */
function test_old_keyed(Request $request): void
{
    $_ = old('name');
    /** @psalm-check-type-exact $_ = mixed */

    $_ = old('qty', 1);
    /** @psalm-check-type-exact $_ = mixed */

    $_ = old('at', new \DateTimeImmutable());
    /** @psalm-check-type-exact $_ = mixed */

    $_ = old('customer', new Customer());
    /** @psalm-check-type-exact $_ = mixed */

    $_ = $request->old('name', 'anonymous');
    /** @psalm-check-type-exact $_ = mixed */
}

/**
 * Without a key: the whole input array (numeric field names become int keys), or `null` when the
 * request has no session.
 */
function test_old_without_key(Request $request): void
{
    $_ = old();
    /** @psalm-check-type-exact $_ = array<array-key, mixed>|null */

    $_ = $request->old();
    /** @psalm-check-type-exact $_ = array<array-key, mixed>|null */
}

?>
--EXPECTF--
