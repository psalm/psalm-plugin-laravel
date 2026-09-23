<?php

declare(strict_types=1);

namespace BladeRuntimeHelpersFixture\Demo\Providers;

use Illuminate\Support\ServiceProvider;

final class DemoServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        include_once __DIR__ . '/../Http/helpers.php';
    }
}
