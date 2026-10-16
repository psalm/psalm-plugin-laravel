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
function generalizedReturnsSingle(GeneralizedItem $a): Collection
{
    return collect([$a]);
}

/** @param list<GeneralizedItem> $list */
function generalizedFromList(array $list): void
{
    $_collect = collect($list);
    /** @psalm-check-type-exact $_collect = Collection<int, GeneralizedItem> */
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

function generalizedValueTypes(): void
{
    $_ints = collect([1, 2]);
    /** @psalm-check-type-exact $_ints = Collection<int, int> */

    $_strings = collect(['a', 'b']);
    /** @psalm-check-type-exact $_strings = Collection<int, string> */

    $_floats = collect([1.5, 2.5]);
    /** @psalm-check-type-exact $_floats = Collection<int, float> */

    $_bools = collect([true]);
    /** @psalm-check-type-exact $_bools = Collection<0, bool> */

    $_mixedLiterals = collect([1, 'a']);
    /** @psalm-check-type-exact $_mixedLiterals = Collection<int, int|string> */

    // A class-string literal must stay `class-string<Foo>`, not decay to `string`.
    $_classStrings = collect([GeneralizedItem::class, GeneralizedItem::class]);
    /** @psalm-check-type-exact $_classStrings = Collection<int, class-string<GeneralizedItem>> */

    $_enums = collect([GeneralizedSuit::Hearts, GeneralizedSuit::Spades]);
    /** @psalm-check-type-exact $_enums = Collection<int, GeneralizedSuit> */

    // Row values keep their inner literals (only the range key widens): widening them would reject a
    // `Collection<int, array<string, int>>` param that the literal makes lenient.
    $_rows = collect([['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'y']]);
    /** @psalm-check-type-exact $_rows = Collection<int, array{id: 1|2, name: 'x'|'y'}> */

    // Literal keys are kept.
    $_keyed = collect(['a' => 1, 'b' => 2]);
    /** @psalm-check-type-exact $_keyed = Collection<'a'|'b', int> */

    $_make = Collection::make([1, 2]);
    /** @psalm-check-type-exact $_make = Collection<int, int>&static */

    $_lazy = LazyCollection::make([1, 2]);
    /** @psalm-check-type-exact $_lazy = LazyCollection<int, int>&static */
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
    generalizedBareParam(collect([['id' => 1]]));
    generalizedBareParam(collect([]));
}

/** @param Collection<int, array<string, int>> $rows */
function generalizedRowsParam(Collection $rows): int
{
    return $rows->count();
}

function generalizedRowsParamCall(): void
{
    generalizedRowsParam(collect([['id' => 1]]));
}

function generalizedUnpacked(): void
{
    $args = [[1, 2]];
    $scalarArgs = [1];

    // The unpacked array's FIRST element is the input, not the array itself: declined.
    $_collect = collect(...$args);
    /** @psalm-check-type-exact $_collect = Collection<int<0, 1>, 1|2> */

    $_make = Collection::make(...$args);
    /** @psalm-check-type-exact $_make = Collection<int<0, 1>, 1|2> */

    $_lazy = LazyCollection::make(...$args);
    /** @psalm-check-type-exact $_lazy = LazyCollection<int<0, 1>, 1|2> */

    // Without the guard the unpacked list itself was read as the input and widened to `Collection<0, int>`.
    $_scalar = collect(...$scalarArgs);
    /** @psalm-check-type-exact $_scalar = Collection<array-key, mixed> */
}
?>
--EXPECTF--
