<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait-hosted scopes whose non-query parameter is typed `self` or `static`.
 *
 * Reproduces issue #1031: when a model with a custom Eloquent builder uses these scopes,
 * the scope's params provider is registered on the *builder* class, so Psalm expands the
 * `self`/`static` parameter typehint against the builder instead of the model and reports
 * a false-positive InvalidArgument. The scope must resolve `self`/`static` to the using
 * model regardless of which class serves the params.
 *
 * Scopes declared directly on a model class are unaffected because Psalm resolves their
 * `self` to the model at scan time; only trait-declared scopes keep `self`/`static`
 * unresolved until argument-check time, which is why this archetype lives in a trait.
 *
 * Only the legacy `scopeXxx()` form is covered: trait-hosted `#[Scope]`-attributed scopes
 * are not detected on builder instances at all (a separate pre-existing gap — they surface
 * as UndefinedMagicMethod on a custom builder and as an unchecked call on the base builder),
 * so a `#[Scope]` scope here would not reach the self/static expansion under test.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait ComparesRank
{
    /**
     * Legacy scope with a native `self`-typed non-query parameter.
     *
     * @param Builder<self> $query
     */
    public function scopeRankedAbove(Builder $query, self $model): void
    {
        $query->whereKeyNot($model->getKey());
    }

    /**
     * Legacy scope with a docblock-only `static`-typed non-query parameter.
     *
     * `static` is not a legal native parameter type, so it can only be expressed in a
     * docblock. This exercises the `static` resolution arm and the docblock-only param
     * path, neither of which the native `self` typehint above reaches.
     *
     * @param Builder<static> $query
     * @param static $model
     */
    public function scopeRankedBelow(Builder $query, $model): void
    {
        $query->whereKeyNot($model->getKey());
    }

    /**
     * Legacy scope with a `self` nested inside a generic (`list<self>`).
     *
     * Exercises the nested-generic arm: TypeExpander recurses into type parameters, so
     * `list<self>` must expand to `list<Model>` — not `list<Builder>` — when served on a
     * custom builder. A flat `self` param would not reach this code path.
     *
     * @param Builder<self> $query
     * @param list<self> $models
     */
    public function scopeRankedAmong(Builder $query, array $models): void
    {
        $query->whereKeyNot(\array_map(static fn(self $model) => $model->getKey(), $models));
    }
}
