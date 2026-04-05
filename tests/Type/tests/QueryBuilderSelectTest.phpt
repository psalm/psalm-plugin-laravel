--FILE--
<?php declare(strict_types=1);

use App\Models\User;
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

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    public function selectThroughEloquentBuilder(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->select('id', 'name');
    }

    /**
     * Model::select() static call forwards to Builder via __callStatic.
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/216
     */
    public function selectDirectlyOnModel(): void
    {
        $_ = User::select('name', 'email');
        /** @psalm-check-type-exact $_ = \Illuminate\Database\Eloquent\Builder<User&static> */
    }

    public function selectDirectlyOnModelWithArray(): void
    {
        $_ = User::select(['name', 'email']);
        /** @psalm-check-type-exact $_ = \Illuminate\Database\Eloquent\Builder<User&static> */
    }

    public function selectDirectlyOnModelChained(): void
    {
        $_ = User::select('name', 'email')->where('active', true);
        /** @psalm-check-type-exact $_ = \Illuminate\Database\Eloquent\Builder<User&static> */
    }

    /**
     * Model::addSelect() and Model::distinct() also use @psalm-variadic
     * and need the same Builder stub treatment as select().
     */
    public function addSelectDirectlyOnModel(): void
    {
        $_ = User::addSelect('name', 'email');
        /** @psalm-check-type-exact $_ = \Illuminate\Database\Eloquent\Builder<User&static> */
    }

    public function distinctDirectlyOnModel(): void
    {
        $_ = User::distinct('name');
        /** @psalm-check-type-exact $_ = \Illuminate\Database\Eloquent\Builder<User&static> */
    }
}
?>
--EXPECTF--
