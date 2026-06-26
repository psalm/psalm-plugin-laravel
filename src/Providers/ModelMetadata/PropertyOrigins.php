<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Immutable set of {@see PropertyOrigin} values.
 *
 * Replaces a bitmask int so consumers enumerate cases by name
 * (see types round-1 review item C2-T).
 *
 * The backing map is keyed by `PropertyOrigin->value` with the origin itself as the
 * value, which lets `toList()` return origins without a `PropertyOrigin::from()`
 * round-trip. Kept private so consumers use the `has()` / `with()` / `toList()` API
 * rather than poking at the storage shape.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class PropertyOrigins
{
    /**
     * @param array<value-of<PropertyOrigin>, PropertyOrigin> $set
     */
    public function __construct(private array $set) {}

    public function has(PropertyOrigin $origin): bool
    {
        return isset($this->set[$origin->value]);
    }

    public function with(PropertyOrigin $origin): self
    {
        // Idempotent adds are common when multiple sources name the same property —
        // skip the allocation when the origin is already in the set.
        if (isset($this->set[$origin->value])) {
            return $this;
        }

        $set = $this->set;
        $set[$origin->value] = $origin;

        return new self($set);
    }

    /** @return list<PropertyOrigin> */
    public function toList(): array
    {
        return \array_values($this->set);
    }

    public function isEmpty(): bool
    {
        return $this->set === [];
    }
}
