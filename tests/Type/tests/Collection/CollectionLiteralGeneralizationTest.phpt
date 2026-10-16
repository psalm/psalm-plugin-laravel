--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1766
 *
 * collect([$a, $b]) used to bind the literal shape verbatim (`Collection<int<0, 1>, Item>`), and the
 * invariant templates then rejected correct code such as `@return Collection<int, Item>`. Values are
 * widened (`1|2` -> `int`) and range keys become `int`; literal keys stay, because Psalm already treats a
 * literal-holding argument leniently at invariant params (see decisions.md, "Collection literal
 * generalization"). An empty `[]` and an input with nothing to widen keep the stub binding.
 */

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class GeneralizedItem
{
    public int $id = 1;
}

enum GeneralizedSuit: string
{
    case Hearts = 'h';
    case Spades = 's';
}

final class GeneralizedHolder
{
    /** @var Collection<int, GeneralizedItem> */
    public Collection $items;

    /** @param list<GeneralizedItem> $list */
    public function __construct(array $list)
    {
        $this->items = collect($list);
    }
}

/** @return Collection<int, GeneralizedItem> */
function generalizedReturnsCollect(GeneralizedItem $a, GeneralizedItem $b): Collection
{
    return collect([$a, $b]);
}

/** @return Collection<int, GeneralizedItem> */
function generalizedReturnsMake(GeneralizedItem $a, GeneralizedItem $b): Collection
{
    return Collection::make([$a, $b]);
}

/** @return LazyCollection<int, GeneralizedItem> */
function generalizedReturnsLazyMake(GeneralizedItem $a, GeneralizedItem $b): LazyCollection
{
    return LazyCollection::make([$a, $b]);
}

/** @return Collection<int, GeneralizedItem> */
function generalizedReturnsNew(GeneralizedItem $a, GeneralizedItem $b): Collection
{
    return new Collection([$a, $b]);
}

/** @return LazyCollection<int, GeneralizedItem> */
function generalizedReturnsNewLazy(GeneralizedItem $a, GeneralizedItem $b): LazyCollection
{
    return new LazyCollection([$a, $b]);
}

/** @return EloquentCollection<int, Customer> */
function generalizedReturnsNewEloquent(Customer $a, Customer $b): EloquentCollection
{
    return new EloquentCollection([$a, $b]);
}

/** @return Collection<int, GeneralizedItem> */
function generalizedReturnsSingle(GeneralizedItem $a): Collection
{
    return collect([$a]);
}

/** @return Collection<int, GeneralizedItem> */
function generalizedReturnsSingleNew(GeneralizedItem $a): Collection
{
    return new Collection([$a]);
}

/** @param list<GeneralizedItem> $list */
function generalizedFromList(array $list): void
{
    $_collect = collect($list);
    /** @psalm-check-type-exact $_collect = Collection<int, GeneralizedItem> */

    $_new = new Collection($list);
    /** @psalm-check-type-exact $_new = Collection<int, GeneralizedItem> */
}

function generalizedChains(): void
{
    // `Collection<0, 1>` made `prepend(2)` / `diff()` / `contains()` fail on the literal value.
    $_prepended = collect([1])->prepend(2);
    /** @psalm-check-type-exact $_prepended = Collection<0, int>&static */

    $_diffed = collect([3, 4])->diff([1, 2]);
    /** @psalm-check-type-exact $_diffed = Collection<int, int>&static */

    $_contains = collect([1])->contains(3);
    /** @psalm-check-type-exact $_contains = bool */
}

function generalizedValueTypes(Customer $customer): void
{
    $_ints = collect([1, 2]);
    /** @psalm-check-type-exact $_ints = Collection<int, int> */

    $_strings = collect(['a', 'b']);
    /** @psalm-check-type-exact $_strings = Collection<int, string> */

    $_floats = collect([1.5, 2.5]);
    /** @psalm-check-type-exact $_floats = Collection<int, float> */

    $_bools = collect([true, false]);
    /** @psalm-check-type-exact $_bools = Collection<int, bool> */

    $_mixedLiterals = collect([1, 'a']);
    /** @psalm-check-type-exact $_mixedLiterals = Collection<int, int|string> */

    // A class-string literal must stay `class-string<Foo>`, not decay to `string`.
    $_classStrings = collect([GeneralizedItem::class, GeneralizedItem::class]);
    /** @psalm-check-type-exact $_classStrings = Collection<int, class-string<GeneralizedItem>> */

    $_enums = collect([GeneralizedSuit::Hearts, GeneralizedSuit::Spades]);
    /** @psalm-check-type-exact $_enums = Collection<int, GeneralizedSuit> */

    // Rows: the values inside the shape widen, the shape keys are structure and stay.
    $_rows = collect([['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'y']]);
    /** @psalm-check-type-exact $_rows = Collection<int, array{id: int, name: string}> */

    $_nested = collect(['k' => ['a' => 1, 'b' => 'x']]);
    /** @psalm-check-type-exact $_nested = Collection<'k', array{a: int, b: string}> */

    // Literal keys are kept.
    $_keyed = collect(['a' => 1, 'b' => 2]);
    /** @psalm-check-type-exact $_keyed = Collection<'a'|'b', int> */

    $_make = Collection::make([1, 2]);
    /** @psalm-check-type-exact $_make = Collection<int, int>&static */

    $_lazy = LazyCollection::make([1, 2]);
    /** @psalm-check-type-exact $_lazy = LazyCollection<int, int>&static */

    $_new = new Collection([1, 2]);
    /** @psalm-check-type-exact $_new = Collection<int, int> */

    $_newLazy = new LazyCollection([1, 2]);
    /** @psalm-check-type-exact $_newLazy = LazyCollection<int, int> */

    $_newEloquent = new EloquentCollection([$customer, $customer]);
    /** @psalm-check-type-exact $_newEloquent = EloquentCollection<int, Customer> */
}

function generalizedDeclines(int $int, string $string, GeneralizedItem $item): void
{
    // `[]` keeps its empty-collection meaning.
    $_empty = collect([]);
    /** @psalm-check-type-exact $_empty = Collection<never, never> */

    $_emptyMake = Collection::make([]);
    /** @psalm-check-type-exact $_emptyMake = Collection<never, never> */

    // Non-literal values are kept as they are.
    $_ints = collect([$int, $int]);
    /** @psalm-check-type-exact $_ints = Collection<int, int> */

    $_strings = collect([$string, $string]);
    /** @psalm-check-type-exact $_strings = Collection<int, string> */

    $_union = collect([$int, $string]);
    /** @psalm-check-type-exact $_union = Collection<int, int|string> */

    // A positive-int range as a VALUE is not literal: kept.
    /** @var list<positive-int> $ranges */
    $ranges = [];
    $_ranges = collect($ranges);
    /** @psalm-check-type-exact $_ranges = Collection<int, int<1, max>> */

    // A single object element: nothing to widen, the stub binding stays (literal key 0).
    $_single = collect([$item]);
    /** @psalm-check-type-exact $_single = Collection<0, GeneralizedItem> */
}

// Bare `Collection` params (`Collection<array-key, mixed>`): literal keys stay, so widening
// values does not add errors here.
function generalizedBareParam(Collection $collection): int
{
    return $collection->count();
}

function generalizedBareParamCalls(GeneralizedItem $item): void
{
    generalizedBareParam(collect([$item]));
    generalizedBareParam(collect([1]));
    generalizedBareParam(collect(['a' => 1, 'b' => 2]));
    generalizedBareParam(new Collection([$item]));
    generalizedBareParam(collect([]));
}
?>
--EXPECTF--
