<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait-hosted scopes whose non-query parameter is typed `self` or `static`.
 *
 * Reproduces issue #1031: when a model with a custom Eloquent builder uses these scopes,
 * the scope's params provider is registered on the *builder* class, so Psalm expands the
 * `self`/`static` parameter typehint against the builder instead of the model and reports
 * a false-positive InvalidArgument. The scope must resolve `self` to the trait's composing
 * class and `static`/`$this` to the queried model, regardless of which class serves the params.
 *
 * Scopes declared directly on a model class are unaffected because Psalm resolves their
 * `self` to the model at scan time; only trait-declared scopes keep `self`/`static`
 * unresolved until argument-check time, which is why this archetype lives in a trait.
 *
 * Both the legacy `scopeXxx()` form and the `#[Scope]`-attributed form are covered.
 * The `#[Scope]` variant (`outranks`) verifies that declaring-class resolution correctly
 * finds trait-hosted attributed scopes and that `self`-pinning applies equally to both forms.
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

    /**
     * `#[Scope]`-attributed variant with a `self`-typed non-query parameter.
     *
     * Mirrors `scopeRankedAbove` for the attributed form, verifying that:
     * 1. Declaring-class resolution finds the `#[Scope]` attribute on the TRAIT's
     *    MethodStorage rather than the composing class's stub (issue #1046).
     * 2. `self`-pinning (issue #1043) works the same way for attributed scopes as
     *    for legacy ones: `self` binds to the composing class, so a sibling child
     *    of that class is a valid argument.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function outranks(Builder $query, self $model): void
    {
        $query->whereKey($model->getKey());
    }
}
