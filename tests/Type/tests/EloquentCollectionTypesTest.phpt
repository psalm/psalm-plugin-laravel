--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use App\Models\User;

final class UserRepository
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public function getAll(): \Illuminate\Database\Eloquent\Collection
    {
      return User::all();
    }

    public function getFirst(): ?User
    {
      return $this->getAll()->first();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    public function getBuilder(array $attributes): \Illuminate\Database\Eloquent\Builder
    {
      return User::where($attributes);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public function getWhereUsingLessMagic(array $attributes): \Illuminate\Database\Eloquent\Collection
    {
      return User::query()->where($attributes)->get();
    }
}
?>
--EXPECTF--
