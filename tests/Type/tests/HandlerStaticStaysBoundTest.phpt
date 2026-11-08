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

/** @param Collection<int, string|null> $c */
function filter(Collection $c): void
{
    $_filtered = box($c->filter())->same()->same();
    /** @psalm-check-type-exact $_filtered = Box<Collection<int, non-falsy-string>&static> */
}

/** @param Collection<int, list<int>> $c */
function flatten(Collection $c): void
{
    $_collapsed = box($c->collapse())->same()->same();
    /** @psalm-check-type-exact $_collapsed = Box<Collection<int, int>&static> */

    $_flattened = box($c->flatten(1))->same()->same();
    /** @psalm-check-type-exact $_flattened = Box<Collection<int, int>&static> */
}

/** @param Collection<string, CollectionGroupingModel> $items */
function groupBy(Collection $items): void
{
    $_grouped = box($items->groupBy('foreign_id'))->same()->same();
    /** @psalm-check-type-exact $_grouped = Box<Collection<int, Collection<int, CollectionGroupingModel>&static>&static> */

    $_keyed = box($items->keyBy('foreign_id'))->same()->same();
    /** @psalm-check-type-exact $_keyed = Box<Collection<int, CollectionGroupingModel>&static> */
}

function make(): void
{
    $_made = box(Collection::make('a'))->same()->same();
    /** @psalm-check-type-exact $_made = Box<Collection<0, 'a'>&static> */
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
