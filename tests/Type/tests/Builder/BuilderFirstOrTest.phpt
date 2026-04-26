--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\SpecializationPivot;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/848
 *
 * Builder::firstOr() must narrow to TModel when the callback's return type is
 * `never` (i.e. always throws). The same applies to BelongsToMany::firstOr()
 * and HasOneOrManyThrough::firstOr().
 */

final class FirstOrNotFoundException extends \RuntimeException {}

// Closure in 1st position with `never` return type → must narrow to Customer.
function test_firstOr_closure_first_position_never_narrows(): void
{
    $_ = Customer::query()
        ->where('id', 1)
        ->firstOr(static function (): never {
            throw new FirstOrNotFoundException();
        });
    /** @psalm-check-type-exact $_ = App\Models\Customer */
}

// Closure in 2nd position (named) with `never` → still narrows to Customer.
function test_firstOr_closure_second_position_never_narrows(): void
{
    $_ = Customer::query()
        ->where('id', 1)
        ->firstOr(['*'], static function (): never {
            throw new FirstOrNotFoundException();
        });
    /** @psalm-check-type-exact $_ = App\Models\Customer */
}

// Closure returning a value → return is TModel|TValue. Psalm narrows the
// closure return to a literal here because the lambda body is constant.
function test_firstOr_closure_returns_value(): void
{
    $_ = Customer::query()->firstOr(static fn (): string => 'fallback');
    /** @psalm-check-type-exact $_ = 'fallback'|App\Models\Customer */
}

// Same as above but with the closure in the second (named) position.
function test_firstOr_named_callback_returns_value(): void
{
    $_ = Customer::query()->firstOr(['*'], static fn (): int => 42);
    /** @psalm-check-type-exact $_ = 42|App\Models\Customer */
}

/**
 * BelongsToMany::firstOr() with `never` callback must narrow to the related
 * model intersected with the pivot.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_firstOr_never_narrows(BelongsToMany $relation): void
{
    $_ = $relation->firstOr(static function (): never {
        throw new FirstOrNotFoundException();
    });
    /** @psalm-check-type-exact $_ = App\Models\MechanicSpecialization&object{pivot: App\Models\SpecializationPivot} */
}

/**
 * BelongsToMany::firstOr() with a value-returning callback in 1st position
 * must include the value type in the union.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_firstOr_value_callback_first_position(BelongsToMany $relation): void
{
    $_ = $relation->firstOr(static fn (): string => 'fallback');
    /** @psalm-check-type-exact $_ = 'fallback'|App\Models\MechanicSpecialization&object{pivot: App\Models\SpecializationPivot} */
}

/**
 * HasOneOrManyThrough::firstOr() with `never` callback must narrow to TRelatedModel.
 *
 * @param HasManyThrough<Invoice, WorkOrder, Customer> $relation
 */
function test_hasManyThrough_firstOr_never_narrows(HasManyThrough $relation): void
{
    $_ = $relation->firstOr(static function (): never {
        throw new FirstOrNotFoundException();
    });
    /** @psalm-check-type-exact $_ = App\Models\Invoice */
}

/**
 * HasOneOrManyThrough::firstOr() with a value-returning callback in 1st position
 * must include the value type in the union.
 *
 * @param HasManyThrough<Invoice, WorkOrder, Customer> $relation
 */
function test_hasManyThrough_firstOr_value_callback_first_position(HasManyThrough $relation): void
{
    $_ = $relation->firstOr(static fn (): string => 'fallback');
    /** @psalm-check-type-exact $_ = 'fallback'|App\Models\Invoice */
}

?>
--EXPECTF--
