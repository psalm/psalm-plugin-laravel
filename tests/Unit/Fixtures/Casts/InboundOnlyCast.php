<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A write-only cast (CastsInboundAttributes). Reading such an attribute back is a passthrough of
 * the column's raw DB type, so the registry's 4-arg `CastResolver::resolve` bake must thread the
 * column base type as `$originalType` — otherwise the read type silently degrades to `mixed`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class InboundOnlyCast implements CastsInboundAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return \is_string($value) ? $value : '';
    }
}
