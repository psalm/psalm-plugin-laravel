<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

// Caster object returned by PriceCastable::castUsing(): CastResolver checks it for CastsAttributes during registry warm-up.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/** @implements CastsAttributes<string, string> */
final class DeprecatedPriceCaster implements CastsAttributes
{
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return '';
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return '';
    }
}
