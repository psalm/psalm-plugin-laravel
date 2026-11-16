--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\UnrelatedTemplate;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * The first template describes an unrelated model: `Factory`'s TModel is `Customer`, never `TRelated`.
 *
 * @template TRelated of Model
 * @extends Factory<Customer, int|null>
 */
abstract class UnrelatedFirstTemplateFactory extends Factory
{
    /**
     * @param positive-int $count
     * @return Collection<int, Customer>
     */
    final public function createOfQuantity(int $count): Collection
    {
        return $this->count($count)->create();
    }
}

/**
 * The template that binds `Factory::TModel` is not the first one.
 *
 * @template TRelated of Model
 * @template TModel of Model
 * @extends Factory<TModel, int|null>
 */
abstract class SecondTemplateFactory extends Factory
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
 * @param SecondTemplateFactory<Invoice, Customer> $factory
 * @param positive-int $count
 * @return Collection<int, Customer>
 */
function outside(SecondTemplateFactory $factory, int $count): Collection
{
    return $factory->count($count)->create();
}
?>
--EXPECTF--
