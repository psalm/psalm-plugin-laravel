<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Union;

/**
 * The fully-localized signature of a method forwarded to a related model's builder.
 *
 * @internal
 * @psalm-immutable
 */
final class ResolvedForwardedMethod
{
    /**
     * @param list<FunctionLikeParameter> $parameters
     */
    public function __construct(
        public readonly Union $returnType,
        public readonly array $parameters,
    ) {}
}
