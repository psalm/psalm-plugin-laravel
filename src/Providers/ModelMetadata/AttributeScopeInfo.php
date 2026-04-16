<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * `#[Scope] public function xxx(Builder $q, ...)` style (Laravel 11.15+).
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class AttributeScopeInfo extends ScopeInfo {}
