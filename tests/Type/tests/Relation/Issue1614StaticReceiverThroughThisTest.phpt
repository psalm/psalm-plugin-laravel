--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\Relation1614;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1614.
 *
 * Inside a NON-final model, `$this->rel()` is typed with the late-static-bound receiver
 * (`Sub&static`), so a method declared `@return HasMany<Vehicle, $this>` accepts
 * `return $this->rel()`, `return $this->rel()->where(...)` and `return $this->rel()->scope()`.
 * Before the fix the handler emitted the plain `Sub` for TDeclaringModel, which is less
 * specific than `$this` and raised LessSpecificReturnStatement / MoreSpecificReturnType.
 *
 * Inline models are not registered with the plugin ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
 * requires `class_exists`), and the bodies of autoloaded fixtures are not analysed by this suite,
 * so the bodies below live in inline subclasses of the registered, non-final fixtures
 * {@see Customer} (HasMany to Vehicle, which has #[Scope] and legacy scope methods) and
 * {@see Admin} (MorphToMany, the 4-template relation shape).
 */
class SubCustomer extends Customer
{
    /** @return HasMany<Vehicle, $this> */
    public function plain(): HasMany
    {
        return $this->vehicles();
    }

    /** @return HasMany<Vehicle, $this> */
    public function viaWhere(): HasMany
    {
        return $this->vehicles()->where('active', true);
    }

    /** @return HasMany<Vehicle, $this> */
    public function viaAttributeScope(): HasMany
    {
        return $this->vehicles()->electric();
    }

    /** @return HasMany<Vehicle, $this> */
    public function viaLegacyScope(): HasMany
    {
        return $this->vehicles()->byMake('Volvo');
    }

    public function probeThis(): void
    {
        $_rel = $this->vehicles();
        /** @psalm-check-type-exact $_rel = HasMany<Vehicle, SubCustomer&static> */
    }

    /**
     * Mixed receiver `T|SubCustomer&static`: the provider runs once per atomic against the same
     * receiver Union, so it must not mark the `T` alternative as `&static`.
     *
     * @template T of SubCustomer
     * @param T $other
     */
    public function probeMixedTemplate(object $other, bool $flag): void
    {
        $receiver = $flag ? $this : $other;
        $_rel = $receiver->vehicles();
        /** @psalm-check-type-exact $_rel = HasMany<Vehicle, SubCustomer> */
    }
}

class SubAdmin extends Admin
{
    /** @return MorphToMany<Customer, $this, MorphPivot, 'pivot'> */
    public function plainMorphToMany(): MorphToMany
    {
        return $this->customers();
    }

    public function probeThis(): void
    {
        $_rel = $this->customers();
        /** @psalm-check-type-exact $_rel = MorphToMany<Customer, SubAdmin&static, MorphPivot, 'pivot'> */
    }
}

// Boundary: an external caller holds a concrete instance, never `&static`.
function external_caller(SubCustomer $customer, SubAdmin $admin): void
{
    $_rel = $customer->vehicles();
    /** @psalm-check-type-exact $_rel = HasMany<Vehicle, SubCustomer> */

    $_morph = $admin->customers();
    /** @psalm-check-type-exact $_morph = MorphToMany<Customer, SubAdmin, MorphPivot, 'pivot'> */
}
?>
--EXPECTF--
