--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\HandlerStaticStaysBound;

use App\Models\CollectionGroupingModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// A handler answering `static` binds it to the receiver, like Psalm does for a declared `@return static`.
// Left unbound, the `&static` of the answer binds again to the receiver of every later call once the value
// sits in a template argument, and the type doubles per call.

/** @template T */
final class Box
{
    /** @return self<T> */
    public function same(): self
    {
        return $this;
    }
}

/**
 * @template T
 * @param T $_value
 * @return Box<T>
 */
function box(mixed $_value): Box
{
    return new Box();
}

// A plain receiver gets the plain class, as core does for `map()`: only a late static receiver keeps `&static`.

/** @param Collection<int, string|null> $c */
function filter(Collection $c): void
{
    $_filtered = box($c->filter())->same()->same();
    /** @psalm-check-type-exact $_filtered = Box<Collection<int, non-falsy-string>> */
}

/** @param Collection<int, list<int>> $c */
function flatten(Collection $c): void
{
    $_collapsed = box($c->collapse())->same()->same();
    /** @psalm-check-type-exact $_collapsed = Box<Collection<int, int>> */

    $_flattened = box($c->flatten(1))->same()->same();
    /** @psalm-check-type-exact $_flattened = Box<Collection<int, int>> */
}

/** @param Collection<string, CollectionGroupingModel> $items */
function groupBy(Collection $items): void
{
    $_grouped = box($items->groupBy('foreign_id'))->same()->same();
    /** @psalm-check-type-exact $_grouped = Box<Collection<int, Collection<int, CollectionGroupingModel>>> */

    $_keyed = box($items->keyBy('foreign_id'))->same()->same();
    /** @psalm-check-type-exact $_keyed = Box<Collection<int, CollectionGroupingModel>> */
}

function make(): void
{
    $_made = box(Collection::make('a'))->same()->same();
    /** @psalm-check-type-exact $_made = Box<Collection<0, 'a'>> */
}

/**
 * @template TKey of array-key
 * @template TValue
 * @extends Collection<TKey, TValue>
 */
class Items extends Collection
{
    /** @param self<TKey, TValue|null> $other */
    public function filledOf(self $other): static
    {
        // `$other` may be another subclass than `static`, so its plain answer is not this `static`.
        return $other->filter();
    }

    // Only a receiver proven plain gets the plain class. Everything else is not proven, so it keeps `&static`:
    // `self::`/`parent::`/`$this::` forward the late static binding, and a `static<...>` value is the late static class.

    /** @param static<int, string|null> $s */
    public function filterBound(self $s): void
    {
        $_filtered = box($s->filter())->same()->same();
        /** @psalm-check-type-exact $_filtered = Box<Items<int, non-falsy-string>&static> */
    }

    /** @param static<int, list<int>> $s */
    public function flattenBound(self $s): void
    {
        $_collapsed = box($s->collapse())->same()->same();
        /** @psalm-check-type-exact $_collapsed = Box<Items<int, int>&static> */

        $_flattened = box($s->flatten(1))->same()->same();
        /** @psalm-check-type-exact $_flattened = Box<Items<int, int>&static> */
    }

    /** @param static<string, CollectionGroupingModel> $s */
    public function groupByBound(self $s): void
    {
        $_grouped = box($s->groupBy('foreign_id'))->same()->same();
        /** @psalm-check-type-exact $_grouped = Box<Items<int, Items<int, CollectionGroupingModel>&static>&static> */

        $_keyed = box($s->keyBy('foreign_id'))->same()->same();
        /** @psalm-check-type-exact $_keyed = Box<Items<int, CollectionGroupingModel>&static> */
    }

    // `when()` answers the receiver unioned with the callback result; the union keeps the late static marker.
    /** @param static<int, int> $s */
    public function whenBound(self $s): void
    {
        $_when = box($s->when(true, fn () => 1))->same()->same();
        /** @psalm-check-type-exact $_when = Box<1|Items<int, int>&static> */
    }

