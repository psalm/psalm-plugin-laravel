<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\DirectCast;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

// Inbound caster named directly in $casts: CastResolver checks it for Castable, CastsAttributes, then
// CastsInboundAttributes.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedDirectInboundCaster implements CastsInboundAttributes
{
    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return '';
    }
}
