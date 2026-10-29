<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;

// Lets a test run configure the app's date class the way a service provider would (`Date::use()`).
match (\getenv('MODEL_DATES_DATE')) {
    'immutable' => DateFactory::use(CarbonImmutable::class),
    'callable' => DateFactory::use(static fn($date) => $date),
    default => null,
};

// A minimal real bootstrap/app.php: Testbench's package-mode fallback reads no project migrations.
return Application::configure(basePath: \dirname(__DIR__))->create();
