--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractWrappedUuidModel;
use App\Models\Customer;
use App\Models\CustomPkUuidModel;
use App\Models\KeylessPermission;
use App\Models\OverriddenGetKeyModel;
use App\Models\StringKeyModel;
use App\Models\UlidModel;
use App\Models\UniqueStringIdModel;
use App\Models\UuidModel;
use App\Models\WrappedUuidModel;
use Illuminate\Database\Eloquent\Model;

function integer_model_key(Customer $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = int */
}

function uuid_model_key(UuidModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function wrapped_uuid_model_key(WrappedUuidModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function unique_string_id_model_key(UniqueStringIdModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function abstract_wrapped_uuid_model_key(AbstractWrappedUuidModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = int|string */
}

function ulid_model_key(UlidModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function configured_string_key(StringKeyModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function custom_primary_key(CustomPkUuidModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function string_key_union(UuidModel|UlidModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}

function keyless_model_falls_back(KeylessPermission $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = int|string */
}

function unknown_model_falls_back(Model $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = int|string */
}

function overridden_get_key_keeps_declared_type(OverriddenGetKeyModel $model): void
{
    $_key = $model->getKey();
    /** @psalm-check-type-exact $_key = string */
}
?>
--EXPECTF--
