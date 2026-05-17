--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard Laravel scaffold: `use HasFactory;` without the template arg.
 * `php artisan make:model -f` produces this shape, so it covers the common
 * case where downstream `Model::factory()->count(N)->make()` chains break
 * without plugin intervention.
 *
 * Tracked by ModelFactoryMethodTypeProvider (Option A) and the
 * FactoryCountTypeProvider Model fallback (Option B).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/960
 */
class Bookshelf extends Model
{
    use HasFactory;
}

/**
 * @extends Factory<Bookshelf>
 */
final class BookshelfFactory extends Factory
{
    /** @var class-string<Bookshelf> */
    protected $model = Bookshelf::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// ----- Option A: factory() returns Factory<Bookshelf> even without `@use HasFactory<...>` -----
$_factory = Bookshelf::factory();
/** @psalm-check-type-exact $_factory = \Illuminate\Database\Eloquent\Factories\Factory<\App\Sandbox\Bookshelf> */;

// ----- Option A: count(N)->make() chains through to Collection<int, Bookshelf> -----
$_shelves = Bookshelf::factory()->count(10)->make();
/** @psalm-check-type-exact $_shelves = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\Bookshelf> */;

// ----- Option A: bare make() (no count) returns the single model -----
$_single = Bookshelf::factory()->make();
/** @psalm-check-type-exact $_single = \App\Sandbox\Bookshelf */;

// ----- foreach over the count(N) result must not trigger PossibleRawObjectIteration -----
foreach (Bookshelf::factory()->count(10)->make() as $_shelf) {
    /** @psalm-check-type-exact $_shelf = \App\Sandbox\Bookshelf */;
}

// ----- create() chain mirrors make() -----
$_created = Bookshelf::factory()->count(3)->create();
/** @psalm-check-type-exact $_created = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\Bookshelf> */;

// ----- LSB: HasFactory inherited via abstract base, still resolves to subclass -----
abstract class Entity extends Model
{
    use HasFactory;
}

class Page extends Entity
{
}

$_pageFactory = Page::factory();
/** @psalm-check-type-exact $_pageFactory = \Illuminate\Database\Eloquent\Factories\Factory<\App\Sandbox\Page> */;

$_pages = Page::factory()->count(3)->make();
/** @psalm-check-type-exact $_pages = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\Page> */;

?>
--EXPECTF--
