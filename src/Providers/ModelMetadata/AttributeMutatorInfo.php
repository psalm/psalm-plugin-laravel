<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Storage\MethodStorage;

/**
 * Laravel-9+ mutator style: paired with an {@see AttributeAccessorInfo}
 * on the same `Attribute::make(get: ..., set: ...)` method.
 *
 * {@see $accessorPropertyName} is surfaced explicitly so a consumer that
 * queries `mutators()['full_name']` can locate the paired accessor without
 * re-scanning methods.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class AttributeMutatorInfo extends MutatorInfo
{
    /**
     * @param non-empty-lowercase-string $propertyName
     * @param non-empty-lowercase-string $accessorPropertyName
     */
    public function __construct(
        string $propertyName,
        MethodStorage $method,
        public string $accessorPropertyName,
    ) {
        parent::__construct($propertyName, $method);
    }
}
