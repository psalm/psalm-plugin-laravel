<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// Boot-time view data, as a service provider registers it: a shared key and a composer.
$app->booted(static function () use ($app): void {
    $view = $app->make('view');
    $view->share('siteName', 'Fixture');
    $view->composer('composed', static fn(): null => null);

    // Anonymous component registrations, as a package's service provider makes them. The namespace
    // and alias maps are string-keyed, which the bootstrapper must not splat as named arguments.
    $blade = $app->make('blade.compiler');
    $blade->anonymousComponentNamespace('anon-ns', 'ns');
    $blade->anonymousComponentPath(\dirname(__DIR__) . '/resources/anon-path', 'pkg');
    $blade->component('aliased.target', 'my-alias');
});

return $app;
