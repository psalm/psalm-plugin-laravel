<?php

declare(strict_types=1);

namespace UnusedViewNamespacedFixture;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

// A package-style provider registering its views under a namespace via loadViewsFrom(), never
// addLocation(): the directory it hands the finder lands in getHints(), not getPaths() (#1497).
final class PackageViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../package-views', 'pkg');
    }
}

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()). The provider is
// declared inline because fixture classes are not composer-autoloaded.
return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([PackageViewServiceProvider::class])
    ->create();
