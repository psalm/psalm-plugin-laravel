--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Plain Scope implementation without @implements — the most common real-world
 * pattern. Must continue to work without errors after promoting the template
 * from method-level to class-level.
 */
final class PlainScope implements Scope
{
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('active', true);
    }
}

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

/**
 * Invalid template bound — \stdClass is not a Model subclass.
 * Psalm should reject this with InvalidTemplateParam.
 *
 * @implements Scope<\stdClass>
 */
final class InvalidScope implements Scope
{
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('invalid', true);
    }
}
?>
--EXPECTF--
InvalidTemplateParam on line %d: Extended template param TModel expects type Illuminate\Database\Eloquent\Model, type stdClass given
ImplementedParamTypeMismatch on line %d: Argument 2 of InvalidScope::apply has wrong type 'Illuminate\Database\Eloquent\Model', expecting 'stdClass' as defined by Illuminate\Database\Eloquent\Scope::apply
