<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Contracts\Config\Repository;

final class ConfigRepositoryProvider
{
    public static function get(): Repository
    {
        return ApplicationProvider::getApp()->get(Repository::class);
    }
}
