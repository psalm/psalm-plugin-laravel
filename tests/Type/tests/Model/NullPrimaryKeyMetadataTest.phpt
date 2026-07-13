--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;
use App\Models\KeylessPermission;

/** Registry-backed relation inference remains precise for a model with no primary key. */
function test_null_primary_key_relation_metadata(KeylessPermission $permission): ?Customer
{
    /** @psalm-check-type-exact $customer = Customer|null */
    $customer = $permission->customer;

    return $customer;
}

?>
--EXPECTF--
