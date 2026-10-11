--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Covers the closure-predicate matcher shared by Collection/LazyCollection where()
 * and filter() (filter-specific rows live in CollectionFilterTest.phpt).
 *
 * Laravel's where() accepts a callable as the first arg and delegates to filter() at
 * runtime, but its declared signature is ($key, $operator, $value). The plugin's
 * CollectionFilterHandler narrows TValue when a single-param closure body matches one
 * of these AST shapes (both arrow and long-form):
 *
 *   - Identity: `fn ($x) => $x` — removes falsy types (null/false; string → non-falsy-string).
 *   - Instanceof: `fn ($x) => $x instanceof Foo` — intersects with Foo.
 *   - Type-check: `fn ($x) => is_string($x)` / `is_int`, `is_array`, `is_object`,
 *     `is_bool`, `is_float`, `is_null`, `is_callable`, `is_scalar`, `is_iterable`,
 *     `is_resource` (plus aliases), with exactly one argument.
 *   - Class check: `is_subclass_of($x, Foo::class)` / `is_a($x, Foo::class[, bool])`.
 *   - Null removal: `!is_null($x)`, `$x !== null`, `null !== $x`.
 *
 * Anything more ambitious (complex predicates, other negations, scope-dependent
 * narrowing) is opaque to the AST matcher. Larastan goes further via PHPStan's scope (PR
 * https://github.com/larastan/larastan/pull/2483); Psalm has no equivalent ready-made.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1018
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1648
 */
final class CollectionWhereCallbackTest
{
    /**
     * Predicate body is `$value > 2`, not the bare param. Handler refuses to narrow;
     * Psalm's default static return preserves the literal key/value unions.
     */
    public function whereWithGreaterThanCallback(): void
    {
        $_result = (new Collection([1, 2, 3, 4, 5, 6]))->where(fn (int $value): bool => $value > 2);
        /** @psalm-check-type-exact $_result = Collection<int<0, 5>, 1|2|3|4|5|6>&static */
    }

    /**
     * Same predicate as above on a non-literal source — still not narrowed.
     * @param Collection<int, int> $numbers
     */
    public function whereWithGreaterThanCallbackOnNonLiteral(Collection $numbers): void
    {
        $_result = $numbers->where(fn (int $value): bool => $value > 2);
        /** @psalm-check-type-exact $_result = Collection<int, int>&static */
    }

    /**
     * Predicate body is `!$user->blocked` — opaque to the handler, no narrowing.
     * @param Collection<int, CollectionWhereCallbackTestUser> $users
     */
    public function whereWithBooleanCallback(Collection $users): void
    {
        $_result = $users->where(fn (CollectionWhereCallbackTestUser $user): bool => !$user->blocked);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestUser>&static */
    }

