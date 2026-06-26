<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Storage\MethodStorage;
use Psalm\Type\Union;

/**
 * Laravel-9+ accessor style: `public function fullName(): Attribute { return Attribute::make(get: fn() => ...); }`.
 *
 * When {@see $hasMutator} is true, the same `Attribute::make()` call also
 * provided a `set:` closure — look up `ModelMetadata::mutators()[$propertyName]`
 * for the paired {@see AttributeMutatorInfo}.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class AttributeAccessorInfo extends AccessorInfo
{
    /**
     * @param non-empty-lowercase-string $propertyName
     */
    public function __construct(
        string $propertyName,
        Union $returnType,
        MethodStorage $method,
        public bool $hasMutator,
    ) {
        parent::__construct($propertyName, $returnType, $method);
    }
}
