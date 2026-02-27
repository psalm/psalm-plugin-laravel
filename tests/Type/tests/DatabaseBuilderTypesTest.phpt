--FILE--
<?php declare(strict_types=1);

use App\Models\User;

final class DatabaseBuilderUserRepository
{
    /** @param \Illuminate\Database\Eloquent\Builder<\App\Models\User> $builder */
    public function firstFromDatabaseBuilderInstance(\Illuminate\Database\Eloquent\Builder $builder): ?User {
        return $builder->first();
    }
}
?>
--EXPECTF--
