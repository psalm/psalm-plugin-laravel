--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.8.0');
--FILE--
<?php declare(strict_types=1);

// Laravel 13.8.0+ types the orderBy() direction as 'asc'|'desc'|\SortDirection (the PHP
// 8.6 enum). The stubs/13.8.0/ override adopts that literal verbatim and keeps the SQL
// taint sink on $column.

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

function test_order_by(): void {
    // both string literals are accepted
    $_asc = Customer::query()->orderBy('name', 'asc');
    /** @psalm-check-type-exact $_asc = Builder<Customer>&static */

    $_desc = Customer::query()->orderBy('name', 'desc');
    /** @psalm-check-type-exact $_desc = Builder<Customer>&static */

    // the new PHP 8.6 enum is accepted
    $_enum = Customer::query()->orderBy('name', \SortDirection::Ascending);
    /** @psalm-check-type-exact $_enum = Builder<Customer>&static */
}
?>
--EXPECTF--
