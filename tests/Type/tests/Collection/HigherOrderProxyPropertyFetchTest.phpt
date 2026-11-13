--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class PropFetchItem
{
    public int $id = 0;
    public int $n = 1;
    public string $name = '';
    public bool $flag = true;
    public ?PropFetchItem $parent = null;
}

final class PropFetchNilItem
{
    public null $nil = null;
}

final class PropFetchChild
{
    public function number(): int
    {
        return 1;
    }
}

final class PropFetchParent
{
    public function __construct(public PropFetchChild $child) {}
}

final class PropFetchHolder
{
    /** @param Collection<int, PropFetchItem> $items */
    public function __construct(public Collection $items) {}
}

/**
 * @param Collection<int, array{n: int}>|null $c
 * @return Collection<int, array{n: int}>|null
 */
function propFetchNullableIdentity(?Collection $c): ?Collection
{
    return $c;
}

/**
 * A property fetched through a higher-order proxy is typed as Laravel's `__get()` returns it:
 * `$c->map->id` ≡ `$c->map(fn ($v) => $v->id)` (#2176).
 */
final class HigherOrderProxyPropertyFetchTest
{
    /**
     * @param Collection<int, Customer> $c
     * @param Collection<int, PropFetchItem> $p
     */
    public function supportCollection(Collection $c, Collection $p): void
    {
        $_map = $c->map->id;
        /** @psalm-check-type-exact $_map = Collection<int, string> */
        $_magic = $c->map->primary_vehicle;
        /** @psalm-check-type-exact $_magic = Collection<int, Vehicle|null> */
        $_relation = $c->map->vehicles;
        /** @psalm-check-type-exact $_relation = Collection<int, EloquentCollection<int, Vehicle>> */
        $_filter = $p->filter->flag;
        /** @psalm-check-type-exact $_filter = Collection<int, PropFetchItem> */
        $_sortBy = $p->sortBy->name;
        /** @psalm-check-type-exact $_sortBy = Collection<int, PropFetchItem> */
        $_contains = $p->contains->flag;
        /** @psalm-check-type-exact $_contains = bool */
        $_sum = $p->sum->n;
        /** @psalm-check-type-exact $_sum = float|int */
        $_avg = $p->avg->n;
        /** @psalm-check-type-exact $_avg = float|int|null */
        $_percentage = $p->percentage->flag;
        /** @psalm-check-type-exact $_percentage = float|null */
        $_first = $p->first->parent;
        /** @psalm-check-type-exact $_first = PropFetchItem|null */
        $_last = $p->last->flag;
        /** @psalm-check-type-exact $_last = PropFetchItem|null */
        $_max = $p->max->n;
        /** @psalm-check-type-exact $_max = int|null */
        $_min = $p->min->name;
        /** @psalm-check-type-exact $_min = null|string */
        $_flatMap = $p->flatMap->name;
        /** @psalm-check-type-exact $_flatMap = Collection<array-key, mixed> */
        $_groupBy = $p->groupBy->name;
        /** @psalm-check-type-exact $_groupBy = Collection<array-key, Collection<int, PropFetchItem>> */
        $_partition = $p->partition->flag;
        /** @psalm-check-type-exact $_partition = Collection<int, Collection<int, PropFetchItem>> */
        $_keyBy = $p->keyBy->name;
        /** @psalm-check-type-exact $_keyBy = Collection<array-key, PropFetchItem> */
    }

    /** @param LazyCollection<string, PropFetchItem> $l */
    public function lazyCollection(LazyCollection $l): void
    {
        $_map = $l->map->name;
        /** @psalm-check-type-exact $_map = LazyCollection<string, string> */
        $_filter = $l->filter->flag;
        /** @psalm-check-type-exact $_filter = LazyCollection<string, PropFetchItem> */
        $_every = $l->every->flag;
        /** @psalm-check-type-exact $_every = bool */
        $_all = $l->map->name->all();
        /** @psalm-check-type-exact $_all = array<string, string> */
    }

    /** @param EloquentCollection<int, Customer> $e */
    public function eloquentCollection(EloquentCollection $e): void
    {
        $_map = $e->map->id;
        /** @psalm-check-type-exact $_map = Collection<int, string> */
        $_reject = $e->reject->id;
        /** @psalm-check-type-exact $_reject = EloquentCollection<int, Customer> */
        $_partition = $e->partition->id;
        /** @psalm-check-type-exact $_partition = Collection<int, EloquentCollection<int, Customer>> */
    }

