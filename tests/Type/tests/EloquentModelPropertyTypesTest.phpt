--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Secret;
use App\Models\User;

function test_id_type(User $user): string
{
    return $user->id;
}

function test_email_verified_at_type(User $user): ?\Carbon\CarbonInterface
{
    return $user->email_verified_at;
}

function test_uuid(Secret $secret): \Ramsey\Uuid\UuidInterface
{
    return $secret->uuid;
}

function test_first_name_using_legacy_accessor(User $user): string
{
    return $user->first_name_using_legacy_accessor;
}
?>
--EXPECTF--
