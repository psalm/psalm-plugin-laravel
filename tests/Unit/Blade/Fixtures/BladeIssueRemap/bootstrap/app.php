<?php

declare(strict_types=1);

use BladeIssueRemapFixture\Providers\LivewireStubProvider;
use BladeIssueRemapFixture\Providers\RouteHelperStubProvider;
use Illuminate\Foundation\Application;

// Required directly: this fixture has no composer autoload mapping of its own (app/Greeter.php is
// only ever read by Psalm's own file scanner, never by PHP), but a real service provider's boot()
// runs as real PHP during the app boot below, so PHP's autoloader must be able to find the class.
require_once __DIR__ . '/../app/Providers/LivewireStubProvider.php';
require_once __DIR__ . '/../app/Providers/RouteHelperStubProvider.php';
// Makes RouteGenerator declared in-process, exactly what a real vendor package's own composer
// autoloader would do — and what the #1505 fix's class_exists($n, false) gate relies on, since
// this fixture's vendor/autoload.php is a deliberate no-op stub (see PsalmShadowRegistrar).
require_once __DIR__ . '/../packages/route-helper/src/RouteGenerator.php';

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
//
// LivewireStubProvider and RouteHelperStubProvider stand in for real vendor packages (Livewire,
// Filament, ...): their boot() registers Blade directives/precompilers, exactly like the packages
// ApplicationProvider::doGetApp() already runs boot() for.
return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([LivewireStubProvider::class, RouteHelperStubProvider::class])
    ->create();