    /**
     * Identity arrow closure → handler strips null/false via removeFalsy.
     * This is the headline use case: clean up null values produced by map().
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereStripsNullViaIdentityArrow(Collection $items): void
    {
        $_result = $items->where(fn (?CollectionWhereCallbackTestAccount $value) => $value);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount>&static */
    }

    /**
     * Identity long-form closure → same narrowing as the arrow form.
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereStripsNullViaIdentityClosure(Collection $items): void
    {
        $_result = $items->where(function (?CollectionWhereCallbackTestAccount $value) {
            return $value;
        });
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount>&static */
    }

    /**
     * Identity closure also strips false and narrows string → non-falsy-string (same
     * rules as filter() without a callback).
     * @param Collection<int, string|false|null> $items
     */
    public function whereWithIdentityClosureNarrowsString(Collection $items): void
    {
        $_result = $items->where(fn (string|false|null $value) => $value);
        /** @psalm-check-type-exact $_result = Collection<int, non-falsy-string>&static */
    }

    /**
     * Long-form closures with multiple statements are NOT recognized as identity —
     * the handler refuses to introspect arbitrary bodies.
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereWithMultiStatementClosureDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(function (?CollectionWhereCallbackTestAccount $value) {
            $alias = $value;

            return $alias;
        });
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount|null>&static */
    }

    /**
     * Column/operator/value form → no narrowing (and would be wrong to try).
     * @param Collection<int, array{name: string, age: int}> $rows
     */
    public function whereWithColumnOperatorValueDoesNotNarrow(Collection $rows): void
    {
        $_result = $rows->where('age', '>', 18);
        /** @psalm-check-type-exact $_result = Collection<int, array{age: int, name: string}>&static */
    }

    /**
     * Same identity-closure narrowing applies to LazyCollection.
     * @param LazyCollection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function lazyCollectionWhereWithIdentityClosureNarrows(LazyCollection $items): void
    {
        $_result = $items->where(fn (?CollectionWhereCallbackTestAccount $value) => $value);
        /** @psalm-check-type-exact $_result = LazyCollection<int, CollectionWhereCallbackTestAccount>&static */
    }

    /**
     * Static arrow closure (`static fn ($x) => $x`) carries the same body shape, so the
     * `static` modifier does not affect identity detection.
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereWithStaticIdentityArrowNarrows(Collection $items): void
    {
        $_result = $items->where(static fn (?CollectionWhereCallbackTestAccount $value) => $value);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount>&static */
    }

    /**
     * Headline use case from #1018: `->map(): ?T → ->where(fn ($x) => $x) → ->all()` cleans
     * up nulls produced by map() and flows the narrowed item type through to the array.
     * @param Collection<int, CollectionWhereCallbackTestUser> $users
     */
    public function mapThenWhereStripsNullEndToEnd(Collection $users): void
    {
        $_result = $users
            ->map(fn (CollectionWhereCallbackTestUser $u): ?CollectionWhereCallbackTestAccount => $u->blocked ? null : new CollectionWhereCallbackTestAccount())
            ->where(fn (?CollectionWhereCallbackTestAccount $a) => $a)
            ->all();
        /** @psalm-check-type-exact $_result = array<int, CollectionWhereCallbackTestAccount> */
    }

    /**
     * Variadic identity closure must NOT narrow: at runtime the parameter captures
     * `[$value, $key]` (always truthy when non-empty), so falsy-removal would be unsound.
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereWithVariadicIdentityDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (?CollectionWhereCallbackTestAccount ...$args) => $args);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount|null>&static */
    }

    /**
     * String callable like `where('strlen')` is forwarded to filter() at runtime,
     * but the handler only inspects literal Closure/ArrowFunction AST nodes — string
     * callables are not introspectable, so no narrowing.
     * @param Collection<int, string|null> $items
     */
    public function whereWithStringCallableDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where('strlen');
        /** @psalm-check-type-exact $_result = Collection<int, null|string>&static */
    }

    /**
     * Pre-bound closure variable is not a closure literal — handler refuses by design.
     * @param Collection<int, CollectionWhereCallbackTestAccount|null> $items
     */
    public function whereWithVariableClosureDoesNotNarrow(Collection $items): void
    {
        $callback = fn (?CollectionWhereCallbackTestAccount $value): ?CollectionWhereCallbackTestAccount => $value;
        $_result = $items->where($callback);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount|null>&static */
    }

    /**
     * `instanceof Foo` narrows TValue to atomics that ARE Foo, dropping non-matches.
     * @param Collection<int, CollectionWhereCallbackTestAccount|CollectionWhereCallbackTestUser|null> $items
     */
    public function whereWithInstanceofNarrows(Collection $items): void
    {
        $_result = $items->where(fn (CollectionWhereCallbackTestAccount|CollectionWhereCallbackTestUser|null $value) => $value instanceof CollectionWhereCallbackTestAccount);
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount>&static */
    }

    /**
     * `is_string($x)` narrows TValue to string atomics; non-strings drop out.
     * @param Collection<int, string|int|null> $items
     */
    public function whereWithIsStringNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string|int|null $value) => is_string($value));
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /**
     * `is_int($x)` keeps only int atomics.
     * @param Collection<int, string|int|null> $items
     */
    public function whereWithIsIntNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string|int|null $value) => is_int($value));
        /** @psalm-check-type-exact $_result = Collection<int, int>&static */
    }

    /**
     * `is_object($x)` keeps object atomics, drops scalars/null.
     * @param Collection<int, CollectionWhereCallbackTestAccount|string|null> $items
     */
    public function whereWithIsObjectNarrows(Collection $items): void
    {
        $_result = $items->where(fn (CollectionWhereCallbackTestAccount|string|null $value) => is_object($value));
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestAccount>&static */
    }

    /**
     * `is_null($x)` keeps only null. Inverse of the identity-closure null-strip case.
     * @param Collection<int, string|null> $items
     */
    public function whereWithIsNullNarrows(Collection $items): void
    {
        $_result = $items->where(fn (?string $value) => is_null($value));
        /** @psalm-check-type-exact $_result = Collection<int, null>&static */
    }

    /**
     * `is_array($x)` keeps array atomics. From `string|array|null` → `array`.
     * @param Collection<int, string|array<string, int>|null> $items
     */
    public function whereWithIsArrayNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string|array|null $value) => is_array($value));
        /** @psalm-check-type-exact $_result = Collection<int, array<string, int>>&static */
    }

    /**
     * `is_bool($x)` keeps bool atomics.
     * @param Collection<int, bool|null> $items
     */
    public function whereWithIsBoolNarrows(Collection $items): void
    {
        $_result = $items->where(fn (?bool $value) => is_bool($value));
        /** @psalm-check-type-exact $_result = Collection<int, bool>&static */
    }

    /**
     * is_string on `mixed` narrows to string (mixed intersection collapses to the target).
     * @param Collection<int, mixed> $items
     */
    public function whereWithIsStringOnMixedNarrows(Collection $items): void
    {
        $_result = $items->where(fn (mixed $value) => is_string($value));
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /**
     * Predicate body negation `! is_string($x)` is not the truthy form — handler refuses.
     * @param Collection<int, string|int|null> $items
     */
    public function whereWithNegatedTypeCheckDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (string|int|null $value) => !is_string($value));
        /** @psalm-check-type-exact $_result = Collection<int, int|null|string>&static */
    }

    /**
     * Long-form Closure with a type-check body (not arrow) is unwrapped through the same
     * extractor as the long-form identity test — locks in support for both forms.
     * @param Collection<int, string|int|null> $items
     */
    public function whereWithLongFormIsStringNarrows(Collection $items): void
    {
        $_result = $items->where(function (string|int|null $value): bool {
            return is_string($value);
        });
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /** @param Collection<int, Countable> $items */
    public function whereWithIsSubclassOfOnObjectNarrows(Collection $items): void
    {
        $_result = $items->where(fn (Countable $value) => is_subclass_of($value, Stringable::class));
        /** @psalm-check-type-exact $_result = Collection<int, Countable&Stringable>&static */
    }

    /** @param Collection<int, class-string<Countable>> $items */
    public function whereWithIsSubclassOfOnClassStringNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string $value) => is_subclass_of($value, Stringable::class));
        /** @psalm-check-type-exact $_result = Collection<int, class-string<Countable&Stringable>>&static */
    }

    /**
     * is_subclass_of() allows strings by default, so a plain string becomes a class-string.
     * @param Collection<int, string> $items
     */
    public function whereWithIsSubclassOfOnStringNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string $value) => is_subclass_of($value, Stringable::class));
        /** @psalm-check-type-exact $_result = Collection<int, class-string<Stringable>>&static */
    }

    /**
     * is_a() rejects strings unless the third argument is true.
     * @param Collection<int, Countable|string> $items
     */
    public function whereWithIsADropsStrings(Collection $items): void
    {
        $_result = $items->where(fn (Countable|string $value) => is_a($value, Stringable::class));
        /** @psalm-check-type-exact $_result = Collection<int, Countable&Stringable>&static */
    }

    /** @param Collection<int, Countable|string> $items */
    public function whereWithIsAAllowStringNarrows(Collection $items): void
    {
        $_result = $items->where(fn (Countable|string $value) => is_a($value, Stringable::class, true));
        /** @psalm-check-type-exact $_result = Collection<int, Countable&Stringable|class-string<Stringable>>&static */
    }

    /** @param Collection<int, mixed> $items */
    public function whereWithIsAOnMixedNarrows(Collection $items): void
    {
        $_result = $items->where(fn (mixed $value) => is_a($value, Stringable::class));
        /** @psalm-check-type-exact $_result = Collection<int, Stringable>&static */
    }

    /**
     * `is_callable($x, true)` only checks callable syntax, so it must not narrow.
     * @param Collection<int, mixed> $items
     */
    public function whereWithIsCallableSyntaxOnlyDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (mixed $value) => is_callable($value, true));
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /** @param Collection<int, string|null> $items */
    public function whereWithNegatedIsNullNarrows(Collection $items): void
    {
        $_result = $items->where(fn (?string $value) => !is_null($value));
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /** @param Collection<int, string|null> $items */
    public function whereWithYodaNotNullNarrows(Collection $items): void
    {
        $_result = $items->where(fn (?string $value) => null !== $value);
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /** @param Collection<int, string|int|array<string, int>|null> $items */
    public function whereWithIsScalarNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string|int|array|null $value) => is_scalar($value));
        /** @psalm-check-type-exact $_result = Collection<int, int|string>&static */
    }

    /** @param Collection<int, string|array<string, int>|null> $items */
    public function whereWithIsIterableNarrows(Collection $items): void
    {
        $_result = $items->where(fn (string|array|null $value) => is_iterable($value));
        /** @psalm-check-type-exact $_result = Collection<int, array<string, int>>&static */
    }

    /**
     * By-reference param: the closure could rewrite the value, so the body is opaque.
     * @param Collection<int, string|null> $items
     */
    public function whereWithByRefParamDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (?string &$value) => $value);
        /** @psalm-check-type-exact $_result = Collection<int, null|string>&static */
    }

    /**
     * Objects pass is_iterable() when Traversable, so object atomics narrow rather than drop.
     * The non-array branch proves the object part survives.
     * @param Collection<int, object|array<int, int>> $items
     */
    public function whereWithIsIterableKeepsTraversableObjects(Collection $items): void
    {
        foreach ($items->where(fn (object|array $value) => is_iterable($value)) as $value) {
            if (!is_array($value)) {
                /** @psalm-check-type-exact $value = Traversable */
            }
        }
    }

    /**
     * Callbacks run in coercive mode: a Stringable item reaches a `string` param as its
     * __toString() value, so the predicate does not describe the stored item.
     * @param Collection<int, CollectionWhereCallbackTestLabel|string> $items
     */
    public function whereWithCoercedClassCheckDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (string $value) => is_a($value, Countable::class, true));
        /** @psalm-check-type-exact $_result = Collection<int, CollectionWhereCallbackTestLabel|string>&static */
    }

    /** @param Collection<int, int|string> $items */
    public function whereWithCoercedIntDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (string $value) => is_a($value, Countable::class, true));
        /** @psalm-check-type-exact $_result = Collection<int, int|string>&static */
    }

    /**
     * A literal string is a specific value, not a provable class-string.
     * @param Collection<int, 'ArrayObject'> $items
     */
    public function whereWithClassCheckOnLiteralStringDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (string $value) => is_a($value, Countable::class, true));
        /** @psalm-check-type-exact $_result = Collection<int, 'ArrayObject'>&static */
    }

    /**
     * class-string is not a lowercase-string; mapping would discard the refinement.
     * @param Collection<int, lowercase-string> $items
     */
    public function whereWithClassCheckOnRefinedStringDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (string $value) => is_a($value, Countable::class, true));
        /** @psalm-check-type-exact $_result = Collection<int, lowercase-string>&static */
    }

    /**
     * @template T
     * @param Collection<int, T> $items
     */
    public function whereWithClassCheckOnTemplateDoesNotNarrow(Collection $items): void
    {
        $_result = $items->where(fn (mixed $value) => is_a($value, Countable::class));
        /** @psalm-check-type-exact $_result = Collection<int, T>&static */
    }

    /** @param Collection<int, Countable|string> $items */
    public function whereWithNonLiteralAllowStringDoesNotNarrow(Collection $items, bool $allowString): void
    {
        $_result = $items->where(fn (Countable|string $value) => is_a($value, Stringable::class, $allowString));
        /** @psalm-check-type-exact $_result = Collection<int, Countable|string>&static */
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

final class CollectionWhereCallbackTestLabel implements Stringable
{
    #[\Override]
    public function __toString(): string
    {
        return 'ArrayObject';
    }
}
?>
--EXPECTF--
