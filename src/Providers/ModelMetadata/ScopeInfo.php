<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;

/**
 * Sealed hierarchy for model scopes.
 *
 * Both styles normalize to the same key:
 * - {@see LegacyScopeInfo}: `scopePublished(Builder $q)` → key `published`
 * - {@see AttributeScopeInfo}: `#[Scope] public function published(Builder $q)` → key `published`
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
abstract readonly class ScopeInfo
{
    /**
     * @param non-empty-lowercase-string    $name       Normalized — "published", not "scopePublished".
     * @param list<FunctionLikeParameter>   $parameters EXCLUDING the leading Builder `$query` parameter.
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public MethodStorage $method,
    ) {}
}
