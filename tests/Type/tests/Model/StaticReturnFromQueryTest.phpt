--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/799
 *
 * Methods declared `: static` that return `static::query()->firstOrCreate(...)`
 * (or sibling write methods) must propagate the `&static` intersection through
 * the Builder template parameter. Otherwise Psalm reports MoreSpecificReturnType
 * because `firstOrCreate()` returns `Customer` while the declared type is
 * `Customer&static`.
 */
// Intentionally non-final: the `: static` template propagation only matters when
// `static` is genuinely deferred. A final class collapses `static` to `self`,
// hiding the regression.
class StaticReturnFromQueryRegression extends Customer
{
    public static function viaFirstOrCreate(): static
    {
        return static::query()->firstOrCreate(['id' => '1']);
    }

    public static function viaUpdateOrCreate(): static
    {
        return static::query()->updateOrCreate(['id' => '1'], ['first_name' => 'x']);
    }

    public static function viaFirstOrNew(): static
    {
        return static::query()->firstOrNew(['id' => '1']);
    }

    public static function viaCreate(): static
    {
        return static::query()->create(['id' => '1']);
    }

    // Contrast case: `: self` already worked before the fix (returning Customer
    // for `: self` is fine). Kept to make the `: static` vs `: self` asymmetry
    // explicit for future readers; it is not a control for the regression.
    public static function viaFirstOrCreateSelfReturn(): self
    {
        return static::query()->firstOrCreate(['id' => '1']);
    }
}
?>
--EXPECTF--
