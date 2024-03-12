<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Contracts\Config\Repository;

final class ConfigRepositoryProvider
{
    /** @psalm-suppress MixedInferredReturnType */
    public static function get(): Repository
    {
        /** @psalm-suppress MixedReturnStatement */
        return ApplicationProvider::getApp()->get(Repository::class);
    }
}
