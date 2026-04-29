--FILE--
<?php declare(strict_types=1);

/**
 * Eloquent\Builder subclass passing a closure to $this->where() must preserve
 * the subclass return type. PR #784 widened the stub closure return to :mixed,
 * which made Psalm reject closures whose parameter narrows to `self`. The
 * workaround below widens the closure parameter to base Builder.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/815
 */

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class Issue815Foo extends Model {}

/**
 * @template TModel of Issue815Foo
 * @extends Builder<TModel>
 */
final class Issue815FooBuilder extends Builder
{
    /** @return self<TModel> */
    public function publishedOnly(): self
    {
        // @todo https://github.com/psalm/psalm-plugin-laravel/issues/815
        //   Once #815 is fixed, narrow the closure parameter back to `self $q`
        //   (the natural form — `$this` in this context is the subclass).
        return $this->where(static fn (Builder $q) => $q->whereNotNull('published_at'));
    }
}
?>
--EXPECTF--
