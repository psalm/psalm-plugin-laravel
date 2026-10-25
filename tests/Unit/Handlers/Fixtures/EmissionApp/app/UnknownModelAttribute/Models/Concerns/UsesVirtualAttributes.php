<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models\Concerns;

/**
 * Mirrors a vendor trait (custom-fields packages) that admits a virtual, non-column key through
 * `isFillable()` / `fillableFromArray()` and consumes it in a saving hook.
 */
trait UsesVirtualAttributes
{
    #[\Override]
    public function isFillable($key)
    {
        return $key === 'custom_fields' || parent::isFillable($key);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    #[\Override]
    protected function fillableFromArray(array $attributes)
    {
        return \array_intersect_key($attributes, ['custom_fields' => true]) + parent::fillableFromArray($attributes);
    }
}
