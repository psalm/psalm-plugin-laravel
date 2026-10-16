--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1885
 *
 * Psalm expands a bare `Collection` parameter to `Collection<array-key, mixed>` and compares the
 * `array-key` slot invariantly, so `Collection<int, Dog>` was rejected (Eloquent's `Model` bound
 * had the same effect). A bare collection parameter means "any instantiation", like a bare
 * `array`; the plugin marks every expanded bound as omitted so any instantiation binds, while the
 * callee body still sees the bounds.
 */

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

final class Dog {}

/**
 * @template TKey of array-key
 * @template TValue
 * @extends Collection<TKey, TValue>
 */
final class Pack extends Collection {}

/**
 * @template TKey of array-key
 */
final class Box
{
    /** @param TKey $key */
    public function __construct(public int|string $key) {}
}

// class_alias() names resolve for the existence check but have no storage of their own.
class_alias(Collection::class, 'AliasCollection');
class_alias(Dog::class, 'AliasDog');

function takeAliasedCollection(AliasCollection $c): int
{
    return $c->count();
}

function takeAliasedNonCollection(AliasDog $d): string
{
    return $d::class;
}

final class Consumer
{
    public function takeBare(Collection $c): void
    {
        /** @psalm-check-type-exact $c = Collection<array-key, mixed> */
    }

    public function takeNullable(?Collection $c): void {}

    /** @param Collection|array $c */
    public function takeUnion(Collection|array $c): void {}

    /** @param Collection $c */
    public function takeDocblock($c): void {}

    /** @param array<int, Collection> $c */
    public function takeNested(array $c): void {}

    public function takeEloquent(EloquentCollection $c): void
    {
        /** @psalm-check-type-exact $c = EloquentCollection<array-key, \Illuminate\Database\Eloquent\Model> */
    }

    public function takeLazy(LazyCollection $c): void {}

    public function takeEnumerable(Enumerable $c): void {}

    public function takePack(Pack $c): void {}

    /** @param Collection<string, Dog> $c */
    public function takeExplicit(Collection $c): void {}

    public function takeBox(Box $b): void {}
}

/** @return Collection<array-key, mixed> */
function takeFree(Collection $c): Collection
{
    /** @psalm-check-type-exact $c = Collection<array-key, mixed> */
    return $c;
}

/** @param Closure(Collection<int, Dog>): void $callback */
function withCallback(Closure $callback): void
{
    $callback(new Collection());
}

// A closure literal's bare parameter is still inferred from the expected callable.
function inferred(): void
{
    withCallback(function (Collection $c): void {
        /** @psalm-check-type-exact $c = Collection<int, Dog> */
        echo $c->count();
    });
}

/**
 * @param Collection<int, Dog> $dogs
 * @param EloquentCollection<int, Customer> $customers
 * @param LazyCollection<int, Dog> $lazy
 * @param Pack<int, Dog> $pack
 */
function accepts(
    Consumer $consumer,
    Collection $dogs,
    EloquentCollection $customers,
    LazyCollection $lazy,
    Pack $pack,
): void {
    $consumer->takeBare($dogs);
    $consumer->takeNullable($dogs);
    $consumer->takeUnion($dogs);
    $consumer->takeDocblock($dogs);
    $consumer->takeNested([$dogs]);
    $consumer->takeEloquent($customers);
    $consumer->takeBare($customers);
    $consumer->takeLazy($lazy);
    $consumer->takeEnumerable($dogs);
    $consumer->takePack($pack);
    takeFree($dogs);
    takeAliasedCollection($dogs);
    takeAliasedNonCollection(new Dog());
}

/**
 * @param Collection<int, Dog> $dogs
 * @param Box<int> $box
 */
function rejects(Consumer $consumer, Collection $dogs, Box $box): void
{
    // An explicit instantiation is still compared invariantly.
    $consumer->takeExplicit($dogs);
    // Only collections are relaxed: a bare non-collection generic keeps Psalm's behaviour.
    $consumer->takeBox($box);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Consumer::takeExplicit expects Illuminate\Support\Collection<string, Dog>, but Illuminate\Support\Collection<int, Dog> provided
InvalidArgument on line %d: Argument 1 of Consumer::takeBox expects Box<array-key>, but Box<int> provided
