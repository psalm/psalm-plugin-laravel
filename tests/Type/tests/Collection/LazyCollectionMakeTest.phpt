--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 *
 * LazyCollection::make() declares its own $items param (not inherited from EnumeratesValues, since
 * it adds the closure-generator and self<...> forms), so it's widened directly in
 * stubs/common/Support/LazyCollection.phpstub. The closure-generator template binding comes from
 * Laravel's own docblock branch, kept as-is.
 */

use Illuminate\Support\LazyCollection;

$_noArgs = LazyCollection::make();
/** @psalm-check-type-exact $_noArgs = LazyCollection<never, never> */

// Closure-generator form: TClosure is excluded from the resolver (CollectionInputTypeResolver), so
// this always defers to the native docblock's `(Closure(): Generator<...>)` branch, unaffected by
// the widening (no InvalidArgument either way). That branch doesn't fully bind TKey/TValue from a
// non-arrow Closure body today - pre-existing, unrelated to #808 - so this only pins "no
// regression", not full precision.
$_closure = LazyCollection::make(function () {
    yield 1;
    yield 2;
});
/** @psalm-check-type-exact $_closure = LazyCollection<array-key, mixed> */

$_array = LazyCollection::make([1, 2]);
/** @psalm-check-type-exact $_array = LazyCollection<int<0, 1>, 1|2> */

$_string = LazyCollection::make('str');
/** @psalm-check-type-exact $_string = LazyCollection<0, 'str'>&static */

$_null = LazyCollection::make(null);
/** @psalm-check-type-exact $_null = LazyCollection<never, never>&static */

$_int = LazyCollection::make(42);
/** @psalm-check-type-exact $_int = LazyCollection<0, 42>&static */

?>
--EXPECTF--
