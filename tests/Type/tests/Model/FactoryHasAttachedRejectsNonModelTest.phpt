--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\HasAttachedReject;

use App\Models\Customer;
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

// After widening hasAttached() to iterable<array-key, TRelatedModel|array<string, mixed>>
// in #914, verify the `TRelatedModel of Model` bound still rejects non-Model elements.
// A regression here would mean callers silently accept iterables of scalars.
$_ = (new CustomerFactory())->hasAttached([1, 2, 3])->create();
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Sandbox\HasAttachedReject\CustomerFactory::hasAttached expects %s, but list{1, 2, 3} provided
