--FILE--
<?php declare(strict_types=1);

/**
 * Eloquent\Builder subclass passing a closure to $this->where() inside the
 * subclass must type the closure parameter as the BASE class
 * `\Illuminate\Database\Eloquent\Builder` — not `self`.
 *
 * Why this is the recommended form (not a workaround):
 *
 * PR #784 fixed #776 by typing the stub closure as `\Closure(self<TModel>): mixed`.
 * Psalm 7 does not specialize the closure-parameter `static` against the
 * receiver's generic binding, so the stub uses `self<TModel>` to keep the
 * (more common) untyped arrow-function form working:
 *
 *     Customer::query()->where(fn ($q) => $q->where('email', 'x'));   // works
 *
 * Issue #815 asks for `fn (self $q)` to also work inside subclass methods.
 * This is intrinsically incompatible with the #776 fix under Psalm 7:
 *
 *   - `self<TModel>`                  → #776 works, `fn (self $q)` rejected.
 *   - `static<TModel>`                → `fn (self $q)` works, #776 collapses to `mixed`.
 *   - `self<TModel> | static<TModel>` → union arms produce `UndefinedClass`.
 *   - `self<TModel> & static`         → `fn (self $q)` works inside subclass,
 *                                       but `fn (Builder $q)` (the canonical
 *                                       Laravel-docs form) is then rejected on
 *                                       subclass instances from external code.
 *
 * Until Psalm 7 specializes `static` in closure-parameter positions, the only
 * shape that satisfies every caller is the base `\Illuminate\Database\Eloquent\Builder`
 * type — which is also what Laravel itself passes to the closure unless the
 * model overrides `newEloquentBuilder()`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/815
 * @see https://github.com/psalm/psalm-plugin-laravel/pull/784
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
        return $this->where(static fn (Builder $q) => $q->whereNotNull('published_at'));
    }
}

/**
 * Same closure shape from an external caller against a subclass-typed receiver.
 * Covers column 3 of the trade-off table in `docs/contributing/decisions.md`.
 *
 * @param Issue815FooBuilder<Issue815Foo> $b
 * @return Issue815FooBuilder<Issue815Foo>
 */
function issue815_external_caller(Issue815FooBuilder $b): Issue815FooBuilder
{
    return $b->where(static fn (Builder $q) => $q->whereNotNull('published_at'));
}
?>
--EXPECTF--
