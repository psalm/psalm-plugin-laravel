--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/815
 *
 * PR #784 widened Builder::where's closure param to Closure(self<TModel>): mixed, which
 * resolves to Closure(Builder<TModel>): mixed inside subclasses. When a subclass passes
 * a narrower closure — e.g. Closure(FooEloquentBuilder): FooEloquentBuilder via `self`
 * or the subclass name — Psalm raises ArgumentTypeCoercion because the provided param
 * is narrower than the advertised Builder<TModel> (closure params are contravariant).
 *
 * The fix adds an untyped \Closure branch alongside Closure(self<TModel>): mixed. The
 * typed branch still drives bidirectional inference for untyped arrow fns (#776); the
 * untyped branch accepts subclass-typed closures without tripping contravariance. A
 * Closure(static): mixed branch was considered but Psalm 7 does not unify `static`
 * against `TModel` in closure parameter positions, so it cannot drive bidirectional
 * inference for branch 1 — see the rationale docblock in Builder.stubphp's where().
 *
 * Return types (`Builder<Customer>`, not `self`) are deliberate here: this file tests the
 * closure-argument coercion, not the orthogonal return-type specificity concern that
 * `@return self<TModel>` carries in custom-builder contexts — see CustomQueryBuilderTest.
 */

/**
 * @extends Builder<Customer>
 */
final class FooEloquentBuilder extends Builder
{
    /** @return Builder<Customer> */
    public function publishedOnlySelfTyped(): Builder
    {
        $result = $this->where(static fn (self $q): self => $q->whereNotNull('published_at'));
        /** @psalm-check-type-exact $result = Builder<Customer> */
        return $result;
    }

    /** @return Builder<Customer> */
    public function namedOnly(): Builder
    {
        $result = $this->where(static fn (FooEloquentBuilder $q): FooEloquentBuilder => $q->whereNotNull('name'));
        /** @psalm-check-type-exact $result = Builder<Customer> */
        return $result;
    }

    /** @return Builder<Customer> */
    public function untypedArrow(): Builder
    {
        $result = $this->where(static fn ($q) => $q->whereNotNull('email'));
        /** @psalm-check-type-exact $result = Builder<Customer> */
        return $result;
    }

    /** @return Builder<Customer> */
    public function voidLongForm(): Builder
    {
        return $this->where(function (self $q): void {
            $q->whereNotNull('deleted_at');
        });
    }

    public function firstPublishedSelfTyped(): ?Customer
    {
        $result = $this->firstWhere(static fn (self $q): self => $q->whereNotNull('published_at'));
        /** @psalm-check-type-exact $result = Customer|null */
        return $result;
    }

    /** @return Builder<Customer> */
    public function notPublishedSelfTyped(): Builder
    {
        return $this->whereNot(static fn (self $q): self => $q->whereNull('published_at'));
    }

    /** @return Builder<Customer> */
    public function orNotPublishedSelfTyped(): Builder
    {
        return $this->orWhereNot(static fn (self $q): self => $q->whereNull('published_at'));
    }

    /** @return Builder<Customer> */
    public function orPublishedSelfTyped(): Builder
    {
        return $this->orWhere(static fn (self $q): self => $q->whereNotNull('published_at'));
    }
}

// Keep the #776 arrow-fn case covered here too so the union that fixes #815
// doesn't silently regress bidirectional inference on untyped arrow closures.
function test_base_builder_untyped_arrow(): Builder
{
    $result = Customer::query()->where(fn ($q) => $q->where('email', 'x'));
    /** @psalm-check-type-exact $result = Builder<Customer> */
    return $result;
}

// orWhere got the same two-branch union in this PR (was previously a bare
// `\Closure|array|string` with no typed branch and no `Expression`), so the
// typed branch now drives bidirectional inference here too.
function test_base_builder_orWhere_untyped_arrow(): Builder
{
    $result = Customer::query()->orWhere(fn ($q) => $q->where('email', 'x'));
    /** @psalm-check-type-exact $result = Builder<Customer> */
    return $result;
}

?>
--EXPECT--
