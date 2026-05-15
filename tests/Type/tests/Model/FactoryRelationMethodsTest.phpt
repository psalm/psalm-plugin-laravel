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

// Regression for https://github.com/psalm/psalm-plugin-laravel/issues/914:
// hasAttached() must accept the Eloquent\Collection returned by a counted
// factory's create() call. Previously the stub typed the collection arg as
// Support\Collection<array-key, TRelatedModel>, which rejected both the
// subclass (Eloquent\Collection) and the narrower key (int) under Psalm's
// invariant template check. The stub now uses iterable<array-key, ...> which
// accepts either collection shape.

// ----- hasAttached() accepts an Eloquent\Collection of related models -------
$_attachedMechanics = MechanicFactory::new()->count($count)->create();
/** @psalm-check-type-exact $_attachedMechanics = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Mechanic> */;

$_hasAttachedFromCollection = (new CustomerFactory())->hasAttached($_attachedMechanics)->create();
/** @psalm-check-type-exact $_hasAttachedFromCollection = \App\Models\Customer */;

// ----- hasAttached() still accepts a Support\Collection of related models ---
/** @var \Illuminate\Support\Collection<array-key, \App\Models\Mechanic> $supportCollection */
$supportCollection = new \Illuminate\Support\Collection();
$_hasAttachedFromSupport = (new CustomerFactory())->hasAttached($supportCollection)->create();
/** @psalm-check-type-exact $_hasAttachedFromSupport = \App\Models\Customer */;

// ----- hasAttached() still accepts an array of related models ---------------
$_hasAttachedFromArray = (new CustomerFactory())->hasAttached([new Mechanic()])->create();
/** @psalm-check-type-exact $_hasAttachedFromArray = \App\Models\Customer */;

// ----- hasAttached() still accepts a single related model -------------------
$_hasAttachedFromModel = (new CustomerFactory())->hasAttached(new Mechanic())->create();
/** @psalm-check-type-exact $_hasAttachedFromModel = \App\Models\Customer */;

?>
--EXPECTF--
