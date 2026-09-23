<?php

declare(strict_types=1);

use BladeRuntimeHelpersFixture\Demo\Providers\DemoServiceProvider;
use Illuminate\Foundation\Application;

// Nothing autoloads `packages/`, which is the point: the provider is reachable only because this
// file requires it, exactly as a package-style monorepo's root bootstrap reaches its own packages.
require_once __DIR__ . '/../packages/Demo/src/Providers/DemoServiceProvider.php';

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()). The provider's
// register() is what `include`s the helper file, so the helpers exist only after a full boot.
return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([DemoServiceProvider::class])
    ->create();
