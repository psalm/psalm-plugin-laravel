--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Models\User;

final class UserRepository
{
    /** @return Builder<User> */
    public function getNewQuery(): Builder
    {
        return User::query();
    }

    /** @return Builder<User> */
    public function getNewModelQuery(): Builder
    {
        return (new User())->newModelQuery();
    }

    /** @param Builder<User> $builder */
    public function firstOrFailFromBuilderInstance(Builder $builder): User {
        return $builder->firstOrFail();
    }

    /** @param Builder<User> $builder */
    public function findOrFailFromBuilderInstance(Builder $builder): User {
        return $builder->findOrFail(1);
    }

    /**
    * @param Builder<User> $builder
    * @return Collection<int, User>
    */
    public function findMultipleOrFailFromBuilderInstance(Builder $builder): Collection {
        return $builder->findOrFail([1, 2]);
    }

    /** @param Builder<User> $builder */
    public function findOne(Builder $builder): ?User {
        return $builder->find(1);
    }

    /** @param Builder<User> $builder */
    public function findViaArray(Builder $builder): Collection {
        return $builder->find([1]);
    }

    /** @return Builder<User> */
    public function getWhereBuilderViaInstance(array $attributes): Builder {
        return (new User())->where($attributes);
    }

    public function chunkReturnsTemplatedCollection(): void
    {
        User::query()
            ->chunk(10, function (Collection $collection) {
                /** @psalm-check-type-exact $collection = Collection<int, User> */
                echo $collection->count();
            });
    }

    /** @return \Illuminate\Pagination\CursorPaginator<User> */
    public function testCursorPaginate(Builder $builder): \Illuminate\Pagination\CursorPaginator
    {
        return User::query()->cursorPaginate();
    }

    /** @return Builder<User> */
    public function getWhereBuilderViaStatic(array $attributes): Builder
    {
      return User::where($attributes);
    }

//    /** @return Collection<int, User> */
//    public function getWhereViaStatic(array $attributes): Collection
//    {
//      return User::where($attributes)->get();
//    }
}

/**
* @psalm-param Builder<User> $builder
* @psalm-return Builder<User>
*/
function can_call_methods_on_underlying_query_builder(Builder $builder): Builder {
    return $builder->orderBy('id', 'ASC');
}

function test_whereDateWithDateTimeInterface(Builder $builder): Builder {
    return $builder->whereDate('created_at', '>', new \DateTimeImmutable());
}

function test_whereDateWithString(Builder $builder): Builder {
    return $builder->whereDate('created_at', '>', (new \DateTimeImmutable())->format('d/m/Y'));
}

function test_whereDateWithNull(Builder $builder): Builder
{
    return $builder->whereDate('created_at', '>', null);
}

function test_whereDateWithInt(Builder $builder): Builder
{
    return $builder->whereDate('created_at', '>', 1);
}

function test_failure_on_calling_not_defined_method(): mixed
{
    return User::fakeQueryMethodThatDoesntExist();
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 3 of Illuminate\Database\Eloquent\Builder::whereDate expects DateTimeInterface|null|string, but 1 provided
UndefinedMagicMethod on line %d: Magic method App\Models\User::fakequerymethodthatdoesntexist does not exist