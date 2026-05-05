--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox;

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


// ----- Default factory call (no count) ---------------------------------------
$_default = (new CustomerFactory())->create();
/** @psalm-check-type-exact $_default = \App\Models\Customer */;

// ----- count(N>1) ------------------------------------------------------------
$_count3 = (new CustomerFactory())->count(3)->create();
/** @psalm-check-type-exact $_count3 = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count(1) — Laravel returns a one-item Collection ---------------------
$_count1 = (new CustomerFactory())->count(1)->create();
/** @psalm-check-type-exact $_count1 = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count(0) — Laravel returns an empty Collection -----------------------
$_count0 = (new CustomerFactory())->count(0)->create();
/** @psalm-check-type-exact $_count0 = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count(null) — explicit single mode -----------------------------------
$_countNull = (new CustomerFactory())->count(null)->create();
/** @psalm-check-type-exact $_countNull = \App\Models\Customer */;

// ----- times(N) static call -------------------------------------------------
$_times3 = CustomerFactory::times(3)->create();
/** @psalm-check-type-exact $_times3 = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count(N>1)->make() ---------------------------------------------------
$_makeCount3 = (new CustomerFactory())->count(3)->make();
/** @psalm-check-type-exact $_makeCount3 = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count(N>1)->createQuietly() ------------------------------------------
$_quietCount3 = (new CustomerFactory())->count(3)->createQuietly();
/** @psalm-check-type-exact $_quietCount3 = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count(N>1)->lazy() — Closure of the conditional ----------------------
$_lazyCount3 = (new CustomerFactory())->count(3)->lazy();
/** @psalm-check-type-exact $_lazyCount3 = Closure():\Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- createOne always single, regardless of count -------------------------
$_createOne = (new CustomerFactory())->count(3)->createOne();
/** @psalm-check-type-exact $_createOne = \App\Models\Customer */;

// ----- makeOne always single ------------------------------------------------
$_makeOne = (new CustomerFactory())->count(3)->makeOne();
/** @psalm-check-type-exact $_makeOne = \App\Models\Customer */;

// ----- createMany always Collection -----------------------------------------
$_createMany = (new CustomerFactory())->createMany();
/** @psalm-check-type-exact $_createMany = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- makeMany always Collection -------------------------------------------
$_makeMany = (new CustomerFactory())->makeMany();
/** @psalm-check-type-exact $_makeMany = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- count then state preserves plurality through `static` return ---------
$_countThenState = (new CustomerFactory())->count(3)->state(['email' => 'a@b'])->create();
/** @psalm-check-type-exact $_countThenState = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- non-null int variable: definitely a Collection -----------------------
$n = random_int(2, 5);
$_variableInt = (new CustomerFactory())->count($n)->create();
/** @psalm-check-type-exact $_variableInt = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

// ----- nullable int variable: union is the contract -------------------------
/** @var int|null */
$maybeNull = null;
$_variableNullable = (new CustomerFactory())->count($maybeNull)->create();
/** @psalm-check-type-exact $_variableNullable = \App\Models\Customer|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */;

?>
--EXPECTF--
