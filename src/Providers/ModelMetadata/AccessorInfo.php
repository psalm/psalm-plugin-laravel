<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Storage\MethodStorage;
use Psalm\Type\Union;

/**
 * Sealed hierarchy for model accessors.
 *
 * Two concrete subtypes carry different downstream semantics:
 * - {@see LegacyAccessorInfo}: `getXxxAttribute()` style (pre-Laravel 9)
 * - {@see AttributeAccessorInfo}: `Attribute::make(...)` style
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
abstract readonly class AccessorInfo
{
    /**
     * @param non-empty-lowercase-string $propertyName Normalized property name (e.g. `full_name`).
     */
    public function __construct(
        public string $propertyName,
        public Union $returnType,
        public MethodStorage $method,
    ) {}
}
