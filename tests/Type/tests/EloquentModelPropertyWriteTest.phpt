--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-sealed.xml
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * With sealAllProperties="true", property writes on models must be recognized by Psalm.
 * The plugin registers pseudo_property_set_types so that relationship, legacy mutator, and new-style Attribute writes
 * don't trigger UndefinedMagicPropertyAssignment errors.
 *
 * Column writes are not tested here because the type test environment does not have
 * migration files in the Testbench default database path. Column write support is
 * covered by the unit test in ModelPropertyHandlerTest.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/446
 */

/** Relationship property write */
function test_relationship_write(User $user, Collection $roles): void
{
    $user->roles = $roles;
}

/** Legacy mutator write (setXxxAttribute) */
function test_legacy_mutator_write(User $user): void
{
    $user->nickname = 'alice';
}

/** New-style Attribute accessor write */
function test_attribute_accessor_write(User $user): void
{
    $user->first_name = 'Alice';
}
?>
--EXPECTF--
