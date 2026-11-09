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

/** @extends Collection<int, string|null> */
class Strings extends Collection
{
    public function filtered(): void
    {
        $_filtered = box($this->filter())->same()->same();
        /** @psalm-check-type-exact $_filtered = Box<Strings&static> */
    }
}

/** @extends Collection<int, list<int>> */
class Lists extends Collection
{
    public function flattened(): void
    {
        $_collapsed = box($this->collapse())->same()->same();
        /** @psalm-check-type-exact $_collapsed = Box<Lists&static> */

        $_flattened = box($this->flatten(1))->same()->same();
        /** @psalm-check-type-exact $_flattened = Box<Lists&static> */
    }
}

/** @extends Collection<string, CollectionGroupingModel> */
class Models extends Collection
{
    public function grouped(): void
    {
        $_grouped = box($this->groupBy('foreign_id'))->same()->same();
        /** @psalm-check-type-exact $_grouped = Box<Models&static> */

        $_keyed = box($this->keyBy('foreign_id'))->same()->same();
        /** @psalm-check-type-exact $_keyed = Box<Models&static> */
    }
}

/** @extends Collection<int, string> */
class Names extends Collection
{
    public function made(): void
    {
        $_static = box(static::make('a'))->same()->same();
        /** @psalm-check-type-exact $_static = Box<Names&static> */

        $_self = box(self::make('a'))->same()->same();
        /** @psalm-check-type-exact $_self = Box<Names> */
    }

    public function boundReceiver(): void
    {
        /** @var static $s */
        $s = Collection::make();
        $_filtered = box($s->filter())->same()->same();
        /** @psalm-check-type-exact $_filtered = Box<Names&static> */
    }
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
