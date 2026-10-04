<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

/**
 * One literal relation aggregate requested by a `withCount()`/`loadSum()`/... call.
 *
 * @psalm-immutable
 * @internal
 */
final readonly class AggregateEntry
{
    /**
     * @param 'count'|'exists'|'sum'|'min'|'max'|'avg' $function
     * @param non-empty-string $relation relation method name, exactly as passed (alias clause stripped)
     * @param non-empty-string $alias    attribute name Laravel stores the aggregate under
     * @param string $column             '*' for count/exists
     */
    public function __construct(
        public string $function,
        public string $relation,
        public string $alias,
        public string $column,
    ) {}
}
