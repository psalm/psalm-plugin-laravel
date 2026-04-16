<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * `scopeXxx(Builder $q, ...)` style.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class LegacyScopeInfo extends ScopeInfo {}
