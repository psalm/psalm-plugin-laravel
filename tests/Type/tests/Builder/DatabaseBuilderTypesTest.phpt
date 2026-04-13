--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

final class DatabaseBuilderCustomerRepository
{
    /** @param \Illuminate\Database\Eloquent\Builder<\App\Models\Customer> $builder */
    public function firstFromDatabaseBuilderInstance(\Illuminate\Database\Eloquent\Builder $builder): ?Customer {
        return $builder->first();
    }
}
?>
--EXPECTF--
