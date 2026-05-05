--FILE--
<?php declare(strict_types=1);

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A user-declared `forFoo()` / `hasFoo()` method on a Factory subclass must keep
 * its real signature and return type — the magic-method handler must decline
 * for any method that exists natively, regardless of whether the underlying
 * model has a same-named relation.
 *
 * This is the second half of the native-shadowing guard's contract (the first
 * half, inherited Factory methods like hasAttached, lives in
 * MagicMethodsNativeShadowingTest.phpt).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 *
 * @extends Factory<WorkOrder>
 */
class UserOverrideWorkOrderFactory extends Factory
{
    /** @var class-string<WorkOrder> */
    protected $model = WorkOrder::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /**
     * Real method that shadows the magic forVehicle() resolution. Returns a
     * literal int rather than a Factory so any handler-injected `static`
     * return-type would surface as a CheckType mismatch.
     */
    public function forVehicle(): int
    {
        return 42;
    }
}

function test_user_declared_method_wins(UserOverrideWorkOrderFactory $factory): int
{
    /** @psalm-check-type-exact $result = int */
    $result = $factory->forVehicle();
    return $result;
}
?>
--EXPECTF--
