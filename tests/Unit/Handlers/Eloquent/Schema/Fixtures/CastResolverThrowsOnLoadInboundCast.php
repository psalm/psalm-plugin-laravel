<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

// Stands in for a load-time deprecation, which Psalm's error handler turns into an exception (#1652).
throw new \RuntimeException('raised while loading');

// Declared at run time, so the throw above leaves it undeclared.
if (true) {
    final class CastResolverThrowsOnLoadInboundCast implements CastsInboundAttributes
    {
        #[\Override]
        public function set(Model $model, string $key, mixed $value, array $attributes): string
        {
            return '';
        }
    }
}
