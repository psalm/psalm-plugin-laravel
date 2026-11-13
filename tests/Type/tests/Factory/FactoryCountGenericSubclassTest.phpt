--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\GenericSubclass;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit `TCount` means no synthesized one, so `count()` answers `Factory<TModel, count>`:
 * the template `TModel` must survive instead of collapsing to `Model`.
 *
 * @template TModel of Model
 * @extends Factory<TModel, int|null>
 */
abstract class ExplicitCountFactory extends Factory
{
    /**
     * @param positive-int $count
     * @return Collection<int, TModel>
     */
    final public function createOfQuantity(int $count): Collection
    {
        return $this->count($count)->create();
    }
}

/**
 * Same for a receiver typed `Factory<TModel>`.
 *
 * @template TModel of Model
 * @param Factory<TModel> $factory
 * @param positive-int $count
 * @return Collection<int, TModel>
 */
function createMany(Factory $factory, int $count): Collection
{
    return $factory->count($count)->create();
}
?>
--EXPECTF--
