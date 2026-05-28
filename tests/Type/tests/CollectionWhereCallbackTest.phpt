--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

/**
 * Documents Psalm's behavior for Collection::where() called with a callback.
 *
 * Laravel's where() accepts a callable as the first arg and delegates to filter(),
 * but its declared signature is ($key, $operator, $value). Psalm has no native
 * understanding of the callback-form, and the plugin's CollectionFilterHandler
 * only narrows filter()/whereNotNull() — not where().
 *
 * Larastan adds dynamic narrowing for this pattern in
 * https://github.com/larastan/larastan/pull/2483. This test snapshots the
 * un-narrowed behavior so we notice if/when Psalm or the plugin gains support.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1018
 */
final class CollectionWhereCallbackTest
{
    /**
     * Larastan narrows to Collection<int, int<3, max>>.
     * Psalm leaves the literal value union as-is — the `> 2` predicate is opaque.
     */
    public function whereWithGreaterThanCallback(): void
    {
        $_result = (new Collection([1, 2, 3, 4, 5, 6]))->where(fn (int $value): bool => $value > 2);
        /** @psalm-check-type-exact $_result = Collection<int<0, 5>, 1|2|3|4|5|6>&static */
    }

    /**
     * Same predicate as above, but with a non-literal source.
     * Larastan would still narrow to int<3, max>; Psalm keeps the int as-is.
     * @param Collection<int, int> $numbers
     */
    public function whereWithGreaterThanCallbackOnNonLiteral(Collection $numbers): void
    {
        $_result = $numbers->where(fn (int $value): bool => $value > 2);
        /** @psalm-check-type-exact $_result = Collection<int, int>&static */
    }

    /**
     * Larastan keeps Collection<int, User>; Psalm keeps the same shape.
     * Identical outcome here, but only because the callback doesn't narrow.
     * @param Collection<int, CollectionWhereCallbackTestUser> $users
     */
    public function whereWithBooleanCallback(Collection $users): void
    {
        $_result = $users->where(fn (CollectionWhereCallbackTestUser $user): bool => !$user->blocked);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestUser>&static */
    }

    /**
     * Larastan strips null via the truthy callback (Collection<int, Account>).
     * Psalm leaves null in place.
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereStripsNullViaTruthyCallback(Collection $items): void
    {
        $_result = $items->where(fn (?CollectionWhereCallbackTestAccount $value) => $value);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount|null>&static */
    }

    /**
     * Column/operator form — neither larastan nor psalm narrow this.
     * @param Collection<int, array{name: string, age: int}> $rows
     */
    public function whereWithColumnOperatorValueDoesNotNarrow(Collection $rows): void
    {
        $_result = $rows->where('age', '>', 18);
        /** @psalm-check-type-exact $_result = Collection<int, array{age: int, name: string}>&static */
    }
}

final class CollectionWhereCallbackTestUser
{
    public bool $blocked = false;
}

final class CollectionWhereCallbackTestAccount
{
    public int $id = 0;
}
?>
--EXPECT--
