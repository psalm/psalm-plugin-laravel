--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Support\Facades\Schema;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/774
 *
 * Laravel stubs widened to accept \Stringable (not just string) for methods that
 * runtime-accept stringable inputs. Each test below passes a Stringable and must
 * not raise ImplicitToStringCast.
 */

// Scalar \Stringable is intentionally NOT accepted by Stringable::contains: Str::contains
// normalises via `(array) $needles` which casts an object to its properties rather than
// wrapping it in a one-element array, producing a silent `false` for generic \Stringable.
// See the stub docblock. The iterable form is the supported path.

function test_stringable_contains_accepts_stringable_iterable(Stringable $haystack, Stringable $n1, Stringable $n2): bool
{
    return $haystack->contains([$n1, $n2]);
}

function test_stringable_contains_still_accepts_string(Stringable $haystack): bool
{
    return $haystack->contains('foo');
}

function test_stringable_is_accepts_stringable(Stringable $haystack, Stringable $pattern): bool
{
    return $haystack->is($pattern);
}

function test_stringable_is_accepts_stringable_iterable(Stringable $haystack, Stringable $p1, Stringable $p2): bool
{
    return $haystack->is([$p1, $p2]);
}

function test_str_plural_accepts_stringable(Stringable $word): string
{
    return Str::plural($word);
}

function test_str_singular_accepts_stringable(Stringable $word): string
{
    return Str::singular($word);
}

function test_str_slug_accepts_stringable(Stringable $title): string
{
    return Str::slug($title);
}

// Note: Str::camel, Str::studly, Str::snake are intentionally NOT covered here.
// They use $value as an array key for internal caches and would throw
// "Illegal offset type" at runtime if a \Stringable were passed.

function test_str_helper_accepts_stringable(Stringable $value): Stringable
{
    return str($value);
}

function test_schema_has_table_accepts_stringable(Stringable $table): bool
{
    return Schema::hasTable($table);
}

// Arbitrary \Stringable objects (not Illuminate\Support\Stringable) must also work.
final class SomeStringable implements \Stringable
{
    #[\Override]
    public function __toString(): string
    {
        return 'foo';
    }
}

function test_plain_stringable_works_everywhere(SomeStringable $s): void
{
    Str::plural($s);
    Str::singular($s);
    str($s);
    Schema::hasTable($s);
}
?>
--EXPECTF--
