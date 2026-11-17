--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Under the default config (`findArrKeyNotFound` off) a definitely-absent key is typed but never
 * reported. The same shapes are reported in ArrKeyNotFoundTest.phpt.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2345
 */

/** @param array{a: int} $shape */
function test_get_absent_key_is_silent(array $shape): void
{
    $_result = Arr::get($shape, 'z');
    /** @psalm-check-type-exact $_result = null */
}

/** @param array{a: int} $shape */
function test_pull_absent_key_is_silent(array $shape): void
{
    $_result = Arr::pull($shape, 'z', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback' */
}

/** @param array{a: array{b: int}} $shape */
function test_get_absent_dot_segment_is_silent(array $shape): void
{
    $_result = Arr::get($shape, 'a.c', 'fallback');
    /** @psalm-check-type-exact $_result = 'fallback' */
}
?>
--EXPECTF--
