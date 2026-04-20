--FILE--
<?php declare(strict_types=1);

use App\Builders\ScopedSongBuilder;
use App\Models\Admin;
use App\Models\ScopedSong;

/**
 * Regression test for https://github.com/psalm/psalm-plugin-laravel/issues/795
 *
 * Traits that declare `@method static Builder query()` (e.g. Koel's
 * SupportsDeleteWhereValueNotIn) inject a zero-param pseudo into every model
 * that uses them. Before the fix, Psalm's static call analyzer validated
 * argument lists against that pseudo rather than the overriding signature on
 * the model, emitting TooManyArguments and InvalidNamedArgument for every
 * call like `Song::query(type: $t, user: $u)`.
 *
 * ScopedSong mirrors the Koel shape exactly: custom builder via
 * #[UseEloquentBuilder], trait with @method static Builder query(), and an
 * overriding static query() with extra parameters.
 *
 * The fix drops shadowing pseudo_static_methods from Model subclasses during
 * AfterCodebasePopulated, after the populator has merged trait pseudos into
 * the model's storage.
 */

/** Overriding query() accepts named args without shadowing from the trait's pseudo. */
function test_query_override_named_args(): void
{
    $_result = ScopedSong::query(type: 'song', user: new Admin());
    /** @psalm-check-type-exact $_result = ScopedSongBuilder */
}

/** Same signature accepts positional args too. */
function test_query_override_positional_args(): void
{
    $_result = ScopedSong::query('song', new Admin());
    /** @psalm-check-type-exact $_result = ScopedSongBuilder */
}

/** Zero-arg call still works (all params optional). */
function test_query_override_no_args(): void
{
    $_result = ScopedSong::query();
    /** @psalm-check-type-exact $_result = ScopedSongBuilder */
}
?>
--EXPECT--
