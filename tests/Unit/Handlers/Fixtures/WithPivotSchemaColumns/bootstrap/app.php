<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use WithPivotFixture\PlainDate;

// Lets a test run configure the app's date class the way a service provider would (`Date::use()`).
match (\getenv('WITH_PIVOT_DATE')) {
    'immutable' => DateFactory::use(CarbonImmutable::class),
    'callable' => DateFactory::use(static fn(mixed $date): mixed => $date),
    'plain' => DateFactory::use(PlainDate::class),
    default => null,
};

// A minimal real bootstrap/app.php: Testbench's package-mode fallback reads no project migrations.
return Application::configure(basePath: \dirname(__DIR__))->create();
