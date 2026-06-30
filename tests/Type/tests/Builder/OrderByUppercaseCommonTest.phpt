--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13.8.0');
--FILE--
<?php declare(strict_types=1);

// On Laravel 12–13.7 there is no \SortDirection enum, and the runtime canonicalizes the
// direction via strtolower(), so the common stub accepts the uppercase literals 'ASC'|'DESC'
// as well as the lowercase form. orderBy('x', 'DESC') is valid, idiomatic code and must not
// raise InvalidArgument. From 13.8 the version override drops uppercase in favour of the
// enum (see OrderBySortDirectionL13Test).

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

function test_order_by_uppercase(): void {
    $_asc = Customer::query()->orderBy('name', 'asc');
    /** @psalm-check-type-exact $_asc = Builder<Customer>&static */

    $_ASC = Customer::query()->orderBy('name', 'ASC');
    /** @psalm-check-type-exact $_ASC = Builder<Customer>&static */

    $_DESC = Customer::query()->orderBy('name', 'DESC');
    /** @psalm-check-type-exact $_DESC = Builder<Customer>&static */
}
?>
--EXPECTF--
