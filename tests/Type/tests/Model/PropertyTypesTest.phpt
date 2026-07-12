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

/** New-style Attribute accessor read resolves to TGet (Attribute<string, string>). */
function test_attribute_accessor_with_mutator_read(Customer $customer): string
{
    $value = $customer->first_name;
    /** @psalm-check-type-exact $value = string */
    return $value;
}

/** Read-only Attribute<string, never> accessor read still resolves to TGet. */
function test_read_only_attribute_accessor_read(Customer $customer): string
{
    $value = $customer->display_name;
    /** @psalm-check-type-exact $value = string */
    return $value;
}

/**
 * camelCase access to a snake_case attribute accessor resolves identically — Laravel's
 * case-insensitive Str::camel resolution means $customer->firstName and $customer->first_name
 * both hit the firstName(): Attribute accessor.
 */
function test_attribute_accessor_camelcase_read(Customer $customer): string
{
    $value = $customer->firstName;
    /** @psalm-check-type-exact $value = string */
    return $value;
}
?>
--EXPECTF--
