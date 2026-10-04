--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: the #[Scope] attribute is Laravel 12+, so on Laravel 11 the plugin
// correctly does not resolve `electric()` as a scope (see EloquentModelMethods::hasScopeAttribute).
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\Relation1614AttributeScope;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1614, #[Scope] case.
 * Split from Issue1614StaticReceiverThroughThisTest.phpt because the attribute is Laravel 12+.
 */
class SubCustomer extends Customer
{
    /** @return HasMany<Vehicle, $this> */
    public function viaAttributeScope(): HasMany
    {
        return $this->vehicles()->electric();
    }
}
?>
--EXPECTF--
