<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\ToArrayCasts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

// Class cast target: the registry must classify it from Psalm's storage. Loading it ends the run.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/** @implements CastsAttributes<Money, Money> */
final class DeprecatedMoneyCaster implements CastsAttributes
{
    /** @return Money */
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money();
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return '';
    }
}
