--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\Collection;

/**
 * Collection::has(), doesntContain(), doesntContainStrict(), zip() accept variadic
 * arguments via func_get_args(). Without @psalm-variadic the multi-arg calls would
 * be rejected as TooManyArguments.
 *
 * @param Collection<string, string> $c
 */
function collection_has_variadic(Collection $c): void
{
    $_single = $c->has('name');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $c->has('name', 'email', 'missing');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $c->has(['name', 'email']);
    /** @psalm-check-type-exact $_array = bool */
}

/** @param Collection<int, string> $c */
function collection_doesnt_contain_variadic(Collection $c): void
{
    $_single = $c->doesntContain('needle');
    /** @psalm-check-type-exact $_single = bool */

    $_triple = $c->doesntContain('status', '=', 'active');
    /** @psalm-check-type-exact $_triple = bool */
}

/** @param Collection<int, string> $c */
function collection_doesnt_contain_strict_variadic(Collection $c): void
{
    $_single = $c->doesntContainStrict('needle');
    /** @psalm-check-type-exact $_single = bool */

    $_triple = $c->doesntContainStrict('status', '=', 'active');
    /** @psalm-check-type-exact $_triple = bool */
}

/**
 * @param Collection<int, string> $c
 * @param Collection<int, string> $other
 */
function collection_zip_variadic(Collection $c, Collection $other): void
{
    $_one = $c->zip($other);
    /** @psalm-check-type-exact $_one = Collection<int, Collection<int, string>&static>&static */

    $_two = $c->zip($other, ['x', 'y']);
    /** @psalm-check-type-exact $_two = Collection<int, Collection<int, string>&static>&static */

    $_three = $c->zip($other, ['x', 'y'], ['a', 'b']);
    /** @psalm-check-type-exact $_three = Collection<int, Collection<int, string>&static>&static */
}
?>
--EXPECTF--
