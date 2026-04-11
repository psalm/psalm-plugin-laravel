--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/717
 */

/** When $subject is a string, Str::replace() must return string, not string|string[]. */
function test_str_replace_with_string_subject(string $subject): void
{
    $_result = Str::replace('foo', 'bar', $subject);
    /** @psalm-check-type-exact $_result = string */
}

/** When $subject is a string with array search/replace, must still return string. */
function test_str_replace_with_array_search_and_string_subject(string $subject): void
{
    $_result = Str::replace(['foo', 'baz'], ['bar', 'qux'], $subject);
    /** @psalm-check-type-exact $_result = string */
}

/**
 * When $subject is array<int, string>, must return array<int, string> (key-preserving).
 * Exercises the non-Traversable array branch of the conditional return type.
 *
 * @param array<int, string> $subjects
 */
function test_str_replace_with_array_subject(array $subjects): void
{
    $_result = Str::replace('foo', 'bar', $subjects);
    /** @psalm-check-type-exact $_result = array<int, string> */
}

/**
 * When $subject is a Traversable<int, string>, must return array<int, string>.
 *
 * @param \Traversable<int, string> $subjects
 */
function test_str_replace_with_traversable_subject(\Traversable $subjects): void
{
    $_result = Str::replace('foo', 'bar', $subjects);
    /** @psalm-check-type-exact $_result = array<int, string> */
}
?>
--EXPECTF--
