<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Storage\MethodStorage;

/**
 * Sealed hierarchy for model mutators (the write-side of accessors).
 *
 * Concrete subtypes:
 * - {@see LegacyMutatorInfo}: `setXxxAttribute()` style.
 * - {@see AttributeMutatorInfo}: `Attribute::make(set: ...)` style.
 *
 * A legacy mutator can exist without a matching accessor (write-only);
 * consumers tolerate that by keying on {@see $propertyName} independently
 * of {@see AccessorInfo}.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
abstract readonly class MutatorInfo
{
    /**
     * @param non-empty-lowercase-string $propertyName
     */
    public function __construct(
        public string $propertyName,
        public MethodStorage $method,
    ) {}
}
