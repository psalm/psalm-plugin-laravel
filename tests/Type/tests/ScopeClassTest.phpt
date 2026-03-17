--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Implementing Scope with narrowed @param types should not trigger
 * MoreSpecificImplementedParamType when @implements binds the template.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/207
 *
 * @implements Scope<User>
 */
final class ActiveScope implements Scope
{
    /**
     * @param Builder<User> $builder
     * @param User $model
     */
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('active', true);
    }
}
?>
--EXPECTF--
