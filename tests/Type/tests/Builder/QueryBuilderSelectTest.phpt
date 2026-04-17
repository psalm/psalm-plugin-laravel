--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Query\Builder;

/**
 * Query\Builder::select() accepts both array and variadic string arguments
 * via func_get_args(). The stub uses @psalm-variadic to model this.
 */
final class QueryBuilderSelectTest
{
    public function selectWithArray(Builder $builder): Builder
    {
        return $builder->select(['id', 'name', 'email']);
    }

    public function selectWithSingleString(Builder $builder): Builder
    {
        return $builder->select('id');
    }

    public function selectWithVariadicStrings(Builder $builder): Builder
    {
        return $builder->select('id', 'name', 'email');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Customer> */
    public function selectThroughEloquentBuilder(): \Illuminate\Database\Eloquent\Builder
    {
        return Customer::query()->select('id', 'name');
    }
}
?>
--EXPECTF--
