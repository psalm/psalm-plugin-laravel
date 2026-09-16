--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ZzWatchLarastanProbe2Test
{
    /** @param HasMany<Vehicle, Customer> $relation */
    public function whereRelationAcceptsRelationObject(HasMany $relation): void
    {
        Customer::query()->whereRelation($relation, 'active', 1);
    }

    public function whereRelationAcceptsContractExpression(ExpressionContract $e): void
    {
        Customer::query()->whereRelation('vehicles', $e, '=', 1);
    }

    public function whereRelationClosureColumnParam(): void
    {
        Customer::query()->whereRelation('vehicles', function ($q): void {
            /** @psalm-check-type-exact $q = Builder<Vehicle> */
        });
    }

    public function whereHasClosureParam(): void
    {
        Customer::query()->whereHas('vehicles', function ($q): void {
            /** @psalm-check-type-exact $q = Builder<Vehicle> */
        });
    }
}
?>
--EXPECTF--
