<?php

declare(strict_types=1);

use AutoloadCrashFixture\Cases\ContainerStringBinding\DeprecatedBoundService;
use Illuminate\Foundation\Application;

// A real bootstrap so the binding below exists in the app the plugin boots. `::class` never loads the class.
return Application::configure(basePath: \dirname(__DIR__))
    ->withBindings(['service.class' => static fn(): string => DeprecatedBoundService::class])
    ->create();
