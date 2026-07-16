--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — a bare (non-closure) Generator passed directly to LazyCollection::make()
 * type-checks clean here, but Laravel's runtime throws InvalidArgumentException for it: only the
 * closure-returning-a-generator form (`LazyCollection::make(fn () => yield ...)`) is accepted,
 * not a directly-constructed Generator instance.
 *
 * `iterable<TMakeKey, TMakeValue>` in the stub matches any Traversable, including Generator, and
 * "Traversable minus Generator" can't be expressed in Psalm's type algebra. Laravel's own
 * docblock has the same imprecision (it types `$items` as `iterable`, not narrower). This test
 * pins the CURRENT (imprecise) behaviour - zero issues - so it flips loudly if a future Psalm
 * type-algebra feature makes the narrower exclusion expressible.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 */

use Illuminate\Support\LazyCollection;

/** @return \Generator<int, int> */
function makeBareGenerator(): \Generator
{
    yield 1;
    yield 2;
}

// Type-checks clean (no InvalidArgument), even though this throws at runtime.
$_bareGenerator = LazyCollection::make(makeBareGenerator());
/** @psalm-check-type-exact $_bareGenerator = LazyCollection<int, int> */

?>
--EXPECTF--
