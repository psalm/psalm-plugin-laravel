--FILE--
<?php declare(strict_types=1);

namespace Tests\Factories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory as BaseFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An app-level generic base factory: count() keeps the TModel template, and the
 * one-argument Factory<X> form omits only the defaulted TCount.
 *
 * @template TModel of Model
 * @extends BaseFactory<TModel>
 */
abstract class GenericFactory extends BaseFactory
{
    /**
     * @param positive-int $count
     * @return Collection<int, TModel>
     */
    final public function createOfQuantity(int $count): Collection
    {
        return $this->count($count)->create();
    }

    /**
     * @param positive-int $count
     * @return Collection<int, TModel>
     */
    final public function makeOfQuantity(int $count): Collection
    {
        return $this->count($count)->make();
    }

    /**
     * @template THasRelated of Model
     * @param BaseFactory<THasRelated> $factory
     */
    #[\Override]
    final public function has(BaseFactory $factory, ?string $relationship = null): static
    {
        return parent::has($factory, $relationship);
    }
}
?>
--EXPECTF--
