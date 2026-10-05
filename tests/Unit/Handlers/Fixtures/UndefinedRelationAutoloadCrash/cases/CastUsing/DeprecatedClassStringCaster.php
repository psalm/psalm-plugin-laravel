<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CastUsing;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

// Caster class-string returned by ClassStringCastable::castUsing(): CastResolver checks it for CastsAttributes.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/** @implements CastsAttributes<string, string> */
final class DeprecatedClassStringCaster implements CastsAttributes
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
