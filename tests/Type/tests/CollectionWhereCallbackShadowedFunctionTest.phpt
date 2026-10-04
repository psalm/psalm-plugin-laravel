--FILE--
<?php declare(strict_types=1);

/**
 * The Collection where()/filter() predicate matcher only trusts calls that resolve to the
 * global builtin: a same-named namespaced function or a `use function` import wins at
 * runtime and may mean anything.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1648
 */

namespace CollectionWhereCallbackShadowedFunctionTest\Helpers {
    function is_string(mixed $value): bool
    {
        return $value !== null;
    }
}

namespace CollectionWhereCallbackShadowedFunctionTest {
    use Illuminate\Support\Collection;

    use function CollectionWhereCallbackShadowedFunctionTest\Helpers\is_string;

    function is_a(mixed $value, string $class): bool
    {
        return $value !== $class;
    }

    final class CollectionWhereCallbackShadowedFunctionTest
    {
        /** @param Collection<int, string|\stdClass> $items */
        public function namespacedFunctionDoesNotNarrow(Collection $items): void
        {
            $_result = $items->where(fn (string|\stdClass $value) => is_a($value, \stdClass::class));
            /** @psalm-check-type-exact $_result = Collection<int, \stdClass|string>&static */
        }

        /** @param Collection<int, string|int> $items */
        public function importedFunctionDoesNotNarrow(Collection $items): void
        {
            $_result = $items->where(fn (string|int $value) => is_string($value));
            /** @psalm-check-type-exact $_result = Collection<int, int|string>&static */
        }

        /** @param Collection<int, string|\stdClass> $items */
        public function fullyQualifiedBuiltinNarrows(Collection $items): void
        {
            $_result = $items->where(fn (string|\stdClass $value) => \is_a($value, \stdClass::class));
            /** @psalm-check-type-exact $_result = Collection<int, \stdClass>&static */
        }

        /** @param Collection<int, string|null> $items */
        public function unshadowedBuiltinFallbackNarrows(Collection $items): void
        {
            $_result = $items->where(fn (?string $value) => !is_null($value));
            /** @psalm-check-type-exact $_result = Collection<int, string>&static */
        }
    }
}
?>
--EXPECTF--
