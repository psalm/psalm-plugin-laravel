--FILE--
<?php declare(strict_types=1);

use App\Models\Contract;
use App\Models\Customer;

/**
 * Instance scope calls on Builder (Customer::query()->active()) must resolve to
 * Builder<Model> instead of mixed, so downstream chained calls (sum, where, get)
 * keep their narrowed types.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1004
 */

$legacy = Customer::query()->active();
/** @psalm-trace $legacy */

$attribute = Customer::query()->verified();
/** @psalm-trace $attribute */

$chained = Customer::query()->active()->verified();
/** @psalm-trace $chained */

$sum = Customer::query()->active()->sum('vehicles_count');
/** @psalm-trace $sum */

$withArg = Customer::query()->ofName('Ada');
/** @psalm-trace $withArg */

// Args after $query are type-checked against the scope's declared params.
$badArg = Customer::query()->ofName(123);

// Scope declared on an abstract parent model: params resolve from the declaring class.
$inherited = Contract::query()->signedBetween(now(), now());
/** @psalm-trace $inherited */

// Scope hosted in a trait used by the model.
$fromTrait = Contract::query()->flagged();
/** @psalm-trace $fromTrait */

echo $legacy::class, $attribute::class, $chained::class, $withArg::class, $badArg::class, $inherited::class, $fromTrait::class, $sum;
?>
--EXPECTF--
Trace on line %d: $legacy: Illuminate\Database\Eloquent\Builder<App\Models\Customer>
Trace on line %d: $attribute: Illuminate\Database\Eloquent\Builder<App\Models\Customer>
Trace on line %d: $chained: Illuminate\Database\Eloquent\Builder<App\Models\Customer>
Trace on line %d: $sum: int
Trace on line %d: $withArg: Illuminate\Database\Eloquent\Builder<App\Models\Customer>
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::ofname expects string, but 123 provided
Trace on line %d: $inherited: Illuminate\Database\Eloquent\Builder<App\Models\Contract>
Trace on line %d: $fromTrait: Illuminate\Database\Eloquent\Builder<App\Models\Contract>