    /** @param list<Customer> $customers */
    public function issueRepro(array $customers): void
    {
        $_ids = collect($customers)->map->id->all();
        /** @psalm-check-type-exact $_ids = array<int, string> */
        Customer::query()->whereIn('id', collect($customers)->map->id->all())->get();
    }

    /**
     * Psalm analyses the fetch again for a `foreach` (`getIterator()`) or an ArrayAccess read (`offsetGet()`); a
     * receiver without a scope key (a call) must still be wrapped once.
     *
     * @param list<array{id: int}> $raw
     * @param list<PropFetchItem> $objs
     */
    public function reanalysedFetches(array $raw, array $objs): int
    {
        foreach (collect($raw)->map->id as $arrayId) {
            /** @psalm-check-type-exact $arrayId = int */
            echo $arrayId;
        }

        foreach (Customer::all()->map->primary_vehicle as $vehicle) {
            /** @psalm-check-type-exact $vehicle = Vehicle|null */
            echo $vehicle?->exists === true ? 'y' : 'n';
        }

        $_offset = collect($raw)->map->id[0];
        /** @psalm-check-type-exact $_offset = int */

        $sum = 0;
        foreach (collect($objs)->map->n as $n) {
            $sum += $n;
        }

        return $sum;
    }

    /**
     * A re-analysed fetch behind `?->` keeps the receiver's null outside the items.
     *
     * @param Collection<int, array{n: int}>|null $c
     */
    public function reanalysedNullsafeFetches(?Collection $c): int
    {
        $sum = 0;
        foreach ($c?->map->n ?? [] as $n) {
            $sum += $n;
        }

        foreach (propFetchNullableIdentity($c)?->map->n ?? [] as $n) {
            $sum += $n;
        }

        return $sum;
    }

    /**
     * Inside `??`, a nullable receiver's null is the fetch's own: the default stays reachable.
     *
     * @param Collection<int, PropFetchItem>|null $nc
     */
    public function nullableReceiverInsideCoalesce(?Collection $nc, ?PropFetchHolder $holder, Vehicle $vehicle): void
    {
        $_n = $nc->map->n ?? 0;
        /** @psalm-check-type-exact $_n = 0|Collection<int, int|null> */
        $_items = $holder->items->map->n ?? 'none';
        /** @psalm-check-type-exact $_items = 'none'|Collection<int, int|null> */
        $_ids = $vehicle->customer->vehicles->map->id ?? [];
    }

    /**
     * Psalm reaches the proxy's `__get` when no item declares the property (array items, an item union, an
     * undefined property): the same mapping, wrapped once. `__get` has no item type for an object union, so its
     * value stays mixed.
     *
     * @param Collection<int, array{id: int}> $a
     * @param Collection<int, PropFetchItem|Customer> $u
     * @param Collection<int, PropFetchItem> $p
     */
    public function magicGetPath(Collection $a, Collection $u, Collection $p): void
    {
        $_array = $a->map->id;
        /** @psalm-check-type-exact $_array = Collection<int, int> */
        $_filtered = $a->filter->id;
        /** @psalm-check-type-exact $_filtered = Collection<int, array{id: int}> */
        $_union = $u->map->id;
        /** @psalm-check-type-exact $_union = Collection<int, mixed> */
        $_undefined = $p->map->undefined_thing;
        /** @psalm-check-type-exact $_undefined = Collection<int, mixed> */
    }

    /** @param Collection<int, PropFetchItem>|null $nullable */
    public function nullsafeReceiver(?Collection $nullable): void
    {
        $_n = $nullable?->map->n;
        /** @psalm-check-type-exact $_n = Collection<int, int>|null */
        $_parent = $nullable?->map->parent;
        /** @psalm-check-type-exact $_parent = Collection<int, PropFetchItem|null>|null */
        $_first = $nullable?->first->n;
        /** @psalm-check-type-exact $_first = PropFetchItem|null */
        $_count = $nullable?->map->parent->count();
        /** @psalm-check-type-exact $_count = int<0, max>|null */
    }

