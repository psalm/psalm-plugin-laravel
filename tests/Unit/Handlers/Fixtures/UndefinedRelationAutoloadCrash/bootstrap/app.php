<?php

declare(strict_types=1);

use AutoloadCrashFixture\Container\DeprecatedBoundService;
use Illuminate\Foundation\Application;

// A real bootstrap, so database_path('migrations') resolves to this project's database/migrations (the
// Testbench fallback anchors it at its own skeleton) and the binding below exists in the app the plugin
// boots. `::class` never loads the class.
return Application::configure(basePath: \dirname(__DIR__))
    ->withBindings(['service.class' => static fn(): string => DeprecatedBoundService::class])
    ->create();
