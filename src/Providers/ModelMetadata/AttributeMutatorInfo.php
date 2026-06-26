<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Storage\MethodStorage;
use Psalm\Type\Union;

/**
 * Laravel-9+ mutator style: paired with an {@see AttributeAccessorInfo}
 * on the same `Attribute::make(get: ..., set: ...)` method.
 *
 * {@see $accessorPropertyName} is surfaced explicitly so a consumer that
 * queries `mutators()['full_name']` can locate the paired accessor without
 * re-scanning methods.
 *
 * {@see $setType} is the `Attribute<TGet, TSet>` setter type (TSet) the write-path bakes into
 * `pseudo_property_set_types`. A `never` TSet means read-only and yields no mutator entry, so this
 * is never `never`; mixed when TSet is absent or the Attribute is bare.
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
        public Union $setType,
    ) {
        parent::__construct($propertyName, $method);
    }
}