    public function makeForwarded(): void
    {
        $_static = box(static::make('a'))->same()->same();
        /** @psalm-check-type-exact $_static = Box<Items<0, 'a'>&static> */

        $_self = box(self::make('a'))->same()->same();
        /** @psalm-check-type-exact $_self = Box<Items<0, 'a'>&static> */

        $_parent = box(parent::make('a'))->same()->same();
        /** @psalm-check-type-exact $_parent = Box<Items<0, 'a'>&static> */

        $_this = box($this::make('a'))->same()->same();
        /** @psalm-check-type-exact $_this = Box<Items<0, 'a'>&static> */
    }

    /** @param static<int, string|null> $s */
    public function returnFilterBound(self $s): static
    {
        return $s->filter();
    }

    /** @param static<int, list<int>> $s */
    public function returnCollapseBound(self $s): static
    {
        return $s->collapse();
    }

    /** @param static<string, CollectionGroupingModel> $s */
    public function returnGroupByBound(self $s): static
    {
        return $s->groupBy('foreign_id');
    }

    /** @param static<string, CollectionGroupingModel> $s */
    public function returnKeyByBound(self $s): static
    {
        return $s->keyBy('foreign_id');
    }

    public function returnStaticMake(): static
    {
        return static::make('a');
    }

    public function returnSelfMake(): static
    {
        return self::make('a');
    }

    public function returnParentMake(): static
    {
        return parent::make('a');
    }

    public function returnThisMake(): static
    {
        return $this::make('a');
    }

    public function returnClassStringMake(): static
    {
        $class = static::class;

        return $class::make('a');
    }

    public function returnNewStaticMake(): static
    {
        return (clone $this)::make('a');
    }

    /** @param static<int, string|null>|static<string, string|null> $s */
    public function returnUnion(self $s): static
    {
        return $s->filter();
    }

    /**
     * @template T of static<int, string|null>
     * @param T $s
     */
    public function returnTemplate(self $s): static
    {
        return $s->filter();
    }

    /** @psalm-if-this-is Collection<int, string|null> */
    public function returnRefinedThis(): static
    {
        return $this->filter();
    }

    /** @param static<int, string|null>|static<string, string|null> $s */
    public function unionReceiver(self $s): void
    {
        $_filtered = box($s->filter())->same()->same();
        /** @psalm-check-type-exact $_filtered = Box<Items<int, non-falsy-string>&static|Items<string, non-falsy-string>&static> */
    }

    /**
     * @template T of static<int, string|null>
     * @param T $s
     */
    public function templateReceiver(self $s): void
    {
        $_filtered = box($s->filter())->same()->same();
        /** @psalm-check-type-exact $_filtered = Box<Items<int, non-falsy-string>&static> */
    }

    /** @psalm-if-this-is Collection<int, string|null> */
    public function refinedThis(): void
    {
        $_filtered = box($this->filter())->same()->same();
        /** @psalm-check-type-exact $_filtered = Box<Items<int, non-falsy-string>&static> */
    }
}

final class User extends Model
{
    use HasFactory;
}

/** @extends Factory<User> */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function change(): static
    {
        $this->count = 2;

        return $this;
    }
}

function factoryCount(): void
{
    $_changed = box(F::new()->state([])->change())->same()->same();
    /** @psalm-check-type-exact $_changed = Box<F<int<2, max>|null>&static> */;
}
?>
--EXPECTF--
MoreSpecificReturnType on line %d: The declared return type 'App\Sandbox\HandlerStaticStaysBound\Items&static' for App\Sandbox\HandlerStaticStaysBound\Items::filledOf is more specific than the inferred return type '%s'
LessSpecificReturnStatement on line %d: The type '%s' is more general than the declared return type 'App\Sandbox\HandlerStaticStaysBound\Items&static' for App\Sandbox\HandlerStaticStaysBound\Items::filledOf
