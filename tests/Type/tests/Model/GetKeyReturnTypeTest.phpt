--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractUuidKeyModel;
use App\Models\ConflictingKeyCastModel;
use App\Models\GetKeyOverrideModel;
use App\Models\KeylessPermission;
use App\Models\StringKeyModel;
use App\Models\UlidModel;
use App\Models\UuidModel;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard auto-incrementing int primary key narrows getKey() from the stub's
 * `int|string` to `int`. Uses Vehicle, not Customer — Customer declares
 * `@property string $id` (see PropertyAnnotationPrecedenceTest.phpt), which would assert a
 * conflicting type for the same runtime value on the property-read path.
 */
function test_get_key_int(Vehicle $vehicle): int
{
    $key = $vehicle->getKey();
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
 * An abstract receiver's metadata is derived from declared-property defaults, not an
 * instance — HasUuids overrides getKeyType() as a METHOD and never declares its own $keyType
 * property, so the abstract path can't see the override and must not narrow. This is pinned
 * via the SECTION_CASTS gate (abstract warm-up never sets it), not a dedicated abstract check
 * — this test is the tripwire if that coupling ever breaks.
 */
function test_get_key_abstract_uuid(AbstractUuidKeyModel $model): int|string
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
