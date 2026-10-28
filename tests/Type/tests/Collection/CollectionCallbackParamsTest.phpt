--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Laravel hands `$this` to the callbacks of tap(), pipe() and the whenEmpty() family, and a plain
 * Collection chunk to chunkWhile(). Untyped closure params resolve to the receiver with its generics;
 * typed ones are checked against it. The whenEmpty() family returns the receiver unless a callback
 * returns non-null (`$callback(...) ?? $this`).
 */
final class CollectionCallbackParamsTest
{
    /** @param Collection<int, Customer> $customers */
    public function collection(Collection $customers): void
    {
        $customers->tap(function ($_c): void {
            /** @psalm-check-type-exact $_c = Collection<int, Customer> */
        });
        $customers->tap(fn (Collection $c): int => $c->count());

        $_count = $customers->pipe(fn ($c) => $c->count());
        /** @psalm-check-type-exact $_count = int<0, max> */

        $customers->chunkWhile(function (Customer $_customer, int $_key, $chunk): bool {
            /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
            return $chunk->isNotEmpty();
        });

        $_n = $customers->whenEmpty(function ($_c, $_value): void {
            /** @psalm-check-type-exact $_c = Collection<int, Customer> */
            /** @psalm-check-type-exact $_value = bool */
        })->count();
        $_n = $customers->whenNotEmpty(fn ($_c) => null)->count();
        $_n = $customers->unlessEmpty(fn ($c) => $c->push(new Customer()))->count();
        $_n = $customers->unlessNotEmpty(fn (Collection $c, bool $_value) => $c)->count();

        $result = $customers->whenNotEmpty(fn ($c): int => $c->count(), fn ($_c, $value): string => $value ? 'y' : 'n');
        if (!$result instanceof Collection) {
            /** @psalm-check-type-exact $result = 'n'|'y'|int<0, max> */
        }

        $maybe = $customers->whenEmpty(fn ($c): ?string => $c->isEmpty() ? null : 'x');
        if (!$maybe instanceof Collection) {
            /** @psalm-check-type-exact $maybe = 'x' */
        }
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollection(EloquentCollection $customers): void
    {
        $customers->tap(function ($_c): void {
            /** @psalm-check-type-exact $_c = EloquentCollection<int, Customer> */
        });
        $customers->tap(fn (EloquentCollection $c): array => $c->modelKeys());

        $_keys = $customers->pipe(fn ($c) => $c->modelKeys());
        /** @psalm-check-type-exact $_keys = array<int, array-key> */

        $customers->chunkWhile(function (Customer $_customer, int $_key, $chunk): bool {
            /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
            return $chunk->isNotEmpty();
        });

        $keys = $customers->whenEmpty(fn ($c) => $c->modelKeys());
        if (!$keys instanceof EloquentCollection) {
            /** @psalm-check-type-exact $keys = array<int, array-key> */
        }
        $_n = $customers->whenNotEmpty(function ($_c): void {
            /** @psalm-check-type-exact $_c = EloquentCollection<int, Customer> */
        })->count();
        $_n = $customers->unlessEmpty(fn ($_c) => null)->count();
        $_n = $customers->unlessNotEmpty(fn ($_c) => null)->count();
    }

    /** @param LazyCollection<int, Customer> $customers */
    public function lazyCollection(LazyCollection $customers): void
    {
        $customers->tap(function ($_c): void {
            /** @psalm-check-type-exact $_c = LazyCollection<int, Customer> */
        });

        $_count = $customers->pipe(fn ($c) => $c->count());
        /** @psalm-check-type-exact $_count = int */

        $_n = $customers->whenEmpty(function ($_c): void {
            /** @psalm-check-type-exact $_c = LazyCollection<int, Customer> */
        })->count();
        $_n = $customers->whenNotEmpty(fn ($_c) => null)->count();
        $_n = $customers->unlessEmpty(fn ($_c) => null)->count();
        $_n = $customers->unlessNotEmpty(fn ($_c) => null)->count();
    }

    /**
     * Non-closure callables are not arity-adapted by Psalm, so a one-parameter callable must match the stub as-is.
     *
     * @param Collection<int, Customer> $customers
     * @param callable(Collection<int, Customer>): void $void
     * @param callable(Collection<int, Customer>): int $int
     * @param EloquentCollection<int, Customer> $eloquent
     * @param callable(EloquentCollection<int, Customer>): void $eloquentCallback
     * @param LazyCollection<int, Customer> $lazy
     * @param callable(LazyCollection<int, Customer>): void $lazyCallback
     */
    public function singleParameterCallables(
        Collection $customers,
        callable $void,
        callable $int,
        EloquentCollection $eloquent,
        callable $eloquentCallback,
        LazyCollection $lazy,
        callable $lazyCallback,
    ): void {
        $_n = $customers->whenEmpty($void)->count();
        $_n = $customers->whenNotEmpty($void, $void)->count();
        $_n = $customers->unlessEmpty([$this, 'handle'])->count();
        $_n = $customers->unlessNotEmpty($this->handle(...))->count();

        $result = $customers->whenEmpty($int);
        if (!$result instanceof Collection) {
            /** @psalm-check-type-exact $result = int */
        }

        $_n = $eloquent->whenEmpty($eloquentCallback)->count();
        $_n = $lazy->whenNotEmpty($lazyCallback)->count();
    }

    /** @param Collection<int, Customer> $_customers */
    public function handle(Collection $_customers): void {}

    /** @param Collection<int, Customer> $customers */
    public function wrongShapesStayReported(Collection $customers): void
    {
        $customers->tap(fn (Customer $_c): int => 1);
        $customers->whenEmpty(fn ($_c, $_value, int $_extra) => 1);
        $customers->whenEmpty(fn ($_c, array $value): int => \count($value));
    }
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::tap expects callable[impure](Illuminate\Support\Collection<int, App\Models\Customer>):mixed, but Closure[pure](App\Models\Customer):1 provided
InvalidScalarArgument on line %d: Argument 1 of Illuminate\Support\Collection::whenEmpty expects callable[impure](Illuminate\Support\Collection<int, App\Models\Customer>):(1|null)|callable[impure](Illuminate\Support\Collection<int, App\Models\Customer>, bool):(1|null), but Closure[pure](Illuminate\Support\Collection<int, App\Models\Customer>, bool, int):1 provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::whenEmpty expects %s, but Closure[pure](Illuminate\Support\Collection<int, App\Models\Customer>, array<array-key, mixed>):int<0, max> provided
