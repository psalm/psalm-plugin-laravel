<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\DirectCast;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

// Caster named directly in $casts: CastResolver checks it exists and what it implements during registry warm-up.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/** @implements CastsAttributes<string, string> */
final class DeprecatedDirectCaster implements CastsAttributes
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
