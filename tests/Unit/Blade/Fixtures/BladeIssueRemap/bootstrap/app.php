<?php

declare(strict_types=1);

use BladeIssueRemapFixture\Providers\LivewireStubProvider;
use Illuminate\Foundation\Application;

// Required directly: this fixture has no composer autoload mapping of its own (app/Greeter.php is
// only ever read by Psalm's own file scanner, never by PHP), but a real service provider's boot()
// runs as real PHP during the app boot below, so PHP's autoloader must be able to find the class.
require_once __DIR__ . '/../app/Providers/LivewireStubProvider.php';

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
//
// LivewireStubProvider stands in for a real vendor package (Livewire, Filament, ...): its boot()
// registers a Blade precompiler, exactly like the packages ApplicationProvider::doGetApp() already
// runs boot() for.
return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([LivewireStubProvider::class])
    ->create();
