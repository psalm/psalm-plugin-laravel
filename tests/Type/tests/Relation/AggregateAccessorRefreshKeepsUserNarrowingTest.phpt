--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1641
 *
 * `refresh()` drops only the aggregate facts ModelAggregateLoadHandler recorded. Narrowings the user
 * made on relations and attributes survive it, as they do for any other impure call.
 */
function test_refresh_keeps_a_relation_narrowing(Invoice $invoice): void
{
    \assert($invoice->billable instanceof Customer);
    $invoice->refresh();
    /** @psalm-check-type-exact $billable = Customer */
    $billable = $invoice->billable;
    echo $billable::class;
}

function test_refresh_keeps_an_attribute_narrowing(Customer $customer): void
{
    \assert($customer->email_verified_at !== null);
    $customer->refresh();
    /** @psalm-check-type-exact $verifiedAt = \Carbon\CarbonInterface */
    $verifiedAt = $customer->email_verified_at;
    echo $verifiedAt::class;
}

function test_refresh_after_a_load_chain_keeps_the_narrowing(Customer $customer): void
{
    \assert($customer->email_verified_at !== null);
    $customer->loadCount('vehicles')->refresh();
    /** @psalm-check-type-exact $verifiedAt = \Carbon\CarbonInterface */
    $verifiedAt = $customer->email_verified_at;
    echo $verifiedAt::class;
}
?>
--EXPECTF--
