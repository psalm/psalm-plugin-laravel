--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\KeylessPermission;

/**
 * Regression for #1260: metadata warm-up must retain a model with a null primary-key name
 * even when its inherited `$incrementing` setting remains true. The bare BelongsTo return
 * on KeylessPermission::customer() makes the magic property's precise type depend on the
 * registry relation snapshot rather than an explicit generic annotation.
 */
function keyless_model_relation_uses_registry_metadata(KeylessPermission $permission): ?Customer
{
    $_customer = $permission->customer;
    /** @psalm-check-type-exact $_customer = Customer|null */

    return $_customer;
}
?>
--EXPECTF--