    /**
     * A nullable receiver's null stays outside the collection on the `__get` path too.
     *
     * @param Collection<int, array{id: int}>|null $arrays
     */
    public function nullsafeReceiverWithArrayItems(?Collection $arrays): void
    {
        $_n = $arrays?->map->id;
        /** @psalm-check-type-exact $_n = Collection<int, int>|null */
        $_filter = $arrays?->filter->id;
        /** @psalm-check-type-exact $_filter = Collection<int, array{id: int}>|null */
        $_count = $_n->count();
    }

    /**
     * A null item value is a value like any other: the collection operation still decides the result.
     *
     * @param Collection<int, PropFetchNilItem> $nils
     * @param Collection<int, PropFetchNilItem>|null $nullable
     * @param LazyCollection<int, array{nil: null}> $arrays
     */
    public function nullItemValues(Collection $nils, ?Collection $nullable, LazyCollection $arrays): void
    {
        $_map = $nils->map->nil;
        /** @psalm-check-type-exact $_map = Collection<int, null> */
        $_filter = $nils->filter->nil;
        /** @psalm-check-type-exact $_filter = Collection<int, PropFetchNilItem> */
        $_contains = $nils->contains->nil;
        /** @psalm-check-type-exact $_contains = bool */
        $_all = $nils->map->nil->all();
        /** @psalm-check-type-exact $_all = array<int, null> */
        $_nullsafe = $nullable?->map->nil;
        /** @psalm-check-type-exact $_nullsafe = Collection<int, null>|null */
        $_array = $arrays->map->nil;
        /** @psalm-check-type-exact $_array = LazyCollection<int, null> */
    }

    /**
     * Without `__isset()` on the proxy, PHP reads a fetch through `__get` everywhere except as the direct operand of
     * isset()/empty()/unset(): the left side of `??`, and a fetch behind an array dim or a method call, are wrapped.
     * The reports below are true positives: `all()` and the collection are never null, `number()` never returns
     * null, and `$collection[0]` is null for a missing offset.
     *
     * @param Collection<int, PropFetchItem> $p
     * @param Collection<int, PropFetchParent> $parents
     */
    public function evaluatedFetchesInsideIssetAndCoalesce(Collection $p, Collection $parents): void
    {
        $_all = $p->map->id->all() ?? [];
        /** @psalm-check-type-exact $_all = array<int, int> */
        $_first = $p->map->n->first() ?? 0;
        /** @psalm-check-type-exact $_first = int */
        $_empty = empty($p->keyBy->name->all());
        $_after = $p->keyBy->name;
        /** @psalm-check-type-exact $_after = Collection<array-key, PropFetchItem> */
        $_number = $parents->map->child[0]->number() ?? 0;
        /** @psalm-check-type-exact $_number = int */
        unset($p->filter->n->all()[0]);
        $_afterUnset = $p->filter->n;
        /** @psalm-check-type-exact $_afterUnset = Collection<int, PropFetchItem> */
        $_coalesce = $p->sortBy->parent ?? 'x';
        /** @psalm-check-type-exact $_coalesce = 'x'|Collection<int, PropFetchItem> */
    }

    /**
     * A re-read takes Psalm's scope entry, which holds the wrapped type: not wrapped twice, and narrowable.
     *
     * @param Collection<int, PropFetchItem> $p
     */
    public function scopedReads(Collection $p): void
    {
        $_once = $p->map->n;
        $_twice = $p->map->n;
        /** @psalm-check-type-exact $_twice = Collection<int, int> */
        if ($p->max->n !== null) {
            $_narrowed = $p->max->n;
            /** @psalm-check-type-exact $_narrowed = int */
        }
    }
}
?>
--EXPECTF--
PossiblyNullReference on line %d: Cannot call method count on possibly null value
RedundantConditionGivenDocblockType on line %d: Docblock-defined type array<int, int> for $<tmp coalesce var>%d is never null
DocblockTypeContradiction on line %d: Cannot resolve types for $<tmp coalesce var>%d - docblock-defined type array<int, int> does not contain null
RedundantCondition on line %d: Type int for $<tmp coalesce var>%d is never null
PossiblyNullReference on line %d: Cannot call method number on possibly null value
TypeDoesNotContainNull on line %d: Cannot resolve types for $<tmp coalesce var>%d - int does not contain null
RedundantCondition on line %d: Type Illuminate\Support\Collection<int, PropFetchItem> for $p->sortBy->parent is always isset
TypeDoesNotContainType on line %d: Cannot resolve types for $p->sortBy->parent with type Illuminate\Support\Collection<int, PropFetchItem> and !isset assertion
