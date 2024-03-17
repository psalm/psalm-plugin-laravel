--FILE--
<?php declare(strict_types=1);
use App\Models\User;

final class UserRepository
{
    /** @param \Illuminate\Database\Eloquent\Builder<\App\Models\User> $builder */
    public function firstFromDatabaseBuilderInstance(\Illuminate\Database\Eloquent\Builder $builder): ?User {
        return $builder->first();
    }
}

function test_db_raw(): \Illuminate\Contracts\Database\Query\Expression {
    return \DB::raw(1);
}
?>
--EXPECTF--
