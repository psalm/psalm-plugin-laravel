--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression test for #1246. Laravel treats the second argument as the value
 * when whereDate() is called with two arguments, and as the operator when the
 * third argument is present.
 */
function where_date_accepts_two_argument_datetime_value(): void
{
    $_query = Customer::query()->whereDate('created_at', CarbonImmutable::now());
    /** @psalm-check-type-exact $_query = Builder<Customer>&static */

    Customer::query()->whereDate('created_at', new DateTimeImmutable());
}

function where_date_accepts_three_argument_operator_and_datetime_value(): void
{
    $_query = Customer::query()->whereDate('created_at', '>=', CarbonImmutable::now());
    /** @psalm-check-type-exact $_query = Builder<Customer>&static */
}
?>
--EXPECTF--
