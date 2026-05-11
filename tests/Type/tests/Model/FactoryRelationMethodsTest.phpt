--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\RelationMethods;

use App\Models\Customer;
use App\Models\Mechanic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    /** @var class-string<Customer> */
    protected $model = Customer::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * @extends Factory<Mechanic>
 */
final class MechanicFactory extends Factory
{
    /** @var class-string<Mechanic> */
    protected $model = Mechanic::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// Regression for https://github.com/psalm/psalm-plugin-laravel/issues/693:
// after the Factory stub gained a second template (TCount), Laravel's inherited
// `has()` / `hasAttached()` / `for()` signatures (which reference `Factory`
// without template args) collapsed the arg to `Factory<Model, int|null>`. A
// `count()` chain returns `Factory<TModel, 2>`, which fails Psalm's invariant
// template check. Stub-level overrides keep the receiver's TCount preserved
// via `@return static` while accepting any `Factory<TRelated, TRelatedCount>`.

$count = random_int(2, 5);

// ----- has() accepts a counted factory --------------------------------------
$_hasCounted = (new CustomerFactory())->has(MechanicFactory::new()->count($count))->create();
/** @psalm-check-type-exact $_hasCounted = \App\Models\Customer */;

// ----- has() preserves receiver TCount through the chain --------------------
$_hasFromCounted = (new CustomerFactory())->count(3)->has(MechanicFactory::new()->count($count))->create();
/** @psalm-check-type-exact $_hasFromCounted = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- for() accepts a counted factory --------------------------------------
$_forCounted = (new MechanicFactory())->for(CustomerFactory::new()->count($count))->create();
/** @psalm-check-type-exact $_forCounted = \App\Models\Mechanic */;

// ----- hasAttached() accepts a counted factory ------------------------------
$_hasAttachedCounted = (new CustomerFactory())->hasAttached(MechanicFactory::new()->count($count))->create();
/** @psalm-check-type-exact $_hasAttachedCounted = \App\Models\Customer */;

?>
--EXPECTF--
