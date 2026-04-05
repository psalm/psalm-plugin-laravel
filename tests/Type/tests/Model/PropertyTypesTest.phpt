--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;
use App\Models\Secret;

function test_id_type(Customer $customer): string
{
    return $customer->id;
}

function test_email_verified_at_type(Customer $customer): ?\Carbon\CarbonInterface
{
    return $customer->email_verified_at;
}

function test_uuid(Secret $secret): \Ramsey\Uuid\UuidInterface
{
    return $secret->uuid;
}

function test_first_name_using_legacy_accessor(Customer $customer): string
{
    return $customer->first_name_using_legacy_accessor;
}
?>
--EXPECTF--
