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
}
?>
--EXPECTF--
