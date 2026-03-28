--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Phone;
use App\Models\User;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/409
 *
 * When a model declares @property ?Phone $phone, the nullable type should be
 * respected even though a relationship method with the same name exists.
 */
function test_nullable_relationship_property_allows_null_check(User $user): void
{
    if ($user->phone === null) {
        echo 'null';
    }
}

function test_nullable_relationship_property_type(User $user): ?Phone
{
    /** @psalm-check-type-exact $phone = Phone|null */
    $phone = $user->phone;
    return $phone;
}
?>
--EXPECTF--
