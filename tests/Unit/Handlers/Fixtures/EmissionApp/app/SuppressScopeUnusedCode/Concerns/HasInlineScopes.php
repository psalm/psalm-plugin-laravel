<?php

declare(strict_types=1);

namespace ScopeUnusedCodeFixture\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait-hosted scopes — the case #1046's detection fix left leaking under findUnusedCode.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasInlineScopes
{
    /**
     * Trait-hosted #[Scope] (protected). Dispatched via Builder::__call, never referenced
     * directly, so it must be suppressed rather than reported PossiblyUnusedMethod.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Trait-hosted legacy scope (public). Dispatched via Model::callNamedScope.
     *
     * @param Builder<self> $query
     */
    public function scopeFlagged(Builder $query): void
    {
        $query->where('flagged', true);
    }

    /**
     * Trait-hosted legacy accessor. Dispatched via __get magic ($model->computed), never referenced
     * directly, so it must be suppressed rather than reported PossiblyUnusedMethod — exercises the
     * accessor branch of the same trait-resolution path.
     */
    protected function getComputedAttribute(): string
    {
        return 'computed';
    }

    /**
     * Trait boot hook: Model::bootTraits() invokes `boot` . basename(self) statically by reflection,
     * so it has no visible caller and must be suppressed rather than reported. See #1069.
     */
    protected static function bootHasInlineScopes(): void {}

    /**
     * Trait initialize hook: Model::initializeTraits() invokes `initialize` . basename(self) on the
     * instance by reflection. Same suppression requirement as the boot hook above. See #1069.
     */
    public function initializeHasInlineScopes(): void {}

    /**
     * Private #[Scope]: never a usable scope (Eloquent cannot dispatch it), so the handler does
     * NOT suppress it. It produces no output here regardless — Psalm only emits UnusedMethod for a
     * private method when the class has no __call, and every Model declares __call. Present to
     * document that the visibility carve-out is intact, not to assert a report.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    private function secret(Builder $query): void
    {
        $query->where('secret', true);
    }
}
