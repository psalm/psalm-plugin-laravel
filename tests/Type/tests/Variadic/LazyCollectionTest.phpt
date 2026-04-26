--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\LazyCollection;

/**
 * LazyCollection::has(), hasAny(), doesntContain(), doesntContainStrict(), zip()
 * mirror their Collection counterparts and accept variadic arguments via
 * func_get_args(). Without @psalm-variadic the multi-arg calls would be
 * rejected as TooManyArguments.
 *
 * @param LazyCollection<string, string> $c
 */
function lazy_collection_has_variadic(LazyCollection $c): void
{
    $_single = $c->has('name');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $c->has('name', 'email', 'missing');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $c->has(['name', 'email']);
    /** @psalm-check-type-exact $_array = bool */
}

/** @param LazyCollection<string, string> $c */
function lazy_collection_has_any_variadic(LazyCollection $c): void
{
    $_single = $c->hasAny('name');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $c->hasAny('name', 'email', 'missing');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $c->hasAny(['name', 'email']);
    /** @psalm-check-type-exact $_array = bool */
}

/** @param LazyCollection<int, string> $c */
function lazy_collection_doesnt_contain_variadic(LazyCollection $c): void
{
    $_single = $c->doesntContain('needle');
    /** @psalm-check-type-exact $_single = bool */

    $_triple = $c->doesntContain('status', '=', 'active');
    /** @psalm-check-type-exact $_triple = bool */
}

/**
 * @param LazyCollection<int, string> $c
 * @param LazyCollection<int, string> $other
 */
function lazy_collection_zip_variadic(LazyCollection $c, LazyCollection $other): void
{
    $_one = $c->zip($other);
    /** @psalm-check-type-exact $_one = LazyCollection<int, LazyCollection<int, string>&static>&static */

    $_two = $c->zip($other, ['x', 'y']);
    /** @psalm-check-type-exact $_two = LazyCollection<int, LazyCollection<int, string>&static>&static */

    $_three = $c->zip($other, ['x', 'y'], ['a', 'b']);
    /** @psalm-check-type-exact $_three = LazyCollection<int, LazyCollection<int, string>&static>&static */
}
?>
--EXPECTF--
