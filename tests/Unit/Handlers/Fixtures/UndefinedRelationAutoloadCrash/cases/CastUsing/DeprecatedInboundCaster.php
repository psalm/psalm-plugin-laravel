<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CastUsing;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

// Inbound caster object returned by InboundCastable::castUsing(): CastResolver checks it for CastsInboundAttributes.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedInboundCaster implements CastsInboundAttributes
{
    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return '';
    }
}
