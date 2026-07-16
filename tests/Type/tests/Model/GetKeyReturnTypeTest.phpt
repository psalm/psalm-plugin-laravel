--FILE--
<?php declare(strict_types=1);

use App\Models\ConflictingKeyCastModel;
use App\Models\Customer;
use App\Models\GetKeyOverrideModel;
use App\Models\KeylessPermission;
use App\Models\StringKeyModel;
use App\Models\UlidModel;
use App\Models\UuidModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard auto-incrementing int primary key narrows getKey() from the stub's
 * `int|string` to `int`.
 */
function test_get_key_int(Customer $customer): int
{
    $key = $customer->getKey();
    /** @psalm-check-type-exact $key = int */

    return $key;
}

/** HasUuids forces the primary key to string, regardless of the base $keyType default. */
function test_get_key_uuid(UuidModel $model): string
{
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = string */

    return $key;
}

/** HasUlids forces the primary key to string, same as HasUuids. */
function test_get_key_ulid(UlidModel $model): string
{
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = string */

    return $key;
}

/** A plain $keyType='string' property override narrows the same as the trait-driven forms. */
function test_get_key_string_property(StringKeyModel $model): string
{
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = string */

    return $key;
}

/**
 * A keyless model (no single Eloquent primary key) must fall back to the stub's
 * `int|string` — there is no column name to look up.
 */
function test_get_key_keyless(KeylessPermission $model): int|string
{
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = int|string */

    return $key;
}

/**
 * A model overriding getKey() must fall back to the stub's `int|string` — the plugin
 * never narrows past a user override of the method it is narrowing.
 */
function test_get_key_override(GetKeyOverrideModel $model): int|string
{
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = int|string */

    return $key;
}

/**
 * A cast on the primary-key column that conflicts with the mapped type (here $keyType='int'
 * by default, but $casts declares 'id' => 'string') must not be trusted — falls back to the
 * stub's `int|string`.
 */
function test_get_key_conflicting_cast(ConflictingKeyCastModel $model): int|string
{
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = int|string */

    return $key;
}

/**
 * An anonymous model subclass is never warmed up by the registry (synthetic,
 * unloadable FQCN), so getKey() falls back to the stub's `int|string`.
 */
function test_get_key_unregistered(): int|string
{
    $model = new class extends Model {};
    $key = $model->getKey();
    /** @psalm-check-type-exact $key = int|string */

    return $key;
}
?>
--EXPECTF--
